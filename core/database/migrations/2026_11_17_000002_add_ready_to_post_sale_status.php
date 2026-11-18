<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->enum('status', ['DRAFT', 'READY_TO_POST', 'POSTED', 'VOID'])
                ->default('DRAFT')
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('sales')
            ->where('status', 'READY_TO_POST')
            ->update(['status' => 'DRAFT']);

        Schema::table('sales', function (Blueprint $table): void {
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])
                ->default('DRAFT')
                ->change();
        });
    }
};
