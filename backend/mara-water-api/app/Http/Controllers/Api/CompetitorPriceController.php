<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompetitorCompany;
use App\Models\CompetitorPrice;
use App\Models\Sku;
use App\Services\PriceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ops brief §5: Competitive pricing comparison — Manager logs competitor
 * prices (dropdown of named companies) against MARA default per SKU.
 * Distinct from corporate negotiated rates on price_lists.
 */
class CompetitorPriceController extends Controller
{
    public function __construct(private PriceService $prices)
    {
    }

    public function companies()
    {
        return response()->json([
            'success' => true,
            'data' => CompetitorCompany::where('active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Comparison matrix: SKU rows × MARA default + each competitor column.
     */
    public function matrix(Request $request)
    {
        $companies = CompetitorCompany::where('active', true)->orderBy('name')->get();
        $skus = Sku::where('active', true)->whereNull('deleted_at')
            ->orderBy('brand')->orderBy('name')->get();
        $mara = $this->prices->priceMap('default');
        $corporate = $this->prices->priceMap('corporate');

        $compPrices = CompetitorPrice::whereNull('deleted_at')
            ->whereIn('competitor_company_id', $companies->pluck('id'))
            ->get()
            ->groupBy('competitor_company_id');

        $rows = $skus->map(function ($sku) use ($mara, $corporate, $companies, $compPrices) {
            $competitors = [];
            foreach ($companies as $c) {
                $price = optional($compPrices->get($c->id)?->firstWhere('sku_id', $sku->id))->unit_price;
                $competitors[$c->id] = $price !== null ? (float) $price : null;
            }

            return [
                'sku_id' => $sku->id,
                'code' => $sku->code,
                'name' => $sku->name,
                'brand' => $sku->brand,
                'size_liters' => $sku->size_liters,
                'mara_default' => $mara[$sku->id] ?? null,
                'mara_corporate' => $corporate[$sku->id] ?? null,
                'competitors' => $competitors,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'companies' => $companies,
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * Upsert one competitor price for a SKU. Manager or Director only.
     */
    public function upsert(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->accessTier(), ['manager', 'director'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only Manager or Director can log competitive pricing.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'competitor_company_id' => 'required|uuid|exists:competitor_companies,id',
            'sku_id' => 'required|uuid|exists:skus,id',
            'unit_price' => 'required|numeric|min:0',
            'effective_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $row = CompetitorPrice::withTrashed()
            ->where('competitor_company_id', $request->competitor_company_id)
            ->where('sku_id', $request->sku_id)
            ->first();

        if ($row) {
            $row->restore();
            $row->update([
                'unit_price' => $request->unit_price,
                'effective_date' => $request->effective_date ?: now()->toDateString(),
                'notes' => $request->notes,
                'logged_by' => Auth::id(),
            ]);
        } else {
            $row = CompetitorPrice::create([
                'competitor_company_id' => $request->competitor_company_id,
                'sku_id' => $request->sku_id,
                'unit_price' => $request->unit_price,
                'effective_date' => $request->effective_date ?: now()->toDateString(),
                'notes' => $request->notes,
                'logged_by' => Auth::id(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Competitor price saved',
            'data' => $row->load('company', 'sku'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $matrix = $this->matrix($request)->getData(true)['data'];
        $companies = $matrix['companies'];
        $rows = $matrix['rows'];

        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Competitive Pricing');

        $headers = ['Code', 'Brand', 'Name', 'Size (L)', 'MARA Default', 'MARA Corporate'];
        foreach ($companies as $c) {
            $headers[] = $c['name'];
        }
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, 1], $h);
        }

        $r = 2;
        foreach ($rows as $row) {
            $ws->setCellValue([1, $r], $row['code']);
            $ws->setCellValue([2, $r], $row['brand']);
            $ws->setCellValue([3, $r], $row['name']);
            $ws->setCellValue([4, $r], $row['size_liters']);
            $ws->setCellValue([5, $r], $row['mara_default']);
            $ws->setCellValue([6, $r], $row['mara_corporate']);
            $col = 7;
            foreach ($companies as $c) {
                $ws->setCellValue([$col, $r], $row['competitors'][$c['id']] ?? '');
                $col++;
            }
            $r++;
        }

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="competitive-pricing.xlsx"',
        ]);
    }
}
