<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Warehouse & Equipment Audit (Phase 10) -- recreates the "Warehouse
 * Audit" as a standing, repeatable module instead of a one-off Word
 * document. Every section here is either a filtered live view of data
 * that already exists (packaging materials, PPE, stationery, chemicals
 * -- all just materials with different categories, Phase 8) or a small
 * amount of genuinely new equipment/calibration state -- never a
 * separately maintained number.
 *
 * A material/equipment's own "reorder point" (materials.min_level,
 * equipment_items.minimum_required) is deliberately left at 0/null
 * until someone sets a real one via the Materials/Equipment UI -- see
 * the seed migrations' comments for why guessing one would be dishonest.
 */
class WarehouseAuditController extends Controller
{
    // A service/calibration date lands in "due soon" inside this many
    // days -- matches DashboardController's own expiry window.
    private const DUE_SOON_WINDOW_DAYS = 14;

    /**
     * Packaging materials stock watch -- labels, seals (bottle vs
     * refill jerrican), stickers, bailing papers. Same "materials"
     * data Phase 8 already tracks, just filtered to this category with
     * a plain-English status per item.
     */
    public function packagingWatch()
    {
        return response()->json(['success' => true, 'data' => $this->materialWatch('Packaging')]);
    }

    /**
     * Stationery stock -- receipt books, delivery books, invoice books.
     */
    public function stationeryWatch()
    {
        return response()->json(['success' => true, 'data' => $this->materialWatch('Stationery')]);
    }

    /**
     * Chemicals & water testing log (chemical stock side) -- chlorine
     * stock, flagged for both low stock and expiry. The spec is
     * explicit these two are unsafe to treat as separate issues, so
     * either one alone is enough to mark the item critical.
     */
    public function chemicalsWatch()
    {
        $rows = $this->materialWatch('Chemical');

        $rows = array_map(function ($row) {
            $material = $row['material'];
            $expiryDate = $material['expiry_date'] ?? null;
            $expiryStatus = 'not_set';
            if ($expiryDate) {
                $days = now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($expiryDate)->startOfDay(), false);
                $expiryStatus = $days < 0 ? 'expired' : ($days <= self::DUE_SOON_WINDOW_DAYS ? 'expiring_soon' : 'valid');
            }
            $row['expiry_date'] = $expiryDate;
            $row['expiry_status'] = $expiryStatus;
            if (in_array($expiryStatus, ['expired', 'expiring_soon']) && $row['severity'] !== 'critical') {
                $row['severity'] = $expiryStatus === 'expired' ? 'critical' : 'warning';
            }
            return $row;
        }, $rows);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * PPE tracking -- gunboots, raincoats, hair coverings against
     * headcount from HR (Phase 4), computed live rather than a static
     * minimum, so a shortage is calculated, not guessed.
     */
    public function ppeWatch()
    {
        $headcount = User::where('status', 'active')->count();

        $materials = Material::where('category', 'PPE')->whereNull('deleted_at')->get();
        $rows = $materials->map(function ($material) use ($headcount) {
            $qty = (float) StockItem::where('material_id', $material->id)->whereNull('deleted_at')->sum('qty');
            $shortfall = max(0, $headcount - $qty);

            return [
                'material' => $material,
                'qty_on_hand' => $qty,
                'headcount' => $headcount,
                'shortfall' => $shortfall,
                'status' => $shortfall > 0 ? 'shortage' : 'adequate',
                'severity' => $shortfall > 0 ? 'critical' : 'ok',
                'message' => $shortfall > 0
                    ? "{$material->name}: {$qty} on hand for {$headcount} active staff -- short by {$shortfall}"
                    : "{$material->name}: adequate for {$headcount} active staff",
            ];
        });

        return response()->json(['success' => true, 'data' => $rows->values()]);
    }

    /**
     * Equipment & machinery log -- count on hand vs minimum needed,
     * condition, and last/next service date so overdue maintenance
     * gaps get flagged automatically.
     */
    public function equipmentStatus()
    {
        return response()->json(['success' => true, 'data' => $this->equipmentWatch('equipment')]);
    }

    /**
     * Test equipment (pH tester etc.) -- calibration status and
     * availability, since "missing test equipment" was flagged as a
     * blocking issue alongside expired chemicals.
     */
    public function testEquipmentStatus()
    {
        return response()->json(['success' => true, 'data' => $this->equipmentWatch('test_equipment')]);
    }

    /**
     * Round 5B Phase 1: stock arrivals / material batches with their
     * quality-check status — the Warehouse Audit link for every purchase.
     */
    public function receiptQuality(Request $request)
    {
        $limit = min((int) $request->get('limit', 50), 200);
        return response()->json(['success' => true, 'data' => $this->receiptQualityWatch($limit)]);
    }

    /**
     * Ranked critical gaps -- anything out-of-stock, overdue-
     * maintenance, expired, or missing/uncalibrated surfaces at the
     * top, same "ranked by what actually blocks production" logic the
     * original audit used.
     */
    public function criticalGaps()
    {
        $gaps = [];

        foreach (['Packaging' => 'packaging', 'Stationery' => 'stationery'] as $category => $label) {
            foreach ($this->materialWatch($category) as $row) {
                if ($row['severity'] !== 'ok') {
                    $gaps[] = ['area' => $label, 'severity' => $row['severity'], 'message' => $row['message']];
                }
            }
        }

        foreach ($this->chemicalsWatch()->getData(true)['data'] as $row) {
            if ($row['severity'] !== 'ok') {
                $gaps[] = ['area' => 'chemicals', 'severity' => $row['severity'], 'message' => $row['message']];
            }
        }

        foreach ($this->ppeWatch()->getData(true)['data'] as $row) {
            if ($row['severity'] !== 'ok') {
                $gaps[] = ['area' => 'ppe', 'severity' => $row['severity'], 'message' => $row['message']];
            }
        }

        foreach (['equipment' => 'equipment', 'test_equipment' => 'test_equipment'] as $category => $label) {
            foreach ($this->equipmentWatch($category) as $row) {
                if ($row['severity'] !== 'ok') {
                    $gaps[] = ['area' => $label, 'severity' => $row['severity'], 'message' => $row['message']];
                }
            }
        }

        // Round 5B Phase 1: pending/failed receipt quality checks are
        // Warehouse Audit gaps — stock arrived but isn't cleared for use.
        foreach ($this->receiptQualityWatch() as $row) {
            if ($row['severity'] !== 'ok') {
                $gaps[] = ['area' => 'receipts', 'severity' => $row['severity'], 'message' => $row['message']];
            }
        }

        $severityOrder = ['critical' => 0, 'warning' => 1];
        usort($gaps, fn ($a, $b) => ($severityOrder[$a['severity']] ?? 2) <=> ($severityOrder[$b['severity']] ?? 2));

        return response()->json([
            'success' => true,
            'data' => [
                'critical_count' => count(array_filter($gaps, fn ($g) => $g['severity'] === 'critical')),
                'warning_count' => count(array_filter($gaps, fn ($g) => $g['severity'] === 'warning')),
                'gaps' => $gaps,
            ],
        ]);
    }

    /**
     * Recent material-batch arrivals and their QA status.
     */
    private function receiptQualityWatch(?int $limit = null): array
    {
        $query = MaterialBatch::with(['material', 'warehouse', 'receivedBy', 'qualityCheckedBy'])
            ->orderByDesc('purchase_date')
            ->orderByDesc('created_at');

        if ($limit) {
            $query->limit($limit);
        } else {
            $query->whereIn('quality_status', ['pending', 'failed'])->limit(100);
        }

        return $query->get()->map(function (MaterialBatch $batch) {
            $severity = match ($batch->quality_status) {
                'failed' => 'critical',
                'pending' => 'warning',
                default => 'ok',
            };
            $materialName = $batch->material->name ?? 'Material';
            $message = match ($batch->quality_status) {
                'failed' => "{$materialName} batch {$batch->batch_number}: quality FAILED — not released to stock",
                'pending' => "{$materialName} batch {$batch->batch_number}: quality check PENDING — awaiting Warehouse Audit pass",
                default => "{$materialName} batch {$batch->batch_number}: quality passed",
            };

            return [
                'material_batch' => $batch,
                'quality_status' => $batch->quality_status,
                'severity' => $severity,
                'message' => $message,
            ];
        })->values()->toArray();
    }

    /**
     * Shared status logic for a materials category: on-hand qty
     * (summed across all warehouses) against the material's own
     * reorder point.
     */
    private function materialWatch(string $category): array
    {
        $materials = Material::where('category', $category)->whereNull('deleted_at')->get();

        return $materials->map(function ($material) {
            $qty = (float) StockItem::where('material_id', $material->id)->whereNull('deleted_at')->sum('qty');
            $minLevel = (float) $material->min_level;

            if ($qty <= 0) {
                $status = 'out_of_stock';
                $severity = 'critical';
            } elseif ($minLevel > 0 && $qty <= $minLevel) {
                $status = 'watch';
                $severity = 'warning';
            } else {
                $status = 'adequate';
                $severity = 'ok';
            }

            return [
                'material' => $material,
                'qty_on_hand' => $qty,
                'reorder_level' => $minLevel,
                'status' => $status,
                'severity' => $severity,
                'message' => "{$material->name}: {$qty} {$material->uom} on hand (" . ($minLevel > 0 ? "reorder at {$minLevel}" : 'no reorder level set') . ')',
            ];
        })->values()->toArray();
    }

    /**
     * Shared status logic for equipment/test_equipment: condition,
     * count vs minimum, and service/calibration due dates.
     */
    private function equipmentWatch(string $category): array
    {
        $items = EquipmentItem::where('category', $category)->whereNull('deleted_at')->orderBy('name')->get();

        return $items->map(function ($item) use ($category) {
            $severity = 'ok';
            $reasons = [];

            if ($item->condition === 'broken') {
                $severity = 'critical';
                $reasons[] = 'broken';
            } elseif ($item->condition === 'poor') {
                $severity = 'warning';
                $reasons[] = 'poor condition';
            }

            if ($item->qty_on_hand !== null && $item->minimum_required !== null && $item->qty_on_hand < $item->minimum_required) {
                $severity = 'critical';
                $reasons[] = "only {$item->qty_on_hand} on hand, needs {$item->minimum_required}";
            }

            $serviceStatus = 'not_set';
            if ($item->next_service_due) {
                $days = now()->startOfDay()->diffInDays($item->next_service_due->copy()->startOfDay(), false);
                $serviceStatus = $days < 0 ? 'overdue' : ($days <= self::DUE_SOON_WINDOW_DAYS ? 'due_soon' : 'ok');
                if ($serviceStatus === 'overdue') {
                    $severity = 'critical';
                    $reasons[] = 'service overdue';
                } elseif ($serviceStatus === 'due_soon' && $severity === 'ok') {
                    $severity = 'warning';
                    $reasons[] = 'service due soon';
                }
            }

            if ($category === 'test_equipment') {
                if ($item->qty_on_hand === 0) {
                    $severity = 'critical';
                    $reasons[] = 'none available';
                } elseif ($item->calibration_status === 'not_calibrated') {
                    $severity = 'critical';
                    $reasons[] = 'not calibrated';
                } elseif ($item->calibration_status === 'due' && $severity === 'ok') {
                    $severity = 'warning';
                    $reasons[] = 'calibration due';
                }
            }

            return [
                'item' => $item,
                'service_status' => $serviceStatus,
                'severity' => $severity,
                'message' => empty($reasons) ? "{$item->name}: OK" : "{$item->name}: " . implode(', ', $reasons),
            ];
        })->values()->toArray();
    }
}
