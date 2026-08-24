<?php

declare(strict_types=1);

use App\Core\Database\Database;
use App\Core\Update\Migration;

return new Migration(
    id: '2026_08_23_060000_rank_tracking_engine',
    schemaVersion: 7,
    transactional: false,
    operation: static function (Database $database): void {
        $mysql = $database->driver() === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $foreignId = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $requestIndexes = $mysql ? ', UNIQUE INDEX rank_requests_public (public_id), INDEX rank_requests_queue (status, available_at, created_at), INDEX rank_requests_owner (user_id, created_at), INDEX rank_requests_keyword (keyword_id, created_at)' : '';
        $attemptIndexes = $mysql ? ', UNIQUE INDEX rank_attempts_public (public_id), UNIQUE INDEX rank_attempts_number (request_id, attempt_number), INDEX rank_attempts_lease (status, lease_expires_at)' : '';
        $resultIndexes = $mysql ? ', UNIQUE INDEX rank_results_public (public_id), UNIQUE INDEX rank_results_attempt (attempt_id), INDEX rank_results_keyword_time (keyword_id, observed_at)' : '';
        $database->execute("CREATE TABLE IF NOT EXISTS rank_check_requests (id $id, public_id CHAR(32) NOT NULL, user_id $foreignId NOT NULL, website_id $foreignId NOT NULL, keyword_id $foreignId NOT NULL, keyword_text VARCHAR(255) NOT NULL, target_url VARCHAR(2048) NULL, search_engine VARCHAR(50) NOT NULL, country_code CHAR(2) NOT NULL, language_code VARCHAR(35) NOT NULL, requested_device VARCHAR(30) NOT NULL, execution_source VARCHAR(30) NOT NULL, adapter_key VARCHAR(50) NOT NULL, status VARCHAR(30) NOT NULL, attempt_count INTEGER NOT NULL, available_at DATETIME NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME NULL, completed_at DATETIME NULL, error_code VARCHAR(60) NULL, error_detail VARCHAR(255) NULL, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT, FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE RESTRICT, FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE RESTRICT$requestIndexes)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS rank_execution_attempts (id $id, public_id CHAR(32) NOT NULL, request_id $foreignId NOT NULL, attempt_number INTEGER NOT NULL, execution_source VARCHAR(30) NOT NULL, adapter_key VARCHAR(50) NOT NULL, adapter_version VARCHAR(30) NOT NULL, requested_device VARCHAR(30) NOT NULL, execution_device VARCHAR(50) NULL, user_agent_profile VARCHAR(100) NULL, network_context VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, leased_by VARCHAR(100) NOT NULL, lease_token_hash CHAR(64) NOT NULL, lease_expires_at DATETIME NOT NULL, started_at DATETIME NOT NULL, completed_at DATETIME NULL, error_code VARCHAR(60) NULL, error_detail VARCHAR(255) NULL, retryable INTEGER NOT NULL, FOREIGN KEY (request_id) REFERENCES rank_check_requests(id) ON DELETE RESTRICT$attemptIndexes)$suffix");
        $database->execute("CREATE TABLE IF NOT EXISTS rank_results (id $id, public_id CHAR(32) NOT NULL, request_id $foreignId NOT NULL, attempt_id $foreignId NOT NULL, website_id $foreignId NOT NULL, keyword_id $foreignId NOT NULL, result_type VARCHAR(30) NOT NULL, position INTEGER NULL, ranking_url VARCHAR(2048) NULL, checked_depth INTEGER NOT NULL, search_engine VARCHAR(50) NOT NULL, country_code CHAR(2) NOT NULL, language_code VARCHAR(35) NOT NULL, requested_device VARCHAR(30) NOT NULL, execution_device VARCHAR(50) NOT NULL, execution_source VARCHAR(30) NOT NULL, adapter_key VARCHAR(50) NOT NULL, adapter_version VARCHAR(30) NOT NULL, observed_at DATETIME NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY (request_id) REFERENCES rank_check_requests(id) ON DELETE RESTRICT, FOREIGN KEY (attempt_id) REFERENCES rank_execution_attempts(id) ON DELETE RESTRICT, FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE RESTRICT, FOREIGN KEY (keyword_id) REFERENCES keywords(id) ON DELETE RESTRICT$resultIndexes)$suffix");
        if (!$mysql) {
            foreach ([
                'CREATE UNIQUE INDEX IF NOT EXISTS rank_requests_public ON rank_check_requests (public_id)',
                'CREATE INDEX IF NOT EXISTS rank_requests_queue ON rank_check_requests (status, available_at, created_at)',
                'CREATE INDEX IF NOT EXISTS rank_requests_owner ON rank_check_requests (user_id, created_at)',
                'CREATE INDEX IF NOT EXISTS rank_requests_keyword ON rank_check_requests (keyword_id, created_at)',
                'CREATE UNIQUE INDEX IF NOT EXISTS rank_attempts_public ON rank_execution_attempts (public_id)',
                'CREATE UNIQUE INDEX IF NOT EXISTS rank_attempts_number ON rank_execution_attempts (request_id, attempt_number)',
                'CREATE INDEX IF NOT EXISTS rank_attempts_lease ON rank_execution_attempts (status, lease_expires_at)',
                'CREATE UNIQUE INDEX IF NOT EXISTS rank_results_public ON rank_results (public_id)',
                'CREATE UNIQUE INDEX IF NOT EXISTS rank_results_attempt ON rank_results (attempt_id)',
                'CREATE INDEX IF NOT EXISTS rank_results_keyword_time ON rank_results (keyword_id, observed_at)',
            ] as $sql) $database->execute($sql);
        }
    },
);
