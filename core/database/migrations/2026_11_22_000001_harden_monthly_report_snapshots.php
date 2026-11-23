<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addVersionColumn();
        $this->addStatusColumn();
        $this->addChecksumColumn();
        $this->addNoteColumn();
        $this->addSupersedesColumn();
        $this->addSupersededByColumn();
        $this->addSupersededAtColumn();

        DB::table('monthly_report_snapshots')
            ->whereNull('version')
            ->update(['version' => 1]);
        DB::table('monthly_report_snapshots')
            ->whereNull('status')
            ->update(['status' => 'CURRENT']);

        if (! Schema::hasIndex('monthly_report_snapshots', 'monthly_snapshot_period_type_version_unique')) {
            Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
                $table->unique(
                    ['reporting_period_id', 'report_type', 'version'],
                    'monthly_snapshot_period_type_version_unique',
                );
            });
        }

        if (! Schema::hasIndex('monthly_report_snapshots', 'monthly_snapshot_current_lookup')) {
            Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
                $table->index(
                    ['reporting_period_id', 'report_type', 'status'],
                    'monthly_snapshot_current_lookup',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('monthly_report_snapshots', 'monthly_snapshot_period_type_version_unique')) {
            Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
                $table->dropUnique('monthly_snapshot_period_type_version_unique');
            });
        }

        if (Schema::hasIndex('monthly_report_snapshots', 'monthly_snapshot_current_lookup')) {
            Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
                $table->dropIndex('monthly_snapshot_current_lookup');
            });
        }

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supersedes_snapshot_id');
            $table->dropConstrainedForeignId('superseded_by');
            $table->dropColumn([
                'version',
                'status',
                'checksum',
                'note',
                'superseded_at',
            ]);
        });
    }

    private function addVersionColumn(): void
    {
        if (Schema::hasColumn('monthly_report_snapshots', 'version')) {
            return;
        }

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('report_type');
        });
    }

    private function addStatusColumn(): void
    {
        if (Schema::hasColumn('monthly_report_snapshots', 'status')) {
            return;
        }

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->string('status', 20)->default('CURRENT')->after('version');
        });
    }

    private function addChecksumColumn(): void
    {
        if (Schema::hasColumn('monthly_report_snapshots', 'checksum')) {
            return;
        }

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->char('checksum', 64)->nullable()->after('payload');
        });
    }

    private function addNoteColumn(): void
    {
        if (Schema::hasColumn('monthly_report_snapshots', 'note')) {
            return;
        }

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->text('note')->nullable()->after('checksum');
        });
    }

    private function addSupersedesColumn(): void
    {
        if (Schema::hasColumn('monthly_report_snapshots', 'supersedes_snapshot_id')) {
            return;
        }

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->foreignId('supersedes_snapshot_id')
                ->nullable()
                ->after('note')
                ->constrained('monthly_report_snapshots')
                ->restrictOnDelete();
        });
    }

    private function addSupersededByColumn(): void
    {
        if (Schema::hasColumn('monthly_report_snapshots', 'superseded_by')) {
            return;
        }

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->foreignId('superseded_by')
                ->nullable()
                ->after('supersedes_snapshot_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    private function addSupersededAtColumn(): void
    {
        if (Schema::hasColumn('monthly_report_snapshots', 'superseded_at')) {
            return;
        }

        Schema::table('monthly_report_snapshots', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('superseded_by');
        });
    }
};
