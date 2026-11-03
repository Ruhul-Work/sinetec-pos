<?php

namespace App\Data\Finance;

use App\Models\backend\JournalEntry;

final readonly class JournalDraftResult
{
    public function __construct(
        public JournalEntry $journal,
        public string $totalDebit,
        public string $totalCredit,
        public bool $wasAlreadyCreated = false,
    ) {}
}
