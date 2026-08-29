<?php

declare(strict_types=1);

namespace App\Modules\Keywords\Infrastructure;

use App\Core\Auth\AuthFactory;
use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Modules\Keywords\Application\KeywordManager;

final class KeywordFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}

    public function services(): array
    {
        $database = (new ConnectionFactory($this->config))->connect();
        return [
            new KeywordManager($database, new Authorization($database), new AuditRecorder($database)),
            (new AuthFactory($this->basePath, $this->config))->make(),
            (array) $this->config->get('keywords.search_engines', []),
            (array) $this->config->get('keywords.devices', []),
            (array) $this->config->get('keywords.countries', []),
        ];
    }
}
