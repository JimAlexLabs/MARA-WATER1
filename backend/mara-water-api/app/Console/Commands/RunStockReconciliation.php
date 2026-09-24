<?php

namespace App\Console\Commands;

use App\Services\StockReconciliationReportService;
use Illuminate\Console\Command;

/**
 * Round 5B Phase 3: weekly + monthly stock reconciliation Excel generation.
 * Invoked by Railway cron (same pattern as backups:run) or manually:
 *   php artisan stock-reconciliation:run weekly
 *   php artisan stock-reconciliation:run monthly
 */
class RunStockReconciliation extends Command
{
    protected $signature = 'stock-reconciliation:run {period=weekly : weekly|monthly}';

    protected $description = 'Generate and store a stock reconciliation Excel for the period';

    public function handle(StockReconciliationReportService $service): int
    {
        $period = $this->argument('period');
        if (!in_array($period, ['weekly', 'monthly'], true)) {
            $this->error('period must be weekly or monthly');
            return self::FAILURE;
        }

        $report = $period === 'monthly' ? $service->runMonthly() : $service->runWeekly();
        $this->info("Stored {$report->filename} ({$report->size_bytes} bytes) id={$report->id}");
        return self::SUCCESS;
    }
}
