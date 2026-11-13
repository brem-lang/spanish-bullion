<?php
/**
 * Leads log: a copy of every lead the CRM accepted, with a readable report of how the
 * visitor filled the form (form-timing.js records the raw times in the browser).
 *
 * Adapted from the brasil-bullion app's src/lib/form-timing.ts, form-timing-report.ts and
 * sendLeadLog() in lead-proxy.server.ts.
 */

declare(strict_types=1);

const LEADS_LOG_DEFAULT_ENDPOINT = 'https://alphamastercrm.online/api/leads-logs';
const LEADS_LOG_TIMEOUT = 10;

// The form fields form-timing.js tracks (same names as the lead), with the labels used in
// the report. Not the honeypot.
const TIMED_FIELDS = [
    'firstName'  => 'Nombre',
    'lastName'   => 'Apellidos',
    'email'      => 'E-mail',
    'phone'      => 'Móvil',
    'over30'     => '"30 años o más" checkbox',
    'experience' => 'Q1 Experiencia',
    'goal'       => 'Q2 Objetivo',
    'deposit'    => 'Q3 Depósito 250 $',
    'consent'    => 'Consent (RGPD) checkbox',
];
const TIMED_TEXT_FIELDS = ['firstName', 'lastName', 'email', 'phone'];
const TIMED_CHECKBOXES  = ['over30', 'consent'];
const CONTINUE_BUTTON   = 'Continuar';
const SUBMIT_BUTTON     = 'Quiero empezar ahora';

const TIMING_MAX_MS    = 7 * 86_400_000;
const TIMING_MAX_COUNT = 10_000;

// --- Validation (the zod timingSchema of the original) ---------------------------------

/**
 * The browser's timing data, checked. Anything malformed → null, so bad timing is dropped
 * and never rejects the lead. Unknown keys are left out.
 */
function sanitize_timing(mixed $raw): ?array
{
    if (!is_array($raw)) return null;
    $ms    = static fn($v) => is_int($v) && $v >= 0 && $v <= TIMING_MAX_MS;
    $count = static fn($v) => is_int($v) && $v >= 0 && $v <= TIMING_MAX_COUNT;
    $str   = static fn(int $max) => static fn($v) => is_string($v) && strlen($v) <= $max;

    /** Copies the listed keys that are present; false when one of them is invalid. */
    $pick = static function (array $src, array $rules, array $required = []): array|false {
        $out = [];
        foreach ($rules as $key => $ok) {
            if (!array_key_exists($key, $src)) {
                if (in_array($key, $required, true)) return false;
                continue;
            }
            if (!$ok($src[$key])) return false;
            $out[$key] = $src[$key];
        }
        return $out;
    };

    $t = $pick($raw, [
        'landed_at'               => $str(40),
        'submitted_at'            => $str(40),
        'timezone'                => $str(64),
        'utc_offset_min'          => static fn($v) => is_int($v) && $v >= -900 && $v <= 900,
        'form_loaded_ms'          => $ms,
        'first_continue_click_ms' => $ms,
        'continue_click_ms'       => $ms,
        'first_submit_click_ms'   => $ms,
        'submit_click_ms'         => $ms,
        'submit_clicks'           => $count,
        'form_fill_ms'            => $ms,
    ], ['landed_at', 'submitted_at', 'submit_click_ms']);
    if ($t === false || !is_array($raw['fields'] ?? null) || (array_is_list($raw['fields']) && $raw['fields'] !== [])) {
        return null;
    }

    $fieldRules = [
        'first_focus_ms' => $ms, 'last_blur_ms' => $ms, 'focus_total_ms' => $ms,
        'first_input_ms' => $ms, 'last_input_ms' => $ms, 'input_count' => $count,
    ];
    $t['fields'] = [];
    foreach (array_keys(TIMED_FIELDS) as $name) {
        if (!array_key_exists($name, $raw['fields'])) continue;
        $f = $raw['fields'][$name];
        if (!is_array($f) || (array_is_list($f) && $f !== [])) return null;
        $f = $pick($f, $fieldRules);
        if ($f === false) return null;
        $t['fields'][$name] = $f;
    }
    return $t;
}

// --- Readable report -------------------------------------------------------------------

/** 1234 → "1.2s", 65000 → "1m 05s" */
function timing_duration(int $ms): string
{
    if ($ms < 60_000) return sprintf('%.1fs', $ms / 1000);
    $s = (int) round($ms / 1000);
    return sprintf('%dm %02ds', intdiv($s, 60), $s % 60);
}

/** "2026-11-12T12:34:20.777Z" + 60 → "2026-11-12 13:34:20 (Europe/Madrid, UTC+01:00)" */
function timing_local_time(string $iso, ?int $offsetMin, ?string $tz): string
{
    if ($offsetMin === null || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $iso)) return $iso;
    try {
        $t = (new DateTimeImmutable($iso))->getTimestamp();
    } catch (Exception) {
        return $iso;
    }
    $abs = abs($offsetMin);
    $utc = sprintf('UTC%s%02d:%02d', $offsetMin < 0 ? '-' : '+', intdiv($abs, 60), $abs % 60);
    return gmdate('Y-m-d H:i:s', $t + $offsetMin * 60) . ' (' . ($tz ? "$tz, " : '') . $utc . ')';
}

function timing_times(int $n): string
{
    return $n === 1 ? '1 time' : "$n times";
}

function timing_describe_field(string $name, array $f): string
{
    $n = $f['input_count'] ?? 0;
    if (in_array($name, TIMED_TEXT_FIELDS, true)) {
        $parts = [];
        if (isset($f['focus_total_ms'])) $parts[] = timing_duration($f['focus_total_ms']) . ' in the field';
        $parts[] = $n === 1 ? '1 change' : "$n changes";
        // A whole name/e-mail arriving in one change means the browser filled it or it was pasted.
        if ($n === 1) $parts[] = 'filled in one go (autofill or paste)';
        elseif ($n > 1) $parts[] = 'typed';
        return implode(', ', $parts);
    }
    $at = isset($f['last_input_ms']) ? ' at ' . timing_duration($f['last_input_ms']) : '';
    if (in_array($name, TIMED_CHECKBOXES, true)) {
        return $n > 1 ? 'clicked ' . timing_times($n) . ", last$at" : "ticked$at";
    }
    return $n > 1 ? 'answered, changed answer ' . timing_times($n - 1) . ", last$at" : "answered$at";
}

/** The raw timing (from sanitize_timing) as text people can read in the leads log. */
function timing_report(array $t): array
{
    $events = [[0, 'Opened the page']];
    $add = static function (?int $ms, string $text) use (&$events): void {
        if ($ms !== null) $events[] = [$ms, $text];
    };
    $add($t['form_loaded_ms'] ?? null, 'Form loaded');

    $fields = [];
    foreach (TIMED_FIELDS as $name => $label) {
        $f = $t['fields'][$name] ?? null;
        if ($f === null) continue;
        $fields[$label] = timing_describe_field($name, $f);
        if (in_array($name, TIMED_TEXT_FIELDS, true)) {
            $add($f['first_focus_ms'] ?? $f['first_input_ms'] ?? null, "Started $label");
        } else {
            $add($f['last_input_ms'] ?? null, (in_array($name, TIMED_CHECKBOXES, true) ? 'Ticked' : 'Answered') . " $label");
        }
    }
    foreach (TIMED_FIELDS as $name => $label) {
        if (!isset($t['fields'][$name])) $fields[$label] = 'not touched';
    }

    $firstContinue = $t['first_continue_click_ms'] ?? null;
    $lastContinue  = $t['continue_click_ms'] ?? null;
    $add($firstContinue, 'Clicked "' . CONTINUE_BUTTON . '"');
    if ($lastContinue !== $firstContinue) $add($lastContinue, 'Clicked "' . CONTINUE_BUTTON . '" again (last time)');

    $clicks = $t['submit_clicks'] ?? 1;
    $firstSubmit = $t['first_submit_click_ms'] ?? null;
    if ($firstSubmit !== null && $firstSubmit !== $t['submit_click_ms']) {
        $add($firstSubmit, 'Clicked "' . SUBMIT_BUTTON . '" (first try)');
    }
    $add($t['submit_click_ms'], 'Clicked "' . SUBMIT_BUTTON . '"' . ($clicks > 1 ? " (try $clicks, sent)" : ' (sent)'));

    usort($events, static fn($a, $b) => $a[0] <=> $b[0]); // stable: same-time events keep their order
    $offset = $t['utc_offset_min'] ?? null;
    $tz     = $t['timezone'] ?? null;
    $landed = timing_local_time($t['landed_at'], $offset, $tz);
    $fill   = isset($t['form_fill_ms']) ? timing_duration($t['form_fill_ms']) : null;

    return array_filter([
        'summary' => "Landed $landed, submitted " . timing_duration($t['submit_click_ms']) . ' later'
            . ($fill !== null ? ", spent $fill on the form" : '')
            . ', ' . ($clicks === 1 ? '1 submit click' : "$clicks submit clicks") . '.',
        'landed_at'          => $landed,
        'submitted_at'       => timing_local_time($t['submitted_at'], $offset, $tz),
        'time_before_submit' => timing_duration($t['submit_click_ms']),
        'time_to_fill_form'  => $fill,
        'submit_clicks'      => $clicks,
        'steps'              => array_map(static fn($e) => timing_duration($e[0]) . ' - ' . $e[1], $events),
        'fields'             => $fields,
    ], static fn($v) => $v !== null);
}

// --- Sending ---------------------------------------------------------------------------

/** LEADS_LOG_ENDPOINT / LEADS_LOG_API_KEY from .env (server only; the key never reaches the browser). */
function lead_log_config(array $env): array
{
    return [
        'endpoint' => ($env['LEADS_LOG_ENDPOINT'] ?? '') ?: LEADS_LOG_DEFAULT_ENDPOINT,
        'api_key'  => $env['LEADS_LOG_API_KEY'] ?? '',
    ];
}

/**
 * Sends the CRM payload (plus the timing report) to the leads log. Call only after the CRM
 * accepted the lead, and after the visitor already has the response. Never throws; skipped
 * when the endpoint or key is not set.
 *
 * $post: fn(string $url, array $payload, array $headers, int $timeout): [int $status, ?array $body],
 * status 0 on network error (post_json in submit-lead.php).
 */
function send_lead_log(array $payload, ?array $timing, array $config, callable $post): void
{
    if (($config['endpoint'] ?? '') === '' || ($config['api_key'] ?? '') === '') return;
    try {
        $data = $payload;
        if ($timing !== null) $data['timing'] = timing_report($timing);
        [$status] = $post($config['endpoint'], [
            'email'      => $payload['email'] ?? null,
            'offer_name' => $payload['offer_name'] ?? null,
            'ip_address' => $payload['ip_address'] ?? null,
            'data'       => $data,
        ], ['Authorization: Bearer ' . $config['api_key']], LEADS_LOG_TIMEOUT);
        if ($status === 0) error_log('[submit-lead] lead log: request failed');
        elseif ($status < 200 || $status >= 300) error_log("[submit-lead] lead log: rejected status=$status");
    } catch (Throwable $e) {
        error_log('[submit-lead] lead log: ' . get_class($e));
    }
}
