<?php

namespace Database\Seeders;

use App\Services\Finance\FinanceFoundationService;
use Illuminate\Database\Seeder;

class FinanceFoundationSeeder extends Seeder
{
    public function run(): void
    {
        app(FinanceFoundationService::class)->bootstrap();
    }
}
