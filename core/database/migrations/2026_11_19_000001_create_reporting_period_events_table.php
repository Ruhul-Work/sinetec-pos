<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_period_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporting_period_id')->constrained('reporting_periods')->restrictOnDelete();
            $table->enum('action', ['OPENED', 'CLOSED', 'REOPENED']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['reporting_period_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_period_events');
    }
};
