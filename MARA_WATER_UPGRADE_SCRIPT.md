# MARA Water — Full Upgrade Script for Claude Code

**How to use this:** paste this entire document into Claude Code in VS Code, in the cloned MARA-WATER1 project. Tell it to work phase by phase, showing you a summary and a way to test after each phase, instead of doing everything silently in one giant pass. It has full permission to add, remove, or edit any file, component, route, or database table needed to accomplish this — see the ground rules below for the two things it must never do without stopping.

Company context so the work is grounded in reality, not generic: this is **Homa Springs Limited**, trading as **MARA Drinking Water**. Brands: **Premium** (0.5L, 1L, 1.5L, 5L, 10L, 20L), **Platinum** (0.5L, 1L), **Grace** (0.5L, 1L), and **Refills** (5L, 10L, 20L). The company runs deliveries by route/driver across Homa Bay and Migori county towns (Rongo, Rodi, Homabay, Mara, Abi, Ndhiwa, Migori, Nyatike, Masara, Mikei, Uriri, and others), sells from multiple outlets/branches (one is referred to as "KDQ"), and currently tracks everything below in Excel.

---

## Ground rules (non-negotiable)

1. **Never wipe or migrate production data without an explicit, typed confirmation from a logged-in admin**, and never without taking an automatic backup immediately first (see Phase 8). This applies to the reset button itself and to any schema migration that could drop columns/tables with data in them.
2. **Don't break what already works.** Before changing any route, component, or table, check what currently references it (imports, API calls, foreign keys) so nothing silently breaks elsewhere.
3. Commit to git in small, working chunks per phase — not one giant commit at the end — so any single phase can be reverted without losing the others.
4. If Supabase credentials, a Vercel env var, or any other secret is needed and isn't already available in `.env`, stop and ask rather than inventing a placeholder that silently fails.
5. Work phase by phase in the order below. Each phase ends with something testable.

---

## Phase 0 — Orient before touching anything

Before writing any code:

- Map the existing route structure, component tree, and page inventory (every nav item, every button, every link).
- Map the existing Supabase schema: tables, columns, relationships, RLS policies.
- List every button and route found, and for each one, note whether it currently works, is a dead link, is a placeholder ("coming soon"), or throws an error when clicked. This becomes the checklist for Phase 1.
- Report this back as a short summary before proceeding to Phase 1.

---

## Phase 1 — Fix what's broken

Using the Phase 0 checklist:

- Fix or wire up every button and route that doesn't currently do what its label says. Nothing should be decorative — if a button exists, it performs its labeled action or is removed.
- **Top search bar:** make it functional across the app — search should query real data (customers, employees, sales records, inventory items, invoices, drivers/vehicles as relevant) and route the user to the matching record, not just filter the current page silently or do nothing.
- **System Settings:** go through every settings toggle, field, and section and confirm each one actually changes application behavior when saved (not just visually toggling with no effect). Fix any that are cosmetic-only.
- Test each fix by actually clicking it, not just reading the code.

---

## Phase 2 — Redesign the main dashboard

Acting as a product designer and frontend developer: redesign the main dashboard to be visually clean, well-aligned, and noticeably better than the current one, while keeping every existing metric/widget it currently surfaces (don't remove functionality in the name of a redesign — add clarity to it).

Suggested direction (adjust to match whatever design system/component library the codebase already uses — don't introduce a second competing UI library):

- Consistent spacing/grid — align cards and widgets to a real grid, not eyeballed positions.
- A clear visual hierarchy: today's key numbers first (sales today, cash + M-Pesa collected today, litres produced today, stock alerts, debtor balance outstanding), then trends, then module shortcuts.
- Color used with intent — status colors (low stock, overdue debtor, vehicle maintenance due) should be consistent across the whole app, not just the dashboard.
- Real charts for trends that matter to this business: daily sales by brand, production vs. sales, debtor balances trending, fleet fuel cost trend.
- Quick-action shortcuts into the modules built in the phases below (log a sale, add a customer, add stock movement, record fuel).
- Confirm it's responsive — this will very likely be checked on a phone in the warehouse or a driver's tablet, not just a desktop monitor.

---

## Phase 3 — Danger-zone "Clear All Data" reset

Add a reset function so the database can be wiped clean for a fresh production start, but built as a one-time launch tool, not a casual feature:

- Restrict to admin role only.
- Before wiping, automatically export/back up all current data (see Phase 8's backup mechanism) so a wipe is never actually destructive/unrecoverable.
- Require typed confirmation (e.g. the admin must type the company name or "DELETE ALL DATA") before it executes — a single click must not be able to trigger it.
- After it's used once, either hide the button behind a feature flag/env var that defaults to off, or require a second admin-only "danger zone unlock" toggle in settings before it's even visible. The goal: it's available when you genuinely need a clean slate, but nobody wipes real financial data by misclicking six months from now.
- Log who ran it and when, permanently, even after the data itself is cleared.

---

## Phase 4 — HR Management

- Add a way to bulk-add every employee in the company at once — not just one-by-one. Support pasting/importing a list (CSV/Excel upload or a multi-row add form) so all current staff can be entered in one sitting: name, role/position, phone, ID number, department (Production / Sales / Fleet / Warehouse / Admin / Finance), employment date, salary, and status (active/inactive).
- Since driver names already show up in the fleet data (e.g. "Ishmael Ochieng", "Poul Ochieng", drivers with an "authorizing officer" like "Santos Cathy"), make sure the employee record is the single source of truth that Fleet, Sales, and Payroll modules reference by employee ID rather than re-typing names as free text — that's what causes name-spelling mismatches in the current Excel process.

---

## Phase 5 — Fleet Management

Build this against the actual fields the drivers currently fill in by hand (from the driver worksheets):

| Field | Notes |
|---|---|
| Date | |
| Driver (linked to employee record) | |
| Vehicle / registration | |
| Route | Free text or a route picker — towns like Rongo, Rodi, Homabay, Mara, Abi, Ndhiwa, Migori, Nyatike, Masara, Mikei, Uriri recur, so consider a "route" or "zone" reference table drivers pick from, with the ability to add a new one |
| Mileage start / mileage end | Auto-calculate KM covered — don't make the driver do that math |
| Fuel drawn (litres) | |
| Oil | |
| Authorizing officer | |
| Time out / time in | |
| **Stock carried out** | Per product/brand/size loaded onto the vehicle |
| **Stock returned** | Per product/brand/size brought back unsold |
| **Cash sales collected** | |
| **M-Pesa sales collected** | Ideally with an M-Pesa transaction reference field, same as the petty cash sheet already tracks |

Also build: mileage-per-vehicle history and trend (for maintenance scheduling and fuel-efficiency tracking), and a reconciliation check per trip — stock carried minus stock returned should equal stock sold, which should reconcile against cash + M-Pesa collected for that trip. Flag mismatches instead of silently accepting them, since that's exactly the kind of error manual Excel entry currently misses.

---

## Phase 6 — Customer base

New module for managing clients (shops, hotels, institutions, and resellers who buy water):

- Organization / shop name
- Contact person name, phone, email
- Physical location / delivery address (and ideally a route/zone tag so it links to Fleet routing)
- Customer type (retail shop, hotel/restaurant, institution, distributor/reseller)
- Preferred products/brands and typical order size
- Payment terms (cash, M-Pesa, credit/debtor account)
- Linked debtor ledger balance if they buy on credit (see Phase 8 — this replaces the standalone "HSL Debtors Ledger" Excel)
- Notes/history of interactions
- Active/inactive status

---

## Phase 7 — Sales Management (replace manual entry)

The current process is a paper/Excel "Sales Control Tool" filled out per day per outlet (one tab literally exists for every single day of the month, per outlet — 1ST, 2ND, 3RD... 31ST, repeated for each branch/outlet like "KDQ"). Replace this entirely with a proper transactional sales flow:

- A single "Log a Sale" flow (not one screen per day/outlet) capturing: date/time (auto), outlet/branch, product (brand + size), quantity dispatched, unit price (pull from a price list, allow override with a reason), amount (auto-calculated, never manually typed), quantity returned, payment method (cash / M-Pesa / credit to a customer account), and linked customer (optional, required if credit).
- Auto-roll every sale into daily/weekly/monthly totals per outlet and per brand — this is what the 31-tabs-per-month Excel pattern was manually doing; it should now be a query/report, not a new sheet.
- Returns should automatically feed back into inventory as a stock-in movement (see Phase 8) rather than being a dead number on a sales sheet.
- Credit sales should automatically post to that customer's debtor ledger (see Phase 9) — this is the single biggest manual step to eliminate, since right now sales and debtors are two disconnected spreadsheets that have to be reconciled by hand.
- Build a sales dashboard/report view: by outlet, by brand/size, by day/week/month, with dispatched vs. returned vs. net sold.

---

## Phase 8 — Inventory Management

This is the biggest consolidation. Currently four separate Excel processes cover different angles of the same stock — merge them into one real-time inventory system with a full movement ledger, not four disconnected views:

**1. Production logging** (replaces the daily "2025 PRODUCTION" sheet — per day, per brand, per size: 255, 1, 1.5, 5L, 10L, 20L for Premium; 0.5L, 1L for Platinum; refill sizes; Grace 0.5L/1L). Every production run should create a stock-in movement automatically.

**2. Raw materials usage** (replaces "RAW MATERIAL REAL") — track consumption of bottles/preforms by size (1.5L, 1L, 0.5L, 5L, 10L, 20L for both Premium and Platinum lines) and bailing papers by size, each with opening balance, usage, and running balance. Production logging above should auto-deduct the raw materials consumed per unit produced (this is exactly the "opening → used → balance" pattern already in the sheet, just automated instead of hand-typed).

**3. Warehouse stock card** (replaces "MAIN STOCK WARE HOUSE") — per product: opening balance, stock in, stock out, returns, closing balance, per day. This should just be a filtered view of the unified movement ledger, not a separately maintained number.

**4. Stock reconciliation tool** — per item (brand × size, e.g. Platinum 0.5L, Premium 1.5L, Refill 10L): opening qty, produced, issued, returned, closing qty, and the same set in value terms using unit price. This becomes a report generated on demand from the movement ledger and unit price list — never a manually re-entered table.

**5. Refills tracking** — daily refill quantities by size (5L, 10L, 20L) should just be a filtered slice of the same production/sales data by product type "Refill," not a fifth separate sheet.

Build it as: one inventory items table (brand, size, unit price, reorder threshold) + one stock movement ledger (item, type: production-in / sale-out / return-in / adjustment, quantity, date, reference to the sale/production/driver-trip that caused it) + views/reports that recreate every one of the five sheets above as a live report instead of a static file. Add low-stock alerts against the reorder threshold, surfaced on the dashboard.

---

## Phase 9 — Finance: Petty Cash + Debtors + Costing

**Petty cash journal** (replaces "PETTY CASH SUMMARY G") — recreate the real fields: date, M-Pesa reference, account code, account description, transaction description, requestor, amount in, amount out, running balance. Load the existing Chart of Accounts (nearly 200 account codes, e.g. "Fixed Assets – Vehicles – At cost," "Base salaries (Net)," "Advertising and publicity," etc.) as a real reference table so entries are coded consistently instead of free-typed, and build the same utilization summary report (sum of IN/OUT per account) as a live report.

**Debtors ledger** (replaces "HSL DEBTORS LEDGER") — per customer (linked to the Customer Base module from Phase 6): date, details, cheque/invoice number, payment voucher number, debit, credit, running balance. This should auto-populate from credit sales (Phase 7) rather than being entered separately, with manual entries still possible for adjustments/opening balances.

**Costing & P&L reporting** (from the "KDQ Reopening Plan" model) — this workbook already encodes real per-bottle material costs (bottle/preform, label, seal, wrapper, by brand and size), monthly salary/overhead costs, and a trading P&L (revenue − COGS = gross margin). Turn this into a live costing report: per-bottle cost pulled from the raw materials + packaging costs already tracked in Inventory, actual revenue pulled from real Sales data, and a real-time gross margin report instead of a manually rebuilt spreadsheet every time someone wants to check profitability.

---

## Phase 10 — QA: Warehouse & Equipment Audit module

Recreate the "Warehouse Audit" as a standing, repeatable module rather than a one-off Word document — this is effectively a QA/compliance checklist that should be run periodically, not written from scratch each time:

- **Packaging materials stock watch**: labels (by brand/size), seals (bottle vs. refill jerrican, since refill top seals hitting zero was flagged as a direct blocker to sales), stickers, bailing papers — each with an on-hand count and a status (adequate / watch / out of stock), tied into the same low-stock alerting as Phase 8.
- **Equipment & machinery log**: batching machine, heat guns (track count on hand vs. minimum needed for full production), booster pumps/valves, production basins, backwash system — each with condition, last maintenance date, and next-due date, so "last serviced March 2026" style overdue gaps get flagged automatically instead of discovered during an audit.
- **Chemicals & water testing log**: chlorine stock with expiry dates, pH tester availability/calibration status — flag expired chemicals and missing test equipment as a blocking alert, since the audit specifically noted these two are unsafe to treat as separate issues.
- **PPE tracking**: gunboots, raincoats, hair coverings, etc. against headcount from HR (Phase 4), so shortages are calculated, not guessed.
- **Stationery stock**: receipt books, delivery books, invoice books.
- A ranked "critical gaps" view — anything out-of-stock or overdue-maintenance surfaces at the top, same logic as the audit's "ranked by what actually blocks production" section.

---

## Phase 11 — Backups: never lose data

Given this is financial and production data:

- Set up automated, scheduled Supabase backups (daily at minimum, given daily sales/cash volume) — either via Supabase's built-in point-in-time recovery on a paid tier, or a scheduled export job (pg_dump or a Supabase Edge Function on a cron) pushed to separate storage if on the free tier.
- Retention: keep enough history to recover a full financial year, since petty cash and debtor ledgers are described as yearly-critical.
- Before any destructive operation anywhere in the app (the Phase 3 reset, bulk deletes, schema migrations), trigger an immediate backup — don't rely solely on the nightly schedule.
- Document (in a README or an in-app "Backups" settings page) where backups live and how to restore one — a backup nobody can find or restore in an emergency isn't a real backup.

---

## Phase 12 — Final verification pass

Before calling this done:

- Click through every nav item, every button, every route — confirm each does what it's labeled to do (this re-runs the Phase 0 checklist to confirm every item is now fixed).
- Test the search bar with a real customer name, employee name, and product to confirm it returns and routes correctly.
- Test every settings toggle end-to-end, not just that it saves.
- Run through one full cycle by hand: create a customer → log a production run → log a sale to that customer on credit → confirm inventory decremented, the sale shows on the dashboard, and the customer's debtor balance updated correctly.
- Log a fleet trip with stock carried/returned and cash + M-Pesa collected, and confirm the reconciliation check flags it correctly whether it matches or not.
- Trigger a test backup and confirm a restore actually works, before trusting it with real data.
- Confirm the "Clear All Data" reset requires the typed confirmation, actually backs up first, and is properly locked down afterward.
- Report back a summary of what was built per phase, anything that couldn't be completed and why, and any decision you made that I should sanity-check (e.g. a schema choice, a renamed field, a module you scoped differently than described here).

---

## Appendix — Excel-to-app source map

For traceability, here's which uploaded file maps to which module above, so nothing gets missed:

| Excel/Word file | App module |
|---|---|
| 2025 PRODUCTION(1).xlsx | Inventory → Production logging (Phase 8) |
| DRIVER WORK SHEET SOFTCOPY.xlsx | Fleet Management (Phase 5) |
| HSL DEBTORS LEDGER.xlsx | Finance → Debtors ledger (Phase 9) |
| HSL SALES JUNE.xlsx | Sales Management (Phase 7) |
| INVENTORY CONTROL.xlsx | Inventory → Production + stock (Phase 8) |
| MAIN STOCK WARE HOUSE.xlsx | Inventory → Warehouse stock card (Phase 8) |
| RAW MATERIAL REAL.xlsx | Inventory → Raw materials usage (Phase 8) |
| REFILS.xlsx | Inventory → Refills (Phase 8), Sales (Phase 7) |
| Stock reconcilliation Template JUNE.xlsx | Inventory → Stock reconciliation report (Phase 8) |
| PETTY CASH SUMMARY G.xlsx | Finance → Petty cash + Chart of Accounts (Phase 9) |
| MARA_WATER_Warehouse_Audit.docx | QA → Warehouse & Equipment Audit (Phase 10) |
| MARA_WATER_KDQ_Reopening_Plan.xlsx | Finance → Costing & P&L reporting (Phase 9) |

---

Go phase by phase. Show me what changed and how to test it after each phase, and stop and ask before anything in the Ground Rules section.
