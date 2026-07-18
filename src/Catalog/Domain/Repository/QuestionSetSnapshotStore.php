<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\QuestionSetSnapshot;

interface QuestionSetSnapshotStore
{
    public function saveForRound(string $roundId, QuestionSetSnapshot $snapshot): void;
}
