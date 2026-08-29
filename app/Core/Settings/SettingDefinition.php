<?php

declare(strict_types=1);

namespace App\Core\Settings;

final readonly class SettingDefinition
{
    public function __construct(public string $key, public string $scope, public string $type, public mixed $default, public array $options = [], public bool $featureFlag = false, public bool $secure = false) {}
}
