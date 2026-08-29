<?php

declare(strict_types=1);

namespace App\Modules\Reports\Infrastructure;

use App\Core\Auth\AuthFactory;
use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Localization\Translator;
use App\Core\Rbac\Authorization;
use App\Modules\Reports\Application\ReportService;

final class ReportsFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config) {}
    public function services(): array { $database = (new ConnectionFactory($this->config))->connect(); return [new ReportService($database, new Authorization($database)), (new AuthFactory($this->basePath, $this->config))->make()]; }
    public function translator(): Translator { return new Translator((string) $this->config->get('app.locale', 'fa'), $this->basePath, 'reports'); }
    public function isRtl(): bool { $locale = explode('-', (string) $this->config->get('app.locale', 'fa'), 2)[0]; return in_array($locale, (array) $this->config->get('app.rtl_locales', []), true); }
}
