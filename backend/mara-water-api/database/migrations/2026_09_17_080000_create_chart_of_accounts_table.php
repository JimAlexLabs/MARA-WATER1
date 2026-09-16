<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 9 (Finance). The spec asks to load Homa Springs' real Chart of
 * Accounts (~200 codes) as a reference table so petty cash entries are
 * coded consistently instead of free-typed. That real list wasn't
 * available -- per explicit direction, this seeds a small starter set
 * (the 3 examples given, plus the obvious categories a small water
 * bottling business needs) and builds full account management in the
 * UI so the real ~200-code list can be entered properly later. This is
 * a starting point, not a claim that these are Homa Springs' actual
 * account codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('description', 255);
            $table->enum('category', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        $now = now();
        $accounts = [
            // Assets
            ['1000', 'Cash at Bank', 'asset'],
            ['1010', 'Petty Cash', 'asset'],
            ['1100', 'Accounts Receivable (Debtors)', 'asset'],
            ['1200', 'Inventory - Raw Materials', 'asset'],
            ['1210', 'Inventory - Finished Goods', 'asset'],
            ['1500', 'Fixed Assets - Vehicles - At cost', 'asset'],
            ['1510', 'Fixed Assets - Machinery & Equipment - At cost', 'asset'],
            ['1520', 'Fixed Assets - Office Equipment - At cost', 'asset'],
            // Liabilities
            ['2000', 'Accounts Payable (Creditors)', 'liability'],
            ['2100', 'Accrued Expenses', 'liability'],
            ['2200', 'VAT Payable', 'liability'],
            // Equity
            ['3000', "Owner's Capital", 'equity'],
            ['3100', 'Retained Earnings', 'equity'],
            // Income
            ['4000', 'Sales Revenue - Water Products', 'income'],
            ['4100', 'Other Income', 'income'],
            // Expenses
            ['5000', 'Base salaries (Net)', 'expense'],
            ['5010', 'Staff Welfare', 'expense'],
            ['5020', 'NSSF/NHIF Contributions', 'expense'],
            ['5100', 'Raw Materials - Bottles/Preforms', 'expense'],
            ['5110', 'Raw Materials - Labels & Packaging', 'expense'],
            ['5200', 'Fuel & Vehicle Maintenance', 'expense'],
            ['5210', 'Vehicle Insurance', 'expense'],
            ['5300', 'Rent', 'expense'],
            ['5310', 'Electricity', 'expense'],
            ['5320', 'Water', 'expense'],
            ['5330', 'Internet & Communication', 'expense'],
            ['5400', 'Advertising and publicity', 'expense'],
            ['5410', 'Bank Charges', 'expense'],
            ['5420', 'Office Supplies & Stationery', 'expense'],
            ['5430', 'Repairs & Maintenance', 'expense'],
            ['5440', 'Licenses & Permits', 'expense'],
            ['5450', 'Professional Fees (Legal/Audit)', 'expense'],
            ['5900', 'Miscellaneous Expenses', 'expense'],
        ];

        $rows = [];
        foreach ($accounts as [$code, $description, $category]) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'code' => $code,
                'description' => $description,
                'category' => $category,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('chart_of_accounts')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
