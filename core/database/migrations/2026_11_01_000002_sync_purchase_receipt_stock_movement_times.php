<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MOVEMENT_TABLES = [
        'raw_material_stock_movements',
        'part_stock_movements',
    ];

    public function up(): void
    {
        $receipts = DB::table('purchase_receipt')
            ->whereNotNull('posted_at')
            ->get(['id', 'receipt_date', 'posted_at']);

        foreach ($receipts as $receipt) {
            $postedAt = CarbonImmutable::parse($receipt->posted_at);
            $movementAt = CarbonImmutable::parse($receipt->receipt_date)->setTime(
                $postedAt->hour,
                $postedAt->minute,
                $postedAt->second,
            );

            foreach (self::MOVEMENT_TABLES as $table) {
                DB::table($table)
                    ->where('source_type', 'purchase_receipt')
                    ->where('source_id', $receipt->id)
                    ->update(['movement_at' => $movementAt]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::MOVEMENT_TABLES as $table) {
            DB::table($table)
                ->where('source_type', 'purchase_receipt')
                ->orderBy('id')
                ->eachById(function (object $movement) use ($table): void {
                    DB::table($table)->where('id', $movement->id)->update([
                        'movement_at' => CarbonImmutable::parse($movement->movement_at)->startOfDay(),
                    ]);
                }, 100, 'id', 'id');
        }
    }
};
