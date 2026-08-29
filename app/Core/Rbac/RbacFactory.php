<?php

declare(strict_types=1);

namespace App\Core\Rbac;

use App\Core\Auth\AuthFactory;
use App\Core\Auth\PasswordHasher;
use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;

final class RbacFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}

    public function services(): array
    {
        $database = (new ConnectionFactory($this->config))->connect();
        $authorization = new Authorization($database);
        $audit = new AuditRecorder($database);
        return [
            new UserManager($database, $authorization, $audit, new PasswordHasher()),
            new RoleManager($database, $authorization, $audit),
            $authorization,
            (new AuthFactory($this->basePath, $this->config))->make(),
        ];
    }
}
