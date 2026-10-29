/**
 * Manager dashboard UI locks — client mirror of settings.manager_ui_locks.
 * true = locked/hidden for managers; directors always see full UI.
 * *_edit_* keys: true = locked (cannot edit) until Admin unlocks (= approves).
 */

export type ManagerLockKey =
  | 'dashboard'
  | 'analytics'
  | 'qa'
  | 'production'
  | 'pricing'
  | 'inventory'
  | 'inventory_arrivals'
  | 'sales'
  | 'finance'
  | 'finance_invoices'
  | 'finance_pettycash'
  | 'finance_debtors'
  | 'finance_payables'
  | 'finance_costing'
  | 'payments'
  | 'fleet'
  | 'hr'
  | 'reports'
  | 'issues'
  | 'discrepancies'
  | 'driver_cash_payment'
  | 'manager_cash_payment'
  | 'manager_edit_sales'
  | 'manager_edit_sales_7d'
  | 'manager_edit_inventory'
  | 'manager_edit_production'
  | 'manager_fieldwork_mode';

export const DEFAULT_MANAGER_LOCKS: Record<ManagerLockKey, boolean> = {
  dashboard: false,
  analytics: false,
  qa: false,
  production: false,
  pricing: false,
  inventory: false,
  inventory_arrivals: true,
  sales: false,
  finance: false,
  finance_invoices: true,
  finance_pettycash: true,
  finance_debtors: true,
  finance_payables: true,
  finance_costing: true,
  payments: false,
  fleet: true,
  hr: false,
  reports: true,
  issues: false,
  discrepancies: false,
  driver_cash_payment: true, // cash locked for drivers by default (M-Pesa only)
  manager_cash_payment: true, // cash locked for managers by default
  manager_edit_sales: true,
  manager_edit_sales_7d: true, // unlock = Admin approved edit of sales ≤7 days
  manager_edit_inventory: true, // unlock = Admin approved inventory alters
  manager_edit_production: true, // unlock = Admin approved production edits
  manager_fieldwork_mode: false,
};

export const MANAGER_LOCK_LABELS: Record<ManagerLockKey, string> = {
  dashboard: 'Hide Dashboard nav',
  analytics: 'Hide Analytics nav',
  qa: 'Hide QA nav',
  production: 'Hide Production nav',
  pricing: 'Hide Pricing nav',
  inventory: 'Hide Inventory nav',
  inventory_arrivals: 'Lock inventory stock arrivals',
  sales: 'Hide Sales nav',
  finance: 'Hide Finance nav entirely',
  finance_invoices: 'Hide Finance → Invoices',
  finance_pettycash: 'Hide Finance → Petty cash',
  finance_debtors: 'Hide Finance → Receivables',
  finance_payables: 'Hide Finance → Payables',
  finance_costing: 'Hide Finance → Costing & P&L',
  payments: 'Hide M-Pesa Payments nav',
  fleet: 'Hide Fleet / Vehicles · trips nav',
  hr: 'Hide HR nav',
  reports: 'Hide Reports nav',
  issues: 'Hide Issues nav',
  discrepancies: 'Hide Discrepancies nav',
  driver_cash_payment: 'Lock driver cash (M-Pesa only)',
  manager_cash_payment: 'Lock manager cash (M-Pesa only)',
  manager_edit_sales: 'Lock all manager sale edits',
  manager_edit_sales_7d: 'Lock manager edit of sales (≤7 days) — unlock = Admin approved',
  manager_edit_inventory: 'Lock manager inventory alters — unlock = Admin approved',
  manager_edit_production: 'Lock manager production edits — unlock = Admin approved',
  manager_fieldwork_mode: 'Default fieldwork mode for managers',
};

export const CONTACT_ADMIN_MSG = 'This feature is locked. Contact Admin to unlock it.';

export function isLocked(
  locks: Partial<Record<ManagerLockKey, boolean>> | null | undefined,
  key: ManagerLockKey,
): boolean {
  if (locks && typeof locks[key] === 'boolean') return Boolean(locks[key]);
  return DEFAULT_MANAGER_LOCKS[key];
}

export function mergeManagerLocks(
  raw?: Partial<Record<ManagerLockKey, boolean>> | null,
): Record<ManagerLockKey, boolean> {
  return { ...DEFAULT_MANAGER_LOCKS, ...(raw || {}) };
}

/** Prefer effective locks (per-manager overrides merged), else global defaults. */
export function locksFromSettingsPayload(data: any): Record<ManagerLockKey, boolean> {
  return mergeManagerLocks(data?.manager_ui_locks_effective ?? data?.manager_ui_locks);
}

/** Prefer the sole warehouse, else name/code matching /main/i, else first. */
export function pickMainWarehouse<T extends { id: string; name?: string | null; code?: string | null }>(
  warehouses: T[],
): T | null {
  if (!warehouses.length) return null;
  if (warehouses.length === 1) return warehouses[0];
  return (
    warehouses.find((w) => /main/i.test(w.name || '') || /main/i.test(w.code || '')) ||
    warehouses[0]
  );
}

export const FIELDWORK_MODE_STORAGE_KEY = 'mara_fieldwork_mode';

export function getFieldworkModeLocal(): boolean {
  try {
    return localStorage.getItem(FIELDWORK_MODE_STORAGE_KEY) === '1';
  } catch {
    return false;
  }
}

export function setFieldworkModeLocal(on: boolean): void {
  try {
    localStorage.setItem(FIELDWORK_MODE_STORAGE_KEY, on ? '1' : '0');
  } catch {
    // ignore
  }
}

/** Sale date within last 7 Nairobi calendar days (inclusive of today). */
export function isWithinPastWeek(dateStr: string | null | undefined): boolean {
  if (!dateStr) return false;
  const day = String(dateStr).slice(0, 10);
  const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' });
  const t0 = new Date(today + 'T00:00:00');
  const d0 = new Date(day + 'T00:00:00');
  const diffDays = Math.floor((t0.getTime() - d0.getTime()) / 86400000);
  return diffDays >= 0 && diffDays <= 7;
}
