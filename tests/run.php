<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';
require __DIR__ . '/TestCase.php';
foreach (glob(__DIR__ . '/Support/*.php') ?: [] as $support) {
    require_once $support;
}

/** @return list<class-string> */
function loadTestClasses(string $file): array
{
    $before = get_declared_classes();
    require $file;
    return array_values(array_filter(
        array_diff(get_declared_classes(), $before),
        static fn (string $class): bool => is_subclass_of($class, Tests\TestCase::class),
    ));
}

$options = getopt('', ['suite::', 'filter::', 'coverage::']);
$selectedSuite = strtolower((string) ($options['suite'] ?? 'all'));
$filter = (string) ($options['filter'] ?? '');
$coveragePath = isset($options['coverage']) ? (string) $options['coverage'] : '';
$suites = [
    'unit' => __DIR__ . '/Unit',
    'integration' => __DIR__ . '/Integration',
    'e2e' => __DIR__ . '/E2E',
    'feature' => __DIR__ . '/Feature',
    'architecture' => __DIR__ . '/Architecture',
];
if ($selectedSuite !== 'all' && !isset($suites[$selectedSuite])) {
    fwrite(STDERR, "Unknown suite. Choose unit, integration, e2e, feature, architecture, or all.\n");
    exit(2);
}
if (($dsn = getenv('SEO_TRACKER_TEST_DSN')) !== false && !str_starts_with($dsn, 'sqlite:')) {
    fwrite(STDERR, "Refusing non-SQLite SEO_TRACKER_TEST_DSN; tests must not access production data.\n");
    exit(2);
}
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

if ($coveragePath !== '') {
    if (!function_exists('xdebug_start_code_coverage')) {
        fwrite(STDERR, "Coverage requested but Xdebug coverage support is unavailable.\n");
        exit(2);
    }
    xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
}

$failures = 0;
$tests = 0;
$suiteTotals = [];
$started = microtime(true);
foreach ($suites as $suite => $directory) {
    if ($selectedSuite !== 'all' && $selectedSuite !== $suite) continue;
    $files = glob($directory . '/*Test.php') ?: [];
    sort($files, SORT_STRING);
    $suiteTotals[$suite] = ['tests' => 0, 'failures' => 0];
    foreach ($files as $file) {
        foreach (loadTestClasses($file) as $class) {
            $instance = new $class();
            foreach (get_class_methods($instance) as $method) {
                if (!str_starts_with($method, 'test')) continue;
                $name = $class . '::' . $method;
                if ($filter !== '' && stripos($name, $filter) === false) continue;
                $tests++;
                $suiteTotals[$suite]['tests']++;
                try {
                    $instance->$method();
                    echo "PASS {$name}\n";
                } catch (Throwable $exception) {
                    $failures++;
                    $suiteTotals[$suite]['failures']++;
                    fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
                }
            }
        }
    }
}

if ($tests === 0) {
    fwrite(STDERR, "No tests matched the selected suite/filter.\n");
    exit(2);
}
foreach ($suiteTotals as $suite => $totals) {
    printf("SUITE %s: %d tests, %d failures\n", $suite, $totals['tests'], $totals['failures']);
}
printf("TOTAL: %d tests, %d failures, %.2fs\n", $tests, $failures, microtime(true) - $started);

if ($coveragePath !== '') {
    $coverage = xdebug_get_code_coverage();
    $covered = $executable = 0;
    foreach ($coverage as $file => $lines) {
        if (!str_starts_with($file, dirname(__DIR__) . '/app/')) continue;
        foreach ($lines as $status) {
            if ($status === 1) $covered++;
            if ($status === 1 || $status === -1) $executable++;
        }
    }
    $percent = $executable > 0 ? ($covered / $executable) * 100 : 0.0;
    $report = sprintf("Application line coverage: %d/%d (%.2f%%)\n", $covered, $executable, $percent);
    if (file_put_contents($coveragePath, $report) === false) {
        fwrite(STDERR, "Unable to write coverage report.\n");
        exit(2);
    }
    echo $report;
}

exit($failures === 0 ? 0 : 1);
