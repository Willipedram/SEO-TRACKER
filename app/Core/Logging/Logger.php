<?php

declare(strict_types=1);

namespace App\Core\Logging;

use RuntimeException;

final class Logger
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400, 'critical' => 500];
    private const REDACTED_KEYS = ['password', 'secret', 'token', 'authorization', 'cookie', 'app_key'];

    public function __construct(private readonly string $path, private readonly string $minimumLevel = 'info')
    {
        if (!isset(self::LEVELS[$minimumLevel])) {
            throw new RuntimeException('Invalid log level.');
        }
    }

    public function debug(string $message, array $context = []): void { $this->log('debug', $message, $context); }
    public function info(string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function error(string $message, array $context = []): void { $this->log('error', $message, $context); }
    public function critical(string $message, array $context = []): void { $this->log('critical', $message, $context); }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!isset(self::LEVELS[$level]) || self::LEVELS[$level] < self::LEVELS[$this->minimumLevel]) {
            return;
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            error_log(sprintf('Logger unavailable: cannot create %s', $directory));
            return;
        }
        $record = ['timestamp' => gmdate('c'), 'level' => strtoupper($level), 'message' => self::redactString($message), 'context' => $this->redact($context)];
        if (@file_put_contents($this->path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('Logger unavailable: application log is not writable.');
        }
    }

    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }
        return $this->redactStrings($context);
    }

    private function redactStrings(array $context): array
    {
        array_walk_recursive($context, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::redactString($value);
            }
        });
        return $context;
    }

    private static function redactString(string $value): string
    {
        return preg_replace('/(?i)(password|secret|token|authorization|cookie|app[_-]?key)(\s*[=:]\s*)([^\s,;]+)/', '$1$2[REDACTED]', $value) ?? $value;
    }
}
