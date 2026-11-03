<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('posting_key', 191)->nullable()->after('source_id');
        });

        DB::table('journal_entries')
            ->orderBy('id')
            ->select('id')
            ->eachById(function (object $journal): void {
                DB::table('journal_entries')->where('id', $journal->id)->update([
                    'posting_key' => 'LEGACY_JOURNAL_'.$journal->id,
                ]);
            });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('posting_key', 191)->nullable(false)->change();
            $table->unique('posting_key', 'journal_posting_key_unique');
            $table->unique(['source_type', 'source_id'], 'journal_source_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_posting_key_unique');
            $table->dropUnique('journal_source_unique');
            $table->dropColumn('posting_key');
        });
    }
};
