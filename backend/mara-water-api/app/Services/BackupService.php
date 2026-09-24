<?php

namespace App\Services;

use App\Models\Backup;
use Illuminate\Support\Facades\DB;

/**
 * Phase 11 (Backups: never lose data). Snapshot/cleanup/restore logic
 * extracted out of AdminController so both the HTTP endpoints and the
 * new scheduled `backups:run` console command (app/Console/Commands/
 * RunScheduledBackup.php) share exactly one implementation -- two
 * copies of this drifting apart is exactly how the Phase 3 table-list
 * gap (see AdminController's PRESERVE_TABLES/WIPE_TABLES comment)
 * happened in the first place.
 */
class BackupService
{
    // See AdminController::PRESERVE_TABLES/WIPE_TABLES for what each
    // list means -- these are the same two lists, now canonical here.
    public const PRESERVE_TABLES = [
        'departments', 'roles', 'permissions', 'role_permissions', 'users',
        'user_sessions', 'audits', 'api_keys', 'director_handover_logs',
        'suppliers', 'materials', 'skus', 'bom_items', 'warehouses', 'routes',
        'vehicles', 'price_lists', 'price_list_items', 'qa_thresholds',
        'cleaning_tasks', 'bank_accounts', 'taxes', 'shifts',
        'insurance_policies', 'settings', 'chart_of_accounts', 'equipment_items',
    ];

    public const WIPE_TABLES = [
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
        'driver_trips', 'driver_trip_items', 'petty_cash_entries',
        'debtor_ledger_entries',
        // Round 5B
        'material_batches', 'material_batch_consumptions', 'stock_reconciliation_reports',
    ];

    /**
     * Round 5B Phase 8: granular clear-by-section. Each key is a module
     * the Director can wipe independently. Tables listed here must also
     * appear in WIPE_TABLES (or be a subset thereof).
     */
    public const SECTION_TABLES = [
        'sales' => [
            'orders', 'order_items', 'manifests', 'manifest_items', 'manifest_signatures',
            'deliveries', 'returns', 'return_items', 'driver_trips', 'driver_trip_items',
            'invoices', 'invoice_items', 'receipts', 'debts', 'debtor_ledger_entries',
        ],
        'production' => [
            'batches', 'packaging_runs', 'packaging_checks', 'production_plans',
            'production_plan_items', 'cleaning_logs', 'material_batch_consumptions',
        ],
        'inventory' => [
            'stock_items', 'stock_moves', 'purchase_orders', 'po_items',
            'goods_receipts', 'grn_items', 'stock_counts', 'stock_count_items',
            'material_batches', 'material_batch_consumptions', 'stock_reconciliation_reports',
        ],
        'finance' => [
            'invoices', 'invoice_items', 'receipts', 'petty_cash_entries',
            'debtor_ledger_entries', 'debts', 'expenses', 'bank_statements',
            'bank_lines', 'reconciliations', 'recon_items',
        ],
        'qa' => [
            'water_tests', 'instrument_calibrations', 'non_conformances',
            'corrective_actions', 'packaging_checks',
        ],
        'hr' => [
            'attendances', 'uniform_checks', 'safety_checks', 'disciplinary_actions',
            'leave_requests',
        ],
        'fleet' => [
            'vehicle_checks', 'services', 'fuel_logs', 'driver_assignments',
            'ntsa_inspections', 'speed_governor_logs', 'driver_trips', 'driver_trip_items',
        ],
        'all' => [], // special: uses full WIPE_TABLES
    ];

    /** Hours a backup must be newer than to unlock Danger Zone clear actions. */
    public const FRESH_BACKUP_MAX_HOURS = 24;

    // "Recover a full financial year" per the spec -- 400 days gives a
    // full year plus margin either side of a year boundary.
    public const RETENTION_DAYS = 400;

    public function allTables(): array
    {
        return array_merge(self::PRESERVE_TABLES, self::WIPE_TABLES);
    }

    public function snapshot(string $reason, ?string $userId): Backup
    {
        $snapshot = [];
        $rowCounts = [];

        foreach ($this->allTables() as $table) {
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

    /**
     * Deletes backups older than the retention window. Never deletes
     * the single most recent backup regardless of age, so there's
     * always at least one to fall back on even if scheduled backups
     * have been broken/stopped for longer than the retention window.
     */
    public function cleanup(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $keepId = Backup::orderByDesc('created_at')->value('id');

        return Backup::where('created_at', '<', $cutoff)
            ->when($keepId, fn ($q) => $q->where('id', '!=', $keepId))
            ->delete();
    }

    /**
     * Restores every table in a backup's payload: truncates each table
     * this snapshot covers, then re-inserts the snapshotted rows.
     * Callers are responsible for taking their own safety backup
     * first and for the confirmation/authorization gate -- this method
     * just does the actual overwrite once those checks have passed.
     */
    public function restore(Backup $backup): array
    {
        $snapshot = json_decode($backup->payload, true);
        $restoredCounts = [];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->allTables() as $table) {
                if (!array_key_exists($table, $snapshot)) {
                    continue; // an older backup predating a table we've since added
                }

                DB::table($table)->truncate();

                $rows = $snapshot[$table];
                $restoredCounts[$table] = count($rows);

                // Insert in chunks -- a full-table snapshot can be large
                // enough that one INSERT with every row blows past
                // MySQL's max_allowed_packet.
                foreach (array_chunk($rows, 500) as $chunk) {
                    if (!empty($chunk)) {
                        DB::table($table)->insert($chunk);
                    }
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $restoredCounts;
    }

    /**
     * Round 5B Phase 8: a clear action requires a backup taken within
     * FRESH_BACKUP_MAX_HOURS. Returns the latest backup or null.
     */
    public function latestFreshBackup(): ?Backup
    {
        $cutoff = now()->subHours(self::FRESH_BACKUP_MAX_HOURS);
        return Backup::where('created_at', '>=', $cutoff)->orderByDesc('created_at')->first();
    }

    public function tablesForSection(string $section): array
    {
        if ($section === 'all') {
            return self::WIPE_TABLES;
        }
        return self::SECTION_TABLES[$section] ?? [];
    }

    public function sectionCatalog(): array
    {
        $out = [];
        foreach (self::SECTION_TABLES as $key => $tables) {
            $out[] = [
                'key' => $key,
                'label' => $key === 'all' ? 'All operational data' : ucfirst($key),
                'table_count' => $key === 'all' ? count(self::WIPE_TABLES) : count($tables),
                'tables' => $key === 'all' ? self::WIPE_TABLES : $tables,
            ];
        }
        return $out;
    }
}
