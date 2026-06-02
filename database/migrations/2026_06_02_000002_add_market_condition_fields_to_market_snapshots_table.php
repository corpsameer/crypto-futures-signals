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
        if (! Schema::hasTable('market_snapshots')) {
            return;
        }

        $this->addColumnIfMissing('btc_price', function (Blueprint $table): void {
            $table->decimal('btc_price', 28, 12)->nullable();
        });

        $this->addColumnIfMissing('btc_24h_change_percent', function (Blueprint $table): void {
            $table->decimal('btc_24h_change_percent', 12, 4)->nullable();
        });

        $this->addColumnIfMissing('eth_price', function (Blueprint $table): void {
            $table->decimal('eth_price', 28, 12)->nullable();
        });

        $this->addColumnIfMissing('eth_24h_change_percent', function (Blueprint $table): void {
            $table->decimal('eth_24h_change_percent', 12, 4)->nullable();
        });

        $this->addColumnIfMissing('market_condition', function (Blueprint $table): void {
            $table->string('market_condition')->nullable();
        });

        $this->addColumnIfMissing('captured_at', function (Blueprint $table): void {
            $table->timestamp('captured_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('market_snapshots')) {
            return;
        }

        $columns = [
            'btc_price',
            'btc_24h_change_percent',
            'eth_price',
            'eth_24h_change_percent',
            'market_condition',
            'captured_at',
        ];

        foreach ($columns as $column) {
            if (Schema::hasColumn('market_snapshots', $column)) {
                Schema::table('market_snapshots', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function addColumnIfMissing(string $column, callable $callback): void
    {
        if (Schema::hasColumn('market_snapshots', $column)) {
            return;
        }

        Schema::table('market_snapshots', function (Blueprint $table) use ($callback): void {
            $callback($table);
        });
    }
};
