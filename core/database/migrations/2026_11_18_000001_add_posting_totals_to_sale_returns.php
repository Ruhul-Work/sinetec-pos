<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->decimal('subtotal', 20, 4)->default(0)->after('status');
            $table->decimal('discount', 20, 4)->default(0)->after('subtotal');
            $table->decimal('tax', 20, 4)->default(0)->after('discount');
            $table->decimal('due_adjustment', 20, 4)->default(0)->after('total');
            $table->decimal('refund_amount', 20, 4)->default(0)->after('due_adjustment');
        });
        DB::table('sale_returns')->update(['subtotal' => DB::raw('total')]);

        DB::table('voucher_types')->insertOrIgnore([
            'code' => 'SALE_RETURN',
            'name' => 'Sale Return Voucher',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('voucher_types')
            ->where('code', 'SALE_RETURN')
            ->update(['name' => 'Sale Return Voucher', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table): void {
            $table->dropColumn([
                'subtotal',
                'discount',
                'tax',
                'due_adjustment',
                'refund_amount',
            ]);
        });
    }
};
