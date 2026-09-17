<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payslip extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'payroll_run_id', 'user_id',
        'basic_pay', 'house_allowance', 'absentism_hours', 'absentism_deduction',
        'overtime_hours_1_5x', 'overtime_hours_2x', 'overtime_pay',
        'commission', 'leave_hours', 'leave_pay', 'telephone_allowance', 'other_allowance', 'bonus',
        'gross_pay', 'pensionable_pay', 'employee_nssf', 'company_nssf', 'avc',
        'total_pension_contribution', 'shif', 'housing_levy', 'taxable_pay',
        'tax_payable', 'insurance_relief', 'tax_relief', 'paye',
        'bus_fare', 'insurance_deduction', 'loan_deduction', 'sacco_loan_deduction',
        'sacco_contribution', 'sacco_advance_deduction', 'other_deduction',
        'staff_advance_deduction', 'penalties', 'total_deductions', 'net_salary',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'basic_pay' => 'decimal:2', 'house_allowance' => 'decimal:2',
        'absentism_hours' => 'decimal:2', 'absentism_deduction' => 'decimal:2',
        'overtime_hours_1_5x' => 'decimal:2', 'overtime_hours_2x' => 'decimal:2', 'overtime_pay' => 'decimal:2',
        'commission' => 'decimal:2', 'leave_hours' => 'decimal:2', 'leave_pay' => 'decimal:2', 'telephone_allowance' => 'decimal:2',
        'other_allowance' => 'decimal:2', 'bonus' => 'decimal:2', 'gross_pay' => 'decimal:2',
        'pensionable_pay' => 'decimal:2', 'employee_nssf' => 'decimal:2', 'company_nssf' => 'decimal:2',
        'avc' => 'decimal:2', 'total_pension_contribution' => 'decimal:2', 'shif' => 'decimal:2',
        'housing_levy' => 'decimal:2', 'taxable_pay' => 'decimal:2', 'tax_payable' => 'decimal:2',
        'insurance_relief' => 'decimal:2', 'tax_relief' => 'decimal:2', 'paye' => 'decimal:2',
        'bus_fare' => 'decimal:2', 'insurance_deduction' => 'decimal:2', 'loan_deduction' => 'decimal:2',
        'sacco_loan_deduction' => 'decimal:2', 'sacco_contribution' => 'decimal:2',
        'sacco_advance_deduction' => 'decimal:2', 'other_deduction' => 'decimal:2',
        'staff_advance_deduction' => 'decimal:2', 'penalties' => 'decimal:2',
        'total_deductions' => 'decimal:2', 'net_salary' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
