# MARA Water — Round 5 Script: Implementing Handwritten Debug/Feature Notes (Manager, Investor, Driver, Trip Page, Director — Inventory/Production/Pricing/Sales/Fleet/Finance/HR/Users/Reports/Danger Zone)

Paste this into Claude Code after Rounds 1-4 are implemented. This round comes from your handwritten notes across 3 photos. Several items reinforce what Rounds 3-4 already specified (noted inline as "confirms") — Claude Code should treat those as double-checks, not rebuilds. The genuinely new items are the bulk of the phases below.

---

## Phase 1 — Manager dashboard: attendance list scope

Confirms and extends Round 3 Phase 8: remove **both** Director and Investor from the staff Attendance register shown on the Manager's dashboard (Round 3 only named Director — add Investor to that exclusion too). Driver/Sales/Field-Work attendance continues to arrive automatically from the check-in/checkout flow (Round 4 Phase 9) so the Manager can verify it's accurate; all other staff continue to have attendance recorded the existing way.

---

## Phase 2 — Investor dashboard: build it as a real, scoped view

This wasn't fully specified before. Build the Investor dashboard as:
- **Summary analytics only**: Sales analytics, Production analytics, Finance/Revenue — high-level trends and totals, not line-item operational detail (no individual trip logs, no individual staff records, no raw customer lists).
- **Debt and stock-depletion alerts**: a simple summary of new debt entries and low-stock/depletion warnings, so the investor stays aware of financial and operational risk without needing operational access.
- Filter out anything not relevant to an investor's role — if a metric requires operational drill-down to be useful, it doesn't belong on this dashboard.
- This matches the Round 2 Phase 11 role definition ("Investor: limited read-only daily summary") — this phase is what actually builds that view out properly.

---

## Phase 3 — Driver dashboard: photo attachment + return notification

Two additions to the shared Driver/Sales dashboard (on top of everything in Round 3 Phase 4 and Round 4 Phase 9):

1. **Optional photo attachment** on a sale or field visit entry — for sales/marketing reporting purposes (e.g., proof of delivery, a photo of the customer's shop/stock, a scanned paper receipt). Store it against the relevant Sale or Trip record; keep it optional, not required, since it will slow down high-volume field days.
2. **Auto-notify Manager/Director when Returned is computed at End Trip.** Round 4 Phase 2 already made Returned an auto-computed, non-editable figure. Add: the moment that computation runs (on End Trip submission), push a notification to Manager/Director showing the computed return figures — don't just store it silently for someone to look up later.

---

## Phase 4 — Trip page: flagged-trip visibility and Director-level correction

Confirms Round 3 Phase 2's lock/audit design and Round 4 Phase 3's mandatory Authorizing Officer, and adds: whenever a trip is flagged with a data issue (a validation failure, a discrepancy, or any other "red alert" condition), it must be clearly visible to Manager and Director on their dashboards as soon as it happens — not just discoverable by digging into the trip list. Once reviewed, Director should be able to correct or remove an erroneous trip log, and that action must go through the same audit trail (who, when, old value, new value, reason) already specified in Round 3 Phase 2's unlock mechanism.

---

## Phase 5 — Inventory: SKU-per-arrival + link to Warehouse Audit

Confirms Round 4 Phase 10 (SKU creation per stock purchase) and adds: every stock arrival/purchase should route through the same Warehouse Audit / QA batch-quality check already built in Round 2 Phase 10, so a new SKU batch is tied to its quality record from the moment it's received, not just its quantity and cost.

---

## Phase 6 — Production: daily entry sheet tied to Inventory SKU consumption

Build (or confirm, if partially done) a daily Production entry view: staff log what was produced each day, date-stamped, with quantities converted from raw bag/bulk units to production output units. Each production entry should draw down the specific Inventory SKU batch(es) it consumed (linking to Phase 5's SKU-per-arrival), so raw-material usage is traceable per batch, not just decremented from a generic total. New production batches should auto-generate a batch ID with the captured date, building a full production history over time.

---

## Phase 7 — Automatic periodic stock reconciliation export

New automation: on a regular cadence (weekly and monthly), auto-generate a downloadable Excel stock reconciliation report — the same layout as the Stock Reconciliation Template from your source files (Round 3 Phase 9's exact-format export rules apply here) — comparing expected stock (from Inventory + Production - Sales) against what should be on hand, without anyone needing to manually run the report. Make it available for download on-demand as well as auto-generated on schedule.

---

## Phase 8 — Pricing page: split Director default list vs Manager corporate/competitive list

Build a Pricing page with two distinct, separately-editable price lists:
- **Default price list** (the standing retail prices, e.g. covering a period like Oct 2025-2026) — editable only by Director.
- **Corporate/competitive price list** — a separate list for bulk/corporate/negotiated pricing — editable by Manager.
Both should be exportable as Excel. Every place in the app that references "the price" for a brand/size (trip sales, analytics, invoices) should read from these lists rather than a hardcoded value, so a price change here propagates everywhere.

---

## Phase 9 — Sales Management: receipts, near-real-time capture, PDF analytics

On top of Round 3 Phase 3's Customer/CRM module:
- Every logged sale should generate a **downloadable receipt** immediately.
- Sales should be captured close to real time (visible on dashboards within minutes of being logged), not batched/delayed.
- Sales analytics should include graphs broken down by date/month/year, with a **PDF export** option (in addition to the Excel exports already specified) for company/corporate-style reporting.

---

## Phase 10 — Fleet: fix reported bugs, safer export

Two live bugs to investigate and fix:
1. The Fleet page is reportedly broken for at least one vehicle's record — trace and fix (likely the same schema-mismatch/stale-state bug class from Round 3 Phase 1).
2. Clicking "End Trip" / arrival throws an error for at least one vehicle — reproduce and fix; check whether this is related to the Phase 2 (Round 4) Dispatched/Returned automation not handling that vehicle's data correctly.

Also make the Fleet Excel export more resilient (validate data before generating the file rather than failing silently or producing a corrupt export).

---

## Phase 11 — Finance: Manager/Director ledger accuracy

Add a reconciliation check between what the Manager's financial dashboard shows and what the Director's shows for the same period — they should always agree, since they're views over the same underlying ledger. If any Finance figure is computed differently for Manager vs Director (e.g., different date-range defaults, different rounding, different included categories), align them so both reflect the same real numbers.

---

## Phase 12 — Users/Employee records: Director-only access

Tighten staff/employee record access: viewing and editing Users/Employee records should be restricted to the Director's dashboard only. Remove any Manager-level access to raw employee records (Manager keeps Attendance per Phase 1/Round 3 Phase 8, but not the underlying employee record itself unless already explicitly granted elsewhere).

---

## Phase 13 — Reports & Analytics: metric set + revenue/tax forecast

Build out the Director's Reports & Analytics section to explicitly cover: Sales, Finance, Production, Turnover, Quality, Inventory rate, and Productivity as tracked metrics. Add a forecasted revenue calculation: expected revenue per month based on current production output and the active price list (Phase 8), plus an expected tax estimate based on that forecast.

---

## Phase 14 — Issues & Discrepancies pages

Confirms Round 3 Phase 5 (issue reporting) and Round 4 Phase 6 (discrepancy tracking) and adds specificity:
- **Issues page**: support auto-flag categories for common triggers — mileage anomalies, cash mismatches, and other automatic flags raised elsewhere in the system — so they land in one place for Manager/Director review, not just ad hoc driver-submitted tickets.
- **Discrepancies page**: explicitly support inventory-vs-production mismatch detection over a configurable window (your note used a 2-week example) — if Inventory's on-hand figure doesn't reconcile against what Production records say was made and what Sales records say was sold, flag it as a supply-chain inconsistency for review, in addition to the trip-level discrepancies already covered in Round 4 Phase 6.

---

## Phase 15 — Danger Zone: granular clear-by-section + mandatory pre-clear backup

This is a meaningful change to the "Clear All Data" feature from Round 2 Phase 3:
1. Replace (or add to) the all-or-nothing clear with a **dropdown to select which section/module** to clear (e.g., Sales only, Production only, a specific date range, etc.) so the Director isn't forced to choose between wiping everything or nothing.
2. **Enforce an automatic backup/download before the danger zone can be unlocked or used.** If a backup hasn't already been taken (check timestamp of last backup), the system must force one to run and complete successfully before allowing any clear action to proceed — no clearing without a fresh safety net first. This is a hard gate, not a warning dialog the Director can dismiss.

---

## Phase 16 — Final verification pass

1. Confirm Investor login shows only the scoped summary dashboard from Phase 2 — no operational drill-down available.
2. Confirm Manager's Attendance list excludes both Director and Investor.
3. Attach a photo to a test sale, confirm it's stored and viewable against that sale record.
4. Complete a trip, confirm Manager/Director get a notification with the computed Returned figures at End Trip.
5. Force a flagged/erroneous trip, confirm it's visible on Manager/Director dashboards immediately, and confirm Director can correct/remove it with an audit trail entry created.
6. Purchase new stock, confirm it creates a SKU tied to a Warehouse Audit quality record.
7. Log a production entry, confirm it draws down the correct Inventory SKU batch and generates an auto-dated batch ID.
8. Confirm a weekly/monthly stock reconciliation Excel auto-generates on schedule and is also available on-demand, matching the Stock Reconciliation Template layout.
9. Edit the default price list as Director and the corporate list as Manager, confirm each is restricted to the correct role and both export to Excel correctly.
10. Log a sale, confirm a receipt downloads immediately and the sale appears in analytics within minutes; export analytics as PDF.
11. Reproduce the Fleet page bug and the "Arrive Trip" error from your notes, confirm both are fixed.
12. Compare Manager's and Director's Finance dashboards for the same period, confirm the numbers match.
13. Confirm Users/Employee records are not reachable from a Manager login.
14. Confirm Reports & Analytics shows all seven listed metrics plus the revenue/tax forecast.
15. Trigger an inventory-vs-production mismatch (test data) and confirm it surfaces on the Discrepancies page.
16. Attempt to open the Danger Zone with no recent backup — confirm it forces a backup first; confirm the section-selection dropdown works and only clears the chosen section.

Report back pass/fail against these 16 checks.

---

## Appendix — What I could and couldn't read from your photos

Your handwriting in these three photos was legible enough to extract clear intent for everything above, but a handful of individual words in dense paragraphs (particularly in the Inventory and Production sections of the Director notes) were genuinely hard to make out at full zoom, even after cropping close. Where a word was unclear I went with the interpretation that made the most operational sense given the surrounding context and the rest of this project's history — if anything in Phases 5-7 or 9 doesn't match what you actually wrote, flag it and I'll adjust that phase specifically rather than re-reading the whole page.
