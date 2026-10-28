<?php

/**
 * Mara Water (Rongo) operations model — salary bands, bottle suppliers,
 * and supply-chain constants used by the Operations Overview
 * and related Excel exports. Operational payroll is staff only —
 * no director allowance, dividend, or investor share.
 */
return [

    'site' => [
        'company_name' => 'Homa Springs Limited / Mara Water',
        'operations_base' => 'Rongo',
        'sourcing_city' => 'Nairobi',
        'bailing_papers_from' => 'Kisumu',
    ],

    /**
     * Empty bottles arrive in BAGS (not bales). Packaging differs by
     * supplier; conversion to bales happens at production for sales.
     */
    'bottle_suppliers' => [
        [
            'code' => 'FINELINE',
            'name' => 'Fine Line',
            'city' => 'Nairobi',
            'package_unit' => 'bag',
            'notes' => 'Empty bottles + top seals; bag packaging specific to Fine Line',
        ],
        [
            'code' => 'BLUEPLUS',
            'name' => 'Blue Plus',
            'city' => 'Nairobi',
            'package_unit' => 'bag',
            'notes' => 'Empty bottles + top seals; bag packaging specific to Blue Plus',
        ],
        [
            'code' => 'BLOWPLAST',
            'name' => 'Blowplast',
            'city' => 'Nairobi',
            'package_unit' => 'bag',
            'notes' => 'Legacy supplier code — prefer Blue Plus / Fine Line going forward',
        ],
    ],

    /**
     * Fixed bottles per finished bale (sales/production unit).
     * Bottles-per-bag varies by supplier+SKU and is stored per arrival.
     * Bales expected = (bags × bottles_per_bag) ÷ bottles_per_bale.
     *
     * Bailing paper: 1 sheet wraps exactly one bale —
     * 24×0.5L, 12×1L, 12×1.5L, 4×5L. 10L / 20L are not baled.
     */
    'bottles_per_bale' => 24,
    'bottles_per_bale_by_size' => [
        0.5 => 24,
        1.0 => 12,
        1.5 => 12,
        5.0 => 4,
        10.0 => 1,
        20.0 => 1,
    ],
    'bailing_sheets_per_bale' => 1,
    'bailing_bag_sheets' => 500,
    'bottle_transport_cost_kes' => 45000,

    /**
     * Hypothesis Model (Oct 2026 Excel) — adjustable ops/planning inputs.
     * Selling prices below are the live suggested retail (bale/unit KES).
     */
    'hypothesis' => [
        'bottle_supplier' => 'Intripet Limited',
        'transport_per_trip_kes' => 45000,
        'wages_per_bottle' => [
            0.5 => 0.5,
            1.0 => 1.0,
            1.5 => 1.5,
            5.0 => 5.0,
            10.0 => 10.0,
            20.0 => 20.0,
        ],
        'materials_ex_vat' => [
            'bottle' => [0.5 => 5.2, 1.0 => 8.7, 1.5 => 12.2],
            'cap' => 0.8,
            'vat' => 0.16,
            'label' => [0.5 => 0.6, 1.0 => 1.15, 1.5 => 1.15, 5.0 => 3.0, 10.0 => 3.0, 20.0 => 3.0],
            'bailing_sheet' => [0.5 => 23, 1.0 => 24, 1.5 => 27, 5.0 => 32],
            // Security seal 0.3 (0.5–5L); 10L/20L = topseal 1.2 + security 1.2 (+0.3 on 10L in Cost Build).
            'seal' => [0.5 => 0.3, 1.0 => 0.3, 1.5 => 0.3, 5.0 => 0.3, 10.0 => 2.7, 20.0 => 2.4],
            'bottle_cap_final' => [5.0 => 36.48, 10.0 => 70.9, 20.0 => 145.42],
        ],
        'trip_bottles' => [0.5 => 23800, 1.0 => 2640, 1.5 => 5712],
        'selling_prices_bale' => [
            'premium' => [0.5 => 390, 1.0 => 390, 1.5 => 400, 5.0 => 500, 10.0 => 300, 20.0 => 500],
            'premium_custom' => [0.5 => 500],
            'platinum' => [0.5 => 500, 1.0 => 500],
            'platinum_custom' => [0.5 => 550, 1.0 => 550],
        ],
        // Excel Assumptions payroll (sales low 15k; accountant 24.5k; highs capped 25k).
        'payroll_monthly' => [
            'accountant_manager' => 24500,
            'driver_low' => 20000,
            'driver_high' => 25000,
            'sales_low' => 15000,
            'sales_high' => 25000,
            'finalis' => 15000,
            'jim_stipend_by_trips' => [1 => 60000, 2 => 65000, 3 => 70000, 4 => 75000, 5 => 75000],
        ],
        'overheads_monthly' => [
            'fuel' => 40000,
            'electricity' => 15000,
            'airtime' => 3000,
            'parking' => 3000,
            'emergency' => 5000,
        ],
        // Shared filling/labeling/sealing/bailing line (Assumptions B112–B113).
        'capacity' => [
            'bottles_per_hour' => 2400,
            'hours_per_day' => 4,
        ],
        'profit_split' => [
            'phase1_one_trip' => ['Jim' => 0.30, 'Derrick' => 0.20, 'Diana' => 0.20, 'Onroy' => 0.20, 'Company' => 0.10],
            'phase1_multi_trip' => ['Jim' => 0.30, 'Derrick' => 0.30, 'Diana' => 0.15, 'Onroy' => 0.15, 'Company' => 0.10],
            'phase2' => ['Jim' => 0.30, 'Derrick' => 0.15, 'Diana' => 0.20, 'Onroy' => 0.20, 'Company' => 0.15],
        ],
    ],

    'other_inputs' => [
        ['name' => 'Bailing papers', 'from' => 'Kisumu'],
        ['name' => 'Labels', 'from' => 'External supplier'],
        ['name' => 'Stickers', 'from' => 'External supplier'],
        ['name' => 'Top seals', 'from' => 'Included with bottle consignments'],
    ],

    /**
     * Monthly salary bands (KES) for operational staff only.
     */
    'salary_bands' => [
        [
            'role_key' => 'accountant_manager',
            'title' => 'Accountant / Manager',
            'headcount_target' => 1,
            'min_kes' => 20000,
            'max_kes' => 25000,
            'default_kes' => 25000,
            'include_in_payroll_export' => true,
            'notes' => 'Manager role; capped at 25,000',
        ],
        [
            'role_key' => 'driver',
            'title' => 'Driver',
            'headcount_target' => 1,
            'min_kes' => 20000,
            'max_kes' => 25000,
            'default_kes' => 20000,
            'include_in_payroll_export' => true,
            'notes' => 'Base 20,000; up to 25,000 on performance',
        ],
        [
            'role_key' => 'salesperson',
            'title' => 'Salesperson',
            'headcount_target' => 1,
            'min_kes' => 20000,
            'max_kes' => 25000,
            'default_kes' => 20000,
            'include_in_payroll_export' => true,
            'notes' => 'Base 20,000; up to 25,000 on performance',
        ],
        [
            'role_key' => 'production',
            'title' => 'Production (ladies)',
            'headcount_target' => 6,
            'min_kes' => 8000,
            'max_kes' => 12500,
            'default_kes' => 12500,
            'include_in_payroll_export' => true,
            'notes' => '6 staff; max 12,500 each; pay tied to production targets',
        ],
    ],

    /**
     * Default bag→bale conversion hints (bottles per bag / bottles per bale).
     * Directors override per supplier+SKU in sku_package_conversions.
     */
    'default_conversions' => [
        'bottles_per_bag' => 24,
        'bottles_per_bale' => 12,
    ],

    'supply_chain_stages' => [
        ['key' => 'people', 'label' => 'People & Payroll', 'href' => '/operations#people'],
        ['key' => 'sourcing', 'label' => 'Sourcing (Nairobi / Kisumu)', 'href' => '/operations#sourcing'],
        ['key' => 'inventory', 'label' => 'Raw inventory (bags)', 'href' => '/operations#inventory'],
        ['key' => 'production', 'label' => 'Production (bags → bales)', 'href' => '/operations#production'],
        ['key' => 'warehouse', 'label' => 'Finished warehouse (bales)', 'href' => '/operations#warehouse'],
        ['key' => 'dispatch', 'label' => 'Driver dispatch', 'href' => '/operations#dispatch'],
        ['key' => 'sales', 'label' => 'Sales & refills', 'href' => '/operations#sales'],
        ['key' => 'returns', 'label' => 'Returns & reconciliation', 'href' => '/operations#returns'],
        ['key' => 'reports', 'label' => 'Monthly reports & debtors', 'href' => '/operations#reports'],
    ],
];
