<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Adding test data...');

        // Add test water tests
        $this->addWaterTests();
        
        // Add test batches
        $this->addBatches();
        
        // Add test customers
        $this->addCustomers();
        
        // Add test orders
        $this->addOrders();

        $this->command->info('Test data added successfully!');
    }

    private function addWaterTests()
    {
        $testData = [
            [
                'id' => Str::uuid(),
                'test_type' => 'baseline',
                'recorded_at' => now(),
                'ph' => 7.2,
                'tds' => 45,
                'chlorine' => 0.5,
                'status' => 'pass',
                'location_text' => 'Production Line 1',
                'unit_notes' => 'All parameters within range',
                'recorded_by' => DB::table('users')->first()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'test_type' => 'random',
                'recorded_at' => now()->subHours(2),
                'ph' => 6.8,
                'tds' => 52,
                'chlorine' => 0.3,
                'status' => 'pass',
                'location_text' => 'Production Line 2',
                'unit_notes' => 'Slightly acidic but acceptable',
                'recorded_by' => DB::table('users')->first()->id,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ]
        ];

        foreach ($testData as $test) {
            DB::table('water_tests')->insert($test);
        }
    }

    private function addBatches()
    {
        $skuId = DB::table('skus')->first()->id ?? null;
        if (!$skuId) return;

        $batchData = [
            [
                'id' => Str::uuid(),
                'code' => 'B2024001',
                'sku_id' => $skuId,
                'manufacture_date' => now()->toDateString(),
                'expiry_date' => now()->addMonths(12)->toDateString(),
                'planned_qty' => 1000,
                'status' => 'open',
                'opened_by' => DB::table('users')->first()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'code' => 'B2024002',
                'sku_id' => $skuId,
                'manufacture_date' => now()->subDays(1)->toDateString(),
                'expiry_date' => now()->addMonths(12)->subDays(1)->toDateString(),
                'planned_qty' => 1500,
                'status' => 'in_progress',
                'opened_by' => DB::table('users')->first()->id,
                'created_at' => now()->subDays(1),
                'updated_at' => now()->subDays(1),
            ]
        ];

        foreach ($batchData as $batch) {
            DB::table('batches')->insert($batch);
        }
    }

    private function addCustomers()
    {
        $customerData = [
            [
                'id' => Str::uuid(),
                'code' => 'CUST-001',
                'name' => 'ABC Supermarket',
                'type' => 'retail',
                'phone' => '+254700000001',
                'email' => 'abc@example.com',
                'address' => 'Nairobi CBD',
                'price_tier' => 'standard',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid(),
                'code' => 'CUST-002',
                'name' => 'XYZ Wholesale',
                'type' => 'wholesale',
                'phone' => '+254700000002',
                'email' => 'xyz@example.com',
                'address' => 'Industrial Area',
                'price_tier' => 'premium',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        foreach ($customerData as $customer) {
            DB::table('customers')->insert($customer);
        }
    }

    private function addOrders()
    {
        $customerId = DB::table('customers')->first()->id ?? null;
        $skuId = DB::table('skus')->first()->id ?? null;
        if (!$customerId || !$skuId) return;

        $orderData = [
            [
                'id' => Str::uuid(),
                'order_no' => 'ORD-2024-001',
                'customer_id' => $customerId,
                'order_date' => now()->toDateString(),
                'requested_date' => now()->addDays(2)->toDateString(),
                'status' => 'confirmed',
                'total_amount' => 25000,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        foreach ($orderData as $order) {
            DB::table('orders')->insert($order);
        }
    }
}
