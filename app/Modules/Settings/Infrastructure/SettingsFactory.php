<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure;

use App\Core\Auth\AuthFactory;
use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Localization\Translator;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Core\Settings\ModuleManager;
use App\Core\Settings\SettingsManager;

final class SettingsFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}
    public function services(): array { $database = (new ConnectionFactory($this->config))->connect(); $authz = new Authorization($database); $audit = new AuditRecorder($database); return [new SettingsManager($database, $authz, $audit), new ModuleManager($database, $authz, $audit, $this->basePath . '/app/Modules'), (new AuthFactory($this->basePath, $this->config))->make()]; }
    public function translator(): Translator { return new Translator((string) $this->config->get('app.locale', 'en'), $this->basePath, 'settings'); }
    public function isRtl(): bool { return in_array(explode('-', (string) $this->config->get('app.locale', 'en'), 2)[0], (array) $this->config->get('app.rtl_locales', []), true); }
}
