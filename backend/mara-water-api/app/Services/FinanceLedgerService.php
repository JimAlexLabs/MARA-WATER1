<?php

namespace App\Services;

use App\Models\DebtorLedgerEntry;
use App\Models\Invoice;
use App\Models\PettyCashEntry;
use Illuminate\Support\Facades\DB;

/**
 * Round 5B Phase 5: single ledger summary for Manager and Director.
 * Both roles must see identical figures for the same date range —
 * no role-specific date defaults or rounding differences here.
 */
class FinanceLedgerService
{
    public function summary(string $dateFrom, string $dateTo): array
    {
        $from = substr($dateFrom, 0, 10);
        $to = substr($dateTo, 0, 10);

        $revenue = (new SalesRevenueService())->combinedRevenueBetween($from, $to);

        $invoiceStats = Invoice::whereNull('deleted_at')
            ->whereBetween('invoice_date', [$from, $to])
            ->selectRaw('
                COUNT(*) as invoice_count,
                COALESCE(SUM(total_amount), 0) as invoiced_total,
                COALESCE(SUM(CASE WHEN status = "paid" OR payment_status = "paid" THEN total_amount ELSE 0 END), 0) as paid_total,
                COALESCE(SUM(CASE WHEN COALESCE(payment_status, status) NOT IN ("paid") THEN total_amount ELSE 0 END), 0) as outstanding_total
            ')
            ->first();

        $pettyIn = (float) PettyCashEntry::whereBetween('entry_date', [$from, $to])->sum('amount_in');
        $pettyOut = (float) PettyCashEntry::whereBetween('entry_date', [$from, $to])->sum('amount_out');

        $debtorDebits = (float) DebtorLedgerEntry::whereBetween('entry_date', [$from, $to])->sum('debit');
        $debtorCredits = (float) DebtorLedgerEntry::whereBetween('entry_date', [$from, $to])->sum('credit');

        // Open debtor balance (all-time closing) — same query regardless of role.
        $openDebtorBalance = (float) DebtorLedgerEntry::selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as bal')->value('bal');

        return [
            'date_from' => $from,
            'date_to' => $to,
            'revenue' => round($revenue, 2),
            'invoices' => [
                'count' => (int) ($invoiceStats->invoice_count ?? 0),
                'invoiced_total' => round((float) ($invoiceStats->invoiced_total ?? 0), 2),
                'paid_total' => round((float) ($invoiceStats->paid_total ?? 0), 2),
                'outstanding_total' => round((float) ($invoiceStats->outstanding_total ?? 0), 2),
            ],
            'petty_cash' => [
                'in' => round($pettyIn, 2),
                'out' => round($pettyOut, 2),
                'net' => round($pettyIn - $pettyOut, 2),
            ],
            'debtors' => [
                'period_debits' => round($debtorDebits, 2),
                'period_credits' => round($debtorCredits, 2),
                'period_net' => round($debtorDebits - $debtorCredits, 2),
                'open_balance' => round($openDebtorBalance, 2),
            ],
        ];
    }
}
