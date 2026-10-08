<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branch_accounts', function (Blueprint $table) {
            $table->foreign(['account_id'], 'fk_branch_accounts_account')->references(['id'])->on('accounts')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_accounts', function (Blueprint $table) {
            $table->dropForeign('fk_branch_accounts_account');
        });
    }
};
