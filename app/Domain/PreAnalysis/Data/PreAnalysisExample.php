<?php

declare(strict_types=1);

namespace App\Domain\PreAnalysis\Data;

final readonly class PreAnalysisExample
{
    public function __construct(
        public int $id,
        public int $version,
        public int $categoryVersionId,
        public string $text,
    ) {}

    /** @return array<string, int|string> */
    public function toProviderPayload(): array
    {
        return [
            'example_id' => $this->id,
            'version' => $this->version,
            'category_version_id' => $this->categoryVersionId,
            'text' => $this->text,
        ];
    }

    /** @return array{id: int, version: int, category_version_id: int} */
    public function provenance(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'category_version_id' => $this->categoryVersionId,
        ];
    }
}
