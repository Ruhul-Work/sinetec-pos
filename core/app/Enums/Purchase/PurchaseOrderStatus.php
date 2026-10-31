<?php

namespace App\Enums\Purchase;

enum PurchaseOrderStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Partial = 'PARTIAL';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
