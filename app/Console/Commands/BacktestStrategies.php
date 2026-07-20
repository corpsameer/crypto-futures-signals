<?php

namespace App\Console\Commands;

use App\Models\StrategyBacktestRun;
use App\Models\StrategyDefinition;
use App\Models\StrategyTradeResult;
use App\Services\StrategyBacktestService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use Throwable;

class BacktestStrategies extends Command
{
    protected $signature = 'strategies:backtest
        {--from= : Process trades whose actual entry-trigger time is on or after this date/time}
        {--capital=500 : Starting capital for a non-incremental backtest}
        {--strategy= : Process only the strategy with this exact code}
        {--reset : Delete prior results for the selected strategy scope before running}
        {--incremental : Process only trades not already processed for each strategy}
        {--force : Execute reset without interactive confirmation}';

    protected $description = 'Backtest active trading strategies against entry-triggered simulated trades.';

    public function handle(StrategyBacktestService $backtestService): int
    {
        $capital = $this->validatedCapital();
        if ($capital === null) {
            return self::FAILURE;
        }

        $from = $this->validatedFrom();
        if ($from === false) {
            return self::FAILURE;
        }

        $incremental = (bool) $this->option('incremental');
        $reset = (bool) $this->option('reset');
        $strategyCode = $this->option('strategy');
        $strategyCode = is_string($strategyCode) && $strategyCode !== '' ? $strategyCode : null;

        if ($reset && $incremental) {
            $this->error('The --reset and --incremental options cannot be used together. Reset deletes the history incremental mode relies upon.');

            return self::FAILURE;
        }

        $strategies = $this->validatedStrategies($strategyCode);
        if ($strategies === null) {
            return self::FAILURE;
        }

        if ($incremental && $this->capitalWasExplicitlySupplied()) {
            $this->warn('Incremental processing continues from each strategy current_capital; the --capital option will not reset strategy capital.');
        }

        $this->printExecutionSummary($incremental, $strategyCode, $from, $capital, $reset);

        if ($reset && ! $this->confirmReset($strategyCode, $strategies)) {
            $this->info('Reset declined. No results were deleted and no backtest run was created.');

            return self::SUCCESS;
        }

        if ($reset) {
            $deleted = $this->deletePriorResults($strategies->pluck('id')->all());
            $this->info("Deleted {$deleted} prior strategy trade result row(s).");
        }

        $now = now();
        $run = StrategyBacktestRun::query()->create([
            'name' => $this->runName($incremental, $strategyCode, $now),
            'started_at' => $now,
            'completed_at' => null,
            'starting_capital' => $capital,
            'status' => StrategyBacktestRun::STATUS_RUNNING,
            'notes' => $this->initialNotes($incremental, $strategyCode, $from, $reset),
        ]);

        $this->line('Backtest Run ID: '.$run->getKey());
        $this->newLine();

        try {
            $summary = $backtestService->run($run, [
                'strategy_code' => $strategyCode,
                'from' => $from ?: null,
                'starting_capital' => $capital,
                'incremental' => $incremental,
            ]);

            $completedAt = now();
            $run->forceFill([
                'status' => StrategyBacktestRun::STATUS_COMPLETED,
                'completed_at' => $completedAt,
                'notes' => $run->notes."\nSummary: strategies={$summary['strategies_processed']}; trades_loaded={$summary['trades_loaded']}; results_created={$summary['results_created']}; duplicates_skipped={$summary['results_skipped_as_duplicates']}.",
            ])->save();

            $this->printResultSummary($summary, $run, $completedAt);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $completedAt = now();
            $message = $this->safeExceptionMessage($exception);

            $run->forceFill([
                'status' => StrategyBacktestRun::STATUS_FAILED,
                'completed_at' => $completedAt,
                'notes' => $run->notes."\nFailure: {$message}",
            ])->save();

            Log::error('Strategy backtest command failed.', [
                'backtest_run_id' => $run->getKey(),
                'strategy_code' => $strategyCode,
                'incremental' => $incremental,
                'reset' => $reset,
                'exception' => $exception,
            ]);

            $this->error('Strategy backtest failed. See application logs for details. Run ID: '.$run->getKey());

            return self::FAILURE;
        }
    }

    private function validatedCapital(): ?string
    {
        $capital = $this->option('capital') ?? '500';

        if (! is_numeric($capital)) {
            $this->error('The --capital option must be numeric.');

            return null;
        }

        if ((float) $capital <= 0.0) {
            $this->error('The --capital option must be greater than zero.');

            return null;
        }

        return (string) $capital;
    }

    private function validatedFrom(): Carbon|false|null
    {
        $from = $this->option('from');
        if ($from === null || $from === '') {
            return null;
        }

        if (! is_string($from)) {
            $this->error('The --from option must be a date or date/time string.');

            return false;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
                return Carbon::createFromFormat('Y-m-d', $from, config('app.timezone'))->startOfDay();
            }

            return Carbon::parse($from, config('app.timezone'));
        } catch (Exception) {
            $this->error('The --from option must be a valid date/time, for example 2026-06-03 or 2026-06-03 14:30:00.');

            return false;
        }
    }

    private function validatedStrategies(?string $strategyCode): mixed
    {
        $query = StrategyDefinition::query()->where('is_active', true)->orderBy('id');

        if ($strategyCode !== null) {
            $strategy = (clone $query)->where('code', $strategyCode)->first();
            if (! $strategy instanceof StrategyDefinition) {
                $this->error("Active strategy [{$strategyCode}] was not found. Check the exact strategy_definitions.code value and that it is active.");

                return null;
            }

            return collect([$strategy]);
        }

        return $query->get(['id', 'code']);
    }

    private function capitalWasExplicitlySupplied(): bool
    {
        return collect($_SERVER['argv'] ?? [])->contains(fn (string $argument): bool => $argument === '--capital' || str_starts_with($argument, '--capital='));
    }

    private function printExecutionSummary(bool $incremental, ?string $strategyCode, Carbon|null $from, string $capital, bool $reset): void
    {
        $this->info('Strategy backtest configuration:');
        $this->line('Mode: '.($incremental ? 'Incremental' : 'Full'));
        $this->line('Strategy: '.($strategyCode ?? 'All Active'));
        $this->line('From: '.($from ? $from->toDateTimeString().' '.$from->getTimezone()->getName() : 'All Eligible Trades'));
        $this->line('Starting Capital: '.($incremental ? 'current_capital per strategy' : $capital));
        $this->line('Reset: '.($reset ? 'Yes' : 'No'));
        $this->newLine();
    }

    private function confirmReset(?string $strategyCode, mixed $strategies): bool
    {
        $codes = $strategies->pluck('code')->implode(', ');
        $this->warn('Reset will delete prior strategy_trade_results for: '.($strategyCode ?? 'all active strategies').' ['.$codes.'].');
        $this->warn('Backtest run records and unrelated strategy results will be preserved.');

        if ((bool) $this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Reset requires --force when running non-interactively.');

            return false;
        }

        return $this->confirm('Continue with this destructive reset?', false);
    }

    private function deletePriorResults(array $strategyIds): int
    {
        return DB::transaction(fn (): int => StrategyTradeResult::query()
            ->whereIn('strategy_definition_id', $strategyIds)
            ->delete());
    }

    private function runName(bool $incremental, ?string $strategyCode, Carbon $timestamp): string
    {
        if ($strategyCode !== null) {
            return $strategyCode.' Backtest - '.$timestamp->toDateTimeString();
        }

        return ($incremental ? 'Incremental' : 'Full').' Strategy Backtest - '.$timestamp->toDateTimeString();
    }

    private function initialNotes(bool $incremental, ?string $strategyCode, Carbon|null $from, bool $reset): string
    {
        $notes = [
            'mode='.($incremental ? 'incremental' : 'full'),
            'strategy='.($strategyCode ?? 'all active strategies'),
            'from='.($from ? $from->toDateTimeString().' '.$from->getTimezone()->getName() : 'all eligible trades'),
            'reset='.($reset ? 'yes' : 'no'),
        ];

        if ($incremental) {
            $notes[] = 'incremental_starting_capital=current_capital per strategy';
        }

        return implode('; ', $notes);
    }

    private function printResultSummary(array $summary, StrategyBacktestRun $run, Carbon $completedAt): void
    {
        $this->table(
            ['Strategy code', 'Starting capital', 'Ending capital', 'Trades processed', 'Wins', 'Losses', 'Open', 'Skipped', 'Net P&L'],
            collect($summary['strategies'])->map(fn (array $strategy): array => [
                $strategy['strategy_code'],
                $strategy['starting_capital'],
                $strategy['ending_capital'],
                $strategy['trades_processed'],
                $strategy['wins'],
                $strategy['losses'],
                $strategy['open'],
                $strategy['skipped'],
                $strategy['net_pnl'],
            ])->all()
        );

        $this->info('Backtest completed.');
        $this->line('Strategies processed: '.$summary['strategies_processed']);
        $this->line('Trades loaded: '.$summary['trades_loaded']);
        $this->line('Results created: '.$summary['results_created']);
        $this->line('Duplicate results skipped: '.$summary['results_skipped_as_duplicates']);
        $this->line('Run ID: '.$run->getKey());
        $this->line('Final status: '.StrategyBacktestRun::STATUS_COMPLETED);
        $this->line('Completion time: '.$completedAt->toDateTimeString());
    }

    private function safeExceptionMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message === '' ? 'Unhandled backtest exception.' : str($message)->limit(300)->toString();
    }
}
