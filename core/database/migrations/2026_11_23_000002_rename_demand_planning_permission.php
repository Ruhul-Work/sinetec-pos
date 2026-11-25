<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $newPermissionExists = DB::table('permissions')
            ->where('key', 'planning.demand_plans')
            ->exists();

        if (! $newPermissionExists) {
            DB::table('permissions')
                ->where('key', 'reporting.demand_plans')
                ->update([
                    'key' => 'planning.demand_plans',
                    'name' => 'Demand Plans',
                    'module' => 'Supply Planning',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        $oldPermissionExists = DB::table('permissions')
            ->where('key', 'reporting.demand_plans')
            ->exists();

        if (! $oldPermissionExists) {
            DB::table('permissions')
                ->where('key', 'planning.demand_plans')
                ->update([
                    'key' => 'reporting.demand_plans',
                    'module' => 'Supply Planning',
                    'updated_at' => now(),
                ]);
        }
    }
};
