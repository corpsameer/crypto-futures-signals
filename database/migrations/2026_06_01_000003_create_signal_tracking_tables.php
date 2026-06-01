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
        if (! Schema::hasTable('pasted_signals')) {
            Schema::create('pasted_signals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('trader_name', 191)->nullable()->index();
                $table->longText('raw_text');
                $table->json('parsed_payload')->nullable();
                $table->string('parse_status', 191)->default('pending')->index();
                $table->text('parse_error')->nullable();
                $table->string('source')->default('manual_paste');
                $table->timestamp('pasted_at')->nullable()->index();
                $table->timestamps();

                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('trade_signals')) {
            Schema::create('trade_signals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pasted_signal_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('trader_name', 191)->nullable()->index();
                $table->string('exchange')->default('coindcx');
                $table->string('symbol', 191)->index();
                $table->string('pair')->nullable();
                $table->string('market_type')->default('futures');
                $table->string('direction', 191)->index();
                $table->decimal('leverage', 8, 2)->nullable();
                $table->string('margin_mode')->nullable();
                $table->decimal('entry_min', 28, 12)->nullable();
                $table->decimal('entry_max', 28, 12)->nullable();
                $table->string('entry_type')->nullable();
                $table->decimal('stop_loss', 28, 12)->nullable();
                $table->decimal('tp1', 28, 12)->nullable();
                $table->decimal('tp2', 28, 12)->nullable();
                $table->decimal('tp3', 28, 12)->nullable();
                $table->decimal('tp4', 28, 12)->nullable();
                $table->timestamp('signal_time')->nullable()->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->string('status', 191)->default('pending_entry')->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('pasted_signal_id');
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('simulated_trades')) {
            Schema::create('simulated_trades', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('trade_signal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('symbol', 191)->index();
                $table->string('direction', 191)->index();
                $table->decimal('leverage', 8, 2)->nullable();
                $table->decimal('entry_price', 28, 12)->nullable();
                $table->timestamp('entry_triggered_at')->nullable()->index();
                $table->decimal('stop_loss', 28, 12)->nullable();
                $table->decimal('current_price', 28, 12)->nullable();
                $table->decimal('max_price', 28, 12)->nullable();
                $table->decimal('min_price', 28, 12)->nullable();
                $table->decimal('max_actual_price_move_percent', 12, 4)->nullable();
                $table->decimal('max_leveraged_pnl_percent', 12, 4)->nullable();
                $table->decimal('min_actual_price_move_percent', 12, 4)->nullable();
                $table->decimal('min_leveraged_pnl_percent', 12, 4)->nullable();
                $table->decimal('exit_price', 28, 12)->nullable();
                $table->string('exit_reason')->nullable();
                $table->timestamp('closed_at')->nullable()->index();
                $table->string('status', 191)->default('active')->index();
                $table->timestamp('tracking_until')->nullable()->index();
                $table->timestamps();

                $table->index('trade_signal_id');
                $table->index('user_id');
            });
        }

        if (! Schema::hasTable('trade_tracking_events')) {
            Schema::create('trade_tracking_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('simulated_trade_id')->constrained()->cascadeOnDelete();
                $table->foreignId('trade_signal_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event_type', 191)->index();
                $table->decimal('event_price', 28, 12);
                $table->decimal('actual_price_move_percent', 12, 4);
                $table->decimal('leveraged_pnl_percent', 12, 4);
                $table->timestamp('event_timestamp')->index();
                $table->json('metadata')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('simulated_trade_id');
                $table->index('trade_signal_id');
                $table->unique(['simulated_trade_id', 'event_type']);
            });
        }

        if (! Schema::hasTable('market_snapshots')) {
            Schema::create('market_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('trade_signal_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('simulated_trade_id')->nullable()->constrained()->nullOnDelete();
                $table->string('symbol', 191)->index();
                $table->string('snapshot_type', 191)->nullable()->index();
                $table->decimal('price', 28, 12)->nullable();
                $table->decimal('volume_24h', 28, 8)->nullable();
                $table->decimal('price_change_24h_percent', 12, 4)->nullable();
                $table->decimal('funding_rate', 12, 6)->nullable();
                $table->decimal('open_interest', 28, 8)->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamp('snapshot_at')->index();
                $table->timestamps();

                $table->index('trade_signal_id');
                $table->index('simulated_trade_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
        Schema::dropIfExists('trade_tracking_events');
        Schema::dropIfExists('simulated_trades');
        Schema::dropIfExists('trade_signals');
        Schema::dropIfExists('pasted_signals');
    }
};
