<?php
/**
 * POST api/submit-lead.php: the signup form posts here.
 *
 * Checks the lead (same rules as index.js), verifies the Cloudflare Turnstile captcha,
 * flags likely bots, then forwards the lead to the CRM with the API key from ../.env.
 * The API key never reaches the browser. Leads the CRM accepted are then copied to the
 * leads log (lib/lead-log.php), after the visitor already has the response.
 *
 * Adapted from the brasil-bullion app's src/lib/lead-proxy.server.ts.
 */

declare(strict_types=1);

require __DIR__ . '/lib/lead-log.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const MAX_BODY_BYTES    = 8192;
const RATE_LIMIT_MAX    = 5;    // submits...
const RATE_LIMIT_WINDOW = 600;  // ...per IP per 10 minutes
const CRM_TIMEOUT       = 15;
const CAPTCHA_TIMEOUT   = 5;
const MIN_FILL_MS       = 3000; // filling 4 fields and 3 questions takes a person well over this
const OFFER_NAME        = 'spanish-bullion';
const TURNSTILE_ACTION  = 'lead';
const TURNSTILE_TEST_SECRET = '1x0000000000000000000000000000000AA';
const CAPTCHA_ERROR     = 'Confirma la verificación de seguridad e inténtalo de nuevo.';

// Responses stay below 502/504 on purpose: Cloudflare replaces those with its own page.
function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Like respond(), but first hands the response to the visitor, then runs $after (the leads
 * log), so slow or failing background work cannot delay or break the response.
 */
function respond_then(int $status, array $body, callable $after): never
{
    $out = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    http_response_code($status);
    header('Content-Length: ' . strlen($out));
    header('Connection: close');
    echo $out;
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // php-fpm (the live server)
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        while (ob_get_level() > 0) ob_end_flush();
        flush();
    }
    try {
        $after();
    } catch (Throwable) {
        // never affects the visitor
    }
    exit;
}

/** One log line per lead: never the API key or personal data. */
function log_line(string $msg): void
{
    error_log('[submit-lead] ' . $msg);
}

/** Minimal .env loader: KEY=VALUE lines, # comments, optional quotes. */
function load_env(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }
    return $env;
}

function csv_list(string $value): array
{
    return array_values(array_filter(array_map(fn($h) => strtolower(trim($h)), explode(',', $value))));
}

/** The visitor's IP: Cloudflare's header only when the site is behind Cloudflare. */
function client_ip(bool $trustCloudflare): string
{
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($trustCloudflare && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** File-based fixed-window rate limit, stored in the system temp folder. */
function rate_limited(string $ip): bool
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spanish-bullion-ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false; // fail open rather than block real visitors
    }
    $fh = @fopen($dir . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json', 'c+');
    if ($fh === false) {
        return false;
    }
    flock($fh, LOCK_EX);
    $now  = time();
    $data = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($data) || ($now - (int) ($data['start'] ?? 0)) >= RATE_LIMIT_WINDOW) {
        $data = ['start' => $now, 'count' => 0];
    }
    $data['count']++;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data['count'] > RATE_LIMIT_MAX;
}

// post_json() and domain_receives_mail() are the only network calls; tests/fakes.php
// defines its own versions first so the tests never reach the CRM, Cloudflare or DNS.
if (!function_exists('post_json')) {
/** POST JSON with curl; returns [status, decoded body or null], status 0 on network error. */
function post_json(string $url, array $payload, array $headers, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) {
        return [0, null];
    }
    $decoded = json_decode((string) $body, true);
    return [$status, is_array($decoded) ? $decoded : null];
}
}

// --- Field checks: same rules and messages as index.js ------------------------------

// Messages per field ("apellidos" is plural in Spanish).
const NAME_MSG = [
    'nombre' => [
        'empty' => 'Introduce tu nombre', 'short' => 'Nombre demasiado corto', 'long' => 'Nombre demasiado largo (máx. 50 letras)',
        'letters' => 'El nombre solo puede contener letras', 'invalid' => 'Nombre no válido',
    ],
    'apellidos' => [
        'empty' => 'Introduce tus apellidos', 'short' => 'Apellidos demasiado cortos', 'long' => 'Apellidos demasiado largos (máx. 50 letras)',
        'letters' => 'Los apellidos solo pueden contener letras', 'invalid' => 'Apellidos no válidos',
    ],
];

function check_name(string $raw, string $label): array
{
    $value = preg_replace('/\s+/u', ' ', trim($raw));
    $m = NAME_MSG[$label];
    $len = mb_strlen($value);
    if ($value === '') return [null, $m['empty']];
    if ($len < 2) return [null, $m['short']];
    if ($len > 50) return [null, $m['long']];
    if (!preg_match("/^\p{L}[\p{L}\p{M}' .\-]*$/u", $value)) return [null, $m['letters']];
    if (preg_match('/(.)\1{3,}/iu', $value)) return [null, $m['invalid']];
    return [$value, null];
}

const DISPOSABLE = [
    'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org',
    'yopmail.com', 'trashmail.com', 'sharklasers.com', 'getnada.com', 'throwawaymail.com',
    'maildrop.cc', 'dispostable.com', 'fakeinbox.com', 'emailondeck.com', 'mohmal.com',
];
const TYPOS = [
    'gmial.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gmail.con' => 'gmail.com',
    'gmail.co' => 'gmail.com', 'gmail.es' => 'gmail.com', 'gamil.com' => 'gmail.com',
    'gnail.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'hotmal.com' => 'hotmail.com',
    'hotmial.com' => 'hotmail.com', 'hotmail.con' => 'hotmail.com', 'hotmai.com' => 'hotmail.com',
    'hotmail.co' => 'hotmail.com', 'hotmil.com' => 'hotmail.com', 'outlok.com' => 'outlook.com',
    'outlook.con' => 'outlook.com', 'outllok.com' => 'outlook.com', 'yahoo.con' => 'yahoo.com',
    'yaho.com' => 'yahoo.com', 'yaho.es' => 'yahoo.es', 'yahoo.e' => 'yahoo.es',
    'hotmial.es' => 'hotmail.es', 'hotmal.es' => 'hotmail.es', 'hotmail.e' => 'hotmail.es',
    'outlok.es' => 'outlook.es', 'outlook.e' => 'outlook.es', 'telefonica.com' => 'telefonica.net',
    'icloud.con' => 'icloud.com', 'iclod.com' => 'icloud.com',
];

function check_email(string $raw): array
{
    $value = strtolower(trim($raw));
    if ($value === '') return [null, 'Introduce tu e-mail'];
    if (strlen($value) > 255 || !preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $value)
        || str_contains($value, '..')) {
        return [null, 'E-mail no válido'];
    }
    $at = strrpos($value, '@');
    $domain = substr($value, $at + 1);
    if (array_key_exists($domain, TYPOS)) return [null, '¿Querías decir ' . substr($value, 0, $at) . '@' . TYPOS[$domain] . '?'];
    if (in_array($domain, DISPOSABLE, true)) return [null, 'Usa un e-mail personal (no se aceptan e-mails temporales)'];
    return [$value, null];
}

if (!function_exists('domain_receives_mail')) {
/** Can the e-mail's domain receive mail (MX, or A as fallback)? */
function domain_receives_mail(string $email): bool
{
    $domain = substr($email, strrpos($email, '@') + 1) . '.';
    return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
}
}

/** Spanish mobile (starts with 6 or 71–74) → [+34..., null] or [null, message]. */
function check_es_mobile(string $raw): array
{
    $d = ltrim(preg_replace('/\D+/', '', $raw), '0');
    if (str_starts_with($d, '34') && strlen($d) >= 11) $d = substr($d, 2);
    $d = substr($d, 0, 9);
    if ($d === '') return [null, 'Introduce tu número de móvil'];
    if (strlen($d) < 9) return [null, 'Número incompleto: debe tener 9 dígitos'];
    if (!preg_match('/^(6|7[1-4])/', $d)) return [null, 'Introduce un móvil (empieza por 6 o 7)'];
    if (preg_match('/^(\d)\1{7}$/', substr($d, 1))) return [null, 'Número de móvil no válido'];
    return ['+34' . $d, null];
}

const EXPERIENCE = ['none' => 'Nunca he operado', 'beginner' => 'Principiante (ya lo he probado)', 'experienced' => 'Con experiencia'];
const GOAL = [
    'extra_income' => 'Ingresos extra cada mes', 'grow_savings' => 'Hacer crecer mi patrimonio',
    'inflation' => 'Protegerme de la inflación', 'learn' => 'Aprender a operar',
];
const DEPOSIT = ['yes' => 'Sí, empiezo con 250 $ o más', 'not_yet' => 'Todavía no'];

/** Ready-to-deposit leads are the priority; experience and income goals raise quality. */
function score_lead(array $lead): string
{
    if ($lead['deposit'] === 'not_yet') return 'low';
    if ($lead['experience'] !== 'none' || $lead['goal'] === 'extra_income' || $lead['goal'] === 'grow_savings') return 'high';
    return 'medium';
}

// --- Request checks ------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false]);
}

// Same-origin only: reject cross-site posts when the browser sends Origin.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $serverHost = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
    if (!is_string($originHost) || strtolower($originHost) !== $serverHost) {
        respond(403, ['success' => false]);
    }
}

if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    respond(415, ['success' => false]);
}

$env = load_env(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);

$ip = client_ip(($env['TRUST_CLOUDFLARE'] ?? '0') === '1');
if ($ip === '' && $isLocal) {
    $ip = $env['DEV_IP_ADDRESS'] ?? '127.0.0.1';
}
if ($ip === '') {
    log_line('no client IP');
    respond(400, ['success' => false]);
}
if (rate_limited($ip)) {
    respond(429, ['success' => false, 'error' => 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.']);
}

$raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
    respond(413, ['success' => false]);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    respond(400, ['success' => false]);
}

// --- Validation ----------------------------------------------------------------------

$str = static fn(string $k): string => is_string($input[$k] ?? null) ? $input[$k] : '';
$fieldError = static fn(string $field, string $msg) => respond(422, ['success' => false, 'field' => $field, 'error' => $msg]);

[$firstName, $err] = check_name($str('firstName'), 'nombre');
if ($err) $fieldError('firstName', $err);
[$lastName, $err] = check_name($str('lastName'), 'apellidos');
if ($err) $fieldError('lastName', $err);
[$email, $err] = check_email($str('email'));
if ($err) $fieldError('email', $err);
[$mobile, $err] = check_es_mobile($str('phone'));
if ($err) $fieldError('phone', $err);

if (($input['over30'] ?? null) !== true) respond(422, ['success' => false, 'error' => 'Solo disponible para mayores de 30 años']);
$lead = ['experience' => $str('experience'), 'goal' => $str('goal'), 'deposit' => $str('deposit')];
if (!array_key_exists($lead['experience'], EXPERIENCE) || !array_key_exists($lead['goal'], GOAL)
    || !array_key_exists($lead['deposit'], DEPOSIT)) {
    respond(422, ['success' => false, 'error' => 'Responde a todas las preguntas']);
}
if (($input['consent'] ?? null) !== true) respond(422, ['success' => false, 'error' => 'Acepta las condiciones para continuar']);

$utm = [];
if (is_array($input['utm'] ?? null)) {
    foreach ($input['utm'] as $k => $v) {
        if (is_string($k) && is_string($v)) $utm[substr($k, 0, 64)] = substr($v, 0, 200);
    }
}
$clickId = $str('clickId');
if (!preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $clickId)) {
    $clickId = null; // drop malformed tracking values instead of rejecting the lead
}
$website = substr($str('website'), 0, 200);
$fillMs  = is_int($input['fillMs'] ?? null) && $input['fillMs'] >= 0 ? $input['fillMs'] : null;
$token   = substr($str('captchaToken'), 0, 2048);
// Landing/field/click times, forwarded only to the leads log (never to the CRM).
// Malformed timing is dropped, never rejects the lead.
$timing  = sanitize_timing($input['timing'] ?? null);

// --- Configuration -------------------------------------------------------------------

$endpoint = $env['BASE_ENDPOINT'] ?? '';
$apiKey   = $env['BASE_API_KEY'] ?? '';
if ($endpoint === '' || $apiKey === '' || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
    log_line('BASE_ENDPOINT (https) or BASE_API_KEY is not set in .env');
    respond(503, ['success' => false]);
}

// --- Captcha: no lead without a valid one (fail closed if Cloudflare is down) ---------

$secret = ($env['TURNSTILE_SECRET_KEY'] ?? '') ?: ($isLocal ? TURNSTILE_TEST_SECRET : '');
if ($secret === '') {
    log_line('TURNSTILE_SECRET_KEY is not set in .env');
    respond(503, ['success' => false]);
}
if ($token === '') {
    log_line('rejected reason=captcha_failed detail=missing');
    respond(403, ['success' => false, 'error' => CAPTCHA_ERROR]);
}
[$cfStatus, $cf] = post_json(
    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ['secret' => $secret, 'response' => $token, 'remoteip' => $ip],
    [],
    CAPTCHA_TIMEOUT,
);
if ($cf === null) {
    log_line("rejected reason=captcha_unavailable detail=status_$cfStatus");
    respond(503, ['success' => false, 'error' => 'No se ha podido verificar. Inténtalo de nuevo en unos instantes.']);
}
$hostnames = csv_list($env['TURNSTILE_HOSTNAMES'] ?? '');
$captchaFail = match (true) {
    ($cf['success'] ?? false) !== true => 'invalid:' . implode(',', (array) ($cf['error-codes'] ?? [])),
    $hostnames && !in_array(strtolower((string) ($cf['hostname'] ?? '')), $hostnames, true) => 'hostname:' . ($cf['hostname'] ?? '-'),
    // Test-key tokens carry no action, so only check it with a real secret.
    $secret !== TURNSTILE_TEST_SECRET && ($cf['action'] ?? '') !== TURNSTILE_ACTION => 'action:' . ($cf['action'] ?? '-'),
    default => null,
};
if ($captchaFail !== null) {
    log_line("rejected reason=captcha_failed detail=$captchaFail");
    respond(403, ['success' => false, 'error' => CAPTCHA_ERROR]);
}

if (!domain_receives_mail($email)) {
    $fieldError('email', 'Este dominio de e-mail no existe. Revisa la dirección.');
}

// --- Likely-bot signals: still sent to the CRM, flagged, so no real lead is lost ------

$reasons = [];
if ($website !== '') $reasons[] = 'honeypot';
if ($fillMs === null) $reasons[] = 'no_fill_time';
elseif ($fillMs < MIN_FILL_MS) $reasons[] = 'fast_submit';
$country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
if ($country !== '' && $country !== 'ES' && $country !== 'XX') $reasons[] = 'non_es_country';

// --- Forward to the CRM --------------------------------------------------------------

$score = score_lead($lead);
$payload = array_filter([
    'firstname'    => $firstName,
    'lastname'     => $lastName,
    'email'        => $email,
    'mobile'       => $mobile,
    'country_code' => 'ES',
    'country'      => 'Spain',
    'ip_address'   => $ip,
    'locale'       => 'es-ES',
    'click_id'     => $clickId,
    'offer_name'   => OFFER_NAME,
    'funnel'       => ($env['LEAD_FUNNEL'] ?? '') ?: 'commodities-es',
    'custom1'      => $utm['utm_source'] ?? null,
    'custom2'      => $utm['utm_campaign'] ?? null,
    'custom3'      => $score,
    'click_ua'     => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
    // The five form questions, then the bot flag; score and UTMs already go in custom1–3.
    'questions'    => [
        ['key' => 'age_30_plus', 'value' => 'yes'],
        ['key' => 'experience', 'value' => EXPERIENCE[$lead['experience']]],
        ['key' => 'goal', 'value' => GOAL[$lead['goal']]],
        ['key' => 'deposit_250', 'value' => DEPOSIT[$lead['deposit']]],
        ['key' => 'consent_contact_rgpd', 'value' => 'yes'],
        ['key' => 'is_bot', 'value' => $reasons ? 'true' : 'false'],
        ['key' => 'suspected_bot', 'value' => $reasons ? implode(',', $reasons) : 'none'],
    ],
], fn($v) => $v !== null);

$sent = sprintf('score=%s click_id=%s is_bot=%s suspected_bot=%s', $score, $clickId ?? '-',
    $reasons ? 'true' : 'false', $reasons ? implode(',', $reasons) : 'none');

[$status, $result] = post_json($endpoint, $payload, ['x-api-key: ' . $apiKey, 'Authorization: Bearer ' . $apiKey], CRM_TIMEOUT);

if ($status === 0) {
    log_line("$sent → CRM request failed");
    respond(503, ['success' => false]);
}
if ($status === 409) {
    log_line("$sent → duplicate (409)");
    respond(409, ['success' => false, 'error' => 'Ya tienes una cuenta con nosotros. Un especialista se pondrá en contacto contigo en breve.']);
}
$result ??= [];
if ($status < 200 || $status >= 300 || ($result['success'] ?? false) !== true) {
    $errorFields = is_array($result['errors'] ?? null) ? array_keys($result['errors']) : [];
    $code = $result['rejection']['code'] ?? '-';
    log_line(sprintf('%s → CRM rejected status=%d code=%s fields=%s', $sent, $status, is_scalar($code) ? $code : '-',
        $errorFields ? implode(',', $errorFields) : '-'));
    if ($code === 'DISPOSABLE_EMAIL') {
        $fieldError('email', 'Usa un e-mail personal (no se aceptan e-mails temporales)');
    }
    // CRM field name → form field + message shown to the visitor.
    $crmField = [
        'firstname' => ['firstName', 'Revisa tu nombre'],
        'lastname'  => ['lastName', 'Revisa tus apellidos'],
        'email'     => ['email', 'Revisa tu e-mail'],
        'mobile'    => ['phone', 'Revisa tu número de móvil'],
    ];
    foreach ($errorFields as $f) {
        if ($status === 422 && isset($crmField[$f])) $fieldError(...$crmField[$f]);
    }
    respond($status >= 500 ? 503 : 422, ['success' => false]);
}

// Only redirect over HTTPS to the CRM's host or an allow-listed one (no open redirect).
$autologin = $result['data']['autologin_url'] ?? $result['autologin_url'] ?? null;
$allowed = array_merge([strtolower((string) parse_url($endpoint, PHP_URL_HOST))], csv_list($env['LEAD_AUTOLOGIN_HOSTS'] ?? ''));
if (is_string($autologin)) {
    $host = strtolower((string) parse_url($autologin, PHP_URL_HOST));
    if (parse_url($autologin, PHP_URL_SCHEME) !== 'https' || !in_array($host, $allowed, true)) {
        log_line("autologin host \"$host\" not allowed; add it to LEAD_AUTOLOGIN_HOSTS");
        $autologin = null;
    }
} else {
    $autologin = null;
}

$leadId = $result['data']['lead_id'] ?? '-';
log_line(sprintf('%s → accepted lead_id=%s', $sent, is_scalar($leadId) ? (string) $leadId : '-'));
// Only accepted leads go to the leads log, and only after the visitor has the response.
respond_then(
    200,
    array_filter(['success' => true, 'autologin_url' => $autologin], fn($v) => $v !== null),
    fn() => send_lead_log($payload, $timing, lead_log_config($env), 'post_json'),
);
