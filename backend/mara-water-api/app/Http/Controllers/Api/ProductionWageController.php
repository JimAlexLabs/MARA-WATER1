<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\ProductionWageEntry;
use App\Models\Sku;
use App\Models\User;
use App\Services\OperationsModelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daily production-lady piece-rate book. Director only.
 * Pay = bales × bottles per bale × KES per bottle from Operations / Planning,
 * snapshotted on the row. Month payable is capped at the lady's salary.
 */
class ProductionWageController extends Controller
{
    public function day(Request $request, OperationsModelService $model)
    {
        $date = $request->validate(['date' => 'required|date'])['date'];

        return response()->json([
            'success' => true,
            'data' => $this->dayPayload($date, $model),
        ]);
    }

    public function save(Request $request, OperationsModelService $model)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'lines' => 'required|array',
            'lines.*.user_id' => 'required|uuid|exists:users,id',
            'lines.*.sku_id' => 'required|uuid|exists:skus,id',
            'lines.*.kind' => 'required|in:produce,refill',
            'lines.*.bales' => 'required|numeric|min:0',
            'caps' => 'nullable|array',
            'caps.*.user_id' => 'required_with:caps|uuid|exists:users,id',
            'caps.*.monthly_cap' => 'required_with:caps|numeric|min:0',
        ]);

        $rates = $this->rates($model);
        foreach ($data['lines'] as $line) {
            $bales = round((float) $line['bales'], 2);
            $existing = ProductionWageEntry::where('work_date', $data['date'])
                ->where('user_id', $line['user_id'])
                ->where('sku_id', $line['sku_id'])
                ->where('kind', $line['kind'])
                ->first();
            if ($bales <= 0) {
                $existing?->delete();
                continue;
            }
            $rate = $rates[$line['sku_id']] ?? ['bottles_per_bale' => 1, 'wage_per_bottle' => 0];
            $bpb = $existing ? (int) $existing->bottles_per_bale : (int) $rate['bottles_per_bale'];
            $wage = $existing ? (float) $existing->wage_per_bottle : (float) $rate['wage_per_bottle'];
            $amount = round($bales * $bpb * $wage, 2);
            if ($existing) {
                $existing->update(['bales' => $bales, 'amount' => $amount]);
            } else {
                ProductionWageEntry::create([
                    'work_date' => $data['date'],
                    'user_id' => $line['user_id'],
                    'sku_id' => $line['sku_id'],
                    'kind' => $line['kind'],
                    'bales' => $bales,
                    'bottles_per_bale' => $bpb,
                    'wage_per_bottle' => $wage,
                    'amount' => $amount,
                    'created_by' => Auth::id(),
                ]);
            }
        }

        foreach ($data['caps'] ?? [] as $cap) {
            User::where('id', $cap['user_id'])->update(['salary' => $cap['monthly_cap']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Production wages saved',
            'data' => $this->dayPayload($data['date'], $model),
        ]);
    }

    public function month(Request $request)
    {
        $month = $request->validate(['month' => 'required|date_format:Y-m'])['month'];
        $start = $month.'-01';
        $end = date('Y-m-t', strtotime($start));

        $entries = ProductionWageEntry::with(['user:id,first_name,last_name,salary', 'sku:id,name,code'])
            ->whereBetween('work_date', [$start, $end])
            ->get();

        $byLady = [];
        foreach ($entries as $row) {
            $id = $row->user_id;
            if (! isset($byLady[$id])) {
                $cap = (float) ($row->user->salary ?? 10000);
                $byLady[$id] = [
                    'user_id' => $id,
                    'name' => trim(($row->user->first_name ?? '').' '.($row->user->last_name ?? '')),
                    'earned' => 0,
                    'monthly_cap' => $cap > 0 ? $cap : 10000,
                    'days' => [],
                ];
            }
            $byLady[$id]['earned'] += (float) $row->amount;
            $day = $row->work_date->toDateString();
            $byLady[$id]['days'][$day] = ($byLady[$id]['days'][$day] ?? 0) + (float) $row->amount;
        }
        $ladies = array_values(array_map(function ($row) {
            $row['earned'] = round($row['earned'], 2);
            $row['payable'] = round(min($row['earned'], $row['monthly_cap']), 2);
            return $row;
        }, $byLady));

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'ladies' => $ladies,
                'earned_total' => round(array_sum(array_column($ladies, 'earned')), 2),
                'payable_total' => round(array_sum(array_column($ladies, 'payable')), 2),
            ],
        ]);
    }

    public function export(Request $request, OperationsModelService $model)
    {
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();

        if ($request->filled('month')) {
            $month = $request->validate(['month' => 'required|date_format:Y-m'])['month'];
            $payload = json_decode($this->month($request)->getContent(), true)['data'];
            $ws->fromArray(['Lady', 'Earned (KES)', 'Monthly cap (KES)', 'Payable (KES)'], null, 'A1');
            $r = 2;
            foreach ($payload['ladies'] as $lady) {
                $ws->fromArray([$lady['name'], $lady['earned'], $lady['monthly_cap'], $lady['payable']], null, 'A'.$r);
                $r++;
            }
            $name = "production-wages-{$month}.xlsx";
        } else {
            $date = $request->validate(['date' => 'required|date'])['date'];
            $payload = $this->dayPayload($date, $model);
            $headers = ['Lady'];
            foreach ($payload['skus'] as $sku) {
                $headers[] = $sku['name'].' new';
                $headers[] = $sku['name'].' refill';
            }
            $headers[] = 'Pay (KES)';
            $ws->fromArray($headers, null, 'A1');
            $r = 2;
            foreach ($payload['ladies'] as $lady) {
                $line = [$lady['name']];
                $pay = 0;
                foreach ($payload['skus'] as $sku) {
                    $produce = $this->cell($payload['entries'], $lady['id'], $sku['id'], 'produce');
                    $refill = $this->cell($payload['entries'], $lady['id'], $sku['id'], 'refill');
                    $line[] = $produce['bales'];
                    $line[] = $refill['bales'];
                    $pay += $produce['amount'] + $refill['amount'];
                }
                $line[] = round($pay, 2);
                $ws->fromArray($line, null, 'A'.$r);
                $r++;
            }
            $name = "production-wages-{$date}.xlsx";
        }

        $writer = new Xlsx($sheet);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    private function dayPayload(string $date, OperationsModelService $model): array
    {
        $ladies = User::with('role')
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('code', 'BP'))
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => trim($u->first_name.' '.$u->last_name),
                'monthly_cap' => (float) ($u->salary ?? 0) > 0 ? (float) $u->salary : 10000,
            ])
            ->values();

        $rates = $this->rates($model);
        $touchedIds = ProductionWageEntry::whereDate('work_date', $date)->pluck('sku_id')
            ->merge(
                Batch::whereNull('deleted_at')->whereDate('manufacture_date', $date)->pluck('sku_id')
            )
            ->filter()
            ->unique()
            ->values();
        $skus = Sku::whereNull('deleted_at')
            ->where(function ($q) use ($touchedIds) {
                $q->where('active', true)->orWhereNull('active');
                if ($touchedIds->isNotEmpty()) {
                    $q->orWhereIn('id', $touchedIds->all());
                }
            })
            ->orderBy('name')
            ->get()
            ->map(function (Sku $sku) use ($rates) {
            $rate = $rates[$sku->id] ?? ['bottles_per_bale' => $sku->bottlesPerBale(), 'wage_per_bottle' => 0, 'size' => $sku->sizeCode()];

            return [
                'id' => $sku->id,
                'name' => $sku->name,
                'code' => $sku->code,
                'size' => $rate['size'],
                'bottles_per_bale' => $rate['bottles_per_bale'],
                'wage_per_bottle' => $rate['wage_per_bottle'],
                'wage_per_bale' => round($rate['bottles_per_bale'] * $rate['wage_per_bottle'], 4),
            ];
        })->values();

        $entries = ProductionWageEntry::whereDate('work_date', $date)->get()->map(fn ($e) => [
            'user_id' => $e->user_id,
            'sku_id' => $e->sku_id,
            'kind' => $e->kind,
            'bales' => (float) $e->bales,
            'amount' => (float) $e->amount,
            'bottles_per_bale' => (int) $e->bottles_per_bale,
            'wage_per_bottle' => (float) $e->wage_per_bottle,
        ])->values();

        $produced = Batch::whereNull('deleted_at')
            ->whereDate('manufacture_date', $date)
            ->selectRaw('sku_id, SUM(COALESCE(actual_qty, planned_qty)) as bales')
            ->groupBy('sku_id')
            ->pluck('bales', 'sku_id');

        $keyed = [];
        foreach ($entries as $e) {
            $keyed[$e['sku_id']] = ($keyed[$e['sku_id']] ?? 0) + $e['bales'];
        }
        $reconciliation = $skus->map(function ($sku) use ($produced, $keyed) {
            $book = (float) ($produced[$sku['id']] ?? 0);
            $sheet = (float) ($keyed[$sku['id']] ?? 0);

            return [
                'sku_id' => $sku['id'],
                'name' => $sku['name'],
                'production_bales' => $book,
                'sheet_bales' => $sheet,
                'matches' => abs($book - $sheet) < 0.01,
            ];
        })->filter(fn ($row) => $row['production_bales'] > 0 || $row['sheet_bales'] > 0)->values();

        return [
            'date' => $date,
            'ladies' => $ladies,
            'skus' => $skus,
            'entries' => $entries,
            'reconciliation' => $reconciliation,
            'day_pay' => round($entries->sum('amount'), 2),
        ];
    }

    /** @return array<string, array{bottles_per_bale:int, wage_per_bottle:float, size:string}> */
    private function rates(OperationsModelService $model): array
    {
        $effective = $model->effective();
        $out = [];
        foreach (Sku::whereNull('deleted_at')->get() as $sku) {
            $size = $sku->sizeCode();
            $bpb = (int) ($effective['bottles_per_bale'][$size] ?? $sku->bottlesPerBale());
            $wage = (float) ($effective['wage_per_bottle'][$size] ?? 0);
            $out[$sku->id] = [
                'size' => $size,
                'bottles_per_bale' => max(1, $bpb),
                'wage_per_bottle' => $wage,
            ];
        }

        return $out;
    }

    private function cell($entries, string $userId, string $skuId, string $kind): array
    {
        foreach ($entries as $e) {
            if ($e['user_id'] === $userId && $e['sku_id'] === $skuId && $e['kind'] === $kind) {
                return $e;
            }
        }

        return ['bales' => 0, 'amount' => 0];
    }
}
