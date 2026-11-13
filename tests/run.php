<?php
/**
 * PHP tests (no Composer needed): php tests/run.php
 * Runs every tests/*.test.php. Needs php-cgi next to php (it ships with PHP on Windows;
 * `apt install php-cgi` on Debian/Ubuntu) for the submit-lead tests.
 */

declare(strict_types=1);

$tests = [];
$failed = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][] = [$name, $fn];
}

final class AssertionFailed extends Exception {}

function eq(mixed $actual, mixed $expected, string $what = ''): void
{
    if ($actual !== $expected) {
        throw new AssertionFailed(($what ? "$what: " : '') . 'expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function ok(bool $cond, string $what): void
{
    if (!$cond) throw new AssertionFailed($what);
}

foreach (glob(__DIR__ . '/*.test.php') as $file) {
    $tests = [];
    require $file;
    echo basename($file), "\n";
    foreach ($tests as [$name, $fn]) {
        try {
            $fn();
            echo "  ok   $name\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAIL $name\n       ", $e->getMessage(), "\n";
        }
    }
}

echo $failed ? "\n$failed failed\n" : "\nall passed\n";
exit($failed ? 1 : 0);
