<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Database\Database;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use InvalidArgumentException;

final class ModuleManager
{
    public const DEFINITIONS = [
        'core' => ['name' => 'Core', 'mutable' => false, 'source' => null], 'authentication' => ['name' => 'Authentication', 'mutable' => false, 'source' => null],
        'websites' => ['name' => 'Websites', 'mutable' => false, 'source' => 'Websites'], 'keywords' => ['name' => 'Keywords', 'mutable' => false, 'source' => 'Keywords'],
        'rank_tracking' => ['name' => 'Rank Tracker', 'mutable' => false, 'source' => 'RankTracking'], 'reports' => ['name' => 'Reports', 'mutable' => true, 'source' => 'Reports'],
        'search_console' => ['name' => 'Search Console', 'mutable' => true, 'source' => 'SearchConsole'], 'settings' => ['name' => 'Settings', 'mutable' => false, 'source' => 'Settings'],
    ];
    public function __construct(private readonly Database $database, private readonly Authorization $authorization, private readonly AuditRecorder $audit, private readonly string $modulesPath) {}
    public function all(int $actorId): array
    {
        $this->authorization->require($actorId, 'settings.manage'); $rows = $this->database->fetchAll('SELECT module_key,version,enabled,installed_at FROM modules ORDER BY module_key'); $stored = []; foreach ($rows as $row) $stored[$row['module_key']] = $row; $result = [];
        foreach (self::DEFINITIONS as $key => $definition) { $row = $stored[$key] ?? ['version' => null, 'enabled' => 0, 'installed_at' => null]; $available = $definition['source'] === null || is_file(rtrim($this->modulesPath, '/') . '/' . $definition['source'] . '/module.json'); $enabled = (bool) $row['enabled']; $result[] = ['key' => $key, 'name' => $definition['name'], 'version' => $row['version'], 'enabled' => $enabled, 'mutable' => $definition['mutable'], 'status' => !$available ? 'unavailable' : ($enabled ? 'ready' : 'disabled'), 'installed_at' => $row['installed_at']]; }
        return $result;
    }
    public function setEnabled(int $actorId, string $key, bool $enabled): void
    {
        $this->authorization->require($actorId, 'settings.manage'); $definition = self::DEFINITIONS[$key] ?? null; if ($definition === null) throw new InvalidArgumentException('Unknown module.'); if (!$definition['mutable']) throw new InvalidArgumentException('Foundational modules cannot be disabled.'); if ($enabled && ($definition['source'] === null || !is_file(rtrim($this->modulesPath, '/') . '/' . $definition['source'] . '/module.json'))) throw new InvalidArgumentException('Module source is unavailable.');
        $changed = $this->database->execute('UPDATE modules SET enabled=:enabled WHERE module_key=:key', ['enabled' => $enabled ? 1 : 0, 'key' => $key]); if ($changed < 1 && $this->database->fetchOne('SELECT id FROM modules WHERE module_key=:key', ['key' => $key]) === null) throw new InvalidArgumentException('Module is not installed.'); $this->audit->record($actorId, $enabled ? 'module.enabled' : 'module.disabled', 'module', $key);
    }
}
