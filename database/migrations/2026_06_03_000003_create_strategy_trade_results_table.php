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
        Schema::create('strategy_trade_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('strategy_backtest_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_definition_id')->constrained()->restrictOnDelete();
            $table->foreignId('trade_signal_id')->constrained()->restrictOnDelete();
            $table->foreignId('simulated_trade_id')->constrained()->restrictOnDelete();
            $table->string('symbol', 191);
            $table->string('direction', 191);
            $table->string('trader_name', 191)->nullable();
            $table->decimal('capital_before', 18, 8);
            $table->decimal('allocation_percent', 8, 4);
            $table->decimal('allocated_capital', 18, 8);
            $table->decimal('entry_price', 28, 12);
            $table->timestamp('entry_time');
            $table->decimal('exit_price', 28, 12)->nullable();
            $table->timestamp('exit_time')->nullable();
            $table->string('exit_event_type', 191)->nullable();
            $table->decimal('exit_leveraged_pnl_percent', 18, 8)->nullable();
            $table->decimal('gross_pnl', 18, 8)->default(0);
            $table->decimal('fees', 18, 8)->default(0);
            $table->decimal('net_pnl', 18, 8)->default(0);
            $table->decimal('capital_after', 18, 8);
            $table->decimal('return_percent', 18, 8)->nullable();
            $table->string('result_status', 191);
            $table->boolean('sl_hit_first')->default(false);
            $table->timestamp('sl_hit_time')->nullable();
            $table->boolean('post_sl_recovered')->default(false);
            $table->string('post_sl_first_recovery_event', 191)->nullable();
            $table->decimal('post_sl_first_recovery_price', 28, 12)->nullable();
            $table->timestamp('post_sl_first_recovery_time')->nullable();
            $table->decimal('post_sl_max_gain_percent', 18, 8)->nullable();
            $table->decimal('post_sl_max_gain_price', 28, 12)->nullable();
            $table->timestamps();

            $table->unique(
                ['strategy_backtest_run_id', 'strategy_definition_id', 'simulated_trade_id'],
                'strategy_results_run_definition_trade_unique'
            );
            $table->index(['strategy_definition_id', 'entry_time'], 'strategy_results_definition_entry_time_index');
            $table->index(['strategy_definition_id', 'result_status'], 'strategy_results_definition_status_index');
            $table->index(['strategy_backtest_run_id', 'strategy_definition_id'], 'strategy_results_run_definition_index');
            $table->index('simulated_trade_id');
            $table->index(['strategy_definition_id', 'post_sl_recovered'], 'strategy_results_definition_recovered_index');
            $table->index('symbol');
            $table->index('direction');
            $table->index('trader_name');
            $table->index('exit_event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategy_trade_results');
    }
};
