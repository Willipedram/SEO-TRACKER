<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Infrastructure;

use App\Modules\RankTracking\Domain\RankAdapter;
use InvalidArgumentException;

final class RankAdapterRegistry
{
    private array $adapters = [];

    public function __construct(array $adapters = [])
    {
        foreach ($adapters as $adapter) {
            if (!$adapter instanceof RankAdapter || isset($this->adapters[$adapter->key()]) || !preg_match('/^[a-z][a-z0-9_-]{1,49}$/', $adapter->key()) || !preg_match('/^\d+\.\d+\.\d+$/', $adapter->version()) || !in_array($adapter->executionSource(), ['provider_api', 'local_agent', 'browser_extension', 'server_adapter'], true)) {
                throw new InvalidArgumentException('Invalid or duplicate rank adapter.');
            }
            $this->adapters[$adapter->key()] = $adapter;
        }
    }

    public function get(string $key): ?RankAdapter { return $this->adapters[$key] ?? null; }
}
