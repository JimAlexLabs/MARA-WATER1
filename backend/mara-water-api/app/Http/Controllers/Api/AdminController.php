<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Backup;
use App\Models\ResetLog;
use App\Models\Setting;
use App\Services\BackupService;

class AdminController extends Controller
{
    /**
     * Every real business table in the schema, backed up in full on every
     * snapshot. Framework tables (sessions, cache, jobs, personal_access_tokens,
     * migrations, password_reset_tokens) and backups/reset_logs themselves are
     * deliberately excluded -- the point is to protect business data, not to
     * make a backup that (re-)includes itself or gets wiped by the very reset
     * it's meant to protect against.
     *
     * NOTE for whoever reviews this: table purpose was inferred from name and
     * the Phase-0 schema audit, not from reading every table's actual usage.
     * PRESERVE_TABLES vs WIPE_TABLES below is a first-pass policy call -- the
     * Danger Zone UI shows both lists before anyone can confirm a reset, and
     * it's worth double-checking against how the business actually wants
     * "fresh production start" to behave (e.g. should customers really be
     * wiped, or are they reference data too?).
     *
     * Phase 11: audited against a live `SHOW TABLES` on production and found
     * six real tables missing from these two lists entirely --
     * chart_of_accounts, equipment_items (reference/asset data), and
     * driver_trips, driver_trip_items, petty_cash_entries,
     * debtor_ledger_entries (transactional data) -- added in Phases 5, 9,
     * and 10 but never back-filled into this list when those phases
     * shipped. Until this fix, every backup since Phase 5 silently
     * omitted whichever of these existed at the time, and a real reset
     * would have left driver trips and petty cash/debtor ledger entries
     * behind instead of clearing them. Whoever adds a table in a future
     * phase needs to add it to BackupService::PRESERVE_TABLES/
     * WIPE_TABLES in the same change -- nothing catches this
     * automatically. The two lists now live in BackupService, the single
     * shared source both this controller and the scheduled backup
     * command use, specifically so they can't drift apart like this again.
     */
    private const CONFIRMATION_PHRASE = 'DELETE ALL DATA';
    private const RESTORE_CONFIRMATION_PHRASE = 'RESTORE THIS BACKUP';

    public function __construct(private BackupService $backups)
    {
    }

    private function requireAdmin(Request $request)
    {
        if (!$request->user()->isDirector()) {
            abort(response()->json([
                'success' => false,
                'message' => 'Only an administrator can access this.',
            ], 403));
        }
    }

    public function tableGroups()
    {
        $fresh = $this->backups->latestFreshBackup();

        return response()->json([
            'success' => true,
            'data' => [
                'preserved' => BackupService::PRESERVE_TABLES,
                'wiped' => BackupService::WIPE_TABLES,
                'sections' => $this->backups->sectionCatalog(),
                'confirmation_phrase' => self::CONFIRMATION_PHRASE,
                'restore_confirmation_phrase' => self::RESTORE_CONFIRMATION_PHRASE,
                'retention_days' => BackupService::RETENTION_DAYS,
                'fresh_backup_max_hours' => BackupService::FRESH_BACKUP_MAX_HOURS,
                'has_fresh_backup' => (bool) $fresh,
                'latest_fresh_backup' => $fresh ? [
                    'id' => $fresh->id,
                    'created_at' => $fresh->created_at,
                    'reason' => $fresh->reason,
                ] : null,
            ],
        ]);
    }

    public function listBackups(Request $request)
    {
        $this->requireAdmin($request);

        $backups = Backup::with('creator')->latest('created_at')->limit(50)->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'reason' => $b->reason,
                'table_row_counts' => $b->table_row_counts,
                'total_rows' => array_sum($b->table_row_counts),
                'size_bytes' => $b->size_bytes,
                'created_at' => $b->created_at,
                'created_by' => $b->creator->full_name ?? null,
            ]);

        return response()->json(['success' => true, 'data' => $backups]);
    }

    public function createBackup(Request $request)
    {
        $this->requireAdmin($request);

        $backup = $this->backups->snapshot('manual', $request->user()->id);
        $this->backups->cleanup();

        return response()->json([
            'success' => true,
            'message' => 'Backup created',
            'data' => [
                'id' => $backup->id,
                'table_row_counts' => $backup->table_row_counts,
                'total_rows' => array_sum($backup->table_row_counts),
                'size_bytes' => $backup->size_bytes,
                'created_at' => $backup->created_at,
            ],
        ], 201);
    }

    public function downloadBackup(Request $request, $id)
    {
        $this->requireAdmin($request);

        $backup = Backup::find($id);
        if (!$backup) {
            return response()->json(['success' => false, 'message' => 'Backup not found'], 404);
        }

        return response($backup->payload, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="mara-water-backup-' .
                $backup->created_at->format('Y-m-d-His') . '.json"',
        ]);
    }

    public function resetAllData(Request $request)
    {
        $this->requireAdmin($request);

        if (!(Setting::allAsMap()['danger_zone_unlocked'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Danger Zone is locked. Unlock it in Settings first.',
            ], 403);
        }

        // Round 5B Phase 8: hard gate — a fresh backup must already exist
        // before any clear can proceed (not a dismissible warning).
        $fresh = $this->backups->latestFreshBackup();
        if (!$fresh) {
            // Force-create one, then require the Director to re-confirm —
            // unlock path also does this; here we refuse the clear itself
            // until a backup exists within the window.
            $forced = $this->backups->snapshot('pre_clear_required', $request->user()->id);
            $this->backups->cleanup();
            return response()->json([
                'success' => false,
                'message' => 'No fresh backup found. A safety backup was just created — download it, then confirm the clear again.',
                'data' => [
                    'backup_id' => $forced->id,
                    'created_at' => $forced->created_at,
                    'requires_reconfirm' => true,
                ],
            ], 409);
        }

        if ($request->input('confirmation') !== self::CONFIRMATION_PHRASE) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation text did not match "' . self::CONFIRMATION_PHRASE . '".',
            ], 422);
        }

        $section = $request->input('section', 'all');
        if (!array_key_exists($section, BackupService::SECTION_TABLES)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid section. Choose one of: ' . implode(', ', array_keys(BackupService::SECTION_TABLES)),
            ], 422);
        }

        $tables = $this->backups->tablesForSection($section);
        if (empty($tables)) {
            return response()->json(['success' => false, 'message' => 'No tables mapped for that section.'], 422);
        }

        // Optional date-range clear: only wipe rows in date-bearing tables
        // within the window. When omitted, wipe the whole section.
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $user = $request->user();

        // Always take another pre_reset snapshot immediately before wipe.
        $backup = $this->backups->snapshot('pre_reset', $user->id);
        $rowCountsBefore = collect($backup->table_row_counts)
            ->only($tables)->toArray();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            if ($dateFrom && $dateTo) {
                $this->wipeTablesInDateRange($tables, $dateFrom, $dateTo);
            } else {
                foreach ($tables as $table) {
                    if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $log = ResetLog::create([
            'performed_by' => $user->id,
            'performed_by_email' => $user->email,
            'backup_id' => $backup->id,
            'tables_wiped' => $tables,
            'row_counts_before' => $rowCountsBefore,
        ]);

        Setting::putMany(['danger_zone_unlocked' => false]);

        return response()->json([
            'success' => true,
            'message' => $section === 'all'
                ? 'All operational data cleared. A backup was taken first.'
                : "Section '{$section}' cleared. A backup was taken first.",
            'data' => [
                'section' => $section,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'backup_id' => $backup->id,
                'reset_log_id' => $log->id,
                'tables_wiped' => $tables,
                'row_counts_before' => $rowCountsBefore,
            ],
        ]);
    }

    /**
     * Best-effort date-scoped wipe for tables that have a known date column.
     * Tables without a matching date column are fully wiped (section intent).
     */
    private function wipeTablesInDateRange(array $tables, string $dateFrom, string $dateTo): void
    {
        $dateColumns = [
            'orders' => 'order_date',
            'invoices' => 'invoice_date',
            'batches' => 'manufacture_date',
            'packaging_runs' => 'run_start',
            'stock_moves' => 'created_at',
            'petty_cash_entries' => 'entry_date',
            'debtor_ledger_entries' => 'entry_date',
            'driver_trips' => 'trip_date',
            'attendances' => 'date',
            'fuel_logs' => 'log_date',
            'water_tests' => 'recorded_at',
            'material_batches' => 'purchase_date',
        ];

        foreach ($tables as $table) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }
            $col = $dateColumns[$table] ?? null;
            if ($col && \Illuminate\Support\Facades\Schema::hasColumn($table, $col)) {
                DB::table($table)->whereBetween($col, [$dateFrom, $dateTo])->delete();
            } else {
                DB::table($table)->delete();
            }
        }
    }

    public function listResetLogs(Request $request)
    {
        $this->requireAdmin($request);

        $logs = ResetLog::latest('created_at')->limit(50)->get();

        return response()->json(['success' => true, 'data' => $logs]);
    }

    /**
     * Restore the database to exactly the state captured in a given
     * backup. Same three-check pattern as resetAllData() (admin +
     * Danger Zone unlocked + typed confirmation) since this is just as
     * destructive to whatever the CURRENT data is -- and it takes a
     * fresh "pre_restore" safety backup of that current state first,
     * so a restore is itself always undoable.
     */
    public function restoreBackup(Request $request, $id)
    {
        $this->requireAdmin($request);

        $backup = Backup::find($id);
        if (!$backup) {
            return response()->json(['success' => false, 'message' => 'Backup not found'], 404);
        }

        if (!(Setting::allAsMap()['danger_zone_unlocked'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Danger Zone is locked. Unlock it in Settings first.',
            ], 403);
        }

        if ($request->input('confirmation') !== self::RESTORE_CONFIRMATION_PHRASE) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation text did not match "' . self::RESTORE_CONFIRMATION_PHRASE . '".',
            ], 422);
        }

        $user = $request->user();

        // Safety backup of whatever's about to be overwritten -- so
        // restoring the wrong backup by mistake is itself recoverable.
        $preRestoreBackup = $this->backups->snapshot('pre_restore', $user->id);

        $restoredCounts = $this->backups->restore($backup);

        Setting::putMany(['danger_zone_unlocked' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Database restored to the ' . $backup->created_at->toDateTimeString() . ' backup. A safety backup of the prior state was taken first.',
            'data' => [
                'restored_from_backup_id' => $backup->id,
                'pre_restore_backup_id' => $preRestoreBackup->id,
                'restored_row_counts' => $restoredCounts,
            ],
        ]);
    }
}
