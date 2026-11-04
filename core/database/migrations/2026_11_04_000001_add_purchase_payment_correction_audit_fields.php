<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `purchase_payment` MODIFY `status` ENUM('DRAFT', 'POSTED', 'VOID', 'REVERSED') NOT NULL DEFAULT 'DRAFT'");
        }

        Schema::table('purchase_payment', function (Blueprint $table): void {
            $table->foreignId('voided_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
            $table->text('void_reason')->nullable()->after('voided_at');
            $table->foreignId('reversed_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->text('reversal_reason')->nullable()->after('reversed_at');
            $table->foreignId('reversal_journal_id')->nullable()->after('reversal_reason')->constrained('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_payment', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_journal_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::table('purchase_payment')->where('status', 'REVERSED')->update(['status' => 'VOID']);
            DB::statement("ALTER TABLE `purchase_payment` MODIFY `status` ENUM('DRAFT', 'POSTED', 'VOID') NOT NULL DEFAULT 'DRAFT'");
        }
    }
};
