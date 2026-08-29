<?php

declare(strict_types=1);

namespace App\Modules\Websites\Infrastructure;

use App\Core\Auth\AuthFactory;
use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Modules\Websites\Application\WebsiteManager;

final class WebsiteFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}

    public function services(): array
    {
        $database = (new ConnectionFactory($this->config))->connect();
        return [
            new WebsiteManager($database, new Authorization($database), new AuditRecorder($database)),
            (new AuthFactory($this->basePath, $this->config))->make(),
        ];
    }
}
