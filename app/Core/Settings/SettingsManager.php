<?php

declare(strict_types=1);

namespace App\Core\Settings;

use App\Core\Database\Database;
use App\Core\Rbac\AuditRecorder;
use App\Core\Rbac\Authorization;
use DateTimeZone;
use InvalidArgumentException;

final class SettingsManager
{
    private array $cache = [];
    public function __construct(private readonly Database $database, private readonly Authorization $authorization, private readonly AuditRecorder $audit) {}

    public function all(int $actorId, string $scope, ?string $scopeId = null): array
    {
        $this->authorizeScope($actorId, $scope, $scopeId, false); $result = [];
        foreach (SettingsRegistry::definitions() as $key => $definition) if ($definition->scope === $scope && ($scope !== 'module' || str_starts_with($key, 'module.' . $scopeId . '.'))) $result[$key] = ['value' => $this->get($key, $scopeId), 'type' => $definition->type, 'default' => $definition->default, 'options' => $definition->options, 'feature_flag' => $definition->featureFlag, 'secure' => $definition->secure];
        return $result;
    }

    public function get(string $key, ?string $scopeId = null): mixed
    {
        $definition = $this->definition($key); $id = $definition->scope === 'system' ? 'global' : $this->scopeId($scopeId); $cacheKey = $definition->scope . ':' . $id . ':' . $key;
        if (array_key_exists($cacheKey, $this->cache)) return $this->cache[$cacheKey];
        $row = $this->database->fetchOne('SELECT setting_value FROM managed_settings WHERE setting_key=:key AND scope_type=:scope AND scope_id=:id', ['key' => $key, 'scope' => $definition->scope, 'id' => $id]);
        return $this->cache[$cacheKey] = $row === null ? $definition->default : json_decode((string) $row['setting_value'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function set(int $actorId, string $key, mixed $value, ?string $scopeId = null): void
    {
        $definition = $this->definition($key); if ($definition->secure) throw new InvalidArgumentException('Secure settings must be configured through deployment secrets.'); $id = $definition->scope === 'system' ? 'global' : $this->scopeId($scopeId); if ($definition->scope === 'module' && !str_starts_with($key, 'module.' . $id . '.')) throw new InvalidArgumentException('Setting does not belong to this module.'); $this->authorizeScope($actorId, $definition->scope, $id, true); $validated = $this->validate($definition, $value); $now = gmdate('Y-m-d H:i:s');
        $parameters = ['key' => $key, 'scope' => $definition->scope, 'id' => $id, 'value' => json_encode($validated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'type' => $definition->type, 'actor' => $actorId, 'now' => $now];
        if ($this->database->fetchOne('SELECT id FROM managed_settings WHERE setting_key=:key AND scope_type=:scope AND scope_id=:id', ['key' => $key, 'scope' => $definition->scope, 'id' => $id]) === null) $this->database->execute('INSERT INTO managed_settings (setting_key,scope_type,scope_id,setting_value,value_type,updated_by,created_at,updated_at) VALUES (:key,:scope,:id,:value,:type,:actor,:now,:now)', $parameters); else $this->database->execute('UPDATE managed_settings SET setting_value=:value,value_type=:type,updated_by=:actor,updated_at=:now WHERE setting_key=:key AND scope_type=:scope AND scope_id=:id', $parameters);
        unset($this->cache[$definition->scope . ':' . $id . ':' . $key]); $this->audit->record($actorId, $definition->featureFlag ? 'feature_flag.changed' : 'setting.changed', 'setting', $key, ['scope' => $definition->scope, 'scope_id' => $id]);
    }

    public function featureEnabled(string $key): bool { $definition = $this->definition($key); if (!$definition->featureFlag) throw new InvalidArgumentException('Not a feature flag.'); return (bool) $this->get($key); }
    private function definition(string $key): SettingDefinition { $definition = SettingsRegistry::definitions()[$key] ?? null; if ($definition === null) throw new InvalidArgumentException('Unknown setting.'); return $definition; }
    private function scopeId(?string $id): string { if ($id === null || !preg_match('/^[a-z0-9_.-]{1,100}$/', $id)) throw new InvalidArgumentException('Invalid setting scope.'); return $id; }
    private function authorizeScope(int $actorId, string $scope, ?string $id, bool $write): void { if (!in_array($scope, ['system','user','module'], true)) throw new InvalidArgumentException('Invalid setting scope.'); if ($scope === 'user') { if ((string) $actorId !== (string) $id) throw new InvalidArgumentException('User settings are owner scoped.'); return; } $this->authorization->require($actorId, 'settings.manage'); }
    private function validate(SettingDefinition $d, mixed $value): mixed
    {
        return match ($d->type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new InvalidArgumentException('Setting must be boolean.'),
            'integer' => (($int = filter_var($value, FILTER_VALIDATE_INT)) !== false && $int >= ($d->options['min'] ?? PHP_INT_MIN) && $int <= ($d->options['max'] ?? PHP_INT_MAX)) ? $int : throw new InvalidArgumentException('Setting integer is out of range.'),
            'enum' => in_array(is_numeric($value) ? (int) $value : $value, $d->options, true) ? (is_numeric($value) ? (int) $value : $value) : throw new InvalidArgumentException('Setting value is not allowed.'),
            'timezone' => in_array((string) $value, DateTimeZone::listIdentifiers(), true) || $value === 'UTC' ? (string) $value : throw new InvalidArgumentException('Invalid timezone.'),
            'string' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 120 && !preg_match('/[\x00-\x1F\x7F]/u', $value) ? trim($value) : throw new InvalidArgumentException('Invalid setting text.'),
            default => throw new InvalidArgumentException('Unsupported setting type.'),
        };
    }
}
