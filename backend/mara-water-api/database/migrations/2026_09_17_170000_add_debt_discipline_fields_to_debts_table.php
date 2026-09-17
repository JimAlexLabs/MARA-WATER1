<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 6 (debt sales: signatory, repayment date, discipline).
 *
 * Two things found while building this, both real gaps in the existing
 * debts table, not just additions:
 *
 * 1. `invoice_id` was NOT NULL -- every debt required a formal invoice.
 *    Driver-trip credit sales (this phase's other debt-creation point)
 *    are informal shop credit -- a signatory's name IS the
 *    acknowledgment, not a generated invoice document -- so invoice_id
 *    has to be nullable to support them without manufacturing a fake
 *    invoice for every trip-side credit sale.
 *
 * 2. `days_overdue` was computed by a BEFORE INSERT trigger and never
 *    touched again -- frozen at whatever it was the moment the debt was
 *    created, forever. A debt inserted on time (0 days overdue) would
 *    still show "0 days overdue" months later even after becoming
 *    genuinely overdue, since nothing ever recalculated it. This
 *    silently defeated the entire point of overdue tracking regardless
 *    of anything this phase adds. Dropping the trigger; days_overdue is
 *    now a live Eloquent accessor (Debt::getDaysOverdueAttribute()) for
 *    display, and DashboardController's overdue-debts query computes it
 *    directly in SQL (DATEDIFF) rather than trusting the stale column --
 *    "derive, don't store", the same pattern already used for the
 *    debtor ledger and petty cash running balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->string('signatory', 150)->nullable()->after('customer_id');
            $table->date('expected_repayment_date')->nullable()->after('signatory');
        });

        DB::statement('ALTER TABLE debts MODIFY invoice_id CHAR(36) NULL');
        DB::statement('DROP TRIGGER IF EXISTS update_debt_ageing');
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['signatory', 'expected_repayment_date']);
        });

        DB::statement('ALTER TABLE debts MODIFY invoice_id CHAR(36) NOT NULL');
        DB::unprepared('
            CREATE TRIGGER update_debt_ageing BEFORE INSERT ON debts
            FOR EACH ROW
            BEGIN
                DECLARE invoice_due_date DATE;
                SELECT i.due_date INTO invoice_due_date
                FROM invoices i
                WHERE i.id = NEW.invoice_id;

                SET NEW.days_overdue = DATEDIFF(CURDATE(), invoice_due_date);
                IF NEW.days_overdue < 0 THEN
                    SET NEW.days_overdue = 0;
                END IF;
            END
        ');
    }
};
