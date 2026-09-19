# MARA Water — Round 4 Script: Trip/Sales Automation, Fuel Logs, Mileage Logs, Attendance & Discrepancy Tracking

Paste this into Claude Code after Round 3 (`MARA_WATER_ROUND3_TRIPLOG_CRM_AND_UX_FIXES.md`) is implemented. This round fixes live bugs in the End Trip flow, automates calculations that are currently manual/broken, splits Fuel and Mileage into their own tracked entities, adds dual check-in/checkout for the shared Driver/Sales dashboard plus a new Field Work role, and adds a discrepancy-tracking page. It also resolves two ambiguities from your message — both are called out explicitly below so Claude Code implements the right thing.

**Source Excel files are now inside the project in a folder named `Excells`** — read them from there (2025 Production, Driver Work Sheet, HSL Debtors Ledger, HSL Sales June, Inventory Control, Main Stock Warehouse, October/December Sales Summary, Petty Cash Summary, Raw Material Real, Refils, Stock Reconciliation Template, Finalis Payroll Beta) for every "exact format" export in this round and in Round 3 Phase 9.

Work phase by phase, verify visually after each, don't touch anything not named here.

---

## Phase 0 — Two design decisions resolved (read before coding)

**1. Line items and price on the Sales entity.** Your message described this two ways in the same request. Resolved as: each sale must have **at least one line item, and line items are mandatory, not optional**. Each line item carries brand + size + quantity (bales) + **price for that line** — price is required per line, not omitted. The sale's **total amount is never typed in by the driver as a separate field** — it is always the auto-computed sum of the line items. This removes the double-entry that causes mismatches: one number (price) is keyed per item, everything else is computed.

**2. Product taxonomy — Premium and "Mara Water" are the same product line.** Your message confirmed the 0.5L/1L/1.5L/10L/20L bottle range currently split across a "Premium" entity and a "Mara Water" entity is one product, not two. Merge them everywhere in the app (trip dispatch, sales, production, inventory, analytics, exports) into a single brand entity — keep the name **"Premium"** as the canonical label (rename references, don't keep both). Grace, Platinum, and Refill remain separate as before. This is a data migration, not just a UI change — any historical trip/sale/production rows currently tagged "Mara Water" need to be re-tagged "Premium" so historical reports don't fragment.

---

## Phase 1 — Bales-only from Production onward; Bottles/Bags stays pre-production only

Remove any "Bottles" quantity column or section from the trip Dispatched/Returned/Sold tables and from the Sales line items — these must show **bales only**, no bottle-count entry, anywhere from Production forward (trip log, sales, warehouse stock, analytics). The Inventory module's **pre-production raw packaging stock** (empty bottles, caps, labels received before filling) is the only place "Bottles" or "Bags" as a unit still applies — leave that as-is, just make sure it's clearly scoped to pre-production inventory and doesn't leak its unit label into anything downstream of Production.

---

## Phase 2 — Fix End Trip: dispatched must show real numbers, returned must be computed, remove the zero-validation bug

Three live bugs/behaviors to fix in the End Trip screen:

1. **"Dispatched" is showing 0 instead of the real quantities captured at Start Trip.** Trace why the End Trip view isn't reading the locked dispatch values from the trip record — likely pulling from the wrong field, a stale join, or a component that re-initializes to zero instead of loading the trip's stored dispatch data. Fix so Dispatched always displays the actual locked-in numbers from Start Trip, and it remains **non-editable**.
2. **Remove the current validation that forces "Returned" to be zero** (the bug producing "values must be zero" when a driver tries to fill in returns). Returned is no longer a manually-typed field at all.
3. **Automate Returned = Dispatched − Sold**, computed per brand/size, displayed **non-editable** on End Trip. "Sold" here is the live cumulative total from the Sales entity (Phase 4) tied to this trip. This removes manual return counting entirely and is the fix for "math mismatch, errors and irregularities" you flagged — the only number a human enters anywhere in this chain is the sale price per line item; dispatched, sold-so-far, and returned are all either locked-in or computed.

**Only display items with quantity greater than zero** in both the "This Trip — Dispatched by Item" list and the "Cumulative Quantity Sold, by Item" list. Filter out every brand/size combination sitting at 0 so the driver sees a short, relevant list instead of the full 16-line catalogue currently rendered regardless of value.

---

## Phase 3 — Require Authorizing Officer before Start Trip is clickable

Disable the "Start Trip" button (not just validate on submit) while the Authorizing Officer field is empty. Add inline text under the field ("Required before you can start the trip") so it's obvious why the button is disabled, not just silently unclickable.

---

## Phase 4 — New standalone "Log a Sale" entity, connected to but separate from the trip page

Replace the current sprawling sales sub-section with a compact, separate page/panel reachable from the trip: a header showing **"Sales so far: [count] · KES [running total]"** and a **"Log a Sale"** button. This keeps the trip screen simple while still being tied to the trip so a driver can only sell what's been dispatched and remains accountable for it (block any line item quantity that would exceed dispatched-minus-already-sold for that brand/size, with a clear inline error if attempted).

**Log a Sale form fields:**
- Customer name (search existing customers by name/phone first; "add new" fallback — same dedupe behavior as Round 3 Phase 3)
- Customer phone number (required)
- Payment method: **Cash**, **M-Pesa / Paybill (direct)**, or **Debt** — keep Debt as an option since it feeds the existing Debtors Ledger and repayment-date validation from Round 2/3; add a short "prompt payment" step for Cash/M-Pesa that just confirms the amount received before the sale is saved
- Line items (**mandatory, at least one**): brand + size + quantity (bales) + price for that line — this is the only place a number is manually typed
- Total sale amount: **read-only, auto-computed** as the sum of line items — never manually entered (Phase 0, decision 1)

Every logged sale immediately decrements the "available to sell" balance for that trip (dispatched minus sold) and updates the running "Sales so far" total and the trip's live Sold tally used in Phase 2's Returned calculation.

Make the Sales page/panel downloadable as Excel from the trip detail view, for after-the-fact analysis.

---

## Phase 5 — Reconciliation gate before End Trip

Acknowledge the real workflow: during heavy field/market work, drivers/sales staff sometimes fall back to paper (invoice books, delivery notes) instead of logging every sale live in the app. Add a mandatory step between "returning to warehouse" and clicking "End Trip":

- Show a checklist/alert on the trip screen: **"Before ending this trip, reconcile any sales you recorded on paper into the app. Confirm your app totals match your paperwork."**
- Require an explicit confirmation checkbox ("I have reconciled all manual/paper sales into the app and they match my paperwork") before "End Trip" becomes clickable.
- This confirmation is logged with a timestamp on the trip record, so if a discrepancy shows up later (Phase 6), there's a record that reconciliation was attested to, not skipped.

---

## Phase 6 — Discrepancy tracking page + reporting, and keep it off the Driver dashboard

You confirmed the discrepancy-flagging message ("Trip closed with a discrepancy — off by KES 35,000. This has been flagged for Manager/Director.") is already firing correctly. Two changes:

1. **Never surface discrepancy alerts, balance mismatches, or mileage anomalies on the Driver/Sales dashboard.** These go silently to Manager and Director only (in-app notification/inbox), regardless of what triggered them — cost mismatch, sales mismatch, mileage mismatch. The driver sees a normal "Trip completed" confirmation either way.
2. **Add a dedicated Discrepancies page** (Manager/Director only) listing every flagged trip: date, driver, vehicle, amount/quantity off, category (cash, stock, mileage), status (Open/Reviewed/Resolved), with a note field for Manager/Director to record the resolution. Support daily/weekly/monthly rollups and an **Excel export** of the discrepancy log for a given period.

---

## Phase 7 — Mileage validation + standalone Mileage Logs page

**Validation on every trip's mileage:**
- Mileage End must be ≥ Mileage Start — block "End Trip" with a clear error if not.
- Reject negative values outright.
- Flag (don't necessarily block, but flag for Manager/Director review) any single-trip distance that's an outlier for that vehicle — e.g. a spike like 1,000 km in a day when the vehicle's typical trip is far lower. Use a simple threshold per vehicle (configurable, start at something like 3x that vehicle's trailing-30-day average trip distance) rather than a hardcoded number, since routes vary.

**New Mileage Logs page:** auto-populated from every trip's Mileage Start/End (no separate manual entry) — one row per trip, per vehicle, with Distance Covered auto-computed as End − Start. Filterable by vehicle and date range, and this same distance figure should appear in the Daily Trip Report so mileage doesn't have to be looked up separately.

---

## Phase 8 — Fuel Logs as a standalone entity, removed from End Trip

Remove "Cost of Fuel" and "Fuel (Liters)" from the End Trip form entirely — drivers no longer enter fuel data on the trip.

Add a new standalone **Fuel Logs** page, entered only by Manager/Director (since they're the ones paying for fuel): vehicle, date, amount paid (KES), liters purchased — computed price-per-liter is shown automatically. Keep a full history with daily/weekly/monthly/annual filters, and make it exportable as Excel.

---

## Phase 9 — Dual check-in/checkout on the shared Driver/Sales dashboard, plus a Field Work role

The Driver dashboard is shared by the Driver and the Sales person riding with them (confirmed in Round 3 Phase 4's context). Add:

- **Two separate Check In / Check Out controls** on that shared dashboard — one for the Driver, one for the Sales person — each capturing its own time-in and time-out independently (they won't always start/end at the same moment).
- **A third role, Field Work / Marketing**, for a person who joins the driver and sales team for market/field work on some trips. Same check-in/checkout mechanism, same sync behavior below.
- Every check-in/checkout from any of these three roles is **sent automatically to the Manager/Director Attendance tab** as that person's daily Time In / Time Out — no re-typing on the Manager/Director side.
- The **names that appear** in this attendance capture are driven entirely by who the Director has assigned to Driver, Sales, and Field Work roles in the HR/Employee module (Round 2 Phase 4) — once assigned there, their name is what shows up to check in/out against; don't let names be freely typed on the attendance side.

---

## Phase 10 — Route entry as its own visible section

Confirm the free-text route field from Round 3 Phase 2 is presented as its own clearly labeled section on the trip screen ("Route" — key in manually), not buried inside another field group, so drivers/sales don't miss it.

---

## Phase 11 — Sync check across dashboards

Every new entity in this round — Sales, Fuel Logs, Mileage Logs, Discrepancies, dual attendance — must be visible, correctly scoped, and consistent wherever it's already referenced elsewhere in the app:
- Sales totals here must match what Round 3's Customer/CRM module, Debtors Ledger, and analytics already show — don't create a second source of truth for the same sale.
- Fuel Logs and Mileage Logs should both be reachable from the Fleet section as well as from each vehicle's own detail page.
- The Discrepancies page total for a period should reconcile against the Daily Sales & Debt export's DIFFERENCE column (Round 3 Phase 9) — if they don't agree, that's itself a bug to fix, not just a display issue.
- Attendance entries from this new dual check-in flow must appear in the same Attendance view Manager already uses (Round 3 Phase 8), not a separate list.

---

## Phase 12 — Final verification pass

1. Start a trip, confirm Start Trip is disabled until Authorizing Officer is filled, confirm it becomes enabled once filled.
2. Log 2-3 sales against a trip with mixed payment methods (cash, M-Pesa, debt), confirm each requires at least one line item with a price, confirm the total is computed (not typed), confirm "Sales so far" updates live.
3. Attempt to sell more of a brand/size than remains available (dispatched minus already sold) — confirm it's blocked with a clear message.
4. Go to End Trip: confirm Dispatched shows the real locked numbers (not zero), confirm Returned is computed and non-editable, confirm no "must be zero" error appears.
5. Confirm the "Dispatched by Item" and "Cumulative Sold" lists only show items with quantity > 0.
6. Try to click End Trip without checking the reconciliation confirmation box — confirm it's blocked; check it, confirm End Trip proceeds.
7. Force a mismatch (e.g. log a sale then manually adjust a dispatched number if test tooling allows) and confirm the discrepancy notification goes to Manager/Director only, never to the Driver dashboard, and confirm it appears on the new Discrepancies page.
8. Enter a Mileage End lower than Mileage Start — confirm it's rejected. Enter a mileage jump far above the vehicle's typical trip — confirm it's flagged for Manager/Director review.
9. Confirm the Fuel Logs page has no driver-facing entry point, only Manager/Director, and that Cost of Fuel / Fuel Liters no longer appear on End Trip.
10. Check in and check out as a test Driver and a test Sales person from the shared dashboard, confirm both appear correctly and independently on the Manager Attendance tab with their assigned names.
11. Confirm every "Mara Water"-labeled historical record now reads "Premium" and totals in production/sales/inventory reports for that brand are not split across two labels anymore.
12. Confirm no Bottles column appears anywhere from Production onward, and confirm it's still present and correct in pre-production Inventory.

Report back pass/fail against these 12 checks before moving to the next round.
