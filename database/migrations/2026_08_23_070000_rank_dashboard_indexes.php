<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_070000_rank_dashboard_indexes',
    schemaVersion: 8,
    transactional: false,
    operation: static function (Database $database): void {
        if ($database->driver() === 'mysql') {
            if ($database->fetchOne("SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'rank_results' AND index_name = :name", ['name' => 'rank_results_website_device_time']) === null) {
                $database->execute('CREATE INDEX rank_results_website_device_time ON rank_results (website_id, requested_device, observed_at)');
            }
            if ($database->fetchOne("SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'rank_results' AND index_name = :name", ['name' => 'rank_results_keyword_device_time']) === null) {
                $database->execute('CREATE INDEX rank_results_keyword_device_time ON rank_results (keyword_id, requested_device, observed_at)');
            }
            return;
        }
        $database->execute('CREATE INDEX IF NOT EXISTS rank_results_website_device_time ON rank_results (website_id, requested_device, observed_at)');
        $database->execute('CREATE INDEX IF NOT EXISTS rank_results_keyword_device_time ON rank_results (keyword_id, requested_device, observed_at)');
    },
);
