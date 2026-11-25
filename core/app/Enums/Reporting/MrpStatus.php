<?php

namespace App\Enums\Reporting;

enum MrpStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-warning-focus text-warning-main',
            self::Approved => 'bg-success-focus text-success-main',
            self::Cancelled => 'bg-danger-focus text-danger-main',
        };
    }
}
