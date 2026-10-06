<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use InvalidArgumentException;

final readonly class ReviewActor
{
    public string $subject;

    public function __construct(string $subject)
    {
        $subject = trim($subject);

        if ($subject === '') {
            throw new InvalidArgumentException('Identidade confiável da Revisão é obrigatória.');
        }

        $this->subject = $subject;
    }
}
