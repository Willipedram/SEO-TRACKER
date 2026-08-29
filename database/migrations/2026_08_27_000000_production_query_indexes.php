<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_27_000000_production_query_indexes',
    schemaVersion: 14,
    transactional: false,
    operation: static function (Database $database): void {
        // These indexes mirror production query predicates that were not covered by
        // the earlier device-first dashboard indexes: website/date report scans,
        // global date-window movement reports, and per-user sync throttling.
        $indexes = [
            'rank_results_website_time' => ['rank_results', 'rank_results (website_id, observed_at, id)'],
            'rank_results_observed_keyword' => ['rank_results', 'rank_results (observed_at, keyword_id, id)'],
            'search_console_syncs_user_created' => ['search_console_syncs', 'search_console_syncs (user_id, created_at)'],
        ];
        foreach ($indexes as $name => [$table, $definition]) {
            if ($database->driver() !== 'mysql') {
                $database->execute("CREATE INDEX IF NOT EXISTS $name ON $definition");
                continue;
            }
            $exists = $database->fetchOne(
                'SELECT 1 AS found FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=:table AND index_name=:name',
                ['table' => $table, 'name' => $name],
            );
            if ($exists === null) $database->execute("CREATE INDEX $name ON $definition");
        }
    },
);
