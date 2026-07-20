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
        Schema::create('strategy_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->string('strategy_type');
            $table->string('target_event_type')->nullable();
            $table->string('stop_event_type')->nullable();
            $table->decimal('allocation_percent', 8, 4);
            $table->decimal('starting_capital', 18, 8)->default(500);
            $table->decimal('current_capital', 18, 8)->default(500);
            $table->decimal('loss_cap_percent', 8, 4)->nullable();
            $table->boolean('uses_post_sl_recovery')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategy_definitions');
    }
};
