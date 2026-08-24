<?php

declare(strict_types=1);

namespace App\Modules\SearchConsole\Application;

use App\Core\Database\Database;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SearchConsoleSyncManager
{
    public const SEARCH_TYPES = ['web', 'image', 'video', 'news', 'discover', 'googleNews'];

    public function __construct(private readonly Database $database, private readonly Authorization $authorization, private readonly AuditRecorder $audit, private readonly int $maxRangeDays = 31) {}

    public function submit(int $actorId, string $websitePublicId, string $startDate, string $endDate, string $searchType): string
    {
        $this->authorization->require($actorId, 'search_console.sync');
        [$start, $end] = $this->range($startDate, $endDate);
        if (!in_array($searchType, self::SEARCH_TYPES, true)) throw new InvalidArgumentException('Select a supported Search Console search type.');
        $selection = $this->selection($actorId, $websitePublicId);
        $existing = $this->database->fetchOne("SELECT public_id FROM search_console_syncs WHERE user_id = :user AND website_id = :website AND property_id = :property AND start_date = :start AND end_date = :end AND search_type = :type AND status IN ('pending','running','retry_wait') ORDER BY id DESC LIMIT 1", ['user' => $actorId, 'website' => $selection['website_id'], 'property' => $selection['property_id'], 'start' => $start, 'end' => $end, 'type' => $searchType]);
        if ($existing !== null) return (string) $existing['public_id'];
        $recent = (int) ($this->database->fetchOne('SELECT COUNT(*) AS total FROM search_console_syncs WHERE user_id = :user AND created_at >= :cutoff', ['user' => $actorId, 'cutoff' => gmdate('Y-m-d H:i:s', time() - 3600)])['total'] ?? 0);
        if ($recent >= 10) throw new InvalidArgumentException('Search Console sync rate limit reached. Try again later.');
        $publicId = bin2hex(random_bytes(16)); $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $database) use ($actorId, $selection, $start, $end, $searchType, $publicId, $now): void {
            $database->execute("INSERT INTO search_console_syncs (public_id,user_id,website_id,property_id,start_date,end_date,search_type,status,phase,attempt_count,available_at,lease_expires_at,rows_fetched,rows_saved,error_code,error_detail,created_at,started_at,completed_at,updated_at) VALUES (:public,:user,:website,:property,:start,:end,:type,'pending','started',0,:available,NULL,0,0,NULL,NULL,:created,NULL,NULL,:updated)", ['public' => $publicId, 'user' => $actorId, 'website' => $selection['website_id'], 'property' => $selection['property_id'], 'start' => $start, 'end' => $end, 'type' => $searchType, 'available' => $now, 'created' => $now, 'updated' => $now]);
            $syncId = (int) $database->fetchOne('SELECT id FROM search_console_syncs WHERE public_id = :public', ['public' => $publicId])['id'];
            $this->log($database, $syncId, 'started', null, 'Synchronization was queued.', $now);
            $this->audit->record($actorId, 'search_console.sync_requested', 'search_console_sync', $publicId, ['start_date' => $start, 'end_date' => $end, 'search_type' => $searchType]);
        });
        return $publicId;
    }

    public function status(int $actorId, string $publicId): array
    {
        $this->authorization->require($actorId, 'search_console.sync');
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) throw new InvalidArgumentException('Search Console sync not found.');
        $sync = $this->database->fetchOne('SELECT public_id,start_date,end_date,search_type,status,phase,attempt_count,rows_fetched,rows_saved,error_code,error_detail,created_at,started_at,completed_at FROM search_console_syncs WHERE public_id = :public AND user_id = :user', ['public' => $publicId, 'user' => $actorId]);
        if ($sync === null) throw new InvalidArgumentException('Search Console sync not found.');
        $sync['logs'] = $this->database->fetchAll('SELECT search_console_sync_logs.state,search_console_sync_logs.error_code,search_console_sync_logs.message,search_console_sync_logs.occurred_at FROM search_console_sync_logs JOIN search_console_syncs ON search_console_syncs.id = search_console_sync_logs.sync_id WHERE search_console_syncs.public_id = :public AND search_console_syncs.user_id = :user ORDER BY search_console_sync_logs.id', ['public' => $publicId, 'user' => $actorId]);
        return $sync;
    }

    public function recent(int $actorId, string $websitePublicId): array
    {
        $this->authorization->require($actorId, 'search_console.sync'); $selection = $this->selection($actorId, $websitePublicId);
        return $this->database->fetchAll('SELECT public_id,start_date,end_date,search_type,status,phase,attempt_count,rows_saved,error_code,created_at,completed_at FROM search_console_syncs WHERE user_id = :user AND website_id = :website ORDER BY id DESC LIMIT 20', ['user' => $actorId, 'website' => $selection['website_id']]);
    }

    private function selection(int $actorId, string $websitePublicId): array
    {
        $module = $this->database->fetchOne('SELECT enabled FROM modules WHERE module_key = :key', ['key' => SearchConsoleManager::KEY]);
        if (!(bool) ($module['enabled'] ?? false)) throw new InvalidArgumentException('Search Console is disabled.');
        if (!preg_match('/^[a-f0-9]{32}$/', $websitePublicId)) throw new InvalidArgumentException('Connected Search Console property not found.');
        $row = $this->database->fetchOne("SELECT websites.id AS website_id, p.id AS property_id FROM websites JOIN search_console_properties p ON p.website_id = websites.id AND p.selected = 1 JOIN search_console_connections c ON c.id = p.connection_id AND c.user_id = :user AND c.status = 'connected' WHERE websites.public_id = :website AND websites.owner_user_id = :user AND websites.status <> 'archived'", ['website' => $websitePublicId, 'user' => $actorId]);
        if ($row === null) throw new InvalidArgumentException('Connected Search Console property not found.');
        return $row;
    }

    private function range(string $start, string $end): array
    {
        $zone = new DateTimeZone('UTC'); $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $zone); $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $zone);
        if ($startDate === false || $endDate === false || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end || $startDate > $endDate) throw new InvalidArgumentException('Select a valid Search Console date range.');
        $yesterday = new DateTimeImmutable('yesterday', $zone); $earliest = $yesterday->modify('-16 months');
        if ($endDate > $yesterday || $startDate < $earliest || ((int) $startDate->diff($endDate)->days + 1) > $this->maxRangeDays) throw new InvalidArgumentException('Search Console dates must be within the available history and configured range limit.');
        return [$start, $end];
    }

    private function log(Database $database, int $syncId, string $state, ?string $errorCode, string $message, string $at): void
    {
        $database->execute('INSERT INTO search_console_sync_logs (sync_id,state,error_code,message,occurred_at) VALUES (:sync,:state,:error,:message,:at)', ['sync' => $syncId, 'state' => $state, 'error' => $errorCode, 'message' => $message, 'at' => $at]);
    }
}
