<?php

namespace Database\Seeders;

/**
 * Ops brief §8 — one full month of Mara Water ops (current calendar month).
 *
 *   php artisan db:seed --class=OpsMonthSimulationSeeder
 *
 * Targets: sales ≈ KES 1,500,000 | COGS ≈ KES 560k–600k
 * Fuel excise SCRAPPED (no KES 4.61/L). Marker: [SIM] / SIM- codes.
 * Re-runs delete prior SIM-tagged rows first.
 */

use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PackagingRunController;
use App\Models\Batch;
use App\Models\ChartOfAccount;
use App\Models\CompetitorCompany;
use App\Models\CompetitorPrice;
use App\Models\Customer;
use App\Models\DriverTrip;
use App\Models\DriverTripItem;
use App\Models\DriverTripSale;
use App\Models\DriverTripSaleItem;
use App\Models\FuelLog;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialBatchConsumption;
use App\Models\PackagingRun;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PettyCashEntry;
use App\Models\PriceListItem;
use App\Models\Role;
use App\Models\Sku;
use App\Models\StockItem;
use App\Models\StockMove;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRepair;
use App\Models\Warehouse;
use App\Services\PayrollCalculationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OpsMonthSimulationSeeder extends Seeder
{
    private const MARKER = '[SIM]';
    private const PREFIX = 'SIM-';
    private const SALES_TARGET = 1500000.0;
    private const COGS_TARGET = 580000.0;
    private const DIESEL_PER_L = 178.0; // pump only — no excise

    private Carbon $monthStart;
    private Carbon $monthEnd;
    private User $actor;
    private User $manager;
    private User $driverA;
    private User $driverB;
    private Warehouse $warehouse;
    private Vehicle $vehicle;
    /** @var Sku[] */
    private array $skus = [];
    private array $prices = [];

    public function run(): void
    {
        $this->monthStart = now()->startOfMonth()->startOfDay();
        $this->monthEnd = now()->endOfMonth()->endOfDay();
        $this->command?->info('OpsMonthSimulationSeeder — '.$this->monthStart->format('Y-m'));

        $this->clearPriorSimulation();
        $this->resolveContext();
        Auth::login($this->actor);

        DB::transaction(function () {
            $this->seedStockArrivals();
            $this->seedProduction();
            $customers = $this->seedCustomers();
            $this->seedTripsAndSales($customers);
            $this->seedPettyCashFuelElecRepairs();
            $this->seedFuelLogsAndVehicleRepairs();
            $this->seedCompetitorPrices();
            $this->seedPayroll();
        });

        $sales = (float) DriverTripSale::whereHas('trip', fn ($q) => $q->where('notes', 'like', '%'.self::MARKER.'%'))->sum('amount');
        $cogs = (float) MaterialBatch::where('batch_number', 'like', self::PREFIX.'%')
            ->selectRaw('COALESCE(SUM(unit_cost * qty_received), 0) as t')->value('t');
        $this->command?->info(sprintf('Done. Sales KES %s | COGS KES %s', number_format($sales, 0), number_format($cogs, 0)));
    }

    private function clearPriorSimulation(): void
    {
        if (!Schema::hasTable('driver_trips')) {
            throw new \RuntimeException('Core tables missing — run migrations (and base seeders) before OpsMonthSimulationSeeder.');
        }

        $tripIds = DriverTrip::withTrashed()->where('notes', 'like', '%'.self::MARKER.'%')->pluck('id');
        if ($tripIds->isNotEmpty()) {
            $saleIds = DriverTripSale::withTrashed()->whereIn('driver_trip_id', $tripIds)->pluck('id');
            DriverTripSaleItem::whereIn('driver_trip_sale_id', $saleIds)->delete();
            DriverTripSale::withTrashed()->whereIn('id', $saleIds)->forceDelete();
            DriverTripItem::whereIn('driver_trip_id', $tripIds)->delete();
            StockMove::withTrashed()->where('ref_entity', 'driver_trip')->whereIn('ref_id', $tripIds)->forceDelete();
            DriverTrip::withTrashed()->whereIn('id', $tripIds)->forceDelete();
        }

        $batchIds = Batch::withTrashed()->where('code', 'like', self::PREFIX.'%')->pluck('id');
        $runIds = PackagingRun::withTrashed()
            ->where(fn ($q) => $q->where('notes', 'like', '%'.self::MARKER.'%')->orWhereIn('batch_id', $batchIds))
            ->pluck('id');
        if ($runIds->isNotEmpty()) {
            MaterialBatchConsumption::whereIn('packaging_run_id', $runIds)->delete();
            StockMove::withTrashed()->where('ref_entity', 'packaging_run')->whereIn('ref_id', $runIds)->forceDelete();
            PackagingRun::withTrashed()->whereIn('id', $runIds)->forceDelete();
        }
        if ($batchIds->isNotEmpty()) {
            StockItem::withTrashed()->whereIn('batch_id', $batchIds)->forceDelete();
            Batch::withTrashed()->whereIn('id', $batchIds)->forceDelete();
        }

        $mbIds = MaterialBatch::where(function ($q) {
            $q->where('batch_number', 'like', self::PREFIX.'%')
                ->orWhere('notes', 'like', '%'.self::MARKER.'%');
        })->pluck('id');
        if ($mbIds->isNotEmpty()) {
            StockMove::withTrashed()->where('ref_entity', 'material_batch')->whereIn('ref_id', $mbIds)->forceDelete();
            MaterialBatch::whereIn('id', $mbIds)->delete();
        }

        Customer::withTrashed()->where('code', 'like', self::PREFIX.'%')->forceDelete();
        PettyCashEntry::withTrashed()->where('description', 'like', '%'.self::MARKER.'%')->forceDelete();
        VehicleRepair::withTrashed()->where('description', 'like', '%'.self::MARKER.'%')->forceDelete();

        $simIds = User::withTrashed()->where('email', 'like', 'sim-%@marawater.local')->pluck('id');
        if ($simIds->isNotEmpty()) {
            FuelLog::withTrashed()->whereIn('created_by', $simIds)->forceDelete();
            $runs = PayrollRun::withTrashed()->whereDate('month', $this->monthStart->toDateString())->whereIn('created_by', $simIds)->pluck('id');
            Payslip::withTrashed()->whereIn('payroll_run_id', $runs)->forceDelete();
            PayrollRun::withTrashed()->whereIn('id', $runs)->forceDelete();
        }
        if (Schema::hasTable('competitor_prices')) {
            CompetitorPrice::withTrashed()->where('notes', 'like', '%'.self::MARKER.'%')->forceDelete();
        }
    }

    private function resolveContext(): void
    {
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first() ?: Warehouse::orderBy('name')->firstOrFail();
        $this->skus = Sku::where('active', true)->orderBy('size_liters')->get()->all();
        if (!$this->skus) {
            throw new \RuntimeException('No active SKUs — seed base data first.');
        }

        $list = PriceListItem::whereHas('priceList', fn ($q) => $q->where('is_default', true))->pluck('unit_price', 'sku_id');
        foreach ($this->skus as $sku) {
            $fb = (float) $sku->size_liters <= 0.5 ? 420 : ((float) $sku->size_liters <= 1 ? 550 : 720);
            $this->prices[$sku->id] = (float) ($list[$sku->id] ?? $fb);
        }

        $this->manager = $this->staff('Santos', 'Cathy', 'sim-manager@marawater.local', 25000, ['SMM', 'FO'], 'manager');
        $this->driverA = $this->staff('Ishmael', 'Ochieng', 'sim-driver-ishmael@marawater.local', 20000, ['DRV'], 'driver');
        $this->driverB = $this->staff('Paul', 'Ochieng', 'sim-driver-paul@marawater.local', 20000, ['DRV'], 'driver');
        $this->ensureHeadcount('BP', 6, 12500, [['Akinyi','Otieno'],['Awino','Ouma'],['Atieno','Okoth'],['Adhiambo','Odhiambo'],['Achieng','Owino'],['Anyango','Onyango']]);
        $this->ensureHeadcount('SO', 1, 20000, [['Mary','Auma']]);
        $this->actor = $this->staff('Sim', 'Ops', 'sim-ops@marawater.local', 25000, ['SMM', 'FO'], 'manager');

        $this->vehicle = Vehicle::where('active', true)->orderBy('reg_no')->first()
            ?: Vehicle::create([
                'reg_no' => 'SIM-KDQ-001', 'make' => 'Isuzu', 'model' => 'NQR', 'year' => 2018,
                'capacity' => 300, 'active' => true, 'fuel_type' => 'diesel',
                'notes' => self::MARKER.' vehicle', 'created_by' => $this->actor->id,
            ]);
    }

    private function staff(string $first, string $last, string $email, float $salary, array $codes, string $tier): User
    {
        $u = User::where('email', $email)->first()
            ?: User::where('first_name', $first)->where('last_name', $last)->where('status', 'active')->first();
        if ($u) {
            if (!$u->salary) {
                $u->update(['salary' => $salary, 'house_allowance' => $u->house_allowance ?? 0]);
            }
            return $u;
        }
        $role = null;
        foreach ($codes as $c) {
            if ($role = Role::where('code', $c)->first()) {
                break;
            }
        }
        $role ??= Role::where('access_tier', $tier)->first();
        return User::create([
            'email' => $email,
            'phone' => '+2547'.random_int(10000000, 99999999),
            'password_hash' => Hash::make('Sim@2026'),
            'first_name' => $first, 'last_name' => $last, 'status' => 'active',
            'role_id' => $role?->id, 'salary' => $salary, 'house_allowance' => 0,
            'employment_date' => $this->monthStart->copy()->subMonths(6),
        ]);
    }

    private function ensureHeadcount(string $roleCode, int $need, float $salary, array $names): void
    {
        $role = Role::where('code', $roleCode)->first();
        if (!$role) {
            return;
        }
        $have = User::where('status', 'active')->where('role_id', $role->id)->count();
        for ($i = 0; $i < max(0, $need - $have); $i++) {
            [$f, $l] = $names[$i % count($names)];
            User::create([
                'email' => 'sim-'.strtolower($roleCode).'-'.strtolower($f).$i.'@marawater.local',
                'phone' => '+2547'.random_int(10000000, 99999999),
                'password_hash' => Hash::make('Sim@2026'),
                'first_name' => $f, 'last_name' => $l, 'status' => 'active',
                'role_id' => $role->id, 'salary' => $salary, 'house_allowance' => 0,
                'employment_date' => $this->monthStart->copy()->subMonths(4),
            ]);
        }
        User::where('role_id', $role->id)->where('status', 'active')->whereNull('salary')
            ->update(['salary' => $salary, 'house_allowance' => 0]);
    }

    private function seedStockArrivals(): void
    {
        $material = Material::where('category', 'bottles')->orderBy('code')->first()
            ?: Material::whereNull('deleted_at')->orderBy('code')->first()
            ?: Material::create([
                'code' => 'SIM-BTL', 'name' => 'Empty bottles (SIM)', 'category' => 'bottles',
                'uom' => 'bag', 'unit_cost' => 160, 'is_consumable' => true, 'min_level' => 100,
                'created_by' => $this->actor->id,
            ]);

        $bpBag = (float) config('mara_operations.default_conversions.bottles_per_bag', 24);
        $bpBale = (float) config('mara_operations.bottles_per_bale', 24);
        $transport = (float) config('mara_operations.bottle_transport_cost_kes', 45000);
        $bags = (int) round((self::COGS_TARGET / 2) / 160);
        $inv = new InventoryController();

        foreach ([['FINELINE', 'Fine Line', 1], ['BLUEPLUS', 'Blue Plus', 15]] as [$code, $name, $day]) {
            $date = $this->monthStart->copy()->day(min($day, $this->monthStart->daysInMonth));
            $batch = MaterialBatch::create([
                'material_id' => $material->id, 'sku_id' => $this->skus[0]->id,
                'batch_number' => self::PREFIX.'GRN-'.$code.'-'.$date->format('Ymd'),
                'arrival_type' => 'goods_received', 'purchase_date' => $date->toDateString(),
                'supplier_name' => $name, 'supplier_code' => $code,
                'unit_cost' => 160, 'transport_cost' => $transport,
                'qty_received' => $bags, 'bags_received' => $bags,
                'bottles_per_bag' => $bpBag, 'bottles_per_bale' => $bpBale,
                'bales_expected' => round(($bags * $bpBag) / $bpBale, 3),
                'qty_remaining' => $bags, 'package_unit' => 'bag',
                'warehouse_id' => $this->warehouse->id, 'received_by' => $this->actor->id,
                'notes' => self::MARKER.' 2-week stock arrival', 'quality_status' => 'passed',
                'quality_checked_at' => $date, 'quality_checked_by' => $this->actor->id,
            ]);
            $inv->recordMove([
                'move_type' => 'grn', 'item_type' => 'material', 'material_id' => $material->id,
                'warehouse_to_id' => $this->warehouse->id, 'qty' => $bags, 'uom' => 'bag',
                'unit_cost' => 160, 'ref_entity' => 'material_batch', 'ref_id' => $batch->id,
            ]);
        }
    }

    private function seedProduction(): void
    {
        $avg = collect($this->prices)->avg() ?: 500;
        $need = (int) ceil((self::SALES_TARGET / $avg) * 1.12);
        $days = $this->tripDays();
        $per = max(1, (int) ceil($need / max(1, count($days))));
        $packager = app(PackagingRunController::class);

        foreach ($days as $i => $date) {
            $sku = $this->skus[$i % count($this->skus)];
            $qty = $per + ($i % 3);
            $batch = Batch::create([
                'code' => self::PREFIX.'B-'.$date->format('Ymd').'-'.strtoupper(Str::random(3)),
                'sku_id' => $sku->id, 'manufacture_date' => $date->toDateString(),
                'expiry_date' => $date->copy()->addDays((int) ($sku->expiry_days ?: 365))->toDateString(),
                'planned_qty' => $qty, 'status' => 'in_progress',
                'opened_by' => $this->actor->id, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
            ]);
            $packager->createCompletedForBatch($batch, $qty, $this->warehouse->id, self::MARKER.' New Batch auto packaging');
        }
    }

    private function seedCustomers(): array
    {
        $rows = [
            ['Opapo Mini Mart', 'Rongo–Opapo'], ['Rodi General Shop', 'Rodi Kopany'],
            ['Homa Bay Wholesalers', 'Homa Bay Town'], ['Uriri Cool Drinks', 'Uriri'],
            ['Migori Plaza Kiosk', 'Migori'], ['Masara Hardware', 'Masara'],
            ['Nyatike Trading', 'Nyatike'], ['Ogongo Retail', 'Ogongo'],
            ['Grace Resort Store', 'Rongo'], ['Karungu Beach Shop', 'Karungu'],
            ['Kamagambo Stores', 'Kamagambo'], ['Mikei Provision', 'Mikei'],
        ];
        $out = [];
        foreach ($rows as $i => [$name, $addr]) {
            $out[] = Customer::create([
                'code' => self::PREFIX.'C'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name, 'type' => 'retail',
                'phone' => '07'.random_int(10000000, 99999999), 'address' => $addr,
                'status' => 'active', 'notes' => self::MARKER.' customer', 'created_by' => $this->actor->id,
            ]);
        }
        return $out;
    }

    private function seedTripsAndSales(array $customers): void
    {
        $routes = [
            'RONGO, RODI, HOMABAY', 'MARA, RONGO, URIRI, MIGORI', 'MARA, ABI, RODI, HB, OGONGO',
            'MARA, RONGO, MASARA, MIKEI, NYATIKE', 'MARA–RONGO–NDHIWA–RIAT–H/B',
            'MARA–OPAPO–RONGO', 'KARUNGU / MASARA',
        ];
        $kms = [45, 66, 99, 108, 141, 149, 154, 167, 33, 52, 61, 139];
        $days = $this->tripDays();
        $dayTarget = self::SALES_TARGET / max(1, count($days));
        $odo = 78500;
        $inv = new InventoryController();
        $total = 0.0;

        foreach ($days as $i => $date) {
            $driver = $i % 2 === 0 ? $this->driverA : $this->driverB;
            $km = max(30, min(170, $kms[$i % 12] + ($i % 5)));
            $start = $odo;
            $odo += $km;
            $target = ($i === count($days) - 1) ? max(0, self::SALES_TARGET - $total) : $dayTarget;

            $trip = DriverTrip::create([
                'trip_date' => $date->toDateString(), 'driver_id' => $driver->id,
                'vehicle_id' => $this->vehicle->id, 'route' => $routes[$i % count($routes)],
                'warehouse_id' => $this->warehouse->id, 'status' => 'completed',
                'mileage_start' => $start, 'mileage_end' => $odo,
                'fuel_liters' => null, 'fuel_cost' => 0, // fuel via FuelLog + petty cash
                'authorizing_officer_id' => $this->manager->id,
                'time_out' => $date->copy()->setTime(9 + ($i % 3), 15),
                'time_in' => $date->copy()->setTime(15 + ($i % 3), 30),
                'notes' => self::MARKER.' Driver worksheet — authorizing officer Santos Cathy / Kathy',
                'reconciliation_confirmed_at' => $date->copy()->setTime(16, 0),
                'reconciliation_confirmed_by' => $this->manager->id,
                'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
            ]);

            $plan = [];
            foreach ($this->skus as $sku) {
                $price = $this->prices[$sku->id];
                $sold = max(1, (int) round(($target / count($this->skus)) / $price));
                $disp = $sold + ($i % 4);
                $plan[] = ['sku' => $sku, 'price' => $price, 'sold' => $sold, 'disp' => $disp];
                DriverTripItem::create([
                    'driver_trip_id' => $trip->id, 'sku_id' => $sku->id,
                    'qty_carried' => $disp * 24, 'qty_returned' => ($disp - $sold) * 24,
                    'qty_sold' => $sold, 'unit_price' => $price,
                    'qty_carried_bales' => $disp,
                    'qty_returned_bales' => $disp - $sold, // returned = dispatched − sold
                ]);
                $inv->deductStock($sku->id, $sku, $this->warehouse->id, $sold, 'driver_trip', $trip->id, 'BALE');
            }

            $left = collect($plan)->mapWithKeys(fn ($p) => [$p['sku']->id => $p['sold']]);
            $stops = min(count($customers), 3 + ($i % 3));
            $dayRev = 0.0;
            for ($s = 0; $s < $stops; $s++) {
                $lines = [];
                foreach ($plan as $p) {
                    $avail = (int) ($left[$p['sku']->id] ?? 0);
                    if ($avail <= 0) {
                        continue;
                    }
                    $take = min($avail, max(1, (int) floor($avail / max(1, $stops - $s))));
                    $left[$p['sku']->id] = $avail - $take;
                    $lines[] = [
                        'sku_id' => $p['sku']->id, 'qty_bales' => $take,
                        'unit_price' => $p['price'], 'line_total' => round($take * $p['price'], 2),
                    ];
                }
                if (!$lines) {
                    continue;
                }
                $amount = round(collect($lines)->sum('line_total'), 2);
                $method = ['cash', 'mpesa', 'cash', 'debt'][$s % 4];
                $cust = $customers[($i + $s) % count($customers)];
                $sale = DriverTripSale::create([
                    'driver_trip_id' => $trip->id, 'customer_id' => $cust->id,
                    'payment_method' => $method, 'amount' => $amount,
                    'mpesa_reference' => $method === 'mpesa' ? 'SIM'.strtoupper(Str::random(8)) : null,
                    'debt_signatory' => $method === 'debt' ? $cust->name : null,
                    'physical_receipt_no' => self::PREFIX.'R-'.$date->format('md').'-'.($s + 1),
                    'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
                ]);
                foreach ($lines as $line) {
                    DriverTripSaleItem::create($line + ['driver_trip_sale_id' => $sale->id]);
                }
                $dayRev += $amount;
            }
            $total += $dayRev;
        }
    }

    private function seedPettyCashFuelElecRepairs(): void
    {
        $acc = fn (string $c) => ChartOfAccount::where('code', $c)->whereNull('deleted_at')->first();
        $fuel = $acc('5200');
        $elec = $acc('5310');
        $rep = $acc('5430');
        $float = $acc('1010');
        if (!$fuel || !$elec || !$rep) {
            $this->command?->warn('Missing CoA 5200/5310/5430 — skip petty cash');
            return;
        }
        $out = function ($day, $account, $amt, $desc, $who) {
            if ($day > $this->monthStart->daysInMonth) {
                return;
            }
            PettyCashEntry::create([
                'entry_date' => $this->monthStart->copy()->day($day)->toDateString(),
                'account_id' => $account->id, 'description' => self::MARKER.' '.$desc,
                'requestor_id' => $who->id, 'requestor_name' => trim($who->first_name.' '.$who->last_name),
                'amount_in' => 0, 'amount_out' => $amt, 'created_by' => $this->actor->id,
            ]);
        };
        if ($float) {
            PettyCashEntry::create([
                'entry_date' => $this->monthStart->toDateString(), 'account_id' => $float->id,
                'description' => self::MARKER.' Petty cash float', 'requestor_id' => $this->manager->id,
                'requestor_name' => 'Santos Cathy', 'amount_in' => 150000, 'amount_out' => 0,
                'created_by' => $this->actor->id,
            ]);
        }
        foreach ([2, 5, 8, 12, 16, 19, 22, 26] as $d) {
            $out($d, $fuel, 11800, 'Diesel fill (no excise)', $this->driverA);
        }
        foreach ([3, 10, 17, 24] as $d) {
            $out($d, $elec, 3500, 'Electricity token (KPLC)', $this->manager);
        }
        foreach ([[7, 4500, 'Minor repair — puncture'], [14, 8500, 'Minor — brake pads'], [21, 22000, 'Major — suspension']] as [$d, $a, $desc]) {
            $out($d, $rep, $a, $desc.' (acct 5430)', $this->manager);
        }
    }

    private function seedFuelLogsAndVehicleRepairs(): void
    {
        $odo = 78500;
        foreach ([40, 55, 48, 62, 50, 70, 45, 58] as $i => $liters) {
            $day = 2 + $i * 3;
            if ($day > $this->monthStart->daysInMonth) {
                break;
            }
            $odo += (int) round($liters * 8);
            FuelLog::create([
                'vehicle_id' => $this->vehicle->id,
                'date' => $this->monthStart->copy()->day($day)->toDateString(),
                'liters' => $liters,
                'cost' => round($liters * self::DIESEL_PER_L, 2),
                'odometer' => $odo, 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
            ]);
        }
        if (!Schema::hasTable('vehicle_repairs')) {
            return;
        }
        foreach ([[7, 'minor', 'Tyre puncture', 4500], [14, 'minor', 'Brake pads', 8500], [21, 'major', 'Suspension overhaul', 22000]] as [$d, $cat, $desc, $cost]) {
            if ($d > $this->monthStart->daysInMonth) {
                continue;
            }
            VehicleRepair::create([
                'vehicle_id' => $this->vehicle->id,
                'repair_date' => $this->monthStart->copy()->day($d)->toDateString(),
                'category' => $cat, 'description' => self::MARKER.' '.$desc,
                'cost' => $cost, 'created_by' => $this->actor->id,
            ]);
        }
    }

    private function seedCompetitorPrices(): void
    {
        if (!Schema::hasTable('competitor_companies')) {
            return;
        }
        $offsets = ['Aquamist' => 1.05, 'Highland' => 0.98, 'Keringet' => 1.08];
        foreach (array_keys($offsets) as $name) {
            CompetitorCompany::firstOrCreate(['name' => $name], ['active' => true]);
        }
        foreach (CompetitorCompany::whereIn('name', array_keys($offsets))->get() as $co) {
            foreach ($this->skus as $sku) {
                CompetitorPrice::updateOrCreate(
                    ['competitor_company_id' => $co->id, 'sku_id' => $sku->id],
                    [
                        'unit_price' => round($this->prices[$sku->id] * $offsets[$co->name], 2),
                        'effective_date' => $this->monthStart->toDateString(),
                        'notes' => self::MARKER.' competitive pricing', 'logged_by' => $this->actor->id,
                    ]
                );
            }
        }
    }

    private function seedPayroll(): void
    {
        $month = $this->monthStart->toDateString();
        if (PayrollRun::whereDate('month', $month)->where('status', 'finalized')->where('created_by', '!=', $this->actor->id)->exists()) {
            $this->command?->warn('Non-SIM finalized payroll exists for '.$month.' — skip');
            return;
        }
        PayrollRun::whereDate('month', $month)->where('created_by', $this->actor->id)->each(function ($r) {
            Payslip::where('payroll_run_id', $r->id)->forceDelete();
            $r->forceDelete();
        });

        $calc = app(PayrollCalculationService::class);
        $run = PayrollRun::create([
            'month' => $month, 'status' => 'finalized', 'run_by' => $this->actor->id,
            'finalized_at' => now(), 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
        ]);
        // Finalis-style: exclude Director
        User::with('role')->where('status', 'active')->whereNotNull('salary')->get()
            ->filter(fn ($u) => optional($u->role)->access_tier !== 'director')
            ->each(function ($user) use ($run, $calc) {
                Payslip::create(array_merge($calc->calculate([
                    'basic_pay' => (float) $user->salary,
                    'house_allowance' => (float) ($user->house_allowance ?? 0),
                    'absentism_hours' => 0,
                ]), [
                    'payroll_run_id' => $run->id, 'user_id' => $user->id,
                    'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
                ]));
            });
    }

    /** ~20–22 Mon–Sat trip days in the current month. */
    private function tripDays(): array
    {
        $days = [];
        for ($d = $this->monthStart->copy(); $d->lte($this->monthEnd) && count($days) < 22; $d->addDay()) {
            if (!$d->isSunday()) {
                $days[] = $d->copy();
            }
        }
        return $days;
    }
}
