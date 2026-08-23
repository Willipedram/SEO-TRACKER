<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Database\Database;
use App\Core\Installer\SchemaInstaller;
use App\Core\Logging\Logger;
use Throwable;

final class MigrationRunner
{
    public function __construct(
        private readonly Database $database,
        private readonly MigrationDiscovery $discovery,
        private readonly string $sourceVersion,
        private readonly int $targetSchemaVersion,
        private readonly Logger $logger,
    ) {}

    public function plan(): UpdatePlan
    {
        $installation = $this->installation();
        $installedSchema = (int) $installation['schema_version'];
        $installedSource = (string) ($installation['source_version'] ?? '0.3.0');
        if (version_compare($installedSource, $this->sourceVersion, '>')) {
            throw new UpdateException('Installed application version is newer than this source release. Restore compatible source code.');
        }
        if ($installedSchema > $this->targetSchemaVersion) {
            throw new UpdateException('The database schema is newer than this source release. Restore compatible source code.');
        }
        $all = $this->discovery->discover();
        $versions = array_map(static fn (Migration $migration): int => $migration->schemaVersion, $all);
        for ($version = 1; $version <= $this->targetSchemaVersion; $version++) {
            if (!in_array($version, $versions, true)) {
                throw new UpdateException('Source release is missing schema migration ' . $version . '.');
            }
        }
        $applied = array_column($this->database->fetchAll('SELECT migration FROM migrations'), 'migration');
        foreach ($all as $migration) {
            if ($migration->schemaVersion <= $installedSchema && !in_array($migration->id, $applied, true)) {
                throw new UpdateException('Migration ledger is inconsistent at ' . $migration->id . '; restore or repair it before updating.');
            }
        }
        $pending = array_values(array_filter($all, fn (Migration $migration): bool => $migration->schemaVersion > $installedSchema && $migration->schemaVersion <= $this->targetSchemaVersion && !in_array($migration->id, $applied, true)));
        return new UpdatePlan($installedSource, $this->sourceVersion, $installedSchema, $this->targetSchemaVersion, $pending);
    }

    public function run(): UpdatePlan
    {
        $plan = $this->plan();
        if (!$this->acquireLock()) {
            throw new UpdateException('Another update is currently running. Retry after it finishes.');
        }
        try {
            foreach ($plan->pending as $migration) {
                $this->runOne($migration);
            }
            $this->database->execute('UPDATE app_installations SET schema_version = :schema, source_version = :source WHERE application_id = :id', [
                'schema' => $this->targetSchemaVersion, 'source' => $this->sourceVersion, 'id' => SchemaInstaller::APPLICATION_ID,
            ]);
            return $this->plan();
        } finally {
            $this->releaseLock();
        }
    }

    private function runOne(Migration $migration): void
    {
        $this->database->execute('DELETE FROM migration_failures WHERE migration = :migration', ['migration' => $migration->id]);
        $started = gmdate('Y-m-d H:i:s');
        try {
            $operation = fn (): mixed => $migration->up($this->database);
            if ($migration->transactional) {
                $this->database->transaction($operation);
            } else {
                $operation();
            }
            $batch = (int) ($this->database->fetchOne('SELECT COALESCE(MAX(batch), 0) + 1 AS batch FROM migrations')['batch'] ?? 1);
            $this->database->execute('INSERT INTO migrations (migration, batch, applied_at, status, started_at, completed_at) VALUES (:migration, :batch, :applied, :status, :started, :completed)', [
                'migration' => $migration->id, 'batch' => $batch, 'applied' => gmdate('Y-m-d H:i:s'), 'status' => 'succeeded', 'started' => $started, 'completed' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            $message = $this->safeMessage($exception->getMessage());
            try {
                $this->database->execute('INSERT INTO migration_failures (migration, schema_version, error_class, error_message, failed_at) VALUES (:migration, :version, :class, :message, :failed)', [
                    'migration' => $migration->id, 'version' => $migration->schemaVersion, 'class' => $exception::class, 'message' => $message, 'failed' => gmdate('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $recordingFailure) {
                $this->logger->critical('Migration failure state could not be recorded.', ['migration' => $migration->id, 'exception' => $recordingFailure::class]);
            }
            $this->logger->error('Database migration failed.', ['migration' => $migration->id, 'schema_version' => $migration->schemaVersion, 'exception' => $exception::class, 'message' => $message]);
            throw new UpdateException('Database update failed at migration ' . $migration->id . '. Correct the technical cause and retry; later migrations were not run.', 0, $exception);
        }
    }

    private function installation(): array
    {
        try {
            $row = $this->database->fetchOne('SELECT application_id, schema_version, source_version FROM app_installations WHERE application_id = :id', ['id' => SchemaInstaller::APPLICATION_ID]);
        } catch (Throwable) {
            $row = $this->database->fetchOne('SELECT application_id, schema_version FROM app_installations WHERE application_id = :id', ['id' => SchemaInstaller::APPLICATION_ID]);
        }
        if (($row['application_id'] ?? null) !== SchemaInstaller::APPLICATION_ID) {
            throw new UpdateException('This database is not a recognized SEO Tracker installation.');
        }
        return $row;
    }

    private function acquireLock(): bool
    {
        if ($this->database->driver() !== 'mysql') {
            return true;
        }
        return (int) ($this->database->fetchOne("SELECT GET_LOCK('seo_tracker_update', 0) AS acquired")['acquired'] ?? 0) === 1;
    }

    private function releaseLock(): void
    {
        if ($this->database->driver() === 'mysql') {
            $this->database->fetchOne("SELECT RELEASE_LOCK('seo_tracker_update') AS released");
        }
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('/(?i)(password|secret|token|authorization|cookie|app[_-]?key)(\s*[=:]\s*)([^\s,;]+)/', '$1$2[REDACTED]', $message) ?? 'Migration error';
        return substr($message, 0, 2000);
    }
}
