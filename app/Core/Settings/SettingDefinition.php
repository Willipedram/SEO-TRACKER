<?php

declare(strict_types=1);

namespace App\Core\Settings;

final class SettingDefinition
{
    public function __construct(public readonly string $key, public readonly string $scope, public readonly string $type, public readonly mixed $default, public readonly array $options = [], public readonly bool $featureFlag = false, public readonly bool $secure = false) {}
}
