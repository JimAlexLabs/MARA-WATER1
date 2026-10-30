<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\DeletionLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Every delete in the app (soft or hard) is kept here so removals are
 * never silent. Soft-deleted rows also remain on their own tables via
 * deleted_at; this log is the cross-entity trail of who removed what.
 */
class DeletionLogService
{
    /** Models / tables we never log (noise or self-recursion). */
    private const SKIP = [
        DeletionLog::class,
        Audit::class,
        \Illuminate\Notifications\DatabaseNotification::class,
    ];

    private const SKIP_TABLES = [
        'deletion_logs', 'audits', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens',
        'password_reset_tokens', 'migrations',
    ];

    public function record(Model $model, ?string $forcedAction = null): void
    {
        foreach (self::SKIP as $class) {
            if ($model instanceof $class) {
                return;
            }
        }

        $table = $model->getTable();
        if (in_array($table, self::SKIP_TABLES, true)) {
            return;
        }

        $usesSoft = in_array(SoftDeletes::class, class_uses_recursive($model), true);
        $action = $forcedAction ?: ($usesSoft ? 'soft_delete' : 'delete');

        $attrs = $model->getAttributes();
        // Never store password hashes in the delete trail.
        unset($attrs['password'], $attrs['password_hash'], $attrs['remember_token']);

        $payload = [
            'user_id' => Auth::id(),
            'entity' => $table,
            'entity_id' => (string) ($model->getKey() ?? ''),
            'action' => $action,
            'snapshot' => $attrs,
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ];

        try {
            DeletionLog::create($payload);
        } catch (\Throwable $e) {
            \Log::warning('deletion_log_write_failed', ['error' => $e->getMessage(), 'entity' => $table]);
        }

        // Mirror into audits when that table exists (already in backups).
        try {
            if (Schema::hasTable('audits')) {
                Audit::create([
                    'user_id' => Auth::id(),
                    'action' => $action,
                    'entity' => $table,
                    'entity_id' => (string) ($model->getKey() ?? ''),
                    'diff_json' => ['before' => $attrs],
                    'meta_json' => ['source' => 'deletion_log'],
                    'ip' => request()?->ip(),
                    'user_agent' => substr((string) request()?->userAgent(), 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            // audits schema may differ; deletion_logs is the source of truth.
        }
    }
}
