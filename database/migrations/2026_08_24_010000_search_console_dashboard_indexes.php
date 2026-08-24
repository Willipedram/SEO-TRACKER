<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_24_010000_search_console_dashboard_indexes', schemaVersion: 12, transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $indexes = [
            'search_console_data_property_date' => 'search_console_data (website_id,property_id,data_date)',
            'search_console_data_filters' => 'search_console_data (website_id,property_id,search_type,device,country,data_date)',
            'search_console_syncs_latest' => 'search_console_syncs (user_id,website_id,property_id,id)',
        ];
        foreach ($indexes as $name => $definition) {
            if (!$mysql) $database->execute("CREATE INDEX IF NOT EXISTS $name ON $definition");
            elseif ($database->fetchOne('SELECT 1 AS found FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name=:name', ['name' => $name]) === null) $database->execute("CREATE INDEX $name ON $definition");
        }
        $database->execute('UPDATE modules SET version=:version WHERE module_key=:key', ['version' => '1.3.0', 'key' => 'search_console']);
    },
);
