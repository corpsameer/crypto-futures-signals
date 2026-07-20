<?php

namespace Database\Seeders;

use App\Models\StrategyDefinition;
use Illuminate\Database\Seeder;

class StrategyDefinitionSeeder extends Seeder
{
    /**
     * Seed the locked strategy definitions.
     */
    public function run(): void
    {
        foreach ($this->strategies() as $code => $attributes) {
            $strategy = StrategyDefinition::query()->firstOrNew(['code' => $code]);

            $strategy->fill($attributes);

            if (! $strategy->exists) {
                $strategy->current_capital = '500';
            }

            $strategy->save();
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function strategies(): array
    {
        return [
            'QUICK_SCALP_3_5' => [
                'name' => 'Quick Scalp 3.5%',
                'code' => 'QUICK_SCALP_3_5',
                'description' => 'Exit at GAIN_3_5_PERCENT, or at SL_HIT when SL occurs before the target. Any recovery after SL is retained for analytics only and is not counted as a winning exit.',
                'strategy_type' => 'TARGET_BEFORE_STOP',
                'target_event_type' => 'GAIN_3_5_PERCENT',
                'stop_event_type' => 'SL_HIT',
                'allocation_percent' => '30',
                'starting_capital' => '500',
                'loss_cap_percent' => null,
                'uses_post_sl_recovery' => false,
                'is_active' => true,
            ],
            'TP2_RUNNER' => [
                'name' => 'TP2 Runner',
                'code' => 'TP2_RUNNER',
                'description' => 'Exit at TP2_HIT, or at SL_HIT when SL occurs before TP2. Any TP or recovery event after SL is retained for analytics only and is not counted as a winning exit.',
                'strategy_type' => 'TARGET_BEFORE_STOP',
                'target_event_type' => 'TP2_HIT',
                'stop_event_type' => 'SL_HIT',
                'allocation_percent' => '20',
                'starting_capital' => '500',
                'loss_cap_percent' => null,
                'uses_post_sl_recovery' => false,
                'is_active' => true,
            ],
            'RECOVERY_HOLD_TP1' => [
                'name' => 'Recovery Hold TP1',
                'code' => 'RECOVERY_HOLD_TP1',
                'description' => 'Ignore the normal SL as an exit and continue tracking until TP1_HIT or POST_SL_TP1_HIT. If TP1 never occurs, use final_exit_price when available, otherwise the last tracked event price. Loss is capped at 100% of allocated capital.',
                'strategy_type' => 'RECOVERY_HOLD',
                'target_event_type' => 'TP1_HIT',
                'stop_event_type' => null,
                'allocation_percent' => '10',
                'starting_capital' => '500',
                'loss_cap_percent' => '100',
                'uses_post_sl_recovery' => true,
                'is_active' => true,
            ],
        ];
    }
}
