<?php

declare(strict_types=1);

namespace App\Core\Localization;

final class Terminology
{
    /** @var array<string,array{label:string,term:string,description?:string}> */
    private array $entries;

    public function __construct(string $locale, string $basePath)
    {
        $file = $basePath . '/lang/' . ($locale === 'fa' ? 'fa' : 'en') . '/terms.php';
        $this->entries = is_file($file) ? require $file : [];
    }

    /** @return array{label:string,term:string,description?:string}|null */
    public function forLabel(string $label): ?array
    {
        foreach ($this->entries as $entry) if (($entry['label'] ?? null) === $label) return $entry;
        return null;
    }

    /** @return array<string,array{label:string,term:string,description?:string}> */
    public function all(): array { return $this->entries; }
}
