<?php

declare(strict_types=1);

namespace App\Modules\RankTracking\Infrastructure;

use App\Core\Auth\AuthFactory;
use App\Core\Config\Config;
use App\Core\Database\ConnectionFactory;
use App\Core\Logging\Logger;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Modules\RankTracking\Application\RankCheckManager;
use App\Modules\RankTracking\Application\RankWorker;

final class RankTrackingFactory
{
    public function __construct(private readonly string $basePath, private readonly Config $config, private readonly ?RankAdapterRegistry $registry = null) {}

    public function services(): array
    {
        $database = (new ConnectionFactory($this->config))->connect();
        $registry = $this->registry ?? new RankAdapterRegistry();
        return [
            new RankCheckManager($database, new Authorization($database), new AuditRecorder($database), $registry, (string) $this->config->get('rank_tracking.adapter', ''), (int) $this->config->get('rank_tracking.rate_limit', 10), (int) $this->config->get('rank_tracking.rate_window', 900)),
            (new AuthFactory($this->basePath, $this->config))->make(),
        ];
    }

    public function worker(): RankWorker
    {
        $path = (string) $this->config->get('logging.path', 'storage/logs/application.log');
        if (!str_starts_with($path, '/')) $path = $this->basePath . '/' . $path;
        return new RankWorker((new ConnectionFactory($this->config))->connect(), $this->registry ?? new RankAdapterRegistry(), new Logger($path, (string) $this->config->get('logging.level', 'info')), (int) $this->config->get('rank_tracking.max_attempts', 3), (int) $this->config->get('rank_tracking.lease_seconds', 120));
    }
}
