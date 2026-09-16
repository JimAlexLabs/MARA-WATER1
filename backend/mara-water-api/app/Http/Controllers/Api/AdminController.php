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
        return response()->json([
            'success' => true,
            'data' => [
                'preserved' => BackupService::PRESERVE_TABLES,
                'wiped' => BackupService::WIPE_TABLES,
                'confirmation_phrase' => self::CONFIRMATION_PHRASE,
                'restore_confirmation_phrase' => self::RESTORE_CONFIRMATION_PHRASE,
                'retention_days' => BackupService::RETENTION_DAYS,
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

        if ($request->input('confirmation') !== self::CONFIRMATION_PHRASE) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation text did not match "' . self::CONFIRMATION_PHRASE . '".',
            ], 422);
        }

        $user = $request->user();

        // 1. Back up everything, unconditionally, before touching anything.
        $backup = $this->backups->snapshot('pre_reset', $user->id);
        $rowCountsBefore = collect($backup->table_row_counts)
            ->only(BackupService::WIPE_TABLES)->toArray();

        // 2. Wipe the operational/transactional tables only -- see
        // BackupService::PRESERVE_TABLES/WIPE_TABLES. Logins, product
        // catalog, pricing, fleet registry, and system settings are
        // untouched.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (BackupService::WIPE_TABLES as $table) {
                DB::table($table)->delete();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // 3. Permanent record of who did this and when -- reset_logs is
        // never itself wiped, so this survives the very reset it describes.
        $log = ResetLog::create([
            'performed_by' => $user->id,
            'performed_by_email' => $user->email,
            'backup_id' => $backup->id,
            'tables_wiped' => BackupService::WIPE_TABLES,
            'row_counts_before' => $rowCountsBefore,
        ]);

        // 4. Auto-relock so a second reset needs a deliberate unlock again.
        Setting::putMany(['danger_zone_unlocked' => false]);

        return response()->json([
            'success' => true,
            'message' => 'All operational data cleared. A backup was taken first.',
            'data' => [
                'backup_id' => $backup->id,
                'reset_log_id' => $log->id,
                'tables_wiped' => BackupService::WIPE_TABLES,
                'row_counts_before' => $rowCountsBefore,
            ],
        ]);
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
