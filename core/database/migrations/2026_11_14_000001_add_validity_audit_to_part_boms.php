<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_boms', function (Blueprint $table): void {
            $table->foreignId('validity_updated_by')
                ->nullable()
                ->after('effective_to')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('validity_updated_at')
                ->nullable()
                ->after('validity_updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('part_boms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('validity_updated_by');
            $table->dropColumn('validity_updated_at');
        });
    }
};
