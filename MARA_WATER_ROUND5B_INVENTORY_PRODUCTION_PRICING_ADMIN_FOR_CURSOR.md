# MARA Water — Round 5B (Cursor): Inventory, Production, Pricing, Finance, Users, Reports & Analytics, Danger Zone

This is **one half of a two-part split** of the Round 5 handwritten-notes script. This half covers Inventory, Production, the new Pricing page, Finance ledger accuracy, Users/Employee record access, Reports & Analytics, and the Danger Zone. The other half — role dashboards (Manager/Investor/Driver), the Trip page, Sales Management, Fleet bug fixes, and Issues/Discrepancies — is being fed separately to Claude Code as `MARA_WATER_ROUND5A_DASHBOARDS_TRIP_SALES_FLEET_FOR_CLAUDE_CODE.md`.

**Stay inside this half's scope.** Don't touch the Manager/Investor/Driver dashboards, the Trip page, Sales Management, Fleet, or the Issues/Discrepancies pages — Claude Code is working there in parallel and overlapping edits will cause merge conflicts. Two places in this script are *read by* the other half (Sales Management will read your Pricing lists; the Discrepancies page will read your Inventory/Production figures) — build clean, stable read interfaces for those two things even though you won't touch the pages that consume them.

Paste this whole file into Cursor after Rounds 1-4 are implemented. Work phase by phase, verify visually after each.

---

## Phase 1 — Inventory: SKU-per-arrival + link to Warehouse Audit

Every stock arrival/purchase should route through the existing Warehouse Audit / QA batch-quality check, so a new SKU batch is tied to its quality record from the moment it's received, not just its quantity and cost. (SKU creation itself on stock purchase should already exist from an earlier round — this phase is about wiring it to the quality/audit record, not building SKU creation from scratch.)

---

## Phase 2 — Production: daily entry sheet tied to Inventory SKU consumption

Build (or confirm, if partially done) a daily Production entry view: staff log what was produced each day, date-stamped, with quantities converted from raw bag/bulk units to production output units. Each production entry should draw down the specific Inventory SKU batch(es) it consumed (linking to Phase 1's SKU-per-arrival), so raw-material usage is traceable per batch, not just decremented from a generic total. New production batches should auto-generate a batch ID with the captured date, building a full production history over time.

---

## Phase 3 — Automatic periodic stock reconciliation export

New automation: on a regular cadence (weekly and monthly), auto-generate a downloadable Excel stock reconciliation report — matching the layout of the source Stock Reconciliation Template — comparing expected stock (from Inventory + Production − Sales) against what should be on hand, without anyone needing to manually run the report. Make it available for download on-demand as well as auto-generated on schedule. **Note:** the Sales figure this needs is owned by Claude Code's half — read it via whatever shared sales-totals API/query already exists rather than duplicating sales logic here.

---

## Phase 4 — Pricing page: split Director default list vs Manager corporate/competitive list

Build a Pricing page with two distinct, separately-editable price lists:
- **Default price list** (the standing retail prices, e.g. covering a period like Oct 2025-2026) — editable only by Director.
- **Corporate/competitive price list** — a separate list for bulk/corporate/negotiated pricing — editable by Manager.

Both should be exportable as Excel. **Build this with a clean, stable read API** (e.g. `getCurrentPrice(brand, size)`), because Claude Code's Sales Management phase reads from it — every place in the app that references "the price" for a brand/size should ultimately read from these lists rather than a hardcoded value, so a price change here propagates everywhere, including the trip-sales flow you aren't touching directly.

---

## Phase 5 — Finance: Manager/Director ledger accuracy

Add a reconciliation check between what the Manager's financial dashboard shows and what the Director's shows for the same period — they should always agree, since they're views over the same underlying ledger. If any Finance figure is computed differently for Manager vs Director (e.g., different date-range defaults, different rounding, different included categories), align them so both reflect the same real numbers.

---

## Phase 6 — Users/Employee records: Director-only access

Tighten staff/employee record access: viewing and editing Users/Employee records should be restricted to the Director's dashboard only. Remove any Manager-level access to raw employee records (Manager keeps Attendance, but not the underlying employee record itself unless already explicitly granted elsewhere).

---

## Phase 7 — Reports & Analytics: metric set + revenue/tax forecast

Build out the Director's Reports & Analytics section to explicitly cover: Sales, Finance, Production, Turnover, Quality, Inventory rate, and Productivity as tracked metrics. Add a forecasted revenue calculation: expected revenue per month based on current production output and the active price list (Phase 4), plus an expected tax estimate based on that forecast. **Note:** the Sales and Turnover figures pull from data Claude Code's half is also working against (Sales Management) — read via shared totals rather than re-deriving sales numbers independently.

---

## Phase 8 — Danger Zone: granular clear-by-section + mandatory pre-clear backup

This is a meaningful change to the existing "Clear All Data" feature:
1. Replace (or add to) the all-or-nothing clear with a **dropdown to select which section/module** to clear (e.g., Sales only, Production only, a specific date range, etc.) so the Director isn't forced to choose between wiping everything or nothing.
2. **Enforce an automatic backup/download before the danger zone can be unlocked or used.** If a backup hasn't already been taken (check timestamp of last backup), the system must force one to run and complete successfully before allowing any clear action to proceed — no clearing without a fresh safety net first. This is a hard gate, not a warning dialog the Director can dismiss.

---

## Phase 9 — Final verification pass (this half only)

1. Purchase new stock, confirm it creates a SKU tied to a Warehouse Audit quality record.
2. Log a production entry, confirm it draws down the correct Inventory SKU batch and generates an auto-dated batch ID.
3. Confirm a weekly/monthly stock reconciliation Excel auto-generates on schedule and is also available on-demand, matching the Stock Reconciliation Template layout.
4. Edit the default price list as Director and the corporate list as Manager, confirm each is restricted to the correct role and both export to Excel correctly.
5. Compare Manager's and Director's Finance dashboards for the same period, confirm the numbers match.
6. Confirm Users/Employee records are not reachable from a Manager login.
7. Confirm Reports & Analytics shows all seven listed metrics plus the revenue/tax forecast.
8. Attempt to open the Danger Zone with no recent backup — confirm it forces a backup first; confirm the section-selection dropdown works and only clears the chosen section.

Report back pass/fail against these 8 checks. Once Claude Code's half (`ROUND5A`) is also merged, do one combined smoke test across both — particularly Sales Management's price lookups against your Phase 4 Pricing lists, and the Discrepancies page's reads against your Inventory/Production figures, since those are the two seams between the two scripts.
