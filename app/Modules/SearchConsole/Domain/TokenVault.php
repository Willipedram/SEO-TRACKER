<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Domain;

interface TokenVault
{
    public function seal(array $tokens): string;
    public function open(string $envelope): array;
    public function keyVersion(): string;
}
