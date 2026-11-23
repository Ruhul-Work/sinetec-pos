<?php

namespace App\Enums\Reporting;

enum MonthlyReportSnapshotScope: string
{
    case Company = 'COMPANY';
    case Branch = 'BRANCH';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company-wide',
            self::Branch => 'Branch',
        };
    }
}
