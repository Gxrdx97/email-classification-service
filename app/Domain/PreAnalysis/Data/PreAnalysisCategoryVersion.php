<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

final readonly class PreAnalysisCategoryVersion
{
    public function __construct(
        public int $id,
        public int $categoryId,
        public int $versionNumber,
        public string $name,
        public string $slug,
        public ?string $description = null,
        public ?string $instructions = null,
    ) {}

    /** @return array<string, int|string|null> */
    public function toProviderPayload(): array
    {
        return [
            'category_version_id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'instructions' => $this->instructions,
        ];
    }
}
