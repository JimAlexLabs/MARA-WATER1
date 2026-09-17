# MARA Water — Round 2: Bug Fixes, HR/Payroll, Automation & Roles

Paste this into Claude Code in the MARA-WATER1 project (the one now live at your Vercel URLs). This is a follow-on to the first upgrade script — work through it phase by phase, testing after each one before moving to the next.

**Housekeeping first:** I saw two different live URLs in your message — `mara-water-dn1s45oor-jimalshafis-projects.vercel.app` and `mara-water-web.vercel.app`. The first pattern is what Vercel auto-generates for a specific deployment/preview; the second looks like your stable production alias. Confirm with me which one is the real production domain going forward — bugs on a stale preview URL may already be fixed on production, or vice versa, and it wastes time debugging the wrong one.

---

## Phase 0 — Why pages are slow (diagnose, don't guess)

It's almost certainly not "because it's PHP" — a Vercel deployment like this is virtually always a React-based framework (Next.js or similar), not PHP. Slowness on a React app on Vercel is typically one or more of:

- Serverless/edge function **cold starts** on Supabase or API routes after idle time.
- Pages fetching **too much data client-side on load** (whole tables instead of paginated/filtered queries).
- Missing **caching** (no `revalidate`/SWR/React Query caching, so every navigation re-fetches everything from scratch).
- **Waterfall requests** — one query finishing before the next starts, instead of running in parallel.
- Unoptimized bundle size (large libraries loaded on every page instead of code-split per route).
- Supabase queries missing indexes on frequently filtered columns (date, customer_id, outlet_id, etc.), which gets slower as your tables fill up.

Profile it for real — use browser dev tools' Network and Performance tabs, or Vercel's own analytics/logs — on the slowest 2-3 pages, identify the actual bottleneck, and fix that specific thing rather than a generic rewrite. Report back what you found before "optimizing" blindly.

---

## Phase 1 — Fix the root cause behind every broken form (highest priority)

You've reported several different symptoms that are very likely the same underlying pattern repeated across the app: **create/update forms failing or leaving the UI in a broken state.**

- `/settings?tab=profile`: changing First Name from "Managing" to "Sir Jamal" makes the page go blank; only a manual refresh recovers it.
- `/hr`: blank page entirely.
- QA → New Water Test: submitting gives `Failed to create water test`.
- Fleet → Add New Car: gives `validation failed`.
- Per your message: **all new-entry creation across the app has this problem.**

Don't patch each one individually as a one-off — find the shared cause first. Likely suspects, check each:

1. **Client-server schema mismatch** — the form sends a field the Supabase table doesn't have (or a type mismatch, e.g. string sent where the column is numeric/date), so the insert fails validation silently or throws an unhandled error that crashes the component instead of showing a message.
2. **Missing/incorrect error boundaries** — when a save fails, does the component crash into a blank white screen instead of catching the error and showing a friendly message? This exact pattern (blank page after a failed save) points at a missing try/catch or missing React error boundary around the form's submit handler.
3. **Stale state after mutation** — after a successful save, is the local state/cache actually being updated, or is the UI relying on a full page reload to reflect changes (which is why refreshing "fixes" it)?
4. **RLS (row-level security) policies** — confirm the Supabase RLS policies actually allow the logged-in role to insert/update the tables in question. A silent RLS rejection often surfaces exactly as generic "validation failed" errors.
5. **Required fields not matching between frontend validation and DB constraints** — e.g. the form allows submission with a field empty that the DB has as `NOT NULL`.

For each of the four reported cases specifically:

- **Settings profile save**: fix so editing and saving a name updates in place, shows a success confirmation, and never requires a manual refresh.
- **`/hr` blank page**: find the actual JS error in the console/logs (likely a failed query, an undefined property access, or a missing null-check on data that hasn't loaded yet) and fix the root cause, then wrap the page in a proper loading/error state so a future bug here shows an error message instead of a blank page.
- **QA New Water Test**: fix the actual validation/insert failure (check field names/types against the table schema), and while you're in there, add the fields described in Phase 3 below.
- **Add New Car / Fleet**: same — find why validation fails (likely a required field the form isn't sending, or a type mismatch) and fix it.
- Once the root cause is fixed, **audit every other "add new" form in the app** (customers, employees, sales, inventory items, etc.) for the same class of bug, not just the four reported.

Add a general rule going forward: every create/edit form should show a clear inline error if the save fails (never a blank page) and reflect the change immediately in the UI on success (never require a manual refresh).

---

## Phase 2 — Dark mode

Add a toggle (top bar or settings) to switch the whole app between full white/light and a dark theme. Persist the user's choice (per-user setting, not just a session default) and make sure every page/component respects it — not just the dashboard.

---

## Phase 3 — Quality Assurance: Water Test fixes + new fields

On top of fixing the creation bug (Phase 1):

- Add **Date**, **Time**, and **Recorded By** (linked to the logged-in staff member, not free text) to every water test entry — capturing when a test was taken and who took it, alongside the existing TDS, chlorine, and other parameters.
- Confirm the "Recent Water Tests" table (Test Type, Parameters, Location, Status, Recorded By, Date) reflects these fields correctly, including time.

---

## Phase 4 — HR & Payroll (built from your attached payroll workbook)

The `/hr` blank page needs fixing (Phase 1) *and* needs to be rebuilt to actually hold everything in **"Finalis Payroll Beta for April 2025.xlsm."** You'll have a new team with new names, but the structure, roles, and salary/deduction matrix should carry over exactly so future salary adjustments just change numbers, not the model.

**Staff record** (from the "Staff Database" sheet — 14 staff currently, e.g. Elizabeth A. Ojango / SP001 / Management, Emily Anyango Ogono / SP002 / Production):

| Field | Source column |
|---|---|
| Full Name | Full Name |
| Staff Number | Staff Number (e.g. SP001) |
| National ID | ID NO |
| Address | Address |
| Phone | Cell No. |
| KRA PIN | PIN No. |
| NSSF Number | NSSF No. |
| SHIF Number | SHIF NO. |
| Date of Birth | DOB |
| Date of Employment | DOE |
| Department | DEPARTMENT (e.g. Management, Production — extend as needed for Fleet, Sales, Warehouse, Finance) |
| Status | ACTIVE/INACTIVE |
| Terms of Employment | TERMS OF EMPLOYMENT (e.g. Contract) |
| Basic Salary | BASIC SALARY |
| House Allowance | HOUSE ALLOWANCE |
| Gross Salary | GROSS SALARY (basic + house allowance, calculated not typed) |
| Bank Name / Branch / Account Number / Bank Code | for payroll bank transfer |

**Monthly payroll run** (from the "Payroll" sheet — this is the real computation matrix, reproduce every column as a calculated field, not manual entry):

Basic Pay, House Allowance, Absentism (deduction for hours absent — this is what links to Phase 5's attendance tracking), Pensionable Pay, Overtime, Commission, Leave Pay, Telephone Allowance, Other Allowance → **Gross Pay** (sum). Then statutory deductions per current Kenyan rates: Company NSSF contribution, Employee NSSF contribution, Additional Voluntary Contribution, Total Pension Contribution, Taxable Pay, Tax Payable (PAYE bands), Insurance Relief, Tax Relief, **PAYE**, **NSSF**, **SHIF** (2.75% of gross, current rate), **Housing Levy/AHL** (1.5% of gross, current rate). Then non-statutory deductions: Bus fare, Insurance Deduction, Loan, Sacco Loan, Sacco Contribution, Sacco Advance, Other Deduction, Staff Advance, Penalties → **Total Deductions** → **Net Salary Payable**. Use the live statutory rates in force (PAYE bands, NSSF tiers, SHIF %, AHL %) rather than hardcoding the exact numbers from the April 2025 file, since these change periodically — confirm current KRA/NSSF/SHIF rates rather than assuming the sample file's numbers are still current.

**Advances & Loans** (separate tracking table): staff, advance amount, loan amount, monthly deduction, running loan balance, month, department — linked to staff records so outstanding balances auto-populate into the next payroll run's deductions.

**Overtime, Absenteeism & Leave**: OT hours at 1.5x and 2x rates, hourly rate, total overtime pay, absenteeism hours, absenteeism deduction, leave hours sold, leave pay — this is where Phase 5's check-in/check-out data should feed in automatically (absenteeism hours calculated from missed shifts, not hand-entered).

**Payslips**: generate a payslip per staff member per run showing the full breakdown above, downloadable/printable.

**Bank transfer file**: a report of Name, Account Number, Bank, Branch, Bank Code, Net Pay per run — ready to hand to the bank or payment provider, replacing the manual "Bank Transfer Details" sheet.

**Bonuses**: add a bonus field per payroll run per employee (the workbook doesn't show it explicitly populated, but you mentioned it — add it as its own line item, taxable per current PAYE rules, not lumped into "Other Allowance").

Build salary/role templates (e.g. a "Driver" role template, "Production" role template, "Management" role template) so onboarding a new hire means picking a role and adjusting the specific numbers, not building their pay structure from scratch.

---

## Phase 5 — Staff attendance (check-in / check-out)

- Add a check-in / check-out flow for staff (simple enough to use from a phone — this is a candidate for the future mobile app too).
- Record timestamp in and out per staff member per day.
- Auto-calculate hours worked, and auto-flag absenteeism hours when someone doesn't check in on a working day — feed this directly into the Payroll module's Absentism column (Phase 4) instead of it being hand-entered.
- Give managers a daily/weekly attendance view per department.

---

## Phase 6 — Debt sales: signatory, repayment date, and discipline

Add to any sale recorded as debt/credit (and specifically to **Log Driver Trip**, since drivers are the ones extending credit in the field):

- **Amount sold on debt**
- **Debtor** (linked to the Customer Base record — who owes it)
- **Signatory** — the name of the person at the customer/shop who received the goods on credit and is accountable for it
- **Expected repayment date** — validate this can **never be more than 7 days** from the sale date; reject the entry with a clear message if someone tries to set it further out
- Since debt sales are discouraged, not banned: show a visible warning/confirmation step before a debt sale is logged ("This is a credit sale — confirm signatory and repayment date"), so it's a deliberate action, not an easy default
- Auto-flag any debt that passes its expected repayment date as **overdue** on the Debtors Ledger and the dashboard, so follow-up actually happens instead of debts quietly aging in a spreadsheet like they do today

---

## Phase 7 — Driver Trip Log: rebuilt for real use and a future mobile app

Rebuild **Log Driver Trip** as a fast, numbers-only entry screen — the driver (or sales rep with the driver) should only ever be filling in *quantities*, never typing descriptions, since this is being designed for a mobile app where speed matters.

**Remove:** Oil (Litres) — drop this field entirely, per your instruction.

**Keep the existing fields:** Date, Driver (linked to staff), Vehicle, Route, Mileage Start/End (auto-computes KM), Fuel Drawn, Authorizing Officer, Time Out/Time In.

**Add, per product and per size** (dispatched / returned / sold, so nothing has to be typed as free text — just numbers against each pre-listed size):

- Premium: 0.5L, 1L, 1.5L, 5L, 10L, 20L — quantity dispatched, quantity returned, quantity sold
- Platinum: 0.5L, 1L — same three quantities
- Grace: 0.5L, 1L — same three quantities
- Refills: 5L, 10L, 20L — same three quantities (separately from the branded products, since refills are tracked separately in your current sheets)

**Add, per sale made on the trip:**

- Payment method split: quantity/amount sold for **cash**, for **M-Pesa**, and for **debt** (linking to Phase 6's debt fields when debt is used)
- **Customer / who bought it** — company name and contact, so every trip's sales are traceable to a real buyer, not just a total

**Make it downloadable as Excel** — one click export of the trip log with every column above (a column per product size for dispatched/returned/sold, payment method breakdown, buyer name/contact, debt details when applicable) so it can be printed, filed, or handed to someone who still wants a paper trail during the transition.

Design goal for the future mobile app: every field on this screen should be something a driver fills by tapping a number next to a pre-printed label (0.5L, 1L, etc.), never typing a sentence.

---

## Phase 8 — Supply chain automation (stock auto-deduction)

This is the core automation the whole system should run on: **when stock is sold anywhere — a driver trip, a direct outlet sale, a customer order — it should automatically deduct from the relevant inventory and production stock in real time**, not require a separate manual stock adjustment afterward.

- A driver trip sale of, say, 10 units of Premium 1L should immediately reduce Premium 1L's available stock in Inventory Management (Phase 8 of the first script), the same moment the trip is logged — no separate step.
- Returns on a trip should immediately add back to available stock.
- If a sale would take stock negative (selling more than what's actually available), flag it rather than silently allowing an impossible negative stock figure — this is a common real gap between paper records and physical stock.
- This should apply consistently across every sales entry point in the app (driver trips, outlet sales, direct sales), not just one of them — one single stock ledger that everything writes to and reads from.

---

## Phase 9 — Real-time analytics dashboard

Beyond the Phase 2 dashboard redesign from the first script, add a dedicated analytics view with live figures and charts covering:

- Sales trend (daily/weekly/monthly, by brand, by outlet/route)
- Money flow: cash vs. M-Pesa vs. debt sold, and cash/M-Pesa actually collected vs. outstanding debt
- Production vs. sales (are we producing enough to meet demand, or building up unsold stock)
- Inventory levels and low-stock alerts across all products
- Debtor balances and overdue debt totals (from Phase 6)
- Fleet activity — trips, fuel cost, KM covered
- Payroll cost trend (from Phase 4)

Pull this from live Supabase data (not cached snapshots that go stale), refreshing automatically or on a short interval so it reflects what's actually happening right now.

---

## Phase 10 — Products & Prices section

Add a reference page listing every product (Premium, Platinum, Grace, Refills — each size) with its current selling price. This becomes the single source of truth that Sales, Driver Trip Logs, and Stock Reconciliation pull unit prices from (per the first script's Phase 7/8), instead of prices being re-typed at the point of sale.

---

## Phase 11 — Roles & permissions

Build these four roles, matching how the business actually runs:

| Role | Access |
|---|---|
| **Driver** | Their own dashboard/login: Log Driver Trip (Phase 7), view their own trip history, check-in/check-out (Phase 5). No visibility into other drivers, finance, or HR. |
| **Manager / Accountant / Financier** | Full day-to-day operational access: Sales, Inventory, Fleet, Customers, Petty Cash, Debtors, QA, HR/Payroll entry and processing. Not necessarily system-level settings (user management, danger-zone reset) unless you want that included — flag if you want Manager to have that too. |
| **Investor** | Read-only view of a daily performance summary (revenue, production, sales trend, high-level financial health) — explicitly **not** line-level detail like individual salaries, individual debtor names, or petty cash line items. Build this as a distinct, deliberately limited dashboard, not the full app with buttons hidden. |
| **Director** | Full control — everything every other role can see plus user management, role assignment, system settings, and the danger-zone data reset from the first script. |

Implement this with real authentication and role-based access control (Supabase Auth + RLS policies scoped per role, not just hiding UI elements client-side — a hidden button is not real security). Each login should land the user on the dashboard appropriate to their role.

---

## Phase 12 — Re-verify the first script's Excel mapping now that it's live

Since the app is now actually deployed, do a real pass confirming every row/column from the originally attached files is genuinely captured and usable, not just present in the schema:

- Petty Cash (with Chart of Accounts)
- HSL Debtors Ledger
- Raw Materials
- Production
- Driver worksheets (now rebuilt per Phase 7)
- HSL Sales
- Stock Reconciliation

For each, open the live app and confirm you can actually enter, view, and report on the same information the paper/Excel process captured — not just that a table exists in the database for it.

---

## Phase 13 — Final verification pass

- Re-test all four originally reported bugs (settings profile save, `/hr`, water test creation, add new car) and confirm they're actually fixed, not just quieter about failing.
- Create one full end-to-end debt sale via Log Driver Trip: confirm the signatory and repayment-date validation work, confirm it posts to the Debtors Ledger, and confirm it gets flagged if left unpaid past its repayment date.
- Confirm a driver trip sale actually decrements inventory/production stock in real time (Phase 8), and confirm a return adds it back.
- Export a driver trip log to Excel and open it — confirm every quantity column is present and correct.
- Log in as each of the four roles (Driver, Manager, Investor, Director) and confirm each sees exactly what it should and nothing more.
- Toggle dark mode across several different pages, not just the dashboard.
- Report back a summary per phase, anything not completed and why, and current production vs. preview URL status so we know which one to treat as canonical going forward.
