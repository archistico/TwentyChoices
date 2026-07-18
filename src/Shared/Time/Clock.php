<?php

declare(strict_types=1);

namespace App\Shared\Time;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
