# Ops brief implementation status (Cursor)

Grounded simulation inputs: `EXCELLS/` includes DRIVER WORK SHEET, production, inventory, sales, payroll templates. **Petty Cash Summary.xlsx is not in the folder** — flag per brief §10; Petty Cash module already exists in Finance.

## Shipped in this wave
- [x] Distinct Driver vs Sales Executive check-in/out labels + system timestamps
- [x] Manager attendance employee dropdown fixed (`GET /hr/attendance/employees`)
- [x] Attendance Excel export
- [x] Trip date auto-captured server-side; authorizing officer already hard-required
- [x] Stock Arrival terminology (Goods Received only); bags→bales computation on material batch receive
- [x] Fine Line / Blue Plus suppliers in config; bottles_per_bale = 24

## Next waves (in progress)
- [ ] Full Inventory Stock Arrival form (supplier+SKU+bags+doc upload) replacing free-form move
- [ ] Production: New Batch only; remove Package Run UI
- [ ] Competitive pricing table + Investor surface
- [ ] Manager walk-in/field sales
- [ ] HR/Payroll/Profit secondary password gate
- [ ] Director live profit formula
- [ ] Driver KPI/customer-base enhancements
- [ ] Month simulation + cross-dashboard audit
