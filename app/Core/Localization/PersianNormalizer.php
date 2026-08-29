<?php

declare(strict_types=1);

namespace App\Core\Localization;

final class PersianNormalizer
{
    /** Normalize authored UI copy only; never pass user or technical values here. */
    public static function ui(string $value): string
    {
        return str_replace(["ي", "ى", "ك"], ["ی", "ی", "ک"], $value);
    }
}
