<?php

namespace App\Enums\Production;

enum ProductionQcStatus: string
{
    case Pending = 'PENDING';
    case Passed = 'PASSED';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Passed => 'Passed',
            self::Partial => 'Partial Pass',
            self::Failed => 'Failed',
        };
    }
}
