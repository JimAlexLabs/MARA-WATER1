<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Phase 11 (Backups: never lose data). `php artisan backups:run` --
 * takes a full snapshot (reason='scheduled' by default) and prunes
 * anything past the retention window. Triggered daily by a dedicated
 * Railway Cron Schedule service (`mara-water1-backup-cron` in
 * .railway/railway.ts) that calls this command directly -- see that
 * file and docs/BACKUPS.md for how (and its current deployment status).
 */
class RunScheduledBackup extends Command
{
    protected $signature = 'backups:run {reason=scheduled}';

    protected $description = 'Take a full snapshot backup of all business data and prune old backups past the retention window';

    public function handle(BackupService $backups): int
    {
        $backup = $backups->snapshot($this->argument('reason'), null);
        $this->info("Backup {$backup->id} created: " . array_sum($backup->table_row_counts) . ' rows, ' . round($backup->size_bytes / 1024, 1) . ' KB.');

        $deleted = $backups->cleanup();
        if ($deleted > 0) {
            $this->info("Pruned {$deleted} backup(s) older than " . BackupService::RETENTION_DAYS . ' days.');
        }

        return self::SUCCESS;
    }
}
