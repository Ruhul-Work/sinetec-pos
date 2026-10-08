<?php
namespace Database\Seeders;

use App\Models\backend\VoucherType;
use Illuminate\Database\Seeder;

class VoucherTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'name' => 'Sale Voucher',
                'code' => 'SALE',
            ],
            [
                'name' => 'Refund Voucher',
                'code' => 'REFUND',
            ],
            [
                'name' => 'Adjustment Voucher',
                'code' => 'ADJUST',
            ],
            [
                'name' => 'Payment Voucher',
                'code' => 'PAYMENT',
            ],
            [
                'name' => 'Purchase Voucher',
                'code' => 'PURCHASE',
            ],
            [
                'name' => 'Expense Voucher',
                'code' => 'EXPENSE',
            ],
            [
                'name' => 'Account Transfer Voucher',
                'code' => 'TRANSFER',
            ],
            [
                'name' => 'Opening Balance Voucher',
                'code' => 'OPENING',
            ],
            [
                'name' => 'Sale Return Voucher',
                'code' => 'SALE_RETURN',
            ],
            [
                'name' => 'Business Journal',
                'code' => 'B_JOURNAL',
            ],

        ];

        foreach ($types as $type) {
            VoucherType::updateOrCreate(
                ['code' => $type['code']], // unique key
                ['name' => $type['name']]
            );
        }
    }
}
