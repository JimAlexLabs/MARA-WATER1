# MARA Water — End-to-End Test Report

**Date:** 2026-09-28  
**Target:** Production — https://marawater.online → https://mara-water1-production.up.railway.app/api/v1  
**Accounts used:** Director (`director@marawater.com`), Manager (`sim-manager@marawater.local` / `Sim@2026`), Driver (`sim-driver-ishmael@marawater.local` / `Sim@2026`)

## Verdict

Core auth, role gates, Operations overview, Production, Driver app, and Director profit unlock work. Several production bugs block Manager/Director fleet trips UI, inventory arrivals visibility, finished-warehouse integrity, and Costing P&L COGS.

---

## Passed

| Area | Result |
|------|--------|
| Frontend + API up | HTTP 200 |
| Login Director / Manager / Driver | OK |
| Role redirects | Director → `/dashboard`, Driver → `/driver` |
| Tier gates | Manager blocked from Operations/Users/Profit/Investor; Driver blocked from inventory/dashboard/finance |
| Operations overview | Loads; D−S returns = 6835−6220 = **615**; bags 3626; payroll KES 185,000 |
| Bags → bales | 1813 bags × 24 / 24 = **1813** expected (match) |
| Driver KPI returns | 3306−3146 = **160** (match) |
| Director profit (after unlock + `month=YYYY-MM`) | Revenue 1,514,990 · COGS 580,160 · Salaries 185,000 · Other 143,400 · **Profit 606,430** |
| Production page | New Batch + Daily Production (22 days, 1,406 bales last 7d) |
| Pricing API | Default Premium 0.5L = 450; competitive matrix 3 companies |
| Excel exports (ops with `year`+`month`) | Inventory/raw materials OK; trips CSV, attendance xlsx, competitive xlsx OK |
| Driver dashboard / trips / attendance | Scoped nav; trips list with Matches; distinct Driver check-in stations |

---

## Failed / bugs

### 1. Fleet → Driver Trips crashes (P0)
- Opening **Driver Trips** on `/fleet` errors: `Cannot read properties of undefined (reading 'toLocaleString')`.
- Likely `mileage-trend` rows with missing `total_km` / `total_fuel_liters` (FleetPage ~line 630), and/or trip rows with null `total_collected`.
- API `GET /fleet/trips` returns **20 trips**; UI never shows them because the page crashes.

### 2. Inventory Stock Arrivals shows 0 (P1)
- UI counts GRNs from `GET /inventory/stock-moves` (unpaginated/limited list of latest 15).
- Live list is all `issue` moves; the 2 `grn` arrivals exist on `/inventory/material-batches` but fall off the moves window → **Goods Received: 0**, **Stock Arrivals (0)**.

### 3. Finished warehouse qty negative (P1)
- Operations: `warehouse.finished_qty_on_hand = **-1857**` bales.
- Simulation dispatched more than remaining finished stock accounting allows — stock card integrity broken.

### 4. Costing P&L COGS vs Director profit (P1)
- `GET /finance/costing/profit-loss`: COGS ≈ **1,424** (SKU `unit_cost` mostly 0) → net margin ~1.17M.
- `GET /finance/profit-summary`: COGS **580,160** (material batch purchases) → profit **606,430**.
- Two different “truths” for management reporting.

### 5. Dashboard “Orders This Month: 0”
- Trip sales live on driver trips (KES ~1.5M), not `/sales/orders`. KPI card understates sales if read as commercial volume.

### 6. Low-stock noise
- Many SKUs/materials show large negative “left” (e.g. Refill 5L −1636) — related to #3.

---

## Not fully exercised (write paths)

Destructive / mutating flows skipped on production to avoid polluting live sim data:
- Create Stock Arrival, New Batch, Start/End Trip, Log Sale, Petty Cash entry, HR attendance store, password change.

API smoke for those modules’ **read** surfaces is green (customers 15, trips 20, petty cash, water tests 3, repairs 3, discrepancies 2).

---

## Recommended fix order

1. Guard Fleet mileage-trend + trip money fields (`?.( )` / `Number(x\|\|0)`) so Driver Trips tab renders.
2. Point Stock Arrivals tab at `/inventory/material-batches` (or paginate/filter stock-moves by `move_type=grn`).
3. Reconcile finished-goods ledger (negative warehouse) — adjust sim issues vs production packaging.
4. Align Costing P&L COGS with profit-summary basis (or label Costing as “SKU unit-cost only”).
5. Dashboard: show trip sales revenue alongside/instead of order count for Director/Manager.
