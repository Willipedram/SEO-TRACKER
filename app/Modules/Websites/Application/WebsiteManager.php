<?php

declare(strict_types=1);

namespace App\Modules\Websites\Application;

use App\Core\Database\Database;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Modules\Websites\Domain\WebsiteInput;
use InvalidArgumentException;

final class WebsiteManager
{
    public function __construct(private readonly Database $database, private readonly Authorization $authorization, private readonly AuditRecorder $audit) {}

    public function list(int $actorId, bool $includeArchived = false): array
    {
        $this->authorization->require($actorId, 'websites.view');
        $sql = 'SELECT public_id, site_name, normalized_domain, canonical_url, protocol, description, timezone, status, created_at, updated_at, archived_at FROM websites WHERE owner_user_id = :owner';
        if (!$includeArchived) $sql .= " AND status <> 'archived'";
        return $this->database->fetchAll($sql . ' ORDER BY site_name, id', ['owner' => $actorId]);
    }

    public function find(int $actorId, string $publicId): array
    {
        $this->authorization->require($actorId, 'websites.view');
        $website = $this->owned($actorId, $publicId);
        if ($website === null) throw new InvalidArgumentException('Website not found.');
        return $website;
    }

    public function findForEdit(int $actorId, string $publicId): array
    {
        $this->authorization->require($actorId, 'websites.edit');
        return $this->requireOwned($actorId, $publicId);
    }

    public function authorize(int $actorId, string $permission): void
    {
        if (!in_array($permission, ['websites.view', 'websites.create', 'websites.edit', 'websites.delete'], true)) {
            throw new InvalidArgumentException('Unknown website permission.');
        }
        $this->authorization->require($actorId, $permission);
    }

    public function create(int $actorId, WebsiteInput $input): string
    {
        $this->authorization->require($actorId, 'websites.create');
        $publicId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        return $this->database->transaction(function (Database $database) use ($actorId, $input, $publicId, $now): string {
            if ($this->domainExists($actorId, $input->domain)) throw new InvalidArgumentException('This domain is already registered in your account.');
            $database->execute('INSERT INTO websites (public_id, owner_user_id, site_name, normalized_domain, canonical_url, protocol, description, timezone, status, created_at, updated_at, archived_at) VALUES (:public, :owner, :name, :domain, :url, :protocol, :description, :timezone, :status, :created, :updated, NULL)', ['public' => $publicId, 'owner' => $actorId, 'name' => $input->name, 'domain' => $input->domain, 'url' => $input->url, 'protocol' => $input->protocol, 'description' => $input->description, 'timezone' => 'UTC', 'status' => 'active', 'created' => $now, 'updated' => $now]);
            $this->audit->record($actorId, 'website.created', 'website', $publicId, ['domain' => $input->domain]);
            return $publicId;
        });
    }

    public function update(int $actorId, string $publicId, WebsiteInput $input): void
    {
        $this->authorization->require($actorId, 'websites.edit');
        $website = $this->requireOwned($actorId, $publicId);
        if ($website['status'] === 'archived') throw new InvalidArgumentException('Archived websites cannot be edited.');
        if ($this->domainExists($actorId, $input->domain, (int) $website['id'])) throw new InvalidArgumentException('This domain is already registered in your account.');
        $this->database->transaction(function (Database $database) use ($actorId, $publicId, $input): void {
            $database->execute('UPDATE websites SET site_name = :name, normalized_domain = :domain, canonical_url = :url, protocol = :protocol, description = :description, updated_at = :updated WHERE public_id = :public AND owner_user_id = :owner', ['name' => $input->name, 'domain' => $input->domain, 'url' => $input->url, 'protocol' => $input->protocol, 'description' => $input->description, 'updated' => gmdate('Y-m-d H:i:s'), 'public' => $publicId, 'owner' => $actorId]);
            $this->audit->record($actorId, 'website.updated', 'website', $publicId);
        });
    }

    public function settings(int $actorId, string $publicId, string $timezone, string $status): void
    {
        $this->authorization->require($actorId, 'websites.edit');
        $website = $this->requireOwned($actorId, $publicId);
        if ($website['status'] === 'archived') throw new InvalidArgumentException('Archived website settings cannot be changed.');
        if (!in_array($timezone, timezone_identifiers_list(), true) || !in_array($status, ['active', 'paused'], true)) throw new InvalidArgumentException('Select a valid timezone and status.');
        $this->database->transaction(function (Database $database) use ($actorId, $publicId, $timezone, $status): void {
            $database->execute('UPDATE websites SET timezone = :timezone, status = :status, updated_at = :updated WHERE public_id = :public AND owner_user_id = :owner', ['timezone' => $timezone, 'status' => $status, 'updated' => gmdate('Y-m-d H:i:s'), 'public' => $publicId, 'owner' => $actorId]);
            $this->audit->record($actorId, 'website.settings_changed', 'website', $publicId, ['status' => $status, 'timezone' => $timezone]);
        });
    }

    public function archive(int $actorId, string $publicId): void
    {
        $this->authorization->require($actorId, 'websites.delete');
        $website = $this->requireOwned($actorId, $publicId);
        if ($website['status'] === 'archived') throw new InvalidArgumentException('Website is already archived.');
        $now = gmdate('Y-m-d H:i:s');
        $this->database->transaction(function (Database $database) use ($actorId, $publicId, $now): void {
            $database->execute("UPDATE websites SET status = 'archived', archived_at = :archived, updated_at = :updated WHERE public_id = :public AND owner_user_id = :owner", ['archived' => $now, 'updated' => $now, 'public' => $publicId, 'owner' => $actorId]);
            $this->audit->record($actorId, 'website.archived', 'website', $publicId);
        });
    }

    private function requireOwned(int $actorId, string $publicId): array
    {
        $website = $this->owned($actorId, $publicId);
        if ($website === null) throw new InvalidArgumentException('Website not found.');
        return $website;
    }

    private function owned(int $actorId, string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) return null;
        return $this->database->fetchOne('SELECT * FROM websites WHERE public_id = :public AND owner_user_id = :owner', ['public' => $publicId, 'owner' => $actorId]);
    }

    private function domainExists(int $actorId, string $domain, ?int $except = null): bool
    {
        $sql = 'SELECT id FROM websites WHERE owner_user_id = :owner AND normalized_domain = :domain';
        $parameters = ['owner' => $actorId, 'domain' => $domain];
        if ($except !== null) { $sql .= ' AND id <> :except'; $parameters['except'] = $except; }
        return $this->database->fetchOne($sql, $parameters) !== null;
    }
}
