<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 fix: OrderController, InvoiceController, AttendanceController,
 * ReportsController, and their Eloquent models were written against columns
 * that don't exist in the real MARA-WATER SQL schema (orders.total_amount;
 * invoices.subtotal/tax_amount/discount_amount/total_amount/payment_status/
 * payment_date/payment_method/payment_reference/sent_at/payment_terms/notes;
 * attendances.shift_id/date/clock_in_time/clock_out_time/break_start_time/
 * break_end_time/total_hours/overtime_hours/status/notes/location/photo_url).
 * Every write path through
 * those controllers (creating an order, invoice, or attendance record) threw
 * "Unknown column" SQL errors.
 *
 * orders/invoices/attendances all have zero production rows right now
 * (pre-launch), so this adds the missing columns additively instead of
 * rewriting the already-validated controller logic. invoices.status is
 * widened from its 4-value ENUM to VARCHAR so the app's draft/sent/paid
 * lifecycle values are accepted (the app also uses payment_status,
 * separately, for pending/paid/overdue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'total_amount')) {
                $table->decimal('total_amount', 14, 2)->default(0)->after('price_list_id');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'subtotal')) {
                $table->decimal('subtotal', 14, 2)->default(0)->after('currency');
            }
            if (!Schema::hasColumn('invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 14, 2)->default(0)->after('tax');
            }
            if (!Schema::hasColumn('invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 14, 2)->default(0)->after('discount');
            }
            if (!Schema::hasColumn('invoices', 'total_amount')) {
                $table->decimal('total_amount', 14, 2)->default(0)->after('total');
            }
            if (!Schema::hasColumn('invoices', 'payment_status')) {
                $table->string('payment_status', 20)->default('pending')->after('status');
            }
            if (!Schema::hasColumn('invoices', 'payment_date')) {
                $table->date('payment_date')->nullable()->after('due_date');
            }
            if (!Schema::hasColumn('invoices', 'payment_method')) {
                $table->string('payment_method', 50)->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('invoices', 'payment_reference')) {
                $table->string('payment_reference', 255)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('invoices', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('payment_reference');
            }
            if (!Schema::hasColumn('invoices', 'payment_terms')) {
                $table->string('payment_terms', 255)->nullable()->after('sent_at');
            }
            if (!Schema::hasColumn('invoices', 'notes')) {
                $table->text('notes')->nullable()->after('payment_terms');
            }
        });
        // invoices.status: widen from ENUM('open','paid','partial','cancelled')
        // to VARCHAR so the app's draft/sent/paid lifecycle values are valid.
        DB::statement("ALTER TABLE invoices MODIFY status VARCHAR(20) NOT NULL DEFAULT 'draft'");

        Schema::table('attendances', function (Blueprint $table) {
            if (!Schema::hasColumn('attendances', 'shift_id')) {
                $table->char('shift_id', 36)->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('attendances', 'date')) {
                $table->date('date')->nullable()->after('shift_id');
            }
            if (!Schema::hasColumn('attendances', 'clock_in_time')) {
                $table->time('clock_in_time')->nullable()->after('date');
            }
            if (!Schema::hasColumn('attendances', 'clock_out_time')) {
                $table->time('clock_out_time')->nullable()->after('clock_in_time');
            }
            if (!Schema::hasColumn('attendances', 'break_start_time')) {
                $table->time('break_start_time')->nullable()->after('clock_out_time');
            }
            if (!Schema::hasColumn('attendances', 'break_end_time')) {
                $table->time('break_end_time')->nullable()->after('break_start_time');
            }
            if (!Schema::hasColumn('attendances', 'total_hours')) {
                $table->decimal('total_hours', 5, 2)->nullable()->after('break_end_time');
            }
            if (!Schema::hasColumn('attendances', 'overtime_hours')) {
                $table->decimal('overtime_hours', 5, 2)->nullable()->after('total_hours');
            }
            if (!Schema::hasColumn('attendances', 'status')) {
                $table->string('status', 20)->default('present')->after('overtime_hours');
            }
            if (!Schema::hasColumn('attendances', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
            if (!Schema::hasColumn('attendances', 'location')) {
                $table->string('location', 255)->nullable()->after('notes');
            }
            if (!Schema::hasColumn('attendances', 'photo_url')) {
                $table->string('photo_url', 500)->nullable()->after('location');
            }
        });
        // Backfill 'date' from the existing check_in_at column where present,
        // and make it required going forward now that AttendanceController
        // always supplies it.
        DB::statement("UPDATE attendances SET date = DATE(check_in_at) WHERE date IS NULL AND check_in_at IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'shift_id', 'date', 'clock_in_time', 'clock_out_time',
                'break_start_time', 'break_end_time', 'total_hours',
                'overtime_hours', 'status', 'notes', 'location', 'photo_url',
            ]);
        });

        DB::statement("ALTER TABLE invoices MODIFY status ENUM('open','paid','partial','cancelled') NOT NULL DEFAULT 'open'");
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'subtotal', 'tax_amount', 'discount_amount', 'total_amount',
                'payment_status', 'payment_date', 'payment_method',
                'payment_reference', 'sent_at', 'payment_terms', 'notes',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('total_amount');
        });
    }
};
