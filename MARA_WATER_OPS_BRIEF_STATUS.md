# Ops brief implementation status (Cursor)

Grounded simulation inputs: `EXCELLS/` includes DRIVER WORK SHEET, production, inventory, sales, payroll templates. **Petty Cash Summary.xlsx is not in the folder** — flag per brief §10; see also `simulation-inputs/README.md`. Petty Cash module already exists in Finance.

## Shipped
- [x] Distinct Driver vs Sales Executive check-in/out labels + system timestamps
- [x] Manager attendance employee dropdown fixed (`GET /hr/attendance/employees`)
- [x] Attendance Excel export
- [x] Trip date auto-captured server-side; authorizing officer already hard-required
- [x] Stock Arrival terminology (Goods Received only); bags→bales computation on material batch receive
- [x] Fine Line / Blue Plus suppliers in config; bottles_per_bale = 24
- [x] Production: New Batch (SKU + bales + date only); daily rollup; package-run auto behind the scenes
- [x] Director live profit (`GET /finance/profit-summary`) behind HR unlock
- [x] HR/Payroll/Profit secondary password re-auth (own password, 30 min)
- [x] Three-tier pricing: Default (Director) / Corporate (Manager) / Competitive comparison (named competitors)
- [x] Investor pricing overview (`GET /investor/pricing-overview`)
- [x] Driver ops KPIs: sell-through, fuel, repairs, customer base, discrepancies (`/driver/ops-kpis`, `/driver/customers`)
- [x] Vehicle repairs module (Fleet → Repairs tab)
- [x] Month simulation seeder: `OpsMonthSimulationSeeder` (run on migrated DB)

## Remaining / polish
- [ ] Full Inventory Stock Arrival form (supplier+SKU+bags+doc upload) replacing free-form move — partial
- [ ] Manager walk-in/field sales polish
- [ ] End-trip report inbox on Manager/Director
- [ ] Excel export on every remaining table
- [ ] Run simulation on production + cross-dashboard audit pass
