<?php

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Align the live catalog with suggested retail (Oct 2026):
 * Premium 0.5=390, 1=390, 1.5=400, 5=500, 10=300, 20=500;
 * Premium Custom 0.5=500; Platinum 0.5/1=500; Platinum Custom 0.5/1=550.
 */
class SyncOperationalPricing extends Command
{
    protected $signature = 'pricing:sync-operational {--force : Re-apply even if already synced}';

    protected $description = 'Sync SKU catalog + default/corporate prices to suggested retail sheet';

    /** @var array<string,float> Premium retail (KES / bale or unit) */
    private const PREMIUM_PRICES = [
        '0.50' => 390.00,
        '1.00' => 390.00,
        '1.50' => 400.00,
        '5.00' => 500.00,
        '10.00' => 300.00,
        '20.00' => 500.00,
    ];

    private const PLATINUM_PRICES = [
        '0.50' => 500.00,
        '1.00' => 500.00,
    ];

    private const CUSTOM_PRICES = [
        'PREM-CUSTOM-0.5L' => 500.00,
        'PLAT-CUSTOM-0.5L' => 550.00,
        'PLAT-CUSTOM-1L' => 550.00,
    ];

    private const ALLOWED_SIZES = [0.5, 1.0, 1.5, 5.0, 10.0, 20.0];

    public function handle(): int
    {
        $actor = User::whereHas('role', fn ($q) => $q->where('access_tier', 'director'))->first();
        $actorId = $actor?->id;

        DB::transaction(function () use ($actorId) {
            $this->deactivateNonOperational($actorId);
            $prem05 = $this->ensurePremium05($actorId);
            $this->syncPremiumPrices($prem05, $actorId);
            $this->syncBrandPrices('Platinum', self::PLATINUM_PRICES, $actorId);
            $this->syncCodedPrices(self::CUSTOM_PRICES, $actorId);
            $this->refreshListWindows($actorId);
        });

        $this->info('Operational pricing synced.');
        $this->line('  Premium: 0.5/1=390, 1.5=400, 5/20=500, 10=300');
        $this->line('  Platinum: 0.5/1=500; Custom Prem 0.5=500; Plat Custom 0.5/1=550');
        $this->line('  255ml + Mara Water duplicates deactivated');

        return 0;
    }

    private function deactivateNonOperational(?string $actorId): void
    {
        // Mara Water was merged into Premium (Round 4).
        Sku::where(function ($q) {
            $q->where('code', 'like', 'WATER-%')->orWhere('brand', 'Mara Water');
        })->get()->each(function (Sku $sku) use ($actorId) {
            if ($sku->active) {
                $sku->update(['active' => false, 'updated_by' => $actorId]);
                $this->line("  deactivated {$sku->code}");
            }
        });

        // 255ml is not in the live size set.
        Sku::where(function ($q) {
            $q->where('code', 'PREM-0.255L')
                ->orWhere(function ($q2) {
                    $q2->where('brand', 'Premium')->whereBetween('size_liters', [0.2, 0.3]);
                });
        })->get()->each(function (Sku $sku) use ($actorId) {
            if ($sku->active) {
                $sku->update(['active' => false, 'updated_by' => $actorId]);
                $this->line("  deactivated {$sku->code}");
            }
        });

    }

    private function ensurePremium05(?string $actorId): Sku
    {
        $sku = Sku::where('code', 'PREM-0.5L')->first();
        if ($sku) {
            $sku->update([
                'name' => 'Premium 0.5L Bottle',
                'brand' => 'Premium',
                'size_liters' => 0.5,
                'unit' => $sku->unit ?: 'BOTTLE',
                'active' => true,
                'updated_by' => $actorId,
            ]);
            return $sku->fresh();
        }

        return Sku::create([
            'code' => 'PREM-0.5L',
            'name' => 'Premium 0.5L Bottle',
            'brand' => 'Premium',
            'size_liters' => 0.5,
            'unit' => 'BOTTLE',
            'expiry_days' => 365,
            'reorder_threshold' => 20,
            'active' => true,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }

    private function syncPremiumPrices(Sku $prem05, ?string $actorId): void
    {
        $default = PriceList::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('is_default', true)->orWhere('list_kind', 'default');
            })
            ->orderByDesc('is_default')
            ->first();

        if (!$default) {
            throw new \RuntimeException('No default price list found');
        }

        $corporate = PriceList::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('list_kind', 'corporate')
                    ->orWhere('name', 'like', '%Corporate%')
                    ->orWhere('name', 'like', '%Wholesale%');
            })
            ->orderByRaw("CASE WHEN list_kind = 'corporate' THEN 0 ELSE 1 END")
            ->get();

        $premiumSkus = Sku::where('brand', 'Premium')
            ->where('active', true)
            ->whereNull('deleted_at')
            ->get()
            ->filter(function (Sku $sku) {
                $size = round((float) $sku->size_liters, 2);
                return in_array($size, self::ALLOWED_SIZES, true)
                    && stripos($sku->code, 'CUSTOM') === false;
            });

        // Ensure PREM-0.5L is in the set
        if (!$premiumSkus->contains('id', $prem05->id)) {
            $premiumSkus->push($prem05);
        }

        $targets = collect([$default])->merge($corporate)->unique('id');

        foreach ($targets as $list) {
            foreach ($premiumSkus as $sku) {
                $key = number_format((float) $sku->size_liters, 2, '.', '');
                if (!isset(self::PREMIUM_PRICES[$key])) {
                    continue;
                }
                $price = self::PREMIUM_PRICES[$key];
                $item = PriceListItem::withTrashed()
                    ->where('price_list_id', $list->id)
                    ->where('sku_id', $sku->id)
                    ->first();
                if ($item) {
                    if ($item->trashed()) {
                        $item->restore();
                    }
                    $item->update([
                        'unit_price' => $price,
                        'updated_by' => $actorId,
                    ]);
                } else {
                    PriceListItem::create([
                        'price_list_id' => $list->id,
                        'sku_id' => $sku->id,
                        'unit_price' => $price,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                }
                $this->line("  {$list->name}: {$sku->code} → {$price}");
            }
        }
    }

    /**
     * @param  array<string,float>  $bySize
     */
    private function syncBrandPrices(string $brand, array $bySize, ?string $actorId): void
    {
        $lists = $this->targetLists();
        $skus = Sku::where('brand', $brand)->where('active', true)->whereNull('deleted_at')->get()
            ->filter(fn (Sku $sku) => stripos($sku->code, 'CUSTOM') === false);

        foreach ($lists as $list) {
            foreach ($skus as $sku) {
                $key = number_format((float) $sku->size_liters, 2, '.', '');
                if (! isset($bySize[$key])) {
                    continue;
                }
                $this->upsertPrice($list->id, $sku->id, $bySize[$key], $actorId);
                $this->line("  {$list->name}: {$sku->code} → {$bySize[$key]}");
            }
        }
    }

    /**
     * @param  array<string,float>  $byCode
     */
    private function syncCodedPrices(array $byCode, ?string $actorId): void
    {
        $lists = $this->targetLists();
        foreach ($byCode as $code => $price) {
            $sku = Sku::where('code', $code)->first();
            if (! $sku && $code === 'PREM-CUSTOM-0.5L') {
                $sku = Sku::create([
                    'code' => 'PREM-CUSTOM-0.5L',
                    'name' => 'Premium Custom 0.5L',
                    'brand' => 'Premium',
                    'size_liters' => 0.5,
                    'unit' => 'BOTTLE',
                    'expiry_days' => 365,
                    'reorder_threshold' => 20,
                    'active' => true,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
                $this->line("  created {$code}");
            }
            if (! $sku) {
                $this->warn("  missing SKU {$code} — skipped");
                continue;
            }
            $sku->update(['active' => true, 'updated_by' => $actorId]);
            foreach ($lists as $list) {
                $this->upsertPrice($list->id, $sku->id, $price, $actorId);
                $this->line("  {$list->name}: {$code} → {$price}");
            }
        }
    }

    private function targetLists()
    {
        $default = PriceList::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('is_default', true)->orWhere('list_kind', 'default');
            })
            ->orderByDesc('is_default')
            ->first();
        if (! $default) {
            throw new \RuntimeException('No default price list found');
        }
        $corporate = PriceList::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('list_kind', 'corporate')
                    ->orWhere('name', 'like', '%Corporate%')
                    ->orWhere('name', 'like', '%Wholesale%');
            })
            ->get();

        return collect([$default])->merge($corporate)->unique('id');
    }

    private function upsertPrice(string $listId, string $skuId, float $price, ?string $actorId): void
    {
        $item = PriceListItem::withTrashed()
            ->where('price_list_id', $listId)
            ->where('sku_id', $skuId)
            ->first();
        if ($item) {
            if ($item->trashed()) {
                $item->restore();
            }
            $item->update(['unit_price' => $price, 'updated_by' => $actorId]);
        } else {
            PriceListItem::create([
                'price_list_id' => $listId,
                'sku_id' => $skuId,
                'unit_price' => $price,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        }
    }

    private function refreshListWindows(?string $actorId): void
    {
        PriceList::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('is_default', true)->orWhere('list_kind', 'default');
            })
            ->update([
                'name' => 'Retail Price List 2026 (default)',
                'list_kind' => 'default',
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'updated_by' => $actorId,
            ]);

        PriceList::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('list_kind', 'corporate')
                    ->orWhere('name', 'like', '%Corporate%')
                    ->orWhere('name', 'like', '%Wholesale%');
            })
            ->update([
                'list_kind' => 'corporate',
                'valid_from' => '2026-01-01',
                'valid_to' => '2026-12-31',
                'updated_by' => $actorId,
            ]);
    }
}
