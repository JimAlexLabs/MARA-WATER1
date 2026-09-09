<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class Phase1CompleteSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Starting Phase 1 Complete Seeder...');
        
        // Seed additional water tests
        $this->seedWaterTests();
        
        // Seed additional batches
        $this->seedBatches();
        
        // Seed additional orders
        $this->seedOrders();
        
        $this->command->info('Phase 1 Complete Seeder finished!');
    }

    private function seedWaterTests()
    {
        $this->command->info('Seeding water tests...');
        
        $users = DB::table('users')->pluck('id')->toArray();
        $testTypes = ['baseline', 'random', 'retest'];
        $statuses = ['pass', 'fail', 'pending'];
        $locations = ['Production Line 1', 'Production Line 2', 'Storage Tank', 'Filling Station'];
        
        for ($i = 0; $i < 20; $i++) {
            DB::table('water_tests')->insert([
                'id' => Str::uuid(),
                'test_type' => $testTypes[array_rand($testTypes)],
                'recorded_at' => now()->subDays(rand(1, 30)),
                'ph' => rand(65, 85) / 10,
                'tds' => rand(50, 200),
                'chlorine' => rand(1, 5) / 10,
                'unit_notes' => 'Test completed successfully',
                'location_text' => $locations[array_rand($locations)],
                'status' => $statuses[array_rand($statuses)],
                'recorded_by' => $users[array_rand($users)],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedBatches()
    {
        $this->command->info('Seeding batches...');
        
        $skus = DB::table('skus')->pluck('id')->toArray();
        $users = DB::table('users')->pluck('id')->toArray();
        $statuses = ['open', 'in_progress', 'closed'];
        
        for ($i = 0; $i < 15; $i++) {
            $manufactureDate = now()->subDays(rand(1, 30));
            $expiryDate = $manufactureDate->copy()->addDays(365);
            
            DB::table('batches')->insert([
                'id' => Str::uuid(),
                'code' => 'BATCH-' . strtoupper(Str::random(8)),
                'sku_id' => $skus[array_rand($skus)],
                'manufacture_date' => $manufactureDate,
                'expiry_date' => $expiryDate,
                'planned_qty' => rand(1000, 5000),
                'status' => $statuses[array_rand($statuses)],
                'opened_by' => $users[array_rand($users)],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }



    private function seedOrders()
    {
        $this->command->info('Seeding orders...');
        
        $customers = DB::table('customers')->pluck('id')->toArray();
        $skus = DB::table('skus')->pluck('id')->toArray();
        $users = DB::table('users')->pluck('id')->toArray();
        $statuses = ['draft', 'confirmed', 'dispatched', 'delivered', 'cancelled'];
        
        for ($i = 0; $i < 30; $i++) {
            $orderDate = now()->subDays(rand(1, 60));
            $totalAmount = rand(500, 3000);
            
            $orderId = Str::uuid();
            DB::table('orders')->insert([
                'id' => $orderId,
                'order_no' => 'ORD-' . strtoupper(Str::random(8)),
                'customer_id' => $customers[array_rand($customers)],
                'sales_officer_id' => $users[array_rand($users)],
                'status' => $statuses[array_rand($statuses)],
                'order_date' => $orderDate,
                'requested_date' => $orderDate->copy()->addDays(rand(1, 7)),
                'total_amount' => $totalAmount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Add order items
            $numItems = rand(1, 3);
            for ($j = 0; $j < $numItems; $j++) {
                $qty = rand(10, 100);
                $unitPrice = rand(15, 50);
                
                DB::table('order_items')->insert([
                    'id' => Str::uuid(),
                    'order_id' => $orderId,
                    'sku_id' => $skus[array_rand($skus)],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'discount' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedInvoices()
    {
        $this->command->info('Seeding invoices...');
        
        $orders = DB::table('orders')->pluck('id')->toArray();
        $customers = DB::table('customers')->pluck('id')->toArray();
        $users = DB::table('users')->pluck('id')->toArray();
        $paymentStatuses = ['pending', 'paid', 'overdue'];
        $paymentMethods = ['cash', 'bank', 'mpesa'];
        
        for ($i = 0; $i < 20; $i++) {
            $invoiceDate = now()->subDays(rand(1, 45));
            $dueDate = $invoiceDate->copy()->addDays(30);
            $totalAmount = rand(500, 3000);
            $subtotal = $totalAmount * 0.9;
            $taxAmount = $totalAmount * 0.1;
            
            DB::table('invoices')->insert([
                'id' => Str::uuid(),
                'invoice_no' => 'INV-' . strtoupper(Str::random(8)),
                'order_id' => $orders[array_rand($orders)],
                'customer_id' => $customers[array_rand($customers)],
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => 0,
                'total_amount' => $totalAmount,
                'payment_status' => $paymentStatuses[array_rand($paymentStatuses)],
                'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                'created_by' => $users[array_rand($users)],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedStockMoves()
    {
        $this->command->info('Seeding stock moves...');
        
        $materials = DB::table('materials')->pluck('id')->toArray();
        $skus = DB::table('skus')->pluck('id')->toArray();
        $warehouses = DB::table('warehouses')->pluck('id')->toArray();
        $users = DB::table('users')->pluck('id')->toArray();
        $moveTypes = ['grn', 'issue', 'produce', 'adjust', 'transfer'];
        $itemTypes = ['material', 'sku'];
        
        for ($i = 0; $i < 50; $i++) {
            $itemType = $itemTypes[array_rand($itemTypes)];
            $materialId = $itemType === 'material' ? $materials[array_rand($materials)] : null;
            $skuId = $itemType === 'sku' ? $skus[array_rand($skus)] : null;
            
            DB::table('stock_moves')->insert([
                'id' => Str::uuid(),
                'move_type' => $moveTypes[array_rand($moveTypes)],
                'item_type' => $itemType,
                'material_id' => $materialId,
                'sku_id' => $skuId,
                'warehouse_from_id' => $warehouses[array_rand($warehouses)],
                'warehouse_to_id' => $warehouses[array_rand($warehouses)],
                'qty' => rand(10, 500),
                'uom' => 'pcs',
                'unit_cost' => rand(5, 50),
                'ref_entity' => 'order',
                'ref_id' => Str::uuid(),
                'moved_by' => $users[array_rand($users)],
                'created_at' => now()->subDays(rand(1, 30)),
                'updated_at' => now(),
            ]);
        }
    }


}
