<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\StaffLoan;
use App\Models\User;
use App\Services\PayrollCalculationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Round 2 Phase 4 (HR & Payroll). A run is created in `draft`, generating
 * one payslip per active staff member from their current basic
 * salary/house allowance plus whatever's owed on active loans/advances
 * (drawn down but not yet committed -- see finalize()). Each payslip's
 * other line items (overtime, commission, bonus, etc.) start at zero and
 * are edited per employee via updatePayslip() before the run is
 * finalized. Loan balances are only permanently decremented on
 * finalize(), so a draft can be freely edited/regenerated without
 * double-counting a deduction that never actually happened.
 */
class PayrollController extends Controller
{
    public function __construct(private PayrollCalculationService $calc)
    {
    }

    public function index()
    {
        $runs = PayrollRun::withCount('payslips')
            ->orderByDesc('month')
            ->get();

        return response()->json(['success' => true, 'data' => $runs]);
    }

    public function show($id)
    {
        $run = PayrollRun::with(['payslips.user:id,first_name,last_name,staff_number,department_id', 'payslips.user.department'])
            ->find($id);

        if (!$run) {
            return response()->json(['success' => false, 'message' => 'Payroll run not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $run]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'month' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $month = \Carbon\Carbon::parse($request->month)->startOfMonth()->toDateString();

        if (PayrollRun::where('month', $month)->exists()) {
            return response()->json(['success' => false, 'message' => 'A payroll run already exists for this month'], 422);
        }

        DB::beginTransaction();
        try {
            $run = PayrollRun::create([
                'month' => $month,
                'status' => 'draft',
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            $staff = User::where('status', 'active')->whereNotNull('salary')->get();
            $monthCarbon = \Carbon\Carbon::parse($month);

            foreach ($staff as $user) {
                $inputs = [
                    'basic_pay' => (float) $user->salary,
                    'house_allowance' => (float) $user->house_allowance,
                    // Round 2 Phase 5: fed automatically from real attendance
                    // records now, instead of being hand-entered (Phase 4
                    // left this at 0 since attendance auto-linking was
                    // explicitly this phase's job).
                    'absentism_hours' => $this->calculateAbsenteeismHours($user, $monthCarbon),
                ];
                $this->applyActiveLoanDeductions($user, $inputs);

                Payslip::create(array_merge(
                    $this->calc->calculate($inputs),
                    [
                        'payroll_run_id' => $run->id,
                        'user_id' => $user->id,
                        'created_by' => Auth::id(),
                        'updated_by' => Auth::id(),
                    ]
                ));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Payroll run created with {$staff->count()} payslip(s)",
                'data' => $run->load('payslips.user:id,first_name,last_name'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to create payroll run', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Pull whatever's currently owed on this user's active loans/advances
     * into the right deduction line, capped at each loan's own remaining
     * balance (never deduct more than is actually owed).
     */
    private function applyActiveLoanDeductions(User $user, array &$inputs): void
    {
        $fieldByType = [
            'loan' => 'loan_deduction',
            'advance' => 'staff_advance_deduction',
            'sacco_loan' => 'sacco_loan_deduction',
            'sacco_advance' => 'sacco_advance_deduction',
        ];

        foreach ($user->activeStaffLoans as $loan) {
            $field = $fieldByType[$loan->type] ?? null;
            if (!$field) {
                continue;
            }
            $amount = min((float) $loan->monthly_deduction, (float) $loan->balance);
            $inputs[$field] = ($inputs[$field] ?? 0) + $amount;
        }
    }

    /**
     * Round 2 Phase 5: missed working days x a standard 8-hour day (the
     * same "8 hours = one full day" convention this app already uses
     * elsewhere -- Attendance::getIsLateAttribute()/getWorkEfficiencyAttribute()
     * both hardcode it). Working days are Monday-Friday; a business
     * running a 6-day week would need this adjusted, flagging that as a
     * assumption worth confirming, not a verified fact.
     *
     * Only counts days from the later of (month start, the employee's
     * employment_date) through the earlier of (month end, today) -- never
     * penalizes a day before someone was hired or a day that hasn't
     * happened yet. 'present'/'late' count as attended, 'half_day' counts
     * as half a missed day, and 'absent' or no record at all counts as a
     * full missed day.
     */
    private function calculateAbsenteeismHours(User $user, \Carbon\Carbon $month): float
    {
        $periodStart = $month->copy()->startOfMonth();
        if ($user->employment_date && $user->employment_date->gt($periodStart)) {
            $periodStart = $user->employment_date->copy();
        }
        $periodEnd = $month->copy()->endOfMonth();
        $today = now()->startOfDay();
        if ($today->lt($periodEnd)) {
            $periodEnd = $today;
        }
        if ($periodStart->gt($periodEnd)) {
            return 0.0;
        }

        $attendanceByDate = \App\Models\Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        $missedDays = 0.0;
        for ($day = $periodStart->copy(); $day->lte($periodEnd); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            $record = $attendanceByDate->get($day->toDateString());
            if (!$record || $record->status === 'absent') {
                $missedDays += 1.0;
            } elseif ($record->status === 'half_day') {
                $missedDays += 0.5;
            }
        }

        return round($missedDays * 8.0, 2);
    }

    public function updatePayslip(Request $request, $runId, $payslipId)
    {
        $run = PayrollRun::find($runId);
        if (!$run) {
            return response()->json(['success' => false, 'message' => 'Payroll run not found'], 404);
        }
        if ($run->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'This run is finalized and can no longer be edited'], 422);
        }

        $payslip = Payslip::where('payroll_run_id', $runId)->find($payslipId);
        if (!$payslip) {
            return response()->json(['success' => false, 'message' => 'Payslip not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'basic_pay' => 'sometimes|numeric|min:0',
            'house_allowance' => 'sometimes|numeric|min:0',
            'absentism_hours' => 'sometimes|numeric|min:0',
            'overtime_hours_1_5x' => 'sometimes|numeric|min:0',
            'overtime_hours_2x' => 'sometimes|numeric|min:0',
            'commission' => 'sometimes|numeric|min:0',
            'leave_hours' => 'sometimes|numeric|min:0',
            'telephone_allowance' => 'sometimes|numeric|min:0',
            'other_allowance' => 'sometimes|numeric|min:0',
            'bonus' => 'sometimes|numeric|min:0',
            'avc' => 'sometimes|numeric|min:0',
            'bus_fare' => 'sometimes|numeric|min:0',
            'insurance_deduction' => 'sometimes|numeric|min:0',
            'loan_deduction' => 'sometimes|numeric|min:0',
            'sacco_loan_deduction' => 'sometimes|numeric|min:0',
            'sacco_contribution' => 'sometimes|numeric|min:0',
            'sacco_advance_deduction' => 'sometimes|numeric|min:0',
            'other_deduction' => 'sometimes|numeric|min:0',
            'staff_advance_deduction' => 'sometimes|numeric|min:0',
            'penalties' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Merge the edited fields onto the payslip's current values, then
        // recompute everything derived from them.
        $inputs = array_merge($payslip->only([
            'basic_pay', 'house_allowance', 'absentism_hours', 'overtime_hours_1_5x', 'overtime_hours_2x',
            'commission', 'leave_hours', 'telephone_allowance', 'other_allowance', 'bonus', 'avc',
            'bus_fare', 'insurance_deduction', 'loan_deduction', 'sacco_loan_deduction',
            'sacco_contribution', 'sacco_advance_deduction', 'other_deduction',
            'staff_advance_deduction', 'penalties',
        ]), $request->only([
            'basic_pay', 'house_allowance', 'absentism_hours', 'overtime_hours_1_5x', 'overtime_hours_2x',
            'commission', 'leave_hours', 'telephone_allowance', 'other_allowance', 'bonus', 'avc',
            'bus_fare', 'insurance_deduction', 'loan_deduction', 'sacco_loan_deduction',
            'sacco_contribution', 'sacco_advance_deduction', 'other_deduction',
            'staff_advance_deduction', 'penalties',
        ]));

        $payslip->update(array_merge(
            $this->calc->calculate($inputs),
            ['updated_by' => Auth::id()]
        ));

        return response()->json(['success' => true, 'message' => 'Payslip updated', 'data' => $payslip->fresh()]);
    }

    public function finalize(Request $request, $id)
    {
        $run = PayrollRun::with('payslips')->find($id);
        if (!$run) {
            return response()->json(['success' => false, 'message' => 'Payroll run not found'], 404);
        }
        if ($run->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'This run is already finalized'], 422);
        }

        DB::beginTransaction();
        try {
            $fieldByType = [
                'loan' => 'loan_deduction',
                'advance' => 'staff_advance_deduction',
                'sacco_loan' => 'sacco_loan_deduction',
                'sacco_advance' => 'sacco_advance_deduction',
            ];

            foreach ($run->payslips as $payslip) {
                $loans = StaffLoan::where('user_id', $payslip->user_id)->where('status', 'active')->get();
                foreach ($loans as $loan) {
                    $field = $fieldByType[$loan->type] ?? null;
                    if (!$field) {
                        continue;
                    }
                    $deducted = min((float) $payslip->$field, (float) $loan->balance);
                    if ($deducted <= 0) {
                        continue;
                    }
                    $newBalance = round((float) $loan->balance - $deducted, 2);
                    $loan->update([
                        'balance' => max(0, $newBalance),
                        'status' => $newBalance <= 0 ? 'settled' : 'active',
                        'updated_by' => Auth::id(),
                    ]);
                }
            }

            $run->update([
                'status' => 'finalized',
                'run_by' => Auth::id(),
                'finalized_at' => now(),
                'updated_by' => Auth::id(),
            ]);

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Payroll run finalized', 'data' => $run->fresh()]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed to finalize payroll run', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $run = PayrollRun::find($id);
        if (!$run) {
            return response()->json(['success' => false, 'message' => 'Payroll run not found'], 404);
        }
        if ($run->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Only a draft run can be deleted'], 422);
        }

        $run->payslips()->delete();
        $run->delete();

        return response()->json(['success' => true, 'message' => 'Payroll run deleted']);
    }

    public function payslip($id)
    {
        $payslip = Payslip::with(['user.department', 'payrollRun'])->find($id);
        if (!$payslip) {
            return response()->json(['success' => false, 'message' => 'Payslip not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $payslip]);
    }

    /**
     * Round 3 Phase 9: real .xlsx matching "Finalis Payroll Beta"'s Bank
     * Transfer Details sheet layout -- was a plain flat CSV before this
     * (No./NAME/ACCOUNT NUMBER/BANK/BRANCH/BANK CODE/AMOUNT/MONTH/
     * Department, title block, ready to hand to a bank).
     */
    public function bankTransferFile($id)
    {
        $run = PayrollRun::with('payslips.user.department')->find($id);
        if (!$run) {
            return response()->json(['success' => false, 'message' => 'Payroll run not found'], 404);
        }

        return (new \App\Services\PayrollExportService())->bankTransferFile($run);
    }

    /**
     * Round 3 Phase 9: real .xlsx matching "Finalis Payroll Beta"'s
     * Payroll sheet layout -- see PayrollExportService.
     */
    public function payrollSheetExport($id)
    {
        $run = PayrollRun::with('payslips.user')->find($id);
        if (!$run) {
            return response()->json(['success' => false, 'message' => 'Payroll run not found'], 404);
        }

        return (new \App\Services\PayrollExportService())->payrollSheet($run);
    }

    /**
     * Round 3 Phase 9: real .xlsx matching "Finalis Payroll Beta"'s
     * Payslips sheet -- every employee's payslip for this run, one
     * workbook. (payslip() above stays as-is, for the frontend's own
     * single-payslip HTML view.)
     */
    public function payslipsExport($id)
    {
        $run = PayrollRun::with('payslips.user.department')->find($id);
        if (!$run) {
            return response()->json(['success' => false, 'message' => 'Payroll run not found'], 404);
        }

        return (new \App\Services\PayrollExportService())->payslips($run);
    }
}
