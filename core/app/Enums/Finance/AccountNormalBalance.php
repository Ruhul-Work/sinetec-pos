<?php

namespace App\Enums\Finance;

enum AccountNormalBalance: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }
}
