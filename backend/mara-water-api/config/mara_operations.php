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

    // The Hypothesis Model now lives in App\Services\OperationsModelService (string size keys).

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
        ['key' => 'people', 'label' => 'People & Payroll', 'href' => '/operations/planning'],
        ['key' => 'sourcing', 'label' => 'Sourcing (Nairobi / Kisumu)', 'href' => '#reorder'],
        ['key' => 'inventory', 'label' => 'Raw inventory & reorders', 'href' => '#reorder'],
        ['key' => 'production', 'label' => 'Production (bags → bales)', 'href' => '#production'],
        ['key' => 'warehouse', 'label' => 'Finished warehouse (bales)', 'href' => '#production'],
        ['key' => 'dispatch', 'label' => 'Driver dispatch', 'href' => '#dispatch'],
        ['key' => 'sales', 'label' => 'Sales & refills', 'href' => '#sales'],
        ['key' => 'returns', 'label' => 'Returns & reconciliation', 'href' => '#dispatch'],
        ['key' => 'reports', 'label' => 'Monthly reports & debtors', 'href' => '#exports'],
    ],
];
