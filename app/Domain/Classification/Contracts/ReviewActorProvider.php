<?php

declare(strict_types=1);

namespace App\Domain\Classification\Contracts;

use App\Domain\Classification\Data\ReviewActor;

interface ReviewActorProvider
{
    /** Resolves the actor from trusted server-side authentication or local CLI context. */
    public function currentActor(): ReviewActor;
}
