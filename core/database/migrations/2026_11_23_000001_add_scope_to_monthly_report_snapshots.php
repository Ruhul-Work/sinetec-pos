<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->string('scope_type', 20)->default('COMPANY')->after('report_type');
            $table->foreignId('branch_id')
                ->nullable()
                ->after('scope_type')
                ->constrained('branches')
                ->restrictOnDelete();
            $table->string('scope_key', 80)->default('COMPANY')->after('branch_id');
        });

        DB::table('monthly_report_snapshots')->update([
            'scope_type' => 'COMPANY',
            'scope_key' => 'COMPANY',
        ]);

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->dropUnique('monthly_snapshot_period_type_version_unique');
            $table->dropIndex('monthly_snapshot_current_lookup');
            $table->unique(
                ['reporting_period_id', 'report_type', 'scope_key', 'version'],
                'monthly_snapshot_period_scope_version_unique',
            );
            $table->index(
                ['reporting_period_id', 'report_type', 'scope_key', 'status'],
                'monthly_snapshot_scope_current_lookup',
            );
        });
    }

    public function down(): void
    {
        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->dropUnique('monthly_snapshot_period_scope_version_unique');
            $table->dropIndex('monthly_snapshot_scope_current_lookup');
            $table->unique(
                ['reporting_period_id', 'report_type', 'version'],
                'monthly_snapshot_period_type_version_unique',
            );
            $table->index(
                ['reporting_period_id', 'report_type', 'status'],
                'monthly_snapshot_current_lookup',
            );
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['scope_type', 'scope_key']);
        });
    }
};
