<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Auth\SessionStore;

final class ArraySessionStore implements SessionStore
{
    public array $values = [];
    public int $regenerations = 0;
    public bool $invalidated = false;

    public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function remove(string $key): void { unset($this->values[$key]); }
    public function regenerate(): void { $this->regenerations++; }
    public function invalidate(): void { $this->values = []; $this->invalidated = true; }
}
