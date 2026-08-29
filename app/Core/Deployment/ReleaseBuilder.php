<?php

declare(strict_types=1);

namespace App\Core\Deployment;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ReleaseBuilder
{
    public function __construct(private readonly string $basePath) {}

    /** @return array{archive:string,checksum:string,files:int} */
    public function build(string $output): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('The build host requires ext-zip.');
        $base = realpath($this->basePath);
        if (!is_string($base)) throw new RuntimeException('Release source path is unavailable.');
        $output = $this->absoluteOutput($output);
        $directory = dirname($output);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new RuntimeException('Unable to create release output directory.');
        if (is_file($output) && !unlink($output)) throw new RuntimeException('Unable to replace release archive.');

        $zip = new ZipArchive();
        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Unable to create release archive.');
        $files = 0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                $path = $item->getPathname();
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base) + 1));
                if ($this->excluded($relative, $path, $output)) continue;
                if ($item->isLink()) throw new RuntimeException('Release source must not contain symlinks: ' . $relative);
                if ($item->isDir()) {
                    $zip->addEmptyDir($relative);
                    continue;
                }
                if (!$item->isFile() || !$zip->addFile($path, $relative)) throw new RuntimeException('Unable to add release file: ' . $relative);
                $mode = fileperms($path);
                if (is_int($mode)) $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, ($mode & 0777) << 16);
                $files++;
            }
        } finally {
            $zip->close();
        }
        if ($files < 1 || !is_file($output)) throw new RuntimeException('Release archive is empty.');
        $hash = hash_file('sha256', $output);
        if (!is_string($hash) || file_put_contents($output . '.sha256', $hash . '  ' . basename($output) . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('Unable to write release checksum.');
        @chmod($output, 0640);
        @chmod($output . '.sha256', 0640);
        return ['archive' => $output, 'checksum' => $hash, 'files' => $files];
    }

    private function absoluteOutput(string $output): string
    {
        if ($output === '' || str_contains($output, "\0") || !str_ends_with(strtolower($output), '.zip')) throw new RuntimeException('Release output must be a ZIP path.');
        return str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : $this->basePath . '/' . $output;
    }

    private function excluded(string $relative, string $path, string $output): bool
    {
        if ($path === $output || $path === $output . '.sha256') return true;
        $first = explode('/', $relative, 2)[0];
        // Runtime archives intentionally omit development-only material. Besides
        // reducing attack surface and upload size, this keeps test fixtures and
        // their non-production credentials out of hosted releases.
        if (in_array($first, ['.git', '.github', '.idea', '.vscode', 'dist', 'tests'], true)) return true;
        if ((str_starts_with($relative, '.env') && $relative !== '.env.example') || $relative === '.phpunit.result.cache') return true;
        if ((str_starts_with($relative, 'storage/') || str_starts_with($relative, 'bootstrap/cache/')) && !str_ends_with($relative, '/.gitignore')) return true;
        if (preg_match('/\.(?:log|sql|sqlite|bak|zip|tar|tgz|gz)$/i', $relative)) return true;
        return false;
    }
}
