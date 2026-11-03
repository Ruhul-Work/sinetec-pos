<?php

namespace App\Data\Finance;

final readonly class JournalLineData
{
    public function __construct(
        public int $accountId,
        public int|float|string $debit = 0,
        public int|float|string $credit = 0,
        public ?string $memo = null,
    ) {}
}
