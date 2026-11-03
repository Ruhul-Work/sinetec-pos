<?php

namespace App\Data\Finance;

use DateTimeInterface;

final readonly class JournalDraftData
{
    /** @param array<int, JournalLineData> $lines */
    public function __construct(
        public string $voucherTypeCode,
        public int $branchId,
        public DateTimeInterface|string $entryDate,
        public string $sourceType,
        public int $sourceId,
        public string $postingKey,
        public array $lines,
        public ?string $narration = null,
        public ?int $createdBy = null,
    ) {}
}
