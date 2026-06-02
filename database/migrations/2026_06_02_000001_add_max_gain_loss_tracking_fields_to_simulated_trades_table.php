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
        if (! Schema::hasTable('simulated_trades')) {
            return;
        }

        Schema::table('simulated_trades', function (Blueprint $table): void {
            if (! Schema::hasColumn('simulated_trades', 'max_price_after_entry')) {
                $table->decimal('max_price_after_entry', 28, 12)->nullable()->after('current_price');
            }

            if (! Schema::hasColumn('simulated_trades', 'min_price_after_entry')) {
                $table->decimal('min_price_after_entry', 28, 12)->nullable()->after('max_price_after_entry');
            }

            if (! Schema::hasColumn('simulated_trades', 'max_actual_gain_percent')) {
                $table->decimal('max_actual_gain_percent', 12, 4)->nullable()->after('min_leveraged_pnl_percent');
            }

            if (! Schema::hasColumn('simulated_trades', 'max_actual_loss_percent')) {
                $table->decimal('max_actual_loss_percent', 12, 4)->nullable()->after('max_actual_gain_percent');
            }

            if (! Schema::hasColumn('simulated_trades', 'max_gain_percent')) {
                $table->decimal('max_gain_percent', 12, 4)->nullable()->after('max_actual_loss_percent');
            }

            if (! Schema::hasColumn('simulated_trades', 'max_loss_percent')) {
                $table->decimal('max_loss_percent', 12, 4)->nullable()->after('max_gain_percent');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('simulated_trades')) {
            return;
        }

        $columns = [
            'max_price_after_entry',
            'min_price_after_entry',
            'max_actual_gain_percent',
            'max_actual_loss_percent',
            'max_gain_percent',
            'max_loss_percent',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('simulated_trades', $column)) {
                Schema::table('simulated_trades', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
