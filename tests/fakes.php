<?php
// Loaded before api/submit-lead.php in the tests (php-cgi -d auto_prepend_file=...).
// Replaces the script's network calls: records every POST to $TEST_CALLS and answers
// as Cloudflare (always passes), the CRM ($TEST_CRM = {"status":..,"body":..}) or the leads log.

declare(strict_types=1);

function post_json(string $url, array $payload, array $headers, int $timeout): array
{
    file_put_contents((string) getenv('TEST_CALLS'), json_encode(
        ['url' => $url, 'payload' => $payload, 'headers' => $headers, 'timeout' => $timeout],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) . "\n", FILE_APPEND);

    if (str_starts_with($url, 'https://challenges.cloudflare.com/')) {
        return [200, ['success' => true, 'hostname' => 'localhost']];
    }
    if (str_starts_with($url, 'https://crm.example/')) {
        $crm = json_decode((string) getenv('TEST_CRM'), true);
        return [$crm['status'], $crm['body'] ?? null];
    }
    return [201, ['success' => true]]; // leads log
}

function domain_receives_mail(string $email): bool
{
    return true;
}
