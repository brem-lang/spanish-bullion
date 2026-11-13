<?php
// Timing validation, the readable report and send_lead_log() (api/lib/lead-log.php).

declare(strict_types=1);

require_once __DIR__ . '/../api/lib/lead-log.php';

require_once __DIR__ . '/fixtures.php';

test('report: summary, local times, steps and one sentence per field', function () {
    $r = timing_report(sanitize_timing(sample_timing()));
    if (getenv('SHOW_REPORT')) echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    eq($r['summary'], 'Landed 2026-11-12 13:34:20 (Europe/Madrid, UTC+01:00), submitted 20.0s later, spent 19.2s on the form, 1 submit click.');
    eq($r['landed_at'], '2026-11-12 13:34:20 (Europe/Madrid, UTC+01:00)');
    eq($r['submitted_at'], '2026-11-12 13:34:40 (Europe/Madrid, UTC+01:00)');
    eq($r['time_before_submit'], '20.0s');
    eq($r['time_to_fill_form'], '19.2s');
    eq($r['fields']['Móvil'], '4.8s in the field, 14 changes, typed');
    eq($r['fields']['Nombre'], '1.0s in the field, 1 change, filled in one go (autofill or paste)');
    eq($r['fields']['"30 años o más" checkbox'], 'ticked at 7.9s');
    eq($r['fields']['Q2 Objetivo'], 'answered, changed answer 2 times, last at 15.4s');
    eq($r['fields']['Q3 Depósito 250 $'], 'answered at 16.2s');
    eq($r['fields']['Consent (RGPD) checkbox'], 'not touched');
    eq($r['steps'][0], '0.0s - Opened the page');
    eq($r['steps'][1], '0.8s - Form loaded');
    eq($r['steps'][2], '1.1s - Started Nombre');
    ok(in_array('10.7s - Clicked "Continuar"', $r['steps'], true), 'continue step');
    eq(end($r['steps']), '20.0s - Clicked "Quiero empezar ahora" (sent)');
    $ms = array_map(fn($s) => (float) $s, $r['steps']);
    $sorted = $ms;
    sort($sorted);
    eq($ms, $sorted, 'steps sorted by time');
});

test('report: repeated clicks, minutes, negative offset, no time zone', function () {
    $t = sample_timing();
    $t['utc_offset_min'] = -180;
    unset($t['timezone']);
    $t['continue_click_ms'] = 30_000;
    $t['first_submit_click_ms'] = 60_000;
    $t['submit_click_ms'] = 125_000;
    $t['submit_clicks'] = 3;
    $t['fields']['consent'] = ['first_input_ms' => 50_000, 'last_input_ms' => 59_600, 'input_count' => 2];
    $r = timing_report(sanitize_timing($t));
    eq($r['landed_at'], '2026-11-12 09:34:20 (UTC-03:00)');
    eq($r['time_before_submit'], '2m 05s');
    eq($r['fields']['Consent (RGPD) checkbox'], 'clicked 2 times, last at 59.6s');
    ok(in_array('30.0s - Clicked "Continuar" again (last time)', $r['steps'], true), 'second continue');
    ok(in_array('1m 00s - Clicked "Quiero empezar ahora" (first try)', $r['steps'], true), 'first submit');
    eq(end($r['steps']), '2m 05s - Clicked "Quiero empezar ahora" (try 3, sent)');
    ok(str_ends_with($r['summary'], '3 submit clicks.'), 'summary clicks');
});

test('durations', function () {
    eq(timing_duration(0), '0.0s');
    eq(timing_duration(1234), '1.2s');
    eq(timing_duration(59_999), '60.0s');
    eq(timing_duration(65_000), '1m 05s');
    eq(timing_duration(119_600), '2m 00s');
});

test('sanitize: keeps valid timing, drops unknown keys and fields', function () {
    $t = sample_timing();
    $t['extra'] = 'x';
    $t['fields']['website'] = ['input_count' => 1];
    $t['fields']['email']['junk'] = 1;
    $s = sanitize_timing($t);
    ok($s !== null, 'valid');
    ok(!isset($s['extra']) && !isset($s['fields']['website']) && !isset($s['fields']['email']['junk']), 'stripped');
    eq(sanitize_timing(['landed_at' => 'a', 'submitted_at' => 'b', 'submit_click_ms' => 5, 'fields' => []])['fields'], []);
});

test('sanitize: malformed timing → null', function () {
    $bad = [
        'not an object'       => 'x',
        'missing submit'      => array_diff_key(sample_timing(), ['submit_click_ms' => 1]),
        'negative ms'         => ['submit_click_ms' => -5] + sample_timing(),
        'float ms'            => ['submit_click_ms' => 1.5] + sample_timing(),
        'too large'           => ['form_fill_ms' => 8 * 86_400_000] + sample_timing(),
        'bad offset'          => ['utc_offset_min' => 2000] + sample_timing(),
        'long time zone'      => ['timezone' => str_repeat('x', 65)] + sample_timing(),
        'fields not object'   => ['fields' => [1, 2]] + sample_timing(),
        'bad field value'     => ['fields' => ['email' => ['input_count' => 'many']]] + sample_timing(),
        'null value'          => ['timezone' => null] + sample_timing(),
    ];
    foreach ($bad as $what => $t) eq(sanitize_timing($t), null, $what);
    eq(sanitize_timing(null), null, 'absent');
});

test('field lists match form-timing.js', function () {
    preg_match('/const TIMED_FIELDS = \[([^\]]*)\]/', (string) file_get_contents(__DIR__ . '/../form-timing.js'), $m);
    preg_match_all('/"(\w+)"/', $m[1] ?? '', $names);
    eq($names[1], array_keys(TIMED_FIELDS));
});

test('send_lead_log: posts email, offer, IP and the payload with the report', function () {
    $calls = [];
    $post = function (...$args) use (&$calls) { $calls[] = $args; return [201, ['success' => true]]; };
    $payload = ['firstname' => 'José', 'email' => 'jose@gmail.com', 'offer_name' => 'spanish-bullion', 'ip_address' => '203.0.113.7'];
    send_lead_log($payload, sanitize_timing(sample_timing()), ['endpoint' => 'https://log.example/api/leads-logs', 'api_key' => 'log-key'], $post);
    eq(count($calls), 1);
    [$url, $body, $headers, $timeout] = $calls[0];
    eq($url, 'https://log.example/api/leads-logs');
    eq($headers, ['Authorization: Bearer log-key']);
    eq($timeout, 10);
    eq([$body['email'], $body['offer_name'], $body['ip_address']], ['jose@gmail.com', 'spanish-bullion', '203.0.113.7']);
    eq(array_diff_key($body['data'], ['timing' => 1]), $payload);
    ok(str_starts_with($body['data']['timing']['summary'], 'Landed 2026-11-12 13:34:20'), 'timing report');
});

test('send_lead_log: skipped without a key; never throws', function () {
    $called = false;
    send_lead_log(['email' => 'a@b.es'], null, lead_log_config([]), function () use (&$called) { $called = true; return [200, null]; });
    ok(!$called, 'no key → not sent');
    eq(lead_log_config([])['endpoint'], 'https://alphamastercrm.online/api/leads-logs');
    eq(lead_log_config(['LEADS_LOG_ENDPOINT' => 'https://x.example/l', 'LEADS_LOG_API_KEY' => 'k']), ['endpoint' => 'https://x.example/l', 'api_key' => 'k']);

    $log = ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'log'));
    send_lead_log(['email' => 'a@b.es'], null, ['endpoint' => 'https://l', 'api_key' => 'k'], function () { throw new RuntimeException('boom'); });
    send_lead_log(['email' => 'a@b.es'], null, ['endpoint' => 'https://l', 'api_key' => 'k'], fn() => [0, null]);
    send_lead_log(['email' => 'a@b.es'], null, ['endpoint' => 'https://l', 'api_key' => 'k'], fn() => [401, null]);
    $written = (string) file_get_contents(ini_get('error_log'));
    ini_set('error_log', (string) $log);
    ok(str_contains($written, 'lead log: RuntimeException'), 'exception logged');
    ok(str_contains($written, 'lead log: request failed'), 'network error logged');
    ok(str_contains($written, 'lead log: rejected status=401'), 'rejection logged');
});
