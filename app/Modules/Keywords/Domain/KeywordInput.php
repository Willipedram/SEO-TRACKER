<?php

declare(strict_types=1);

namespace App\Modules\Keywords\Domain;

use InvalidArgumentException;

final class KeywordInput
{
    public function __construct(
        public readonly string $text,
        public readonly string $normalizedText,
        public readonly ?string $targetUrl,
        public readonly string $searchEngine,
        public readonly string $country,
        public readonly string $language,
        public readonly string $device,
        public readonly bool $active,
    ) {}

    public static function from(array $values, array $engines, array $devices, array $countries): self
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) ($values['keyword'] ?? '')));
        if (!is_string($text) || $text === '' || strlen($text) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $text)) {
            throw new InvalidArgumentException('Keyword is required, must be 255 characters or fewer, and cannot contain control characters.');
        }
        $normalized = mb_strtolower($text, 'UTF-8');
        $engine = strtolower(trim((string) ($values['search_engine'] ?? '')));
        $device = strtolower(trim((string) ($values['device'] ?? '')));
        if (!in_array($engine, $engines, true) || !preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $engine)) {
            throw new InvalidArgumentException('Select a supported search engine.');
        }
        if (!in_array($device, $devices, true) || !preg_match('/^[a-z][a-z0-9_-]{1,29}$/', $device)) {
            throw new InvalidArgumentException('Select a supported device.');
        }
        $country = strtoupper(trim((string) ($values['country'] ?? '')));
        if (!in_array($country, $countries, true)) throw new InvalidArgumentException('Select a supported ISO 3166-1 alpha-2 country.');
        $language = strtolower(trim((string) ($values['language'] ?? '')));
        if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language) || strlen($language) > 35) throw new InvalidArgumentException('Language must be a valid BCP 47-style language tag.');
        $target = trim((string) ($values['target_url'] ?? ''));
        if ($target === '') {
            $target = null;
        } elseif (strlen($target) > 2048 || filter_var($target, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Target URL must be a valid HTTP or HTTPS URL.');
        } else {
            $parts = parse_url($target);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                throw new InvalidArgumentException('Target URL must be HTTP or HTTPS and cannot contain credentials or a fragment.');
            }
        }
        $activeValue = $values['active'] ?? false;
        $active = filter_var($activeValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($active === null) throw new InvalidArgumentException('Keyword status is invalid.');
        return new self($text, $normalized, $target, $engine, $country, $language, $device, $active);
    }
}
