# Ops brief implementation status (Cursor)

Grounded simulation inputs: `EXCELLS/` includes DRIVER WORK SHEET, production, inventory, sales, payroll templates. **Petty Cash Summary.xlsx is not in the folder** — flag per brief §10; Petty Cash module already exists in Finance.

## Shipped in this wave
- [x] Distinct Driver vs Sales Executive check-in/out labels + system timestamps
- [x] Manager attendance employee dropdown fixed (`GET /hr/attendance/employees`)
- [x] Attendance Excel export
- [x] Trip date auto-captured server-side; authorizing officer already hard-required
- [x] Stock Arrival terminology (Goods Received only); bags→bales computation on material batch receive
- [x] Fine Line / Blue Plus suppliers in config; bottles_per_bale = 24
- [x] Production: New Batch (SKU + bales + date only); daily rollup; package-run auto behind the scenes
- [x] Director live profit (`GET /finance/profit-summary`) behind HR unlock
- [x] HR/Payroll/Profit secondary password re-auth (own password, 30 min)

## Next waves (remaining)
- [ ] Full Inventory Stock Arrival form (supplier+SKU+bags+doc upload) replacing free-form move
- [ ] Competitive pricing comparison table + Investor surface
- [ ] Manager walk-in/field sales
- [ ] Driver KPI/customer-base enhancements + end-trip report inbox
- [ ] Month simulation + cross-dashboard audit
- [ ] Excel export on every remaining table
