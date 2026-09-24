# Mara Water — Operations automation suggestions

Director Operations Overview (`/operations`) maps the full Rongo supply chain and ships Excel downloads that match the historical `EXCELLS/` workbooks. Use these ideas next when debugging dashboards and building the Android app.

## Already in place

- Returns auto-calc: **dispatched bales − sold bales** on the overview and HSL Sales Control export
- Director salary **excluded** from payroll runs and Finalis-style Excel (tracked as future allowance)
- FineLine / Blowplast bag model + KES 45,000 transport constant in `config/mara_operations.php`
- Bag → bale conversion table (`sku_package_conversions`) editable on Operations
- Excel pack: Inventory Control, Raw Materials, Production, Warehouse cards, Refills, Driver Work Sheet, Sales Control, Debtors

## Suggested next automations

1. **Write computed returns on trip close** so warehouse stock cards stay accurate without retyping `qty_returned_bales`.
2. **Low-bag reorder alerts** when FineLine/Blowplast days-of-cover drops (include transport cost in the suggestion).
3. **Production target → production ladies pay** (band max 12,500) as suggested Other Allowance / commission on draft payroll.
4. **Driver/Sales performance band** 20k→25k from completion rate, km/L, and cash variance.
5. **Nightly Excel zip** (same files as the Operations download hub) into BackupService.
6. **GRN wizard** that forces supplier_code + bag qty and applies conversion rates before production.
7. **Fuel/mileage discrepancy rules** (>20% vs fleet average) into Discrepancies.
8. **Refill vs new-bottle split** auto-feeding REFILS.xlsx.
9. **Daily WhatsApp/SMS digest** (Director full; Investor without payroll).
10. **Android offline trip kit** reusing the same `/driver` APIs (prices + dispatched bales + photo sync).

## Investor view

`GET /investor/operations-overview` — stage totals only, no salaries or staff names.
