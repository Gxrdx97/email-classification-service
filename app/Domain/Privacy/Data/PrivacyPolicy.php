<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Data;

use App\Domain\Privacy\Enums\PrivacyAction;
use InvalidArgumentException;

final readonly class PrivacyPolicy
{
    public function __construct(
        public string $version = 'v1',
        public PrivacyAction $action = PrivacyAction::Abort,
    ) {
        if (trim($this->version) === '') {
            throw new InvalidArgumentException('A privacy policy version is required.');
        }
    }
}
