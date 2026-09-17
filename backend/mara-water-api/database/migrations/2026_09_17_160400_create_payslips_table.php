<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 2 Phase 4: one row per staff member per payroll run, reproducing
 * every column the "Payroll" sheet computes. Unlike the debtor
 * ledger/petty-cash "derive, don't store" pattern used elsewhere in this
 * app, a finalized payslip is a historical financial record -- it must
 * NOT silently change if a statutory rate changes later or someone edits
 * an allowance afterward, so every computed figure (gross pay, PAYE,
 * NSSF, net salary, etc.) is captured as a snapshot at generation time,
 * the same way a real payslip works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payroll_run_id');
            $table->uuid('user_id');

            // Earnings
            $table->decimal('basic_pay', 12, 2)->default(0);
            $table->decimal('house_allowance', 12, 2)->default(0);
            $table->decimal('absentism_hours', 8, 2)->default(0);
            $table->decimal('absentism_deduction', 12, 2)->default(0); // hours-based, subtracted from gross
            $table->decimal('overtime_hours_1_5x', 8, 2)->default(0);
            $table->decimal('overtime_hours_2x', 8, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('leave_hours', 8, 2)->default(0);
            $table->decimal('leave_pay', 12, 2)->default(0);
            $table->decimal('telephone_allowance', 12, 2)->default(0);
            $table->decimal('other_allowance', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);

            // Statutory
            $table->decimal('pensionable_pay', 12, 2)->default(0);
            $table->decimal('employee_nssf', 12, 2)->default(0);
            $table->decimal('company_nssf', 12, 2)->default(0);
            $table->decimal('avc', 12, 2)->default(0); // additional voluntary contribution
            $table->decimal('total_pension_contribution', 12, 2)->default(0);
            $table->decimal('shif', 12, 2)->default(0);
            $table->decimal('housing_levy', 12, 2)->default(0);
            $table->decimal('taxable_pay', 12, 2)->default(0);
            $table->decimal('tax_payable', 12, 2)->default(0);
            $table->decimal('insurance_relief', 12, 2)->default(0);
            $table->decimal('tax_relief', 12, 2)->default(0); // personal relief
            $table->decimal('paye', 12, 2)->default(0);

            // Non-statutory deductions
            $table->decimal('bus_fare', 12, 2)->default(0);
            $table->decimal('insurance_deduction', 12, 2)->default(0);
            $table->decimal('loan_deduction', 12, 2)->default(0);
            $table->decimal('sacco_loan_deduction', 12, 2)->default(0);
            $table->decimal('sacco_contribution', 12, 2)->default(0);
            $table->decimal('sacco_advance_deduction', 12, 2)->default(0);
            $table->decimal('other_deduction', 12, 2)->default(0);
            $table->decimal('staff_advance_deduction', 12, 2)->default(0);
            $table->decimal('penalties', 12, 2)->default(0);

            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users');
            $table->unique(['payroll_run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
