<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->decimal('due_before', 20, 4)->default(0)->after('amount');
            $table->decimal('due_after', 20, 4)->default(0)->after('due_before');
        });
    }

    public function down(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table): void {
            $table->dropColumn(['due_before', 'due_after']);
        });
    }
};
