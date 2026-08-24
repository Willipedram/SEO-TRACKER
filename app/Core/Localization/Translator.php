<?php

declare(strict_types=1);

namespace App\Core\Localization;

final class Translator
{
    private array $messages;

    public function __construct(private readonly string $locale, string $basePath, string $catalog = 'rank_dashboard')
    {
        if (!preg_match('/^[a-z_]+$/', $catalog)) $catalog = 'rank_dashboard';
        $fallback = $basePath . '/lang/en/' . $catalog . '.php';
        $safeLocale = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) ? $locale : 'en';
        $localized = $basePath . '/lang/' . $safeLocale . '/' . $catalog . '.php';
        if (!is_file($localized) && str_contains($safeLocale, '-')) $localized = $basePath . '/lang/' . explode('-', $safeLocale, 2)[0] . '/' . $catalog . '.php';
        $this->messages = is_file($fallback) ? require $fallback : [];
        if ($localized !== $fallback && is_file($localized)) $this->messages = array_replace($this->messages, require $localized);
    }

    public function get(string $key, array $replace = []): string
    {
        $message = (string) ($this->messages[$key] ?? $key);
        foreach ($replace as $name => $value) $message = str_replace(':' . $name, (string) $value, $message);
        return $message;
    }

    public function locale(): string { return $this->locale; }
}
