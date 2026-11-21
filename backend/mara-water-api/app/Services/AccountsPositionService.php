<?php

namespace App\Services;

use App\Models\Debt;
use App\Models\FuelLog;
use App\Models\PettyCashEntry;
use Illuminate\Support\Facades\DB;

/**
 * Director financial position. The utility-account statement is the
 * AfriGig pull of 22/11/2026. Sales, debts, tokens, fuel and petty cash
 * are read live so the page moves when those books move.
 */
class AccountsPositionService
{
    public function __construct(private readonly OperationsModelService $model)
    {
    }

    public function build(): array
    {
        $rows = UtilityAccountStatement::rows();
        $paidIn = round(array_sum(array_column($rows, 'paid_in')), 2);
        $withdrawn = round(array_sum(array_column($rows, 'withdrawn')), 2);
        $balance = round((float) ($rows[0]['balance'] ?? 0), 2);

        $financeLines = [];
        $collections = 0.0;
        $deductions = [];
        foreach ($rows as $row) {
            $kind = $this->kind($row);
            $row['kind'] = $kind;
            if ($kind === 'finance') {
                $financeLines[] = $row;
            } elseif ($kind === 'deduction') {
                $deductions[] = $row;
            } else {
                $collections += (float) $row['paid_in'];
            }
        }
        $financeTotal = round(array_sum(array_column($financeLines, 'paid_in')), 2);
        $collections = round($collections, 2);

        $bills = $this->bills();
        $purchases = $this->purchases($bills['purchase_cash']);
        $purchasesPaid = round(array_sum(array_column($purchases['lines'], 'paid')), 2);
        $purchasesOwed = round(array_sum(array_column($purchases['lines'], 'still_owed')), 2);

        $afterBills = round($withdrawn - $bills['total'], 2);
        $paidOutside = round(max(0, $purchasesPaid - max(0, $afterBills)), 2);
        $unrecordedSweep = round(max(0, $afterBills - $purchasesPaid), 2);

        $cashSales = $this->cashSales();
        $cashLeft = round($cashSales['total'] - $paidOutside, 2);
        $shouldHold = round($balance + $cashLeft, 2);

        $debts = Debt::query()
            ->with('customer:id,name')
            ->whereNull('deleted_at')
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->get()
            ->map(fn (Debt $debt) => [
                'customer' => $debt->customer->name ?? 'Customer',
                'balance' => round((float) $debt->balance, 2),
                'due' => optional($debt->expected_repayment_date)->toDateString(),
            ])
            ->values()
            ->all();
        $debtTotal = round(array_sum(array_column($debts, 'balance')), 2);

        $assumptions = $this->model->effective();
        $loan = round((float) ($assumptions['loan']['principal'] ?? 0), 2);
        $pending = round((float) ($assumptions['capital']['derrick_pending'] ?? 0), 2);
        $borrowing = $this->borrowing($financeLines, $bills, $purchases['lines'], $collections, $balance);

        $movements = [];
        foreach ($rows as $row) {
            $movements[] = [
                'date' => $row['date'],
                'time' => $row['time'],
                'receipt' => $row['receipt'],
                'party' => $row['party'],
                'account' => $row['account'],
                'paid_in' => round((float) $row['paid_in'], 2),
                'withdrawn' => round((float) $row['withdrawn'], 2),
                'balance' => round((float) $row['balance'], 2),
                'kind' => $this->kind($row),
            ];
        }

        return [
            'as_of' => UtilityAccountStatement::AS_OF,
            'account' => [
                'holder' => UtilityAccountStatement::HOLDER,
                'short_code' => UtilityAccountStatement::SHORT_CODE,
                'balance' => $balance,
                'paid_in' => $paidIn,
                'withdrawn' => $withdrawn,
            ],
            'money_in' => [
                'collections' => $collections,
                'finance_on_statement' => $financeTotal,
                'finance_lines' => array_map(fn ($row) => [
                    'date' => $row['date'],
                    'party' => $row['party'],
                    'account' => $row['account'],
                    'amount' => round((float) $row['paid_in'], 2),
                ], $financeLines),
            ],
            'deductions' => [
                'total' => $withdrawn,
                'lines' => array_map(fn ($row) => [
                    'date' => $row['date'],
                    'amount' => round((float) $row['withdrawn'], 2),
                    'balance_after' => round((float) $row['balance'], 2),
                ], $deductions),
            ],
            'bills' => [
                'tokens' => $bills['tokens'],
                'fuel' => $bills['fuel'],
                'petty' => $bills['petty'],
                'total' => $bills['total'],
                'lines' => $bills['lines'],
                'duplicates_left_out' => $bills['duplicates'],
            ],
            'after_bills' => $afterBills,
            'purchases' => $purchases['lines'],
            'purchases_paid' => $purchasesPaid,
            'purchases_still_owed' => $purchasesOwed,
            'paid_outside_account' => $paidOutside,
            'unrecorded_sweep' => $unrecordedSweep,
            'cash_sales' => $cashSales,
            'should_be_holding' => $shouldHold,
            'debts' => $debts,
            'debts_total' => $debtTotal,
            'borrowing' => $borrowing,
            'finance_to_repay' => [
                'on_statement' => $financeTotal,
                'outside_loan' => $loan,
                'outside_loan_due' => (string) ($assumptions['loan']['due'] ?? ''),
                'outside_loan_rate' => (float) ($assumptions['loan']['rate'] ?? 0),
                'outside_deficit' => $borrowing['outside_deficit'],
                'total_to_repay' => round($financeTotal + $borrowing['outside_deficit'], 2),
                'promised_not_taken' => $pending,
            ],
            'movements' => $movements,
        ];
    }

    /**
     * Derrick's 250,000 and 65,000 paid bottles, caps and transport.
     * The balance of that, plus Diana's 40,000 and Derrick's further 50,000,
     * paid labels, label transport and the company phones.
     * Every later deduction funded operations and the other purchases.
     * The outside deficit is the part of that later spend the customer
     * paybills did not cover.
     *
     * @param  list<array<string,mixed>>  $financeLines
     * @param  array<string,mixed>  $bills
     * @param  list<array<string,mixed>>  $purchaseLines
     * @return array<string,mixed>
     */
    private function borrowing(array $financeLines, array $bills, array $purchaseLines, float $collections, float $balance): array
    {
        $derrickFirst = 0.0;
        $derrickTopUp = 0.0;
        $diana = 0.0;
        foreach ($financeLines as $line) {
            $amount = (float) $line['paid_in'];
            $party = strtolower((string) $line['party']);
            if (str_contains($party, 'langat')) {
                if ($amount >= 60000) {
                    $derrickFirst += $amount;
                } else {
                    $derrickTopUp += $amount;
                }
                continue;
            }
            $diana += $amount;
        }

        $paid = function (callable $match) use ($purchaseLines): float {
            $sum = 0.0;
            foreach ($purchaseLines as $line) {
                if ($match(strtolower((string) $line['label']))) {
                    $sum += (float) $line['paid'];
                }
            }

            return round($sum, 2);
        };
        $bottles = $paid(fn ($label) => str_contains($label, 'bottle') && ! str_contains($label, 'transport'));
        $transport = $paid(fn ($label) => str_contains($label, 'transport'));
        $labels = $paid(fn ($label) => str_contains($label, 'label'));
        $startupPurchases = round($bottles + $transport + $labels, 2);
        $otherPurchases = round(array_sum(array_column($purchaseLines, 'paid')) - $startupPurchases, 2);

        $phones = 0.0;
        foreach ($bills['lines'] as $line) {
            $description = strtolower((string) ($line['description'] ?? ''));
            if (str_contains($description, 'phone') && ! str_contains($description, 'internet') && ! str_contains($description, 'minute')) {
                $phones += (float) $line['amount'];
            }
        }
        $phones = round($phones, 2);

        $pot1 = round($derrickFirst, 2);
        $pot1Balance = round($pot1 - $bottles - $transport, 2);
        $pot2 = round($pot1Balance + $diana + $derrickTopUp, 2);
        $labelTransport = round($pot2 - $labels - $phones, 2);

        $laterOperations = round((float) $bills['total'] - $phones, 2);
        $laterSpend = round($laterOperations + $otherPurchases, 2);
        $customerUsed = round(max(0, $collections - min($balance, $collections)), 2);
        $outside = round($laterSpend - $customerUsed + max(0, -$labelTransport), 2);

        return [
            'in_account' => round($pot1 + $diana + $derrickTopUp, 2),
            'pot1_in' => $pot1,
            'bottles' => $bottles,
            'transport' => $transport,
            'pot1_balance' => $pot1Balance,
            'diana' => round($diana, 2),
            'derrick_topup' => round($derrickTopUp, 2),
            'pot2_in' => $pot2,
            'labels' => $labels,
            'phones' => $phones,
            'label_transport' => max(0, $labelTransport),
            'later_operations' => $laterOperations,
            'other_purchases' => $otherPurchases,
            'later_spend' => $laterSpend,
            'customer_money_used' => $customerUsed,
            'outside_deficit' => max(0, $outside),
        ];
    }

    /** @param array<string,mixed> $row */
    private function kind(array $row): string
    {
        if ((float) $row['withdrawn'] > 0) {
            return 'deduction';
        }
        $party = strtolower((string) $row['party']);
        $account = strtolower((string) $row['account']);
        if (str_contains($party, 'langat') && (float) $row['paid_in'] > 0) {
            return 'finance';
        }
        if (str_contains($party, 'wenwa') && str_contains($account, 'water') && (float) $row['paid_in'] >= 10000) {
            return 'finance';
        }

        return 'collection';
    }

    /** @return array<string,mixed> */
    private function bills(): array
    {
        $tokens = 0.0;
        $petty = 0.0;
        $lines = [];
        $duplicates = [];
        $purchaseCash = ['shadey_1' => 0.0, 'shadey_2' => 0.0, 'wiring' => 0.0];

        $entries = PettyCashEntry::query()
            ->with('account')
            ->whereNull('deleted_at')
            ->orderBy('entry_date')
            ->orderBy('created_at')
            ->get();

        foreach ($entries as $entry) {
            $out = round((float) $entry->amount_out, 2);
            if ($out <= 0) {
                continue;
            }
            $code = (string) ($entry->account->code ?? '');
            $accountName = strtolower((string) ($entry->account->description ?? ''));
            $description = (string) $entry->description;
            $desc = strtolower($description);
            $date = optional($entry->entry_date)->toDateString();
            $row = ['date' => $date, 'description' => $description, 'amount' => $out];

            $isToken = $code === '5460' || str_contains($desc, 'token');
            $isFuel = $code === '5200' || str_contains($accountName, 'fuel') || str_contains($desc, 'fuel');
            $isShadey = str_contains($desc, 'shadey');
            $isService = $desc === 'service payments' || str_contains($desc, 'shadey') && str_contains($desc, '27/26');
            $isWiring = str_contains($desc, 'wiring');

            if ($isToken) {
                $tokens += $out;
                $lines[] = $row + ['bucket' => 'Tokens'];
                continue;
            }
            if ($isFuel) {
                $duplicates[] = $row + ['reason' => 'Fuel is taken from the fuel log, so this petty line is not added again'];
                continue;
            }
            if ($isShadey) {
                $purchaseCash['shadey_1'] += $out;
                $duplicates[] = $row + ['reason' => 'Shadey payment, counted on the purchase account'];
                continue;
            }
            if ($isService) {
                $purchaseCash['shadey_2'] += $out;
                $duplicates[] = $row + ['reason' => 'Second Shadey payment, counted on the purchase account'];
                continue;
            }
            if ($isWiring) {
                $purchaseCash['wiring'] += $out;
                $duplicates[] = $row + ['reason' => 'Wiring, counted on the purchase account'];
                continue;
            }

            $petty += $out;
            $lines[] = $row + ['bucket' => 'Petty cash'];
        }

        $fuel = round((float) FuelLog::query()->whereNull('deleted_at')->sum('cost'), 2);
        $tokens = round($tokens, 2);
        $petty = round($petty, 2);

        return [
            'tokens' => $tokens,
            'fuel' => $fuel,
            'petty' => $petty,
            'total' => round($tokens + $fuel + $petty, 2),
            'lines' => $lines,
            'duplicates' => $duplicates,
            'purchase_cash' => $purchaseCash,
        ];
    }

    /**
     * @param  array{shadey_1:float,shadey_2:float,wiring:float}  $cash
     * @return array{lines:list<array<string,mixed>>}
     */
    private function purchases(array $cash): array
    {
        $items = $this->model->effective()['spend_items'] ?? [];
        $lines = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = (string) ($item['label'] ?? '');
            $full = round((float) ($item['full'] ?? 0), 2);
            $paid = round((float) ($item['paid'] ?? 0), 2);
            $note = (string) ($item['note'] ?? '');
            $low = strtolower($label);

            if (str_contains($low, 'spc-2026-1042') && $cash['shadey_1'] > 0) {
                $paid = round($cash['shadey_1'], 2);
                $note = 'First Shadey payment, entered in petty cash on 8 Oct. The planning sheet had been holding KES 6,000 as retainage; the cash book paid the invoice in full.';
            } elseif ((str_contains($low, '27/26') || str_contains($low, 'technical services')) && $cash['shadey_2'] > 0) {
                $paid = round($cash['shadey_2'], 2);
                $note = 'Second Shadey payment, entered in petty cash on 23 Oct. The planning sheet had been holding KES 3,000 as retainage; the cash book paid the invoice in full.';
            } elseif (str_contains($low, 'wiring') && $cash['wiring'] > 0) {
                $paid = round($cash['wiring'], 2);
                $note = 'Cash book paid KES '.number_format($paid, 0).'. The planning sheet had KES '.number_format($full, 0).'.';
            }

            $lines[] = [
                'label' => $label,
                'full' => $full,
                'paid' => $paid,
                'still_owed' => round(max(0, $full - $paid), 2),
                'note' => $note,
            ];
        }

        return ['lines' => $lines];
    }

    /** @return array{orders:float,trips:float,total:float,mpesa_already_on_statement:float} */
    private function cashSales(): array
    {
        $orders = round((float) DB::table('orders')
            ->whereNull('deleted_at')
            ->where('payment_method', 'cash')
            ->where(function ($q) {
                $q->whereNull('payment_status')->orWhere('payment_status', 'paid');
            })
            ->sum('total_amount'), 2);

        $trips = round((float) DB::table('driver_trip_sales')
            ->whereNull('deleted_at')
            ->whereNull('source_order_id')
            ->where('payment_method', 'cash')
            ->where(function ($q) {
                $q->whereNull('payment_status')->orWhere('payment_status', 'paid');
            })
            ->sum('amount'), 2);

        $mpesa = round((float) DB::table('orders')
            ->whereNull('deleted_at')
            ->where('payment_method', 'mpesa')
            ->where('payment_status', 'paid')
            ->sum('total_amount'), 2)
            + round((float) DB::table('driver_trip_sales')
                ->whereNull('deleted_at')
                ->whereNull('source_order_id')
                ->where('payment_method', 'mpesa')
                ->where(function ($q) {
                    $q->whereNull('payment_status')->orWhere('payment_status', 'paid');
                })
                ->sum('amount'), 2);

        return [
            'orders' => $orders,
            'trips' => $trips,
            'total' => round($orders + $trips, 2),
            'mpesa_already_on_statement' => round($mpesa, 2),
        ];
    }
}
