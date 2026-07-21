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
        Schema::table('strategy_backtest_runs', function (Blueprint $table): void {
            $table->string('source_scope', 191)->nullable()->after('starting_capital')->index('strategy_backtest_runs_source_scope_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('strategy_backtest_runs', function (Blueprint $table): void {
            $table->dropIndex('strategy_backtest_runs_source_scope_index');
            $table->dropColumn('source_scope');
        });
    }
};
