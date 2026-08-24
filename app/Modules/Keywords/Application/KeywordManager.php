<?php

declare(strict_types=1);

namespace App\Modules\Keywords\Application;

use App\Core\Database\Database;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use App\Modules\Keywords\Domain\KeywordInput;
use InvalidArgumentException;

final class KeywordManager
{
    public function __construct(private readonly Database $database, private readonly Authorization $authorization, private readonly AuditRecorder $audit) {}

    public function list(int $actorId, string $websitePublicId): array
    {
        $this->authorization->require($actorId, 'keywords.view');
        $website = $this->website($actorId, $websitePublicId);
        return $this->database->fetchAll('SELECT public_id, keyword_text, target_url, search_engine, country_code, language_code, device, active, created_at, updated_at FROM keywords WHERE website_id = :website ORDER BY keyword_text, id', ['website' => $website['id']]);
    }

    public function find(int $actorId, string $websitePublicId, string $keywordPublicId, string $permission = 'keywords.view'): array
    {
        $this->permission($actorId, $permission);
        $website = $this->website($actorId, $websitePublicId);
        $keyword = $this->ownedKeyword((int) $website['id'], $keywordPublicId);
        if ($keyword === null) throw new InvalidArgumentException('Keyword not found.');
        return $keyword;
    }

    public function authorize(int $actorId, string $websitePublicId, string $permission): void
    {
        $this->permission($actorId, $permission);
        $this->activeWebsite($actorId, $websitePublicId);
    }

    public function create(int $actorId, string $websitePublicId, KeywordInput $input): string
    {
        $this->authorization->require($actorId, 'keywords.create');
        $website = $this->activeWebsite($actorId, $websitePublicId);
        $publicId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        return $this->database->transaction(function (Database $database) use ($actorId, $website, $input, $publicId, $now): string {
            if ($this->duplicate((int) $website['id'], $input)) throw new InvalidArgumentException('This keyword tracking configuration already exists for the website.');
            $database->execute('INSERT INTO keywords (public_id, website_id, keyword_text, normalized_keyword, target_url, search_engine, country_code, language_code, device, active, created_at, updated_at) VALUES (:public, :website, :text, :normalized, :target, :engine, :country, :language, :device, :active, :created, :updated)', ['public' => $publicId, 'website' => $website['id'], 'text' => $input->text, 'normalized' => $input->normalizedText, 'target' => $input->targetUrl, 'engine' => $input->searchEngine, 'country' => $input->country, 'language' => $input->language, 'device' => $input->device, 'active' => $input->active ? 1 : 0, 'created' => $now, 'updated' => $now]);
            $this->audit->record($actorId, 'keyword.created', 'keyword', $publicId, ['website' => $website['public_id'], 'engine' => $input->searchEngine, 'device' => $input->device]);
            return $publicId;
        });
    }

    public function update(int $actorId, string $websitePublicId, string $keywordPublicId, KeywordInput $input): void
    {
        $this->authorization->require($actorId, 'keywords.edit');
        $website = $this->activeWebsite($actorId, $websitePublicId);
        $keyword = $this->ownedKeyword((int) $website['id'], $keywordPublicId);
        if ($keyword === null) throw new InvalidArgumentException('Keyword not found.');
        if ($this->duplicate((int) $website['id'], $input, (int) $keyword['id'])) throw new InvalidArgumentException('This keyword tracking configuration already exists for the website.');
        $this->database->transaction(function (Database $database) use ($actorId, $website, $keywordPublicId, $input): void {
            $database->execute('UPDATE keywords SET keyword_text = :text, normalized_keyword = :normalized, target_url = :target, search_engine = :engine, country_code = :country, language_code = :language, device = :device, active = :active, updated_at = :updated WHERE public_id = :public AND website_id = :website', ['text' => $input->text, 'normalized' => $input->normalizedText, 'target' => $input->targetUrl, 'engine' => $input->searchEngine, 'country' => $input->country, 'language' => $input->language, 'device' => $input->device, 'active' => $input->active ? 1 : 0, 'updated' => gmdate('Y-m-d H:i:s'), 'public' => $keywordPublicId, 'website' => $website['id']]);
            $this->audit->record($actorId, 'keyword.updated', 'keyword', $keywordPublicId);
        });
    }

    public function setActive(int $actorId, string $websitePublicId, string $keywordPublicId, bool $active): void
    {
        $this->authorization->require($actorId, 'keywords.edit');
        $website = $this->activeWebsite($actorId, $websitePublicId);
        if ($this->ownedKeyword((int) $website['id'], $keywordPublicId) === null) throw new InvalidArgumentException('Keyword not found.');
        $this->database->transaction(function (Database $database) use ($actorId, $website, $keywordPublicId, $active): void {
            $database->execute('UPDATE keywords SET active = :active, updated_at = :updated WHERE public_id = :public AND website_id = :website', ['active' => $active ? 1 : 0, 'updated' => gmdate('Y-m-d H:i:s'), 'public' => $keywordPublicId, 'website' => $website['id']]);
            $this->audit->record($actorId, $active ? 'keyword.activated' : 'keyword.deactivated', 'keyword', $keywordPublicId);
        });
    }

    public function delete(int $actorId, string $websitePublicId, string $keywordPublicId): void
    {
        $this->authorization->require($actorId, 'keywords.delete');
        $website = $this->activeWebsite($actorId, $websitePublicId);
        if ($this->ownedKeyword((int) $website['id'], $keywordPublicId) === null) throw new InvalidArgumentException('Keyword not found.');
        $this->database->transaction(function (Database $database) use ($actorId, $website, $keywordPublicId): void {
            $this->audit->record($actorId, 'keyword.deleted', 'keyword', $keywordPublicId, ['website' => $website['public_id']]);
            $database->execute('DELETE FROM keywords WHERE public_id = :public AND website_id = :website', ['public' => $keywordPublicId, 'website' => $website['id']]);
        });
    }

    private function website(int $actorId, string $publicId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) throw new InvalidArgumentException('Website not found.');
        $website = $this->database->fetchOne('SELECT id, public_id, status FROM websites WHERE public_id = :public AND owner_user_id = :owner', ['public' => $publicId, 'owner' => $actorId]);
        if ($website === null) throw new InvalidArgumentException('Website not found.');
        return $website;
    }

    private function activeWebsite(int $actorId, string $publicId): array
    {
        $website = $this->website($actorId, $publicId);
        if ($website['status'] === 'archived') throw new InvalidArgumentException('Archived websites cannot modify keywords.');
        return $website;
    }

    private function ownedKeyword(int $websiteId, string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) return null;
        return $this->database->fetchOne('SELECT * FROM keywords WHERE website_id = :website AND public_id = :public', ['website' => $websiteId, 'public' => $publicId]);
    }

    private function permission(int $actorId, string $permission): void
    {
        if (!in_array($permission, ['keywords.view', 'keywords.create', 'keywords.edit', 'keywords.delete'], true)) throw new InvalidArgumentException('Unknown keyword permission.');
        $this->authorization->require($actorId, $permission);
    }

    private function duplicate(int $websiteId, KeywordInput $input, ?int $except = null): bool
    {
        $sql = 'SELECT id FROM keywords WHERE website_id = :website AND normalized_keyword = :keyword AND search_engine = :engine AND country_code = :country AND language_code = :language AND device = :device';
        $parameters = ['website' => $websiteId, 'keyword' => $input->normalizedText, 'engine' => $input->searchEngine, 'country' => $input->country, 'language' => $input->language, 'device' => $input->device];
        if ($except !== null) { $sql .= ' AND id <> :except'; $parameters['except'] = $except; }
        return $this->database->fetchOne($sql, $parameters) !== null;
    }
}
