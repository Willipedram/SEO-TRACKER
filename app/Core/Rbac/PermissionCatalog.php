<?php

declare(strict_types=1);

namespace App\Core\Rbac;

final class PermissionCatalog
{
    public const DEFINITIONS = [
        'users.view' => 'View users',
        'users.create' => 'Create users',
        'users.edit' => 'Edit users',
        'users.delete' => 'Delete users',
        'roles.manage' => 'Manage roles and permission assignments',
        'websites.view' => 'View websites',
        'websites.create' => 'Create websites',
        'websites.edit' => 'Edit websites',
        'websites.delete' => 'Delete websites',
        'keywords.view' => 'View keywords',
        'keywords.create' => 'Create keywords',
        'keywords.edit' => 'Edit keywords',
        'keywords.delete' => 'Delete keywords',
        'rank_tracking.run' => 'Run rank tracking',
        'rank_tracking.view' => 'View rank tracking data',
        'search_console.connect' => 'Connect Search Console',
        'search_console.sync' => 'Synchronize Search Console',
        'reports.view' => 'View reports',
        'settings.manage' => 'Manage application settings',
    ];
}
