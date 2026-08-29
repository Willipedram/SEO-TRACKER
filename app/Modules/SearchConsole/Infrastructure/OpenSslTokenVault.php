<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Infrastructure;

use App\Modules\SearchConsole\Domain\SearchConsoleUnavailable;
use App\Modules\SearchConsole\Domain\TokenVault;
use JsonException;

final class OpenSslTokenVault implements TokenVault
{
    private readonly string $key;

    public function __construct(string $encodedKey, private readonly string $version = 'v1')
    {
        $key = base64_decode($encodedKey, true);
        if (!is_string($key) || strlen($key) !== 32 || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) throw new SearchConsoleUnavailable('Search Console token encryption is not configured.');
        $this->key = $key;
    }

    public function seal(array $tokens): string
    {
        $nonce = random_bytes(12); $tag = '';
        $plaintext = json_encode($tokens, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, 'seo-tracker:search-console', 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) throw new SearchConsoleUnavailable('Token encryption failed.');
        return base64_encode($nonce . $tag . $ciphertext);
    }

    public function open(string $envelope): array
    {
        $decoded = base64_decode($envelope, true);
        if (!is_string($decoded) || strlen($decoded) <= 28) throw new SearchConsoleUnavailable('Stored authorization is unavailable.');
        $plaintext = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($decoded, 0, 12), substr($decoded, 12, 16), 'seo-tracker:search-console');
        if (!is_string($plaintext)) throw new SearchConsoleUnavailable('Stored authorization is unavailable.');
        try { $tokens = json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new SearchConsoleUnavailable('Stored authorization is unavailable.'); }
        if (!is_array($tokens)) throw new SearchConsoleUnavailable('Stored authorization is unavailable.');
        return $tokens;
    }

    public function keyVersion(): string { return $this->version; }
}
