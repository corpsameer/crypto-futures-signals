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
        Schema::table('trade_signals', function (Blueprint $table): void {
            $table->string('signal_source', 191)->default('telegram')->index('trade_signals_signal_source_index');
            $table->string('source_image_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trade_signals', function (Blueprint $table): void {
            $table->dropIndex('trade_signals_signal_source_index');
            $table->dropColumn(['signal_source', 'source_image_path']);
        });
    }
};
