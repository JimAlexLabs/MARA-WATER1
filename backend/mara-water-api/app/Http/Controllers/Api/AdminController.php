<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Backup;
use App\Models\ResetLog;
use App\Models\Setting;

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
     */
    private const PRESERVE_TABLES = [
        'departments', 'roles', 'permissions', 'role_permissions', 'users',
        'user_sessions', 'audits', 'api_keys', 'director_handover_logs',
        'suppliers', 'materials', 'skus', 'bom_items', 'warehouses', 'routes',
        'vehicles', 'price_lists', 'price_list_items', 'qa_thresholds',
        'cleaning_tasks', 'bank_accounts', 'taxes', 'shifts',
        'insurance_policies', 'settings',
    ];

    private const WIPE_TABLES = [
        'customers', 'price_agreements', 'water_tests', 'instrument_calibrations',
        'batches', 'packaging_checks', 'non_conformances', 'corrective_actions',
        'production_plans', 'production_plan_items', 'packaging_runs',
        'cleaning_logs', 'stock_items', 'stock_moves', 'purchase_orders',
        'po_items', 'goods_receipts', 'grn_items', 'stock_counts',
        'stock_count_items', 'orders', 'order_items', 'manifests',
        'manifest_items', 'manifest_signatures', 'deliveries', 'returns',
        'return_items', 'invoices', 'invoice_items', 'receipts',
        'bank_statements', 'bank_lines', 'reconciliations', 'recon_items',
        'debts', 'expenses', 'attendances', 'uniform_checks', 'safety_checks',
        'disciplinary_actions', 'leave_requests', 'vehicle_checks', 'services',
        'fuel_logs', 'driver_assignments', 'ntsa_inspections',
        'speed_governor_logs', 'files', 'voice_notes', 'media_albums',
        'album_items', 'tasks', 'task_comments', 'notifications', 'slas',
    ];

    private const CONFIRMATION_PHRASE = 'DELETE ALL DATA';

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
                'preserved' => self::PRESERVE_TABLES,
                'wiped' => self::WIPE_TABLES,
                'confirmation_phrase' => self::CONFIRMATION_PHRASE,
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

        $backup = $this->snapshotAllData('manual', $request->user()->id);

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
        $backup = $this->snapshotAllData('pre_reset', $user->id);
        $rowCountsBefore = collect($backup->table_row_counts)
            ->only(self::WIPE_TABLES)->toArray();

        // 2. Wipe the operational/transactional tables only -- see
        // PRESERVE_TABLES/WIPE_TABLES above. Logins, product catalog,
        // pricing, fleet registry, and system settings are untouched.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::WIPE_TABLES as $table) {
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
            'tables_wiped' => self::WIPE_TABLES,
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
                'tables_wiped' => self::WIPE_TABLES,
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

    private function snapshotAllData(string $reason, ?string $userId): Backup
    {
        $allTables = array_merge(self::PRESERVE_TABLES, self::WIPE_TABLES);
        $snapshot = [];
        $rowCounts = [];

        foreach ($allTables as $table) {
            $rows = DB::table($table)->get();
            $snapshot[$table] = $rows;
            $rowCounts[$table] = $rows->count();
        }

        $payload = json_encode($snapshot);

        return Backup::create([
            'created_by' => $userId,
            'reason' => $reason,
            'table_row_counts' => $rowCounts,
            'size_bytes' => strlen($payload),
            'payload' => $payload,
        ]);
    }
}
