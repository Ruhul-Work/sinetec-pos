<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order', function (Blueprint $table): void {
            $table->enum('discount_type', ['FLAT', 'PERCENT'])->default('FLAT')->after('subtotal');
            $table->decimal('discount_value', 20, 4)->default(0)->after('discount_type');
        });

        DB::table('purchase_order')->update([
            'discount_type' => 'FLAT',
            'discount_value' => DB::raw('discount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_order', function (Blueprint $table): void {
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
