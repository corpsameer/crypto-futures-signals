<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_signals', function (Blueprint $table): void {
            if (! Schema::hasColumn('trade_signals', 'entry_price')) {
                $table->decimal('entry_price', 28, 12)->nullable()->after('entry_type');
            }

            if (! Schema::hasColumn('trade_signals', 'entry_price_min')) {
                $table->decimal('entry_price_min', 28, 12)->nullable()->after('entry_price');
            }

            if (! Schema::hasColumn('trade_signals', 'entry_price_max')) {
                $table->decimal('entry_price_max', 28, 12)->nullable()->after('entry_price_min');
            }
        });

        DB::table('trade_signals')->update([
            'entry_type' => DB::raw("COALESCE(entry_type, 'single')"),
            'entry_price' => DB::raw('COALESCE(entry_price, CASE WHEN entry_min IS NOT NULL AND entry_max IS NOT NULL THEN (entry_min + entry_max) / 2 WHEN entry_min IS NOT NULL THEN entry_min ELSE entry_max END)'),
            'entry_price_min' => DB::raw('COALESCE(entry_price_min, entry_min, entry_max, entry_price)'),
            'entry_price_max' => DB::raw('COALESCE(entry_price_max, entry_max, entry_min, entry_price)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('trade_signals', function (Blueprint $table): void {
            if (Schema::hasColumn('trade_signals', 'entry_price_max')) {
                $table->dropColumn('entry_price_max');
            }

            if (Schema::hasColumn('trade_signals', 'entry_price_min')) {
                $table->dropColumn('entry_price_min');
            }

            if (Schema::hasColumn('trade_signals', 'entry_price')) {
                $table->dropColumn('entry_price');
            }
        });
    }
};
