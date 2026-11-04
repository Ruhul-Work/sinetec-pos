<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('is_cash_bank_account')->default(false)->after('bank_details')->index();
        });

        DB::table('accounts')->whereIn('code', ['1000', '1010'])->update(['is_cash_bank_account' => true]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropIndex(['is_cash_bank_account']);
            $table->dropColumn('is_cash_bank_account');
        });
    }
};
