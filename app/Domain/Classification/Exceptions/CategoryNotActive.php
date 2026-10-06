<?php

declare(strict_types=1);

namespace App\Domain\Classification\Exceptions;

use InvalidArgumentException;

final class CategoryNotActive extends InvalidArgumentException {}
