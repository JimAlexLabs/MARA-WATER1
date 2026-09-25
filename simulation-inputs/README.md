# Simulation inputs

Grounded reference files for Mara Water ops simulation live in **`EXCELLS/`** at the repo root (sibling of this folder).

## Present

| File | Use |
|------|-----|
| `EXCELLS/DRIVER WORK SHEET SOFTCOPY.xlsx` | Mileage, routes (Rongo / Mara / Homa Bay), drivers (Ishmael / Paul), authorizing officer (Santos Cathy) |
| Production, inventory, sales, payroll, debtors workbooks | Patterns for volumes, SKUs, Finalis-style payroll |

## Missing (ops brief §10)

**`Petty Cash Summary.xlsx` is not in `EXCELLS/`.**  
The Finance petty-cash module and Chart of Accounts (incl. repairs **5430**) still exist in-app; the month seeder synthesizes realistic fuel / electricity / repair OUT entries without that workbook.

## Run the month seeder

From `backend/mara-water-api` (requires base data: warehouses, SKUs, CoA, roles):

```bash
php artisan db:seed --class=OpsMonthSimulationSeeder
```

- Window: **current calendar month** (so live “this month” dashboards populate).
- Targets: sales ≈ **KES 1,500,000**; stock COGS ≈ **KES 560k–600k**; **no fuel excise**.
- Marker: codes/notes with `SIM-` / `[SIM]` — re-runs clear prior SIM rows first.
