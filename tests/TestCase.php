<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;

abstract class TestCase
{
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : sprintf('Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Assertion failed.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
