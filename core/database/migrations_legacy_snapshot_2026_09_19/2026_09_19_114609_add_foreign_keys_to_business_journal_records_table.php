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
        Schema::table('business_journal_records', function (Blueprint $table) {
            $table->foreign(['account_id'], 'fk_bjr_account')->references(['id'])->on('accounts')->onUpdate('no action')->onDelete('restrict');
            $table->foreign(['business_journal_id'], 'fk_bjr_bj')->references(['id'])->on('business_journals')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_journal_records', function (Blueprint $table) {
            $table->dropForeign('fk_bjr_account');
            $table->dropForeign('fk_bjr_bj');
        });
    }
};
