<?php

namespace App\Services;

/**
 * Round 2 Phase 4 (HR & Payroll). Statutory rates as of this phase --
 * per the spec's own instruction to confirm current rates rather than
 * assume the sample workbook's numbers still apply, these were verified
 * against current sources (Sept 2026), not carried over from any old
 * file:
 *
 * - NSSF: Tier I covers the first KES 9,000 of pensionable pay, Tier II
 *   covers 9,000-108,000. 6% employee + 6% employer on both tiers. This
 *   is Year 4 of the NSSF Act 2013's phased rollout (effective Feb 2026).
 *   Source: NSSF Act 2013 phase-in schedule; multiple Kenyan payroll
 *   providers (Deel/PaySpace, FaidiHR, KaziQuest) independently confirm
 *   the same Feb-2026 9,000/108,000 figures.
 * - SHIF: 2.75% of gross pay, minimum KES 300/month, no upper cap.
 *   Deducted pre-tax (before PAYE), like NSSF and the Housing Levy.
 * - Housing Levy (AHL): 1.5% of gross pay (employee side), made
 *   permanent under the Affordable Housing Act 2024. Pre-tax.
 * - PAYE bands (Finance Act 2023, monthly, on taxable pay after NSSF/
 *   SHIF/AHL are deducted from gross): 10% on the first 24,000; 25% on
 *   the next 8,333 (24,001-32,333); 30% on the next 467,667
 *   (32,334-500,000); 32.5% on 500,001-800,000; 35% above 800,000.
 * - Personal (tax) relief: KES 2,400/month, automatic for every
 *   resident employee.
 * - Insurance relief: 15% of qualifying insurance premiums paid, capped
 *   at KES 5,000/month. This app has no separate "premiums paid" field
 *   -- the `insurance_deduction` payslip line (a non-statutory
 *   deduction already in the spec) is treated as the premium amount for
 *   this purpose. Flagging this as a modelling assumption worth a
 *   sanity-check, not a fact I verified externally.
 *
 * These are statutory rates, not this business's specific numbers --
 * they change on a schedule set by law, not by this app, so whoever
 * maintains this later should re-verify them periodically (the NSSF
 * tiers in particular step up again every February through 2027).
 */
class PayrollCalculationService
{
    // NSSF (Year 4, effective Feb 2026)
    public const NSSF_TIER_I_LIMIT = 9000.00;
    public const NSSF_TIER_II_LIMIT = 108000.00;
    public const NSSF_RATE = 0.06;

    // SHIF
    public const SHIF_RATE = 0.0275;
    public const SHIF_MINIMUM = 300.00;

    // Affordable Housing Levy
    public const AHL_RATE = 0.015;

    // PAYE bands: [upper bound of band, rate]. The last band (INF) catches everything above.
    public const PAYE_BANDS = [
        [24000, 0.10],
        [32333, 0.25],
        [500000, 0.30],
        [800000, 0.325],
        [PHP_INT_MAX, 0.35],
    ];

    public const PERSONAL_RELIEF = 2400.00;
    public const INSURANCE_RELIEF_RATE = 0.15;
    public const INSURANCE_RELIEF_CAP = 5000.00;

    /**
     * Monthly hours used to derive an hourly rate from basic salary, for
     * overtime, absenteeism, and leave-pay math. Unlike the rates above,
     * this is NOT a statutory figure -- there's no single legally fixed
     * divisor; Kenyan payroll practice varies (common conventions range
     * from 195 to 225 hours/month). 225 (25 working days x 9 hours) is a
     * commonly used default. This is a company-policy choice worth
     * sanity-checking, not something externally verified like the rates
     * above.
     */
    public const STANDARD_MONTHLY_HOURS = 225.0;

    public function hourlyRate(float $basicPay): float
    {
        return self::STANDARD_MONTHLY_HOURS > 0 ? $basicPay / self::STANDARD_MONTHLY_HOURS : 0.0;
    }

    public function nssfEmployee(float $pensionablePay): float
    {
        $tierI = min($pensionablePay, self::NSSF_TIER_I_LIMIT) * self::NSSF_RATE;
        $tierII = max(0, min($pensionablePay, self::NSSF_TIER_II_LIMIT) - self::NSSF_TIER_I_LIMIT) * self::NSSF_RATE;
        return round($tierI + $tierII, 2);
    }

    public function nssfEmployer(float $pensionablePay): float
    {
        // Employer matches the employee side under the current Act.
        return $this->nssfEmployee($pensionablePay);
    }

    public function shif(float $grossPay): float
    {
        return round(max($grossPay * self::SHIF_RATE, self::SHIF_MINIMUM), 2);
    }

    public function housingLevy(float $grossPay): float
    {
        return round($grossPay * self::AHL_RATE, 2);
    }

    /**
     * Progressive PAYE across the bands, on taxable pay (gross minus
     * NSSF/SHIF/AHL, all pre-tax deductions).
     */
    public function taxPayable(float $taxablePay): float
    {
        if ($taxablePay <= 0) {
            return 0.0;
        }
        $tax = 0.0;
        $lowerBound = 0.0;
        foreach (self::PAYE_BANDS as [$upperBound, $rate]) {
            if ($taxablePay <= $lowerBound) {
                break;
            }
            $amountInBand = min($taxablePay, $upperBound) - $lowerBound;
            $tax += $amountInBand * $rate;
            $lowerBound = $upperBound;
        }
        return round($tax, 2);
    }

    public function insuranceRelief(float $insurancePremiumPaid): float
    {
        return round(min($insurancePremiumPaid * self::INSURANCE_RELIEF_RATE, self::INSURANCE_RELIEF_CAP), 2);
    }

    /**
     * Full payslip calculation for one staff member for one run.
     * $inputs carries every editable line item (see Payslip columns);
     * anything not supplied defaults to 0. Returns the complete set of
     * derived figures ready to store on a Payslip row.
     */
    public function calculate(array $inputs): array
    {
        $get = fn (string $key) => (float) ($inputs[$key] ?? 0);

        $basicPay = $get('basic_pay');
        $houseAllowance = $get('house_allowance');
        $hourlyRate = $this->hourlyRate($basicPay);

        $overtimeHours15x = $get('overtime_hours_1_5x');
        $overtimeHours2x = $get('overtime_hours_2x');
        $overtimePay = round($overtimeHours15x * $hourlyRate * 1.5 + $overtimeHours2x * $hourlyRate * 2, 2);

        $absentismHours = $get('absentism_hours');
        $absentismDeduction = round($absentismHours * $hourlyRate, 2);

        $leaveHours = $get('leave_hours');
        // "Leave pay" here means leave *sold* (cashed out unused leave),
        // paid at the normal hourly rate -- not a deduction.
        $leavePay = round($leaveHours * $hourlyRate, 2);

        $commission = $get('commission');
        $telephoneAllowance = $get('telephone_allowance');
        $otherAllowance = $get('other_allowance');
        $bonus = $get('bonus');

        $grossPay = round(
            $basicPay + $houseAllowance - $absentismDeduction + $overtimePay
            + $commission + $leavePay + $telephoneAllowance + $otherAllowance + $bonus,
            2
        );

        // Pensionable pay: basic pay only, per common NSSF practice --
        // allowances generally aren't pensionable. Capped implicitly by
        // the tier math in nssfEmployee().
        $pensionablePay = $basicPay;
        $employeeNssf = $this->nssfEmployee($pensionablePay);
        $companyNssf = $this->nssfEmployer($pensionablePay);
        $avc = $get('avc');
        $totalPensionContribution = round($employeeNssf + $avc, 2);

        $shif = $this->shif($grossPay);
        $housingLevy = $this->housingLevy($grossPay);

        $taxablePay = max(0, round($grossPay - $employeeNssf - $shif - $housingLevy - $avc, 2));
        $taxPayable = $this->taxPayable($taxablePay);

        $insuranceDeduction = $get('insurance_deduction');
        $insuranceRelief = $this->insuranceRelief($insuranceDeduction);
        $taxRelief = self::PERSONAL_RELIEF;
        $paye = max(0, round($taxPayable - $insuranceRelief - $taxRelief, 2));

        $busFare = $get('bus_fare');
        $loanDeduction = $get('loan_deduction');
        $saccoLoanDeduction = $get('sacco_loan_deduction');
        $saccoContribution = $get('sacco_contribution');
        $saccoAdvanceDeduction = $get('sacco_advance_deduction');
        $otherDeduction = $get('other_deduction');
        $staffAdvanceDeduction = $get('staff_advance_deduction');
        $penalties = $get('penalties');

        $totalDeductions = round(
            $employeeNssf + $shif + $housingLevy + $paye
            + $busFare + $insuranceDeduction + $loanDeduction + $saccoLoanDeduction
            + $saccoContribution + $saccoAdvanceDeduction + $otherDeduction
            + $staffAdvanceDeduction + $penalties,
            2
        );

        $netSalary = round($grossPay - $totalDeductions, 2);

        return [
            'basic_pay' => $basicPay,
            'house_allowance' => $houseAllowance,
            'absentism_hours' => $absentismHours,
            'absentism_deduction' => $absentismDeduction,
            'overtime_hours_1_5x' => $overtimeHours15x,
            'overtime_hours_2x' => $overtimeHours2x,
            'overtime_pay' => $overtimePay,
            'commission' => $commission,
            'leave_hours' => $leaveHours,
            'leave_pay' => $leavePay,
            'telephone_allowance' => $telephoneAllowance,
            'other_allowance' => $otherAllowance,
            'bonus' => $bonus,
            'gross_pay' => $grossPay,
            'pensionable_pay' => $pensionablePay,
            'employee_nssf' => $employeeNssf,
            'company_nssf' => $companyNssf,
            'avc' => $avc,
            'total_pension_contribution' => $totalPensionContribution,
            'shif' => $shif,
            'housing_levy' => $housingLevy,
            'taxable_pay' => $taxablePay,
            'tax_payable' => $taxPayable,
            'insurance_relief' => $insuranceRelief,
            'tax_relief' => $taxRelief,
            'paye' => $paye,
            'bus_fare' => $busFare,
            'insurance_deduction' => $insuranceDeduction,
            'loan_deduction' => $loanDeduction,
            'sacco_loan_deduction' => $saccoLoanDeduction,
            'sacco_contribution' => $saccoContribution,
            'sacco_advance_deduction' => $saccoAdvanceDeduction,
            'other_deduction' => $otherDeduction,
            'staff_advance_deduction' => $staffAdvanceDeduction,
            'penalties' => $penalties,
            'total_deductions' => $totalDeductions,
            'net_salary' => $netSalary,
        ];
    }
}
