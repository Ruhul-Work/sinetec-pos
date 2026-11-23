<?php

namespace App\Enums\Reporting;

enum MonthlyReportSnapshotStatus: string
{
    case Current = 'CURRENT';
    case Superseded = 'SUPERSEDED';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current',
            self::Superseded => 'Superseded',
        };
    }
}
