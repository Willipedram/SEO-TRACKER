<?php

declare(strict_types=1);

namespace App\Core\Settings;

final class SettingsRegistry
{
    /** @return array<string, SettingDefinition> */
    public static function definitions(): array
    {
        $definitions = [
            new SettingDefinition('system.application_name', 'system', 'string', 'SEO Tracker'),
            new SettingDefinition('system.locale', 'system', 'enum', 'fa', ['fa', 'en']),
            new SettingDefinition('system.timezone', 'system', 'timezone', 'UTC'),
            new SettingDefinition('user.locale', 'user', 'enum', 'fa', ['fa', 'en']),
            new SettingDefinition('user.timezone', 'user', 'timezone', 'UTC'),
            new SettingDefinition('user.items_per_page', 'user', 'integer', 50, ['min' => 10, 'max' => 100]),
            new SettingDefinition('module.reports.default_page_size', 'module', 'integer', 50, ['min' => 10, 'max' => 100]),
            new SettingDefinition('module.search_console.default_range_days', 'module', 'enum', 28, [7, 28, 90]),
            new SettingDefinition('feature.rank_manual_checks', 'system', 'boolean', true, featureFlag: true),
            new SettingDefinition('feature.search_console_sync', 'system', 'boolean', true, featureFlag: true),
        ];
        $indexed = []; foreach ($definitions as $definition) $indexed[$definition->key] = $definition; return $indexed;
    }
}
