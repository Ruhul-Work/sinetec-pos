<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Preserve the source decision of MRP lines generated before the hardening
     * columns existed. Those rows used title-case source hints and received
     * zero defaults for the new Make/Buy quantities.
     */
    public function up(): void
    {
        DB::table('mrp_component_lines')
            ->whereRaw('UPPER(source_hint) = ?', ['PURCHASE'])
            ->update([
                'source_hint' => 'PURCHASE',
                'make_quantity' => 0,
                'buy_quantity' => DB::raw('net_requirement'),
            ]);

        DB::table('mrp_component_lines')
            ->whereRaw('UPPER(source_hint) = ?', ['PRODUCTION'])
            ->update([
                'source_hint' => 'PRODUCTION',
                'make_quantity' => DB::raw('net_requirement'),
                'buy_quantity' => 0,
            ]);
    }

    /**
     * Restore the legacy representation when only this migration is rolled
     * back. The following hardening migration rollback removes the columns.
     */
    public function down(): void
    {
        DB::table('mrp_component_lines')
            ->where('source_hint', 'PURCHASE')
            ->update([
                'source_hint' => 'Purchase',
                'make_quantity' => 0,
                'buy_quantity' => 0,
            ]);

        DB::table('mrp_component_lines')
            ->where('source_hint', 'PRODUCTION')
            ->update([
                'source_hint' => 'Production',
                'make_quantity' => 0,
                'buy_quantity' => 0,
            ]);
    }
};
