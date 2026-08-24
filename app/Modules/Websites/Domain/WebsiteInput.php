<?php

declare(strict_types=1);

namespace App\Modules\Websites\Domain;

use InvalidArgumentException;

final readonly class WebsiteInput
{
    public function __construct(
        public string $name,
        public string $domain,
        public string $url,
        public string $protocol,
        public string $description,
    ) {}

    public static function from(string $name, string $url, string $description): self
    {
        $name = trim($name);
        $url = trim($url);
        $description = trim($description);
        if ($name === '' || strlen($name) > 150) {
            throw new InvalidArgumentException('Site name is required and must be 150 characters or fewer.');
        }
        if (strlen($description) > 5000 || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Enter a valid website URL and a description of 5000 characters or fewer.');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $forbiddenComponent = isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port']);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $forbiddenComponent) {
            throw new InvalidArgumentException('URL must be an HTTP or HTTPS origin without credentials, port, query, or fragment.');
        }
        if (!str_contains($host, '.') || !preg_match('/^[\x20-\x7e]+$/', $host) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Enter a valid ASCII domain. Convert international domains to Punycode first.');
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            throw new InvalidArgumentException('Website URL must identify an origin and cannot contain a path.');
        }
        return new self($name, $host, $scheme . '://' . $host, $scheme, $description);
    }
}
