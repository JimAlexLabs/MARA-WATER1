# MARA Water — Round 3 Script: Trip Log Redesign, Customer CRM, Navigation & Access Fixes

Paste this entire file into Claude Code as one prompt. It assumes Round 1 (deploy) and Round 2 (bugfixes + HR/Payroll + roles) are already live at `mara-water-web.vercel.app`. This round reshapes the driver trip workflow, splits sales into a real customer/CRM module, fixes navigation and HR visibility, and adds two operational features (issue chat, exact-format Excel exports).

Work phase by phase. After each phase, run the app and visually confirm the change before moving on. Do not touch anything not named in a phase.

---

## Phase 0 — Orient before touching anything

1. Locate the current trip-log component/table (the one rendering Date, Vehicle, Route, Warehouse, Mileage Start/End, and the per-brand Dispatched/Returned/Sold/Price grid for Premium, Platinum, Grace, Refill, Mara Water).
2. Locate the "Sales Made This Trip" block inside that same form (Customer/buyer, Cash/M-Pesa/Debt, Notes) and confirm whether it currently writes to its own table or is embedded in the trip row.
3. Locate the sidebar/nav component used by Manager and Director layouts.
4. Locate the HR/Payroll nav config and the route guards for `/hr/payroll`, `/hr/loans-advances`, `/hr/salary-templates`, `/hr/attendance`.
5. Locate the QA water-test model/table and its `status` field, and the QA page that renders "Recent Water Tests."
6. Locate wherever "director@marawater.com / Admin@2024" (or any hardcoded demo credential text) is rendered on the public landing/login page.
7. Report back a short map of these files before making changes.

---

## Phase 1 — Remove demo credentials from the landing page

Delete the demo-credentials block from the public login/landing page entirely (the `director@marawater.com` / `Admin@2024` hint text, and any equivalent hints for other demo roles). Login should present only the standard email/password fields. If a "forgot password" or "request access" flow doesn't exist yet, add a simple mailto/contact-admin link instead of exposing credentials.

Apply the same rule to the Driver-side login/dashboard: no seeded demo name, demo vehicle, or demo numbers should be visible. Once real staff are entered via HR (Phase 8 of Round 2's HR module) and assigned the Driver role, their real name should appear everywhere the UI currently shows a placeholder. Until a Driver is assigned, the dashboard should show a clean empty/"no driver assigned yet" state — not fake data.

---

## Phase 2 — Rebuild the trip log as a staged, lockable workflow (bales unit)

This replaces the current single-shot "Log Trip" form. Based on your description, this is the design being implemented — a staged flow rather than a one-time form, because it mirrors how the physical warehouse process already works (stock is packed and counted before departure, sales happen on the road, returns are counted back at the warehouse) and because it prevents a driver from editing dispatch numbers after the fact, which is the control your paper sheets currently give you and the app currently doesn't.

**New `trips` state machine — four stages, one record per trip:**

| Stage | Triggered by | Fields captured | Editable after? |
|---|---|---|---|
| 1. Pre-departure | Driver/Manager opens "New Trip" | Date, Vehicle (dropdown), Route (**free-text field, not a dropdown**), Warehouse/origin (dropdown), Mileage Start, Authorizing Officer (defaults to logged-in Manager, editable dropdown of Manager-role users), Departure time (auto-captured, read-only) | Yes, until "Start Trip" is clicked |
| 2. Dispatched | Same screen, before clicking "Start Trip" | Per-brand, per-size quantity **dispatched**, in **bales** — Premium (1.5L, 10L, 1L, 20L, 255ml, 5L, Custom 0.5L), Platinum (0.5L, 1L, Custom 0.5L, Custom 1L), Grace (0.5L, 1L), Refill (10L, 20L, 5L), Mara Water (0.5L, 1.5L, 1L, 20L, 5L) | Yes, until "Start Trip" is clicked |
| 3. In transit (locked) | "Start Trip" button | Mileage Start + all Dispatched quantities are **frozen** (read-only, visually shown as locked with a lock icon). System timestamps `trip_started_at`. Stock is provisionally reserved/deducted from warehouse inventory (see Phase 6). | No — locked fields cannot be edited by anyone below Director role. A Director-only "unlock for correction" action is available, and any unlock is written to an audit log (who, when, old value, new value, reason). |
| 4. Sales during trip | Any time after Start Trip, before End Trip | Each sale is its own row, linked to this trip — see Phase 3, the new Customer/Sales module. Not entered on this form directly; this form just shows a running tally (total sold so far, by brand/size) pulled live from the linked sales records. | Sales themselves follow their own rules (Phase 3) |
| 5. Return & close | "End Trip" button, back at warehouse | Mileage End, per-brand/per-size **Returned** quantities (bales), a computed **Sold = Dispatched − Returned − discrepancy** cross-check against the tally from stage 4 (flag if they don't match), Return time (auto-captured) | Once "End Trip" is submitted, the whole trip becomes read-only to everyone except Director (same unlock/audit rule as above) |

Add a visible status badge on every trip: **Pending Departure → In Transit → Completed**, plus a **Discrepancy** flag if stage-5 reconciliation doesn't match stage-4 tally.

**Two signable, downloadable sheets**, generated automatically from this one trip record (no duplicate data entry):

1. **Dispatch Sheet** — generated the moment "Start Trip" is clicked. Shows Date, Vehicle, Route (free text), Warehouse, Mileage Start, Authorizing Officer, Departure time, and the locked dispatched quantities table. Has a signature line (Driver + Authorizing Officer name, typed or drawn signature — see Phase 9 for exact Excel formatting) and is downloadable as Excel from the Manager and Director dashboards under the trip's detail page.
2. **Return/Reconciliation Sheet** — generated the moment "End Trip" is submitted. Shows the same header plus Mileage End, Returned quantities, computed Sold, and the discrepancy flag if any. Same signature-line and download treatment.

Both sheets should also be viewable/downloadable as a pair from a single "Trip Documents" link so the Manager/Director doesn't have to hunt for two separate downloads.

**Remove entirely:** the Route dropdown and its fixed options (Route A-Westlands/B-Eastlands/C-CBD/D-Industrial). Replace with a required free-text input, but keep a "recent routes" autocomplete suggestion list (pulled from the last 20 distinct route values entered) purely as a typing convenience — it must not restrict what can be typed.

---

## Phase 3 — Split "Sales Made This Trip" into its own Customer/CRM module

Sales stop being a sub-section of the trip form. Build a standalone **Sales & Customers** module:

**Customers table** — one row per customer, reusable across every trip and every sale, ever:
- Customer name (individual or company)
- Company/shop name (optional, for distributors/retailers)
- Phone number (required — used for communication and dedupe matching)
- Location/address (optional)
- Customer type: Retail / Distributor / Institution / Walk-in
- Created date, created by (which driver/salesperson first recorded them)
- Running fields computed from their sales history (not manually entered): total lifetime purchases (KES), total debt outstanding, last purchase date, preferred payment method

**Sales table** — one row per sale, linked to a trip (nullable — some sales may happen at the warehouse counter, not on a trip) and to a customer:
- Trip ID (nullable)
- Customer (link to Customers table — typing a name/phone searches existing customers first, with "add new customer" as a fallback, to avoid duplicate customer records)
- Date/time (auto)
- Sold by (driver/salesperson, auto from logged-in user)
- Line items: brand + size + quantity (bales) + unit price + line total — same brand/size list as the dispatch table, so a sale can be itemized exactly like the stock table
- Payment method: Cash / M-Pesa / Debt / Pay-directly (QR or manual reference note — add a free-text "payment reference" field for M-Pesa code or QR transaction ID)
- If Debt: amount, repayment due date (default +7 days, warn if later), signatory — reuse the debt-sale fields already built in Round 2 Phase 6, just re-point them at this new Sales table instead of the old trip-embedded version
- Notes

**Customer detail page** — clicking any customer name anywhere in the app (trip sales tally, sales list, debtors ledger, analytics) opens their profile: full purchase history table, running debt balance, payment method breakdown, a simple chart of purchases over time. This is the "keeping an account with our customer" view — it's what supports promotions, refunds, reconciliation, and restocking decisions, so make sure refund/adjustment entries (if you build a refund flow) also show up here.

**Cross-dashboard sync:** a customer or sale created from the Driver/salesperson dashboard must appear immediately (not on next deploy, not on refresh-only) on the Sales module, Customer Base page, Debtors Ledger, and Director/Manager analytics. Use the same real-time data layer you're already using for stock (Supabase realtime subscriptions or equivalent) — don't build a second polling mechanism.

**Physical receipt/invoice/delivery books as backup:** add an optional "Physical Receipt No." and "Physical Delivery Note No." text field on each sale/trip. Drivers keep writing the paper receipt as they do today; they just also type the receipt number into the app when logging the sale. This gives you a two-way lookup (app record ↔ paper book) without asking drivers to stop using the paper books, and gives you a reconciliation report (Phase 9) that lists any paper receipt numbers that were never matched to a digital sale, so gaps are visible instead of silent.

---

## Phase 4 — Driver/salesperson's own analytics dashboard

Build a dashboard scoped to the logged-in Driver/salesperson (not the company-wide one Manager/Director see):
- Their sales total this week/month (KES and bales)
- New customers they registered, running tally
- Quantity sold by brand/size, this trip and cumulative
- Their debt sales outstanding and overdue
- Trip history (list of past trips with status badges from Phase 2)
- Keep it read-only and scoped only to their own records — no visibility into other drivers' numbers unless their role is elevated.

---

## Phase 5 — In-app issue reporting

Add a lightweight chat/ticket feature accessible from the Driver/salesperson dashboard: a simple form (subject + message + optional photo attachment) that creates a record visible to Manager and Director in a new "Issues" inbox, with a status (Open/Acknowledged/Resolved) and the ability for Manager/Director to reply. This does not need to be real-time chat — a threaded message list per issue is enough. Notify Manager/Director (in-app badge count is sufficient; email/SMS is optional and can be a later phase).

---

## Phase 6 — Wire trip stages into supply-chain automation

Extend the stock auto-deduction from Round 2 Phase 8 to match the new staged trip flow:
- On "Start Trip": deduct dispatched quantities from warehouse inventory, tag them as "in transit" (not yet sold, not available for other trips).
- On each sale during the trip: no additional inventory deduction (it already left warehouse as "in transit" at dispatch) — but do decrement the trip's "in transit" running balance per brand/size so the live tally in Phase 2 stage 4 is accurate.
- On "End Trip": returned quantities go back into warehouse inventory as available stock; whatever was dispatched minus returned minus recorded sales becomes the discrepancy flag from Phase 2.
- If discrepancy is non-zero, surface it on the Director/Manager dashboard as an alert, don't just log it silently.

---

## Phase 7 — Fix the sidebar/navigation scroll bug

The Manager/Director sidebar containing Dashboard, Analytics, QA, Production, Pricing, Inventory, Sales, Finance, Fleet, HR, Reports is currently clipped and unreachable past a certain point. Fix the container so it scrolls independently of the page (`overflow-y: auto` with a constrained height, or equivalent for your component library), and confirm on a small viewport (laptop screen, ~800px height) that every nav item is reachable by scrolling within the sidebar without the page itself scrolling away from the content area.

---

## Phase 8 — HR/Payroll visibility by role

Using the role system built in Round 2 Phase 11:
- **Manager role:** sidebar/HR section shows **only "Attendance."** Remove "Payroll," "Loans & Advances," and "Salary Templates" from the Manager's nav entirely (not just visually hidden — the routes themselves must reject Manager-role requests server-side, since a hidden nav item is not real access control).
- **Director role:** keeps full HR access — Attendance, Payroll, Loans & Advances, Salary Templates, everything from Round 2 Phase 4.
- **Attendance list:** remove "Director" as a row/entry in the staff attendance list — the Director isn't clocking in/out as staff and shouldn't appear there.
- Double check this doesn't accidentally also hide Attendance from Director — Director should see everything Manager sees plus the admin-only items.

---

## Phase 9 — Exact-format Excel exports

Every Excel download in the app that corresponds to one of the source paperwork types must reproduce that document's actual layout — headers in the same position, merged cells where the original had them, the same section groupings — not a flat generic table dump. Build these using the same approach as the source files (openpyxl-equivalent on your backend, or a templating library that can merge data into a stored template):

| App feature | Must match the layout of |
|---|---|
| Trip Dispatch Sheet / Return Sheet (Phase 2) | The physical driver worksheet structure — Date/Vehicle/Route header block, then per-brand/per-size dispatched-returned-sold-price grid |
| Daily Sales & Debt export (per location) | The "DAILY SALES AND DEBT REPORT [MONTH]" layout confirmed in the October and December sales summaries you uploaded — date rows down the left, TOTAL SALES / Discount / DEBT / DISTRIBUTOR / EXP CASH / BANKED AMOUNT / DIFFERENCE column groups, each split by location (KDN / KDQ / WAREHOUSE), with a GROSS TOTAL row at the bottom and a separate "Bales Sold" section below broken out by brand and size per location |
| Debtors Ledger export | HSL Debtors Ledger layout |
| Petty Cash export | Petty Cash Summary layout |
| Production report export | 2025 Production layout |
| Stock reconciliation export | Stock Reconciliation Template layout |
| Payroll / Payslips / Bank Transfer file | Finalis Payroll Beta's Payroll, Payslips, and Bank Transfer Details sheet layouts respectively |

Confirm KDN and KDQ are your two retail/branch location codes (as opposed to warehouse) — build a `locations` reference table (KDN, KDQ, Warehouse, and any others in use) so every module that currently only knows "Warehouse" (trip origin, inventory) can also tag records by branch where relevant, matching how the sales summaries already split everything three ways.

For every export, generate it server-side (or in a serverless function) so the same code produces the file whether it's downloaded once or run in a nightly backup job — don't duplicate the formatting logic in two places.

---

## Phase 10 — Inventory: SKU creation on stock purchase

When new stock is purchased/received (bottles, caps, labels, chemicals, any raw material or packaging item from the Inventory module), allow the person recording the purchase to create a new SKU/batch record for that specific quantity — batch number or purchase date, supplier, unit cost, quantity received — rather than only being able to add to an existing generic line item. Each SKU should still roll up into the same brand/size stock totals used elsewhere, but keep the batch-level detail retrievable for traceability (which batch of bottles was used in which production run, useful for QA recalls if ever needed).

---

## Phase 11 — QA fixes (direct bug you reported)

Fix the "Recent Water Tests" status bug: a water test that has been recorded is showing "Pending" instead of its actual result. This is almost certainly the same bug class as Round 2 Phase 1 (a status field that defaults to `pending` at creation and is never updated when the test result is saved). Trace the water-test save handler:
1. Confirm the test record's `status` column is being set (to `pass`/`fail`/`completed`, whatever your schema uses) in the same write that saves the test values — not left to a separate, unimplemented update step.
2. Confirm the "Recent Water Tests" list is reading the live `status` column and not a stale cached value or a join that's silently failing (check for `null` joins swallowing the real status).
3. Add a regression test: create a test, save a result, refresh the page, confirm status is not "Pending."

Also apply a chlorine residual upper-bound check with a labeled source, since the current "chlorine must not exceed 2.0" text has no unit or citation next to it: label it explicitly as **mg/L (parts per million) free chlorine residual**, and add a one-line reference next to the field — e.g. "KEBS/WHO guidance: free chlorine residual should be maintained in the 0.2–0.5 mg/L range at point of use; treatment-stage dosing may run higher before residual settles." Don't leave a bare number with no unit or source on a safety-relevant field.

---

## Phase 12 — Driver dashboard: add finance visibility where appropriate

Add "Invoices," "Petty Cash," "Debtors Ledger," and "Costing & P&L" links to the Driver/salesperson dashboard, but scope them by role, not just add them blindly:
- **Invoices:** show only invoices/sales the logged-in driver/salesperson generated (ties into Phase 3's Sales module).
- **Debtors Ledger:** show only debts tied to sales they made (so they can follow up on their own customers' repayment), not the company-wide ledger.
- **Petty Cash and Costing & P&L:** these are typically Manager/Director-level financial views. Unless you intend every driver to see company-wide costing (unlikely), scope these behind the Manager/Financier role check from Round 2 Phase 11, and only surface them on the Driver dashboard if the logged-in user actually holds a Manager/Accountant/Financier role in addition to Driver (e.g., a working owner-operator). Default: Driver-only role does not see Petty Cash or Costing & P&L.

---

## Phase 13 — Final verification pass

Before calling this round done, re-test end to end:

1. Landing page shows no demo credentials, for any role.
2. Log a full trip: pre-departure → dispatch entry → Start Trip (confirm dispatched fields lock) → record 2-3 sales against a new and an existing customer → End Trip with returns → confirm discrepancy math is correct, confirm both Dispatch and Return sheets download as Excel and look right.
3. Confirm a sale made from the Driver dashboard appears immediately on Director's Sales module and Debtors Ledger (if it was a debt sale) without a manual refresh workaround.
4. Confirm the sidebar scrolls and every nav item is reachable on a short viewport, for both Manager and Director.
5. Log in as Manager: confirm HR nav shows only Attendance, and confirm hitting `/hr/payroll` directly (typed URL) is rejected, not just hidden.
6. Confirm "Director" no longer appears in the attendance list.
7. Record a water test result, refresh, confirm status is not "Pending."
8. Confirm the chlorine field shows the mg/L unit and source note.
9. Download the Daily Sales & Debt export for a date range and compare its layout side by side against the uploaded October/December Sales Summary files — location columns, GROSS TOTAL row, and Bales Sold section should all be present and correctly positioned.
10. Log in as a Driver-only user: confirm Petty Cash and Costing & P&L are not visible; confirm Invoices/Debtors Ledger show only their own records; confirm their personal analytics dashboard renders.
11. Submit a test issue report from the Driver dashboard, confirm it appears in Manager/Director's Issues inbox and a reply round-trips back to the driver.
12. Purchase a new stock batch in Inventory, confirm a distinct SKU/batch record is created and it rolls up correctly into brand/size totals.

Report back a short pass/fail list against these 12 checks, not just "done."
