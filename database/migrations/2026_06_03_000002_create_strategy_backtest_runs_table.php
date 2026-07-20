<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('strategy_backtest_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->decimal('starting_capital', 18, 8)->default(500);
            $table->string('status')->default('running');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategy_backtest_runs');
    }
};
