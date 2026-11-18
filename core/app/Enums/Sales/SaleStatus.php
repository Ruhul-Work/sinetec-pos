<?php

namespace App\Enums\Sales;

enum SaleStatus: string
{
    case Draft = 'DRAFT';
    case ReadyToPost = 'READY_TO_POST';
    case Posted = 'POSTED';
    case Void = 'VOID';

    public function label(): string
    {
        return match ($this) {
            self::ReadyToPost => 'Ready to Post',
            default => str($this->value)->title()->toString(),
        };
    }
}
