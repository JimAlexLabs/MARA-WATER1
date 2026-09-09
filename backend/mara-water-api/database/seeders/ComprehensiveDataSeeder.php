<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ComprehensiveDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Starting comprehensive data seeding...');

        // Seed departments
        $this->seedDepartments();
        
        // Seed roles and permissions
        $this->seedRolesAndPermissions();
        
        // Seed users
        $this->seedUsers();
        
        // Seed suppliers
        $this->seedSuppliers();
        
        // Seed materials
        $this->seedMaterials();
        
        // Seed SKUs
        $this->seedSkus();
        
        // Seed warehouses
        $this->seedWarehouses();
        
        // Seed routes
        $this->seedRoutes();
        
        // Seed customers
        $this->seedCustomers();
        
        // Seed vehicles
        $this->seedVehicles();
        
        // Seed sample water tests
        $this->seedWaterTests();
        
        // Seed sample batches
        $this->seedBatches();
        
        // Seed sample orders
        $this->seedOrders();
        
        // Seed sample inventory
        $this->seedInventory();

        $this->command->info('Comprehensive data seeding completed!');
    }

    private function seedDepartments()
    {
        $this->command->info('Seeding departments...');
        
        $departments = [
            ['code' => 'QA', 'name' => 'Quality Assurance'],
            ['code' => 'PROD', 'name' => 'Production'],
            ['code' => 'SALES', 'name' => 'Sales & Marketing'],
            ['code' => 'FIN', 'name' => 'Finance'],
            ['code' => 'HR', 'name' => 'Human Resources'],
            ['code' => 'FLEET', 'name' => 'Fleet Management'],
            ['code' => 'IT', 'name' => 'Information Technology'],
        ];

        foreach ($departments as $dept) {
            DB::table('departments')->insertOrIgnore([
                'id' => Str::uuid(),
                'code' => $dept['code'],
                'name' => $dept['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedRolesAndPermissions()
    {
        $this->command->info('Seeding roles and permissions...');
        
        // Get department IDs
        $qaDept = DB::table('departments')->where('code', 'QA')->first();
        $prodDept = DB::table('departments')->where('code', 'PROD')->first();
        $salesDept = DB::table('departments')->where('code', 'SALES')->first();
        $finDept = DB::table('departments')->where('code', 'FIN')->first();
        $hrDept = DB::table('departments')->where('code', 'HR')->first();
        $fleetDept = DB::table('departments')->where('code', 'FLEET')->first();

        // Seed roles
        $roles = [
            ['code' => 'ADMIN', 'name' => 'Managing Director', 'is_system' => true],
            ['code' => 'QA', 'name' => 'Quality Assurance Officer', 'is_system' => false],
            ['code' => 'RIC', 'name' => 'RIC/Lab Technician', 'is_system' => false],
            ['code' => 'BP', 'name' => 'Bottling & Packaging', 'is_system' => false],
            ['code' => 'SMM', 'name' => 'Sales & Marketing Manager', 'is_system' => false],
            ['code' => 'SO', 'name' => 'Sales Officer', 'is_system' => false],
            ['code' => 'DRV', 'name' => 'Driver', 'is_system' => false],
            ['code' => 'FO', 'name' => 'Finance Officer', 'is_system' => false],
            ['code' => 'STK', 'name' => 'Storekeeper', 'is_system' => false],
            ['code' => 'AUD', 'name' => 'Auditor', 'is_system' => false],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->insertOrIgnore([
                'id' => Str::uuid(),
                'code' => $role['code'],
                'name' => $role['name'],
                'is_system' => $role['is_system'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Seed permissions
        $permissions = [
            ['code' => 'qa.view', 'name' => 'View QA Tests', 'module' => 'qa'],
            ['code' => 'qa.create', 'name' => 'Create QA Tests', 'module' => 'qa'],
            ['code' => 'qa.edit', 'name' => 'Edit QA Tests', 'module' => 'qa'],
            ['code' => 'qa.delete', 'name' => 'Delete QA Tests', 'module' => 'qa'],
            ['code' => 'qa.verify', 'name' => 'Verify QA Tests', 'module' => 'qa'],
            ['code' => 'production.view', 'name' => 'View Production', 'module' => 'production'],
            ['code' => 'production.create', 'name' => 'Create Production', 'module' => 'production'],
            ['code' => 'production.edit', 'name' => 'Edit Production', 'module' => 'production'],
            ['code' => 'sales.view', 'name' => 'View Sales', 'module' => 'sales'],
            ['code' => 'sales.create', 'name' => 'Create Sales', 'module' => 'sales'],
            ['code' => 'sales.edit', 'name' => 'Edit Sales', 'module' => 'sales'],
            ['code' => 'inventory.view', 'name' => 'View Inventory', 'module' => 'inventory'],
            ['code' => 'inventory.create', 'name' => 'Create Inventory', 'module' => 'inventory'],
            ['code' => 'inventory.edit', 'name' => 'Edit Inventory', 'module' => 'inventory'],
            ['code' => 'finance.view', 'name' => 'View Finance', 'module' => 'finance'],
            ['code' => 'finance.create', 'name' => 'Create Finance', 'module' => 'finance'],
            ['code' => 'finance.edit', 'name' => 'Edit Finance', 'module' => 'finance'],
            ['code' => 'fleet.view', 'name' => 'View Fleet', 'module' => 'fleet'],
            ['code' => 'fleet.create', 'name' => 'Create Fleet', 'module' => 'fleet'],
            ['code' => 'fleet.edit', 'name' => 'Edit Fleet', 'module' => 'fleet'],
            ['code' => 'hr.view', 'name' => 'View HR', 'module' => 'hr'],
            ['code' => 'hr.create', 'name' => 'Create HR', 'module' => 'hr'],
            ['code' => 'hr.edit', 'name' => 'Edit HR', 'module' => 'hr'],
            ['code' => 'admin.users', 'name' => 'Manage Users', 'module' => 'admin'],
            ['code' => 'admin.roles', 'name' => 'Manage Roles', 'module' => 'admin'],
            ['code' => 'admin.settings', 'name' => 'Manage Settings', 'module' => 'admin'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'id' => Str::uuid(),
                'code' => $permission['code'],
                'name' => $permission['name'],
                'module' => $permission['module'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Assign all permissions to ADMIN role
        $adminRole = DB::table('roles')->where('code', 'ADMIN')->first();
        $allPermissions = DB::table('permissions')->get();

        foreach ($allPermissions as $permission) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $adminRole->id,
                'permission_id' => $permission->id,
            ]);
        }
    }

    private function seedUsers()
    {
        $this->command->info('Seeding users...');
        
        $adminRole = DB::table('roles')->where('code', 'ADMIN')->first();
        $qaDept = DB::table('departments')->where('code', 'QA')->first();

        // Create director user if not exists
                    DB::table('users')->insertOrIgnore([
                'id' => Str::uuid(),
                'email' => 'director@marawater.com',
                'phone' => '+254700000000',
                'password_hash' => Hash::make('Admin@2024'),
                'first_name' => 'Managing',
                'last_name' => 'Director',
                'status' => 'active',
                'role_id' => $adminRole->id,
                'department_id' => $qaDept->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        // Create sample users for different roles
        $sampleUsers = [
            ['email' => 'qa@marawater.com', 'first_name' => 'John', 'last_name' => 'QA Officer', 'role' => 'QA', 'dept' => 'QA'],
            ['email' => 'ric@marawater.com', 'first_name' => 'Mary', 'last_name' => 'Lab Tech', 'role' => 'RIC', 'dept' => 'QA'],
            ['email' => 'sales@marawater.com', 'first_name' => 'Peter', 'last_name' => 'Sales Manager', 'role' => 'SMM', 'dept' => 'SALES'],
            ['email' => 'finance@marawater.com', 'first_name' => 'Jane', 'last_name' => 'Finance Officer', 'role' => 'FO', 'dept' => 'FIN'],
        ];

        foreach ($sampleUsers as $userData) {
            $role = DB::table('roles')->where('code', $userData['role'])->first();
            $dept = DB::table('departments')->where('code', $userData['dept'])->first();
            
            DB::table('users')->insertOrIgnore([
                'id' => Str::uuid(),
                'email' => $userData['email'],
                'phone' => '+254700000000',
                'password_hash' => Hash::make('Password@2024'),
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'status' => 'active',
                'role_id' => $role->id,
                'department_id' => $dept->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedSuppliers()
    {
        $this->command->info('Seeding suppliers...');
        
        $suppliers = [
            ['name' => 'Bottle Suppliers Ltd', 'contact_name' => 'John Smith', 'phone' => '+254700000001', 'email' => 'info@bottlesuppliers.com'],
            ['name' => 'Chemical Solutions Co', 'contact_name' => 'Mary Johnson', 'phone' => '+254700000002', 'email' => 'sales@chemicalsolutions.com'],
            ['name' => 'Label Printers Ltd', 'contact_name' => 'David Wilson', 'phone' => '+254700000003', 'email' => 'orders@labelprinters.com'],
            ['name' => 'Cap Manufacturers', 'contact_name' => 'Sarah Brown', 'phone' => '+254700000004', 'email' => 'info@capmanufacturers.com'],
        ];

        foreach ($suppliers as $supplier) {
            DB::table('suppliers')->insertOrIgnore([
                'id' => Str::uuid(),
                'name' => $supplier['name'],
                'contact_name' => $supplier['contact_name'],
                'phone' => $supplier['phone'],
                'email' => $supplier['email'],
                'address' => 'Nairobi, Kenya',
                'tax_pin' => 'A' . rand(100000, 999999) . 'Z',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedMaterials()
    {
        $this->command->info('Seeding materials...');
        
        $suppliers = DB::table('suppliers')->get();
        
        $materials = [
            ['code' => 'BTL-500ML', 'name' => '500ml Bottles', 'category' => 'bottles', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 1000],
            ['code' => 'BTL-1L', 'name' => '1L Bottles', 'category' => 'bottles', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 500],
            ['code' => 'BTL-1.5L', 'name' => '1.5L Bottles', 'category' => 'bottles', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 300],
            ['code' => 'CAP-500ML', 'name' => '500ml Caps', 'category' => 'caps', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 1000],
            ['code' => 'CAP-1L', 'name' => '1L Caps', 'category' => 'caps', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 500],
            ['code' => 'CAP-1.5L', 'name' => '1.5L Caps', 'category' => 'caps', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 300],
            ['code' => 'CHLORINE', 'name' => 'Chlorine', 'category' => 'chemicals', 'uom' => 'kg', 'is_consumable' => true, 'min_level' => 50],
            ['code' => 'LABEL-500ML', 'name' => '500ml Labels', 'category' => 'labels', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 1000],
            ['code' => 'LABEL-1L', 'name' => '1L Labels', 'category' => 'labels', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 500],
            ['code' => 'LABEL-1.5L', 'name' => '1.5L Labels', 'category' => 'labels', 'uom' => 'pcs', 'is_consumable' => true, 'min_level' => 300],
        ];

        foreach ($materials as $material) {
            DB::table('materials')->insertOrIgnore([
                'id' => Str::uuid(),
                'code' => $material['code'],
                'name' => $material['name'],
                'category' => $material['category'],
                'uom' => $material['uom'],
                'is_consumable' => $material['is_consumable'],
                'min_level' => $material['min_level'],
                'lead_time_days' => rand(3, 14),
                'supplier_id' => $suppliers->random()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedSkus()
    {
        $this->command->info('Seeding SKUs...');
        
        $skus = [
            ['code' => 'WATER-500ML', 'name' => 'Pure Water 500ml', 'size_liters' => 0.5, 'unit' => 'bottle', 'expiry_days' => 365],
            ['code' => 'WATER-1L', 'name' => 'Pure Water 1L', 'size_liters' => 1.0, 'unit' => 'bottle', 'expiry_days' => 365],
            ['code' => 'WATER-1.5L', 'name' => 'Pure Water 1.5L', 'size_liters' => 1.5, 'unit' => 'bottle', 'expiry_days' => 365],
        ];

        foreach ($skus as $sku) {
            DB::table('skus')->insertOrIgnore([
                'id' => Str::uuid(),
                'code' => $sku['code'],
                'name' => $sku['name'],
                'size_liters' => $sku['size_liters'],
                'unit' => $sku['unit'],
                'expiry_days' => $sku['expiry_days'],
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedWarehouses()
    {
        $this->command->info('Seeding warehouses...');
        
        $warehouses = [
            ['code' => 'WH-MAIN', 'name' => 'Main Warehouse', 'address' => 'Industrial Area, Nairobi'],
            ['code' => 'WH-PROD', 'name' => 'Production Warehouse', 'address' => 'Factory Floor, Nairobi'],
            ['code' => 'WH-DIST', 'name' => 'Distribution Center', 'address' => 'Mombasa Road, Nairobi'],
        ];

        foreach ($warehouses as $warehouse) {
            DB::table('warehouses')->insertOrIgnore([
                'id' => Str::uuid(),
                'code' => $warehouse['code'],
                'name' => $warehouse['name'],
                'address' => $warehouse['address'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedRoutes()
    {
        $this->command->info('Seeding routes...');
        
        $routes = [
            ['name' => 'Nairobi Central', 'description' => 'Central Nairobi delivery route'],
            ['name' => 'Westlands Route', 'description' => 'Westlands and surrounding areas'],
            ['name' => 'Eastlands Route', 'description' => 'Eastlands and Donholm area'],
            ['name' => 'South B Route', 'description' => 'South B and Industrial Area'],
            ['name' => 'Thika Route', 'description' => 'Thika and Juja area'],
        ];

        foreach ($routes as $route) {
            DB::table('routes')->insertOrIgnore([
                'id' => Str::uuid(),
                'name' => $route['name'],
                'description' => $route['description'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedCustomers()
    {
        $this->command->info('Seeding customers...');
        
        $routes = DB::table('routes')->get();
        
        $customers = [
            ['code' => 'CUST-001', 'name' => 'ABC Supermarket', 'type' => 'retail', 'phone' => '+254700000101', 'email' => 'orders@abcsupermarket.com'],
            ['code' => 'CUST-002', 'name' => 'XYZ Restaurant', 'type' => 'corporate', 'phone' => '+254700000102', 'email' => 'supplies@xyzrestaurant.com'],
            ['code' => 'CUST-003', 'name' => 'City Hotel', 'type' => 'corporate', 'phone' => '+254700000103', 'email' => 'procurement@cityhotel.com'],
            ['code' => 'CUST-004', 'name' => 'Downtown Store', 'type' => 'retail', 'phone' => '+254700000104', 'email' => 'info@downtownstore.com'],
            ['code' => 'CUST-005', 'name' => 'Wholesale Distributors', 'type' => 'wholesale', 'phone' => '+254700000105', 'email' => 'orders@wholesaledistributors.com'],
        ];

        foreach ($customers as $customer) {
            DB::table('customers')->insertOrIgnore([
                'id' => Str::uuid(),
                'code' => $customer['code'],
                'name' => $customer['name'],
                'type' => $customer['type'],
                'phone' => $customer['phone'],
                'email' => $customer['email'],
                'address' => 'Nairobi, Kenya',
                'route_id' => $routes->random()->id,
                'price_tier' => 'standard',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedVehicles()
    {
        $this->command->info('Seeding vehicles...');
        
        $vehicles = [
            ['reg_no' => 'KCA 123A', 'make' => 'Toyota', 'model' => 'Hilux', 'year' => 2020, 'capacity' => 1000],
            ['reg_no' => 'KCA 456B', 'make' => 'Isuzu', 'model' => 'NPR', 'year' => 2019, 'capacity' => 2000],
            ['reg_no' => 'KCA 789C', 'make' => 'Mitsubishi', 'model' => 'Fuso', 'year' => 2021, 'capacity' => 3000],
        ];

        foreach ($vehicles as $vehicle) {
            DB::table('vehicles')->insertOrIgnore([
                'id' => Str::uuid(),
                'reg_no' => $vehicle['reg_no'],
                'make' => $vehicle['make'],
                'model' => $vehicle['model'],
                'year' => $vehicle['year'],
                'capacity' => $vehicle['capacity'],
                'active' => true,
                'insurance_expiry' => now()->addMonths(rand(1, 12)),
                'inspection_expiry' => now()->addMonths(rand(1, 6)),
                'speed_gov_status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedWaterTests()
    {
        $this->command->info('Seeding water tests...');
        
        $users = DB::table('users')->get();
        $warehouses = DB::table('warehouses')->get();
        
        for ($i = 0; $i < 20; $i++) {
            $testDate = now()->subDays(rand(0, 30));
            
            DB::table('water_tests')->insertOrIgnore([
                'id' => Str::uuid(),
                'test_type' => ['baseline', 'random', 'retest'][rand(0, 2)],
                'recorded_at' => $testDate,
                'ph' => rand(65, 85) / 10, // 6.5 to 8.5
                'tds' => rand(50, 300),
                'chlorine' => rand(5, 15) / 10, // 0.5 to 1.5
                'unit_notes' => 'Sample water test',
                'location_text' => 'Production Line ' . rand(1, 3),
                'warehouse_id' => $warehouses->random()->id,
                'recorded_by' => $users->random()->id,
                'ric_verified_by' => $users->where('email', 'ric@marawater.com')->first()->id ?? null,
                'status' => ['pending', 'pass', 'fail'][rand(0, 2)],
                'created_at' => $testDate,
                'updated_at' => $testDate,
            ]);
        }
    }

    private function seedBatches()
    {
        $this->command->info('Seeding batches...');
        
        $skus = DB::table('skus')->get();
        $users = DB::table('users')->get();
        
        for ($i = 0; $i < 15; $i++) {
            $manufactureDate = now()->subDays(rand(0, 30));
            $expiryDate = $manufactureDate->copy()->addDays(365);
            
            DB::table('batches')->insertOrIgnore([
                'id' => Str::uuid(),
                'code' => 'BATCH-' . $skus->random()->code . '-' . $manufactureDate->format('Ymd') . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                'sku_id' => $skus->random()->id,
                'manufacture_date' => $manufactureDate,
                'expiry_date' => $expiryDate,
                'planned_qty' => rand(1000, 5000),
                'status' => ['open', 'in_progress', 'closed'][rand(0, 2)],
                'opened_by' => $users->random()->id,
                'closed_by' => $users->random()->id,
                'created_at' => $manufactureDate,
                'updated_at' => $manufactureDate,
            ]);
        }
    }

    private function seedOrders()
    {
        $this->command->info('Seeding orders...');
        
        $customers = DB::table('customers')->get();
        $users = DB::table('users')->get();
        $skus = DB::table('skus')->get();
        
        for ($i = 0; $i < 25; $i++) {
            $orderDate = now()->subDays(rand(0, 30));
            $requestedDate = $orderDate->copy()->addDays(rand(1, 7));
            
            $orderId = Str::uuid();
            
            DB::table('orders')->insertOrIgnore([
                'id' => $orderId,
                'order_no' => 'ORD-' . $orderDate->format('Ymd') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'customer_id' => $customers->random()->id,
                'order_date' => $orderDate,
                'requested_date' => $requestedDate,
                'sales_officer_id' => $users->random()->id,
                'status' => ['draft', 'confirmed', 'dispatched', 'delivered'][rand(0, 3)],
                'total_amount' => 0, // Will be calculated from items
                'created_at' => $orderDate,
                'updated_at' => $orderDate,
            ]);

            // Create order items
            $numItems = rand(1, 3);
            $totalAmount = 0;
            
            for ($j = 0; $j < $numItems; $j++) {
                $qty = rand(10, 100);
                $unitPrice = rand(30, 80);
                $lineTotal = $qty * $unitPrice;
                $totalAmount += $lineTotal;
                
                DB::table('order_items')->insertOrIgnore([
                    'id' => Str::uuid(),
                    'order_id' => $orderId,
                    'sku_id' => $skus->random()->id,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'discount' => 0,
                    'created_at' => $orderDate,
                    'updated_at' => $orderDate,
                ]);
            }
            
            // Update order total
            DB::table('orders')->where('id', $orderId)->update(['total_amount' => $totalAmount]);
        }
    }

    private function seedInventory()
    {
        $this->command->info('Seeding inventory...');
        
        $materials = DB::table('materials')->get();
        $skus = DB::table('skus')->get();
        $warehouses = DB::table('warehouses')->get();
        $batches = DB::table('batches')->get();
        
        // Seed material stock items
        foreach ($materials as $material) {
            foreach ($warehouses as $warehouse) {
                DB::table('stock_items')->insertOrIgnore([
                    'id' => Str::uuid(),
                    'item_type' => 'material',
                    'material_id' => $material->id,
                    'sku_id' => null,
                    'batch_id' => null,
                    'warehouse_id' => $warehouse->id,
                    'qty' => rand(100, 2000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        
        // Seed SKU stock items
        foreach ($skus as $sku) {
            foreach ($warehouses as $warehouse) {
                $batch = $batches->where('sku_id', $sku->id)->first();
                
                DB::table('stock_items')->insertOrIgnore([
                    'id' => Str::uuid(),
                    'item_type' => 'sku',
                    'material_id' => null,
                    'sku_id' => $sku->id,
                    'batch_id' => $batch ? $batch->id : null,
                    'warehouse_id' => $warehouse->id,
                    'qty' => rand(50, 500),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
