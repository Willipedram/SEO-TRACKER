<?php

declare(strict_types=1);

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class ModuleBoundaryTest extends TestCase
{
    public function testCoreDoesNotReferenceBusinessModules(): void
    {
        $root = dirname(__DIR__, 2) . '/app/Core';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->assertTrue(!str_contains((string) file_get_contents($file->getPathname()), 'App\\Modules\\'), 'Core references a module: ' . $file->getPathname());
            }
        }
    }
}
