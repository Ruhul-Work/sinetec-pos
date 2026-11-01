<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_receipt', function (Blueprint $table): void {
            $table->string('supplier_invoice_path')->nullable()->after('supplier_invoice_no');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipt', function (Blueprint $table): void {
            $table->dropColumn('supplier_invoice_path');
        });
    }
};
