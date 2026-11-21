<?php

namespace App\Enums\Reporting;

enum ReportingPeriodStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'bg-dark-success-gradient text-white',
            self::Closed => 'bg-dark-danger-gradient text-white',
        };
    }
}
