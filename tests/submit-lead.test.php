<?php
// api/submit-lead.php end to end: run through php-cgi like a real POST, with the CRM,
// Cloudflare and the leads log replaced by tests/fakes.php.

declare(strict_types=1);

require_once __DIR__ . '/../api/lib/lead-log.php';
require_once __DIR__ . '/fixtures.php';

const CRM_URL = 'https://crm.example/api/leads';
const LOG_URL = 'https://log.example/api/leads-logs';

/** A copy of api/ next to a test .env (the script reads ../.env). */
function test_site(bool $withLogKey = true): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sb-test-' . bin2hex(random_bytes(4));
    mkdir("$dir/api/lib", 0700, true);
    mkdir("$dir/tmp");
    copy(__DIR__ . '/../api/submit-lead.php', "$dir/api/submit-lead.php");
    copy(__DIR__ . '/../api/lib/lead-log.php', "$dir/api/lib/lead-log.php");
    file_put_contents("$dir/.env", implode("\n", [
        'BASE_ENDPOINT=' . CRM_URL,
        'BASE_API_KEY=crm-key',
        'LEADS_LOG_ENDPOINT=' . LOG_URL,
        'LEADS_LOG_API_KEY=' . ($withLogKey ? 'log-key' : ''),
    ]) . "\n");
    return $dir;
}

/** POSTs $lead; returns [status, body, calls (url → list of payloads/headers)]. */
function submit(array $lead, array $crm, bool $withLogKey = true): array
{
    static $n = 0;
    $dir = test_site($withLogKey);
    $body = json_encode($lead, JSON_UNESCAPED_UNICODE);
    $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
    ok(is_file($cgi), "php-cgi not found at $cgi");
    $env = array_merge(getenv(), [
        'REQUEST_METHOD'  => 'POST',
        'CONTENT_TYPE'    => 'application/json',
        'CONTENT_LENGTH'  => (string) strlen($body),
        'SCRIPT_FILENAME' => "$dir/api/submit-lead.php",
        'REDIRECT_STATUS' => '200',
        'SERVER_NAME'     => 'localhost',
        'HTTP_HOST'       => 'localhost',
        'REMOTE_ADDR'     => '203.0.113.' . (++$n),
        'HTTP_USER_AGENT' => 'test',
        'TMP' => "$dir/tmp", 'TEMP' => "$dir/tmp", 'TMPDIR' => "$dir/tmp", // fresh rate limit
        'TEST_CALLS'      => "$dir/calls.jsonl",
        'TEST_CRM'        => json_encode($crm),
    ]);
    $cmd = [$cgi, '-d', 'auto_prepend_file=' . __DIR__ . '/fakes.php', '-d', 'display_errors=stderr'];
    if (php_ini_loaded_file()) array_splice($cmd, 1, 0, ['-c', php_ini_loaded_file()]);
    $p = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $dir, $env);
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);

    [$head, $resBody] = array_pad(explode("\r\n\r\n", (string) $out, 2), 2, '');
    $status = preg_match('/^Status: (\d+)/mi', $head, $m) ? (int) $m[1] : 200;
    $calls = [];
    foreach (is_file("$dir/calls.jsonl") ? file("$dir/calls.jsonl") : [] as $line) {
        $c = json_decode($line, true);
        $calls[$c['url']][] = $c;
    }
    if (getenv('SHOW_LOG') && isset($calls[LOG_URL])) {
        echo json_encode($calls[LOG_URL][0]['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
    $decoded = json_decode($resBody, true);
    ok(is_array($decoded), "no JSON response: $out $err");
    return [$status, $decoded, $calls, $err];
}

function lead(array $extra = []): array
{
    return $extra + [
        'firstName' => 'José', 'lastName' => 'García López', 'email' => 'jose@gmail.com',
        'phone' => '612 34 56 78', 'over30' => true, 'experience' => 'beginner',
        'goal' => 'extra_income', 'deposit' => 'yes', 'consent' => true,
        'utm' => ['utm_source' => 'fb'], 'clickId' => 'abc123', 'fillMs' => 20000,
        'captchaToken' => 'XXXX.DUMMY.TOKEN.XXXX',
    ];
}

$accepted = ['status' => 201, 'body' => ['success' => true, 'data' => ['lead_id' => 7]]];

test('CRM accepts → lead copied to the leads log with the right headers and body', function () use ($accepted) {
    [$status, $res, $calls] = submit(lead(['timing' => sample_timing()]), $accepted);
    eq($status, 200);
    eq($res['success'], true);
    eq(count($calls[CRM_URL] ?? []), 1, 'CRM calls');
    eq(count($calls[LOG_URL] ?? []), 1, 'log calls');
    $crm = $calls[CRM_URL][0]['payload'];
    $log = $calls[LOG_URL][0];
    eq($log['headers'], ['Authorization: Bearer log-key']); // post_json adds Content-Type/Accept: application/json
    eq($log['timeout'], 10);
    eq($log['payload']['email'], 'jose@gmail.com');
    eq($log['payload']['offer_name'], 'spanish-bullion');
    eq($log['payload']['ip_address'], $crm['ip_address']);
    eq(array_diff_key($log['payload']['data'], ['timing' => 1]), $crm, 'data = exact CRM payload');
    ok(!in_array('log-key', $calls[CRM_URL][0]['headers'], true), 'log key not sent to the CRM');
});

test('timing goes to the log, not to the CRM', function () use ($accepted) {
    [, , $calls] = submit(lead(['timing' => sample_timing()]), $accepted);
    ok(!array_key_exists('timing', $calls[CRM_URL][0]['payload']), 'CRM payload has no timing');
    $timing = $calls[LOG_URL][0]['payload']['data']['timing'];
    ok(str_contains($timing['summary'], 'submitted 20.0s later'), 'summary');
    eq($timing['fields']['Móvil'], '4.8s in the field, 14 changes, typed');
    eq(end($timing['steps']), '20.0s - Clicked "Quiero empezar ahora" (sent)');
});

test('malformed timing is dropped, the lead still goes through', function () use ($accepted) {
    [$status, , $calls] = submit(lead(['timing' => ['submit_click_ms' => -5]]), $accepted);
    eq($status, 200);
    ok(!array_key_exists('timing', $calls[LOG_URL][0]['payload']['data']), 'no timing in log');
    [$status] = submit(lead(['timing' => 'garbage']), $accepted);
    eq($status, 200);
});

test('no log when the CRM rejects the lead', function () {
    [$status, , $calls] = submit(lead(), ['status' => 422, 'body' => ['success' => false, 'errors' => ['email' => ['bad']]]]);
    eq($status, 422);
    ok(!isset($calls[LOG_URL]), 'log not called');
    [$status, , $calls] = submit(lead(), ['status' => 200, 'body' => ['success' => false]]);
    eq($status, 422);
    ok(!isset($calls[LOG_URL]), 'log not called (success=false)');
    [$status, , $calls] = submit(lead(), ['status' => 500, 'body' => null]);
    eq($status, 503);
    ok(!isset($calls[LOG_URL]), 'log not called (500)');
});

test('no log on a duplicate (409)', function () {
    [$status, , $calls] = submit(lead(), ['status' => 409, 'body' => ['success' => false]]);
    eq($status, 409);
    ok(!isset($calls[LOG_URL]), 'log not called');
});

test('no log on a CRM network error or timeout', function () {
    [$status, , $calls] = submit(lead(), ['status' => 0, 'body' => null]);
    eq($status, 503);
    ok(isset($calls[CRM_URL]), 'CRM was tried');
    ok(!isset($calls[LOG_URL]), 'log not called');
});

test('no log without LEADS_LOG_API_KEY; the lead still goes through', function () use ($accepted) {
    [$status, , $calls] = submit(lead(), $accepted, false);
    eq($status, 200);
    ok(!isset($calls[LOG_URL]), 'log not called');
});
