<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_types', function (Blueprint $table): void {
            $table->boolean('allows_change')
                ->default(false)
                ->after('image');
        });

        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->decimal('tendered_amount', 20, 4)
                ->nullable()
                ->after('amount');
            $table->decimal('change_amount', 20, 4)
                ->default(0)
                ->after('tendered_amount');
        });

        DB::table('payment_types')
            ->where(function ($query): void {
                $query->whereRaw('LOWER(slug) = ?', ['cash'])
                    ->orWhereRaw('LOWER(name) = ?', ['cash']);
            })
            ->update(['allows_change' => true]);

        DB::table('sale_payments')->update([
            'tendered_amount' => DB::raw('amount'),
            'change_amount' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table): void {
            $table->dropColumn(['tendered_amount', 'change_amount']);
        });

        Schema::table('payment_types', function (Blueprint $table): void {
            $table->dropColumn('allows_change');
        });
    }
};
