<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';
require __DIR__ . '/TestCase.php';
foreach (glob(__DIR__ . '/Support/*.php') ?: [] as $support) {
    require_once $support;
}

$files = array_merge(glob(__DIR__ . '/Unit/*Test.php') ?: [], glob(__DIR__ . '/Feature/*Test.php') ?: [], glob(__DIR__ . '/Architecture/*Test.php') ?: []);
$failures = 0;
$tests = 0;
foreach ($files as $file) {
    $before = get_declared_classes();
    require $file;
    $classes = array_diff(get_declared_classes(), $before);
    foreach ($classes as $class) {
        if (!is_subclass_of($class, Tests\TestCase::class)) {
            continue;
        }
        $instance = new $class();
        foreach (get_class_methods($instance) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }
            $tests++;
            try {
                $instance->$method();
                echo "PASS {$class}::{$method}\n";
            } catch (Throwable $exception) {
                $failures++;
                fwrite(STDERR, "FAIL {$class}::{$method}: {$exception->getMessage()}\n");
            }
        }
    }
}
printf("%d tests, %d failures\n", $tests, $failures);
exit($failures === 0 ? 0 : 1);
