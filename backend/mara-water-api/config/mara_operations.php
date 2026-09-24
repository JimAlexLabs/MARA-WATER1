<?php

/**
 * Mara Water (Rongo) operations model — salary bands, bottle suppliers,
 * and supply-chain constants used by the Director Operations Overview
 * and related Excel exports. Director pay is tracked separately as an
 * allowance and is NEVER included in operational payroll exports.
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
            'name' => 'FineLine',
            'city' => 'Nairobi',
            'package_unit' => 'bag',
            'notes' => 'Empty bottles + top seals; bag packaging specific to FineLine',
        ],
        [
            'code' => 'BLOWPLAST',
            'name' => 'Blowplast',
            'city' => 'Nairobi',
            'package_unit' => 'bag',
            'notes' => 'Empty bottles + top seals; bag packaging specific to Blowplast',
        ],
    ],

    /** Flat Nairobi → Rongo haulage for a bottle consignment (KES). */
    'bottle_transport_cost_kes' => 45000,

    'other_inputs' => [
        ['name' => 'Bailing papers', 'from' => 'Kisumu'],
        ['name' => 'Labels', 'from' => 'External supplier'],
        ['name' => 'Stickers', 'from' => 'External supplier'],
        ['name' => 'Top seals', 'from' => 'Included with bottle consignments'],
    ],

    /**
     * Monthly salary bands (KES). Director is listed for overview only —
     * excluded from payroll Excel / Finalis-style exports.
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
        [
            'role_key' => 'director',
            'title' => 'Director',
            'headcount_target' => 1,
            'min_kes' => 60000,
            'max_kes' => 60000,
            'default_kes' => 60000,
            'include_in_payroll_export' => false,
            'notes' => 'Tracked as allowance later — excluded from payroll Excel',
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
