import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Download, Loader2, Plus, RefreshCw, Save, Trash2, AlertTriangle, ArrowLeft } from 'lucide-react';
import { api } from '../services/api';
import { PageShell, PageHero } from '../components/PageShell';

/* eslint-disable @typescript-eslint/no-explicit-any */

type Path = (string | number)[];
type Snapshot = { assumptions: any; defaults: any; app_prices: any[]; computed: any };

const SIZES = ['0.5L', '1L', '1.5L', '5L', '10L', '20L'];
const BOTTLED = ['0.5L', '1L', '1.5L'];
const PARTNERS = ['Jim', 'Derrick', 'Diana', 'Onroy', 'Company'];

const money = (n: number | null | undefined) =>
  `KES ${Math.round(Number(n || 0)).toLocaleString()}`;
const dec = (n: number | null | undefined, d = 2) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
const pct = (n: number | null | undefined, d = 1) => `${(Number(n || 0) * 100).toFixed(d)}%`;
const neg = (n: number) => (n < 0 ? 'text-red-600 dark:text-red-400' : '');

const getPath = (obj: any, path: Path) => path.reduce((o, k) => (o == null ? o : o[k]), obj);
const setPath = (obj: any, path: Path, value: any) => {
  const next = JSON.parse(JSON.stringify(obj));
  let cur = next;
  for (let i = 0; i < path.length - 1; i++) {
    if (cur[path[i]] == null) cur[path[i]] = typeof path[i + 1] === 'number' ? [] : {};
    cur = cur[path[i]];
  }
  cur[path[path.length - 1]] = value;
  return next;
};

/** Number input that tolerates partial typing ("0.", "") and optional percent scaling. */
const Field: React.FC<{
  label: string;
  value: number | null | undefined;
  onChange: (v: number | null) => void;
  scale?: number;
  suffix?: string;
  nullable?: boolean;
  placeholder?: string;
  hint?: string;
}> = ({ label, value, onChange, scale = 1, suffix, nullable, placeholder, hint }) => {
  const fmt = (v: number | null | undefined) =>
    v == null ? '' : String(Math.round(Number(v) * scale * 1e6) / 1e6);
  const [text, setText] = useState(fmt(value));
  const [focus, setFocus] = useState(false);
  useEffect(() => {
    if (!focus) setText(fmt(value));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, focus]);
  return (
    <label className="block">
      <span className="text-xs text-gray-500 dark:text-gray-400">{label}</span>
      <div className="mt-1 flex items-center rounded-lg border border-blue-200 dark:border-blue-900/60 bg-blue-50/60 dark:bg-blue-950/20 focus-within:ring-2 focus-within:ring-blue-400">
        <input
          inputMode="decimal"
          value={text}
          placeholder={placeholder}
          onFocus={() => setFocus(true)}
          onBlur={() => setFocus(false)}
          onChange={(e) => {
            const t = e.target.value;
            setText(t);
            if (t.trim() === '') {
              if (nullable) onChange(null);
              return;
            }
            const n = Number(t);
            if (!Number.isNaN(n)) onChange(n / scale);
          }}
          className="w-full bg-transparent px-2.5 py-1.5 text-sm tabular-nums outline-none text-blue-900 dark:text-blue-100"
        />
        {suffix && <span className="pr-2 text-xs text-gray-400">{suffix}</span>}
      </div>
      {hint && <span className="text-[11px] text-gray-400">{hint}</span>}
    </label>
  );
};

const Card: React.FC<{ title: string; note?: React.ReactNode; children: React.ReactNode; cols?: string }> = ({
  title, note, children, cols = 'grid-cols-2 md:grid-cols-4',
}) => (
  <section className="mara-panel p-5 space-y-3">
    <div>
      <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">{title}</h3>
      {note && <p className="text-xs text-gray-500 mt-0.5">{note}</p>}
    </div>
    <div className={`grid gap-3 ${cols}`}>{children}</div>
  </section>
);

const TH: React.FC<{ children?: React.ReactNode; right?: boolean; className?: string }> = ({ children, right = true, className = '' }) => (
  <th className={`px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 ${right ? 'text-right' : 'text-left'} ${className}`}>{children}</th>
);
const TD: React.FC<{ children?: React.ReactNode; right?: boolean; className?: string }> = ({ children, right = true, className = '' }) => (
  <td className={`px-3 py-1.5 text-sm tabular-nums ${right ? 'text-right' : 'text-left'} ${className}`}>{children}</td>
);
const Table: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-100 dark:divide-gray-700">{children}</table></div>
);

const TABS = [
  { id: 'assumptions', label: 'Assumptions' },
  { id: 'cost', label: 'Cost Build' },
  { id: 'margins', label: 'SKU Margins' },
  { id: 'trip', label: 'Trip P&L' },
  { id: 'split', label: 'Profit Split' },
  { id: 'funding', label: 'Funding & Startup' },
] as const;
type TabId = typeof TABS[number]['id'];

const DirectorPlanningPage: React.FC = () => {
  const [snap, setSnap] = useState<Snapshot | null>(null);
  const [draft, setDraft] = useState<any>(null);
  const [tab, setTab] = useState<TabId>('assumptions');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [marginView, setMarginView] = useState<'both' | 'vat' | 'nonvat'>('both');

  const apply = (data: Snapshot) => {
    setSnap(data);
    setDraft(JSON.parse(JSON.stringify(data.assumptions)));
  };

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/operations/model');
      apply(res.data.data);
    } catch {
      toast.error('Could not load the planning model');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const dirty = useMemo(
    () => !!snap && !!draft && JSON.stringify(snap.assumptions) !== JSON.stringify(draft),
    [snap, draft],
  );

  const set = (path: Path, v: any) => setDraft((d: any) => setPath(d, path, v));
  const F = (label: string, path: Path, opts: Partial<React.ComponentProps<typeof Field>> = {}) => (
    <Field key={path.join('.')} label={label} value={getPath(draft, path)} onChange={(v) => set(path, v)} {...opts} />
  );

  const save = async () => {
    setSaving(true);
    try {
      const res = await api.put('/operations/model', { assumptions: draft });
      apply(res.data.data);
      toast.success('Saved — every sheet recalculated');
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const reset = async () => {
    if (!window.confirm('Reset every assumption to the Excel defaults? Your saved edits will be lost.')) return;
    setSaving(true);
    try {
      const res = await api.post('/operations/model/reset');
      apply(res.data.data);
      toast.success('Reset to Excel defaults');
    } catch {
      toast.error('Reset failed');
    } finally {
      setSaving(false);
    }
  };

  const download = async () => {
    try {
      const res = await api.get('/operations/model/export', { responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'mara-hypothesis-model.xlsx';
      a.click();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Could not download Excel');
    }
  };

  if (loading && !snap) {
    return <div className="flex items-center justify-center h-64"><Loader2 className="w-8 h-8 animate-spin text-blue-600" /></div>;
  }
  if (!snap || !draft) {
    return <div className="text-center py-12 text-gray-500">Could not load planning data.</div>;
  }

  const c = snap.computed;
  const trip = c.trip_pl;
  const split = c.profit_split;
  const fund = c.funding;
  const sums = (k: string) => PARTNERS.reduce((s, p) => s + Number(getPath(draft, ['profit_split', k, p]) || 0), 0);

  /* ───────────────────────── Assumptions ───────────────────────── */
  const assumptionsTab = (
    <div className="space-y-4">
      <p className="text-xs text-gray-500">
        Blue cells are editable. Press <strong>Save &amp; recalculate</strong> and every other sheet updates.
        Selling prices are <strong>not</strong> taken from the Excel — they come from the application price list.
      </p>

      <Card title="Bottle + cap — Intripet Limited (ex-VAT)" note="Intripet charges 16% VAT on the total of each purchase. 5L / 10L / 20L use the Blowplast quote (final, VAT-inclusive) — Intripet can be quoted later.">
        {BOTTLED.map((s) => F(`Bottle ${s} ex-VAT`, ['bottle_ex_vat', s], { suffix: 'KES' }))}
        {F('Cap ex-VAT (all sizes)', ['cap_ex_vat'], { suffix: 'KES' })}
        {F('VAT rate', ['vat_rate'], { scale: 100, suffix: '%' })}
        {['5L', '10L', '20L'].map((s) => F(`Bottle+cap ${s}, final`, ['bottle_cap_final', s], { suffix: 'KES' }))}
      </Card>

      <Card title="Labels — NEOPACK (listed prices ex-VAT)" note="16% VAT is charged on the total purchase. MOQs: 0.5L = 50,000 pcs · 1L / 1.5L = 30,000 pcs · lead time 7 business days.">
        {BOTTLED.map((s) => F(`Label ${s} ex-VAT`, ['label_ex_vat', s], { suffix: 'KES' }))}
        {F('Label 5L/10L/20L, final', ['label_large_final'], { suffix: 'KES' })}
      </Card>

      <Card title="Bailing paper — KES per sheet (no VAT)" note="One sheet wraps one bale; 1 bag = sheets per bag.">
        {['0.5L', '1L', '1.5L', '5L'].map((s) => F(`Rate / sheet ${s}`, ['bailing_rate_sheet', s], { suffix: 'KES' }))}
        {['0.5L', '1L', '1.5L', '5L'].map((s) => F(`Bottles / bale ${s}`, ['bottles_per_bale', s]))}
        {F('Sheets per bag', ['sheets_per_bag'])}
      </Card>

      <Card title="Seals">
        {F('Security seal 0.5L–5L (and 10L)', ['seal', 'security'], { suffix: 'KES', hint: 'MOQ 100,000 pcs' })}
        {F('Topseal 10L/20L', ['seal', 'topseal'], { suffix: 'KES', hint: '500 pcs/bale MOQ' })}
        {F('Security seal 10L/20L (extra)', ['seal', 'security_large'], { suffix: 'KES' })}
      </Card>

      <Card title="Production wages — KES per bottle" note="Paid per bottle regardless of how many ladies are on the line.">
        {SIZES.map((s) => F(`Wage ${s}`, ['wage_per_bottle', s], { suffix: 'KES' }))}
      </Card>

      <Card title="Trip quantities & transport" note="Edit to model a future Intripet trip. Transport is split evenly across every bottle on the trip.">
        {BOTTLED.map((s) => F(`Bottles / trip ${s}`, ['trip_bottles', s]))}
        {F('Transport / trip (Nairobi → Rongo)', ['transport_per_trip'], { suffix: 'KES' })}
        {F('Refill revenue, % of bottled (reference)', ['refill_revenue_pct'], { scale: 100, suffix: '%' })}
      </Card>

      <section className="mara-panel p-5 space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Selling prices — from the application</h3>
            <p className="text-xs text-gray-500 mt-0.5">Read-only. Change them in Pricing; this model follows automatically.</p>
          </div>
          <Link to="/pricing" className="text-xs font-semibold text-blue-700 hover:underline">Open Pricing →</Link>
        </div>
        <Table>
          <thead><tr><TH right={false}>SKU</TH><TH right={false}>Sold as</TH><TH>Price / unit</TH><TH>Price / bottle</TH></tr></thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
            {snap.app_prices.map((p: any) => (
              <tr key={p.code}>
                <TD right={false} className="font-medium">{p.name}</TD>
                <TD right={false}>{p.sold_as}</TD>
                <TD>{money(p.price_per_unit)}</TD>
                <TD>{dec(p.price_per_piece)}</TD>
              </tr>
            ))}
          </tbody>
        </Table>
      </section>

      <section className="mara-panel p-5 space-y-3">
        <div>
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Payroll — monthly, KES</h3>
          <p className="text-xs text-gray-500 mt-0.5">
            Driver and sales: base + bonuses ≈ 25,000 average. Planning for the next 2 months uses the “with bonuses” figure. Jim’s stipend is not payroll — it’s paid on each month’s profit-share and trips.
          </p>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          {F('Accountant / manager', ['payroll', 'accountant_manager'], { suffix: 'KES' })}
          {F('Driver — base', ['payroll', 'driver_low'], { suffix: 'KES' })}
          {F('Driver — with bonuses', ['payroll', 'driver_high'], { suffix: 'KES' })}
          {F('Finalis LLP (HR)', ['payroll', 'finalis'], { suffix: 'KES' })}
          {F('Sales — base', ['payroll', 'sales_low'], { suffix: 'KES' })}
          {F('Sales — with bonuses', ['payroll', 'sales_high'], { suffix: 'KES' })}
          <label className="block col-span-2">
            <span className="text-xs text-gray-500">Profit-split basis (planning figure)</span>
            <select
              value={draft.payroll_basis}
              onChange={(e) => set(['payroll_basis'], e.target.value)}
              className="mt-1 w-full rounded-lg border border-blue-200 bg-blue-50/60 dark:bg-blue-950/20 dark:border-blue-900/60 px-2.5 py-1.5 text-sm"
            >
              <option value="high">With bonuses (≈ 25k driver &amp; sales) — recommended</option>
              <option value="low">Base only (18k driver &amp; sales)</option>
            </select>
          </label>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
          {[1, 2, 3, 4, 5].map((t) => F(`Jim’s stipend — ${t}${t === 5 ? '+' : ''} trip${t > 1 ? 's' : ''}/mo`, ['jim_stipend', `t${t}`], { suffix: 'KES' }))}
        </div>
      </section>

      <Card title="Monthly overheads — KES">
        {Object.keys(draft.overheads).map((k) => F(k.charAt(0).toUpperCase() + k.slice(1), ['overheads', k], { suffix: 'KES' }))}
      </Card>

      <section className="mara-panel p-5 space-y-3">
        <div>
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Profit split %</h3>
          <p className="text-xs text-gray-500 mt-0.5">Percentages from the Excel, editable. Each phase should total 100%.</p>
        </div>
        {([
          ['phase1_one', 'Phase 1 (Nov–Dec 2026) @ 1 trip / month'],
          ['phase1_multi', 'Phase 1 (Nov–Dec 2026) @ 2–5 trips / month'],
          ['phase2', 'Phase 2 (Jan 2027 onward)'],
        ] as const).map(([k, label]) => {
          const total = sums(k);
          const ok = Math.abs(total - 1) < 0.0001;
          return (
            <div key={k} className="space-y-1">
              <div className="flex items-center justify-between">
                <p className="text-xs font-medium text-gray-700 dark:text-gray-300">{label}</p>
                <span className={`text-xs font-semibold ${ok ? 'text-emerald-600' : 'text-red-600'}`}>{ok ? '✓ 100%' : `Total ${(total * 100).toFixed(1)}% — must be 100%`}</span>
              </div>
              <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                {PARTNERS.map((p) => F(p, ['profit_split', k, p], { scale: 100, suffix: '%' }))}
              </div>
            </div>
          );
        })}
      </section>

      <Card title="Capital & loan" note="Derrick’s total commitment = capital given + pending.">
        {F('Diana / Onroy capital given', ['capital', 'diana_onroy'], { suffix: 'KES' })}
        {F('Derrick capital given', ['capital', 'derrick_given'], { suffix: 'KES' })}
        {F('Derrick capital pending', ['capital', 'derrick_pending'], { suffix: 'KES' })}
        <div className="text-xs text-gray-500 flex items-end pb-2">Derrick total: <strong className="ml-1">{money(Number(draft.capital.derrick_given) + Number(draft.capital.derrick_pending))}</strong></div>
        {F('Loan principal (elder brother)', ['loan', 'principal'], { suffix: 'KES' })}
        {F('Loan interest (flat)', ['loan', 'rate'], { scale: 100, suffix: '%' })}
        <label className="block col-span-2">
          <span className="text-xs text-gray-500">Repayment due</span>
          <input
            value={draft.loan.due}
            onChange={(e) => set(['loan', 'due'], e.target.value)}
            className="mt-1 w-full rounded-lg border border-blue-200 bg-blue-50/60 dark:bg-blue-950/20 dark:border-blue-900/60 px-2.5 py-1.5 text-sm"
          />
        </label>
      </Card>

      <section className="mara-panel p-5 space-y-3">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Total spend to date</h3>
            <p className="text-xs text-gray-500 mt-0.5">Full invoice value vs due now / paid. The difference is retainage still owed.</p>
          </div>
          <button
            type="button"
            onClick={() => set(['spend_items'], [...draft.spend_items, { label: '', full: 0, paid: 0, note: '' }])}
            className="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 hover:underline"
          ><Plus className="w-3.5 h-3.5" /> Add line</button>
        </div>
        <div className="space-y-2">
          {draft.spend_items.map((row: any, i: number) => (
            <div key={i} className="grid grid-cols-12 gap-2 items-end">
              <label className="col-span-12 md:col-span-5">
                <span className="text-xs text-gray-500">Item</span>
                <input value={row.label} onChange={(e) => set(['spend_items', i, 'label'], e.target.value)}
                  className="mt-1 w-full rounded-lg border border-blue-200 bg-blue-50/60 dark:bg-blue-950/20 dark:border-blue-900/60 px-2.5 py-1.5 text-sm" />
              </label>
              <div className="col-span-5 md:col-span-2"><Field label="Full value" value={row.full} onChange={(v) => set(['spend_items', i, 'full'], v ?? 0)} /></div>
              <div className="col-span-5 md:col-span-2"><Field label="Paid / due now" value={row.paid} onChange={(v) => set(['spend_items', i, 'paid'], v ?? 0)} /></div>
              <label className="col-span-10 md:col-span-2">
                <span className="text-xs text-gray-500">Note</span>
                <input value={row.note} onChange={(e) => set(['spend_items', i, 'note'], e.target.value)}
                  className="mt-1 w-full rounded-lg border border-blue-200 bg-blue-50/60 dark:bg-blue-950/20 dark:border-blue-900/60 px-2.5 py-1.5 text-sm" />
              </label>
              <button type="button" aria-label="Remove line" onClick={() => set(['spend_items'], draft.spend_items.filter((_: any, j: number) => j !== i))}
                className="col-span-2 md:col-span-1 p-2 text-gray-400 hover:text-red-600"><Trash2 className="w-4 h-4" /></button>
            </div>
          ))}
        </div>
      </section>

      <Card title="Bailing paper on hand — override (sheets)" note="Leave empty to use live inventory stock. Enter a confirmed warehouse count to override.">
        {['0.5L', '1L', '1.5L', '5L'].map((s) => F(`On hand ${s}`, ['bailing_on_hand', s], { nullable: true, placeholder: 'live stock' }))}
      </Card>

      <Card title="Supply planning (drives Operations reorder alerts)" note="Lead times are business days (Mon–Fri). The trip cash target is bottles + caps incl. 16% VAT + transport unless you override it.">
        {F('Lead time — bottles (Intripet trip)', ['supply', 'lead_business_days', 'bottles'], { suffix: 'bd' })}
        {F('Lead time — labels', ['supply', 'lead_business_days', 'labels'], { suffix: 'bd' })}
        {F('Lead time — bailing paper', ['supply', 'lead_business_days', 'bailing'], { suffix: 'bd' })}
        {F('Lead time — seals', ['supply', 'lead_business_days', 'seals'], { suffix: 'bd' })}
        {F('MOQ labels 0.5L', ['supply', 'moq', 'label_0.5L'], { suffix: 'pcs' })}
        {F('MOQ labels 1L', ['supply', 'moq', 'label_1L'], { suffix: 'pcs' })}
        {F('MOQ labels 1.5L', ['supply', 'moq', 'label_1.5L'], { suffix: 'pcs' })}
        {F('MOQ seals', ['supply', 'moq', 'seal'], { suffix: 'pcs' })}
        {F('Safety days', ['supply', 'safety_days'], { suffix: 'days' })}
        {F('Target cover after an order', ['supply', 'target_cover_days'], { suffix: 'days' })}
        {F('Planned trips / month (forecast prior)', ['supply', 'trips_per_month'])}
        {F('Operating days / month', ['supply', 'operating_days_per_month'])}
        {F('Opening cash (at tracking start)', ['supply', 'opening_cash'], { suffix: 'KES' })}
        <label className="block">
          <span className="text-xs text-gray-500">Cash tracking starts</span>
          <input type="date" value={draft.supply.cash_tracking_start || ''} onChange={(e) => set(['supply', 'cash_tracking_start'], e.target.value || null)}
            className="mt-1 w-full rounded-lg border border-blue-200 bg-blue-50/60 dark:bg-blue-950/20 dark:border-blue-900/60 px-2.5 py-1.5 text-sm" />
          <span className="text-[11px] text-gray-400">Empty = 1st of this month</span>
        </label>
        {F('Bottle-trip cash target override', ['supply', 'trip_cash_target_override'], { nullable: true, placeholder: 'auto', suffix: 'KES' })}
      </Card>
    </div>
  );

  /* ───────────────────────── Cost Build ───────────────────────── */
  const costTab = (
    <section className="mara-panel p-5 space-y-3">
      <div>
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Cost Build — per bottle</h3>
        <p className="text-xs text-gray-500 mt-0.5">
          Transport/bottle = {money(draft.transport_per_trip)} ÷ {Number(c.cost_build.trip_bottles_total).toLocaleString()} bottles brought = KES {dec(c.cost_build.transport_per_bottle, 4)}.
          Purchase-side VAT is part of cost (cannot be reclaimed below the VAT threshold).
        </p>
      </div>
      <Table>
        <thead><tr>
          <TH right={false}>Size</TH><TH>Bottle+cap</TH><TH>Label</TH><TH>Bailing paper</TH><TH>Seal</TH>
          <TH className="bg-sky-50 dark:bg-sky-900/20">Materials cost/btl</TH><TH>Wage</TH><TH>Transport/btl</TH>
          <TH className="bg-emerald-50 dark:bg-emerald-900/20">Cost of production/btl</TH>
        </tr></thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
          {c.cost_build.rows.map((r: any) => (
            <tr key={r.size}>
              <TD right={false} className="font-semibold">{r.size}</TD>
              <TD>{dec(r.bottle_cap, 3)}</TD><TD>{dec(r.label, 3)}</TD><TD>{dec(r.bailing_paper, 3)}</TD><TD>{dec(r.seal, 3)}</TD>
              <TD className="bg-sky-50/60 dark:bg-sky-900/10 font-semibold">{dec(r.materials, 3)}</TD>
              <TD>{dec(r.wage, 3)}</TD><TD>{dec(r.transport, 3)}</TD>
              <TD className="bg-emerald-50/60 dark:bg-emerald-900/10 font-bold text-emerald-800 dark:text-emerald-200">{dec(r.cop, 3)}</TD>
            </tr>
          ))}
        </tbody>
      </Table>
      <p className="text-sm text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-900/20 rounded-lg px-3 py-2">
        <strong>Note:</strong> {c.cost_build.note}
      </p>
      <p className="text-xs text-gray-500">
        5L / 10L / 20L bottle+cap is the Blowplast quote; their label uses the historical flat rate (no current quote). Wage and transport allocation apply to the sizes actually on the trip (0.5L / 1L / 1.5L).
      </p>
    </section>
  );

  /* ───────────────────────── SKU Margins ───────────────────────── */
  const marginsTab = (
    <section className="mara-panel p-5 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">SKU prices &amp; margins — application prices</h3>
          <p className="text-xs text-gray-500 mt-0.5">Cost/bottle is materials only (as in the Excel). “After full cost” also deducts wage + transport share.</p>
        </div>
        <div className="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 p-0.5 text-xs">
          {([['both', 'Both'], ['vat', 'VAT-registered'], ['nonvat', 'Non-VAT (now)']] as const).map(([k, l]) => (
            <button key={k} type="button" onClick={() => setMarginView(k)}
              className={`px-3 py-1 rounded-md font-medium ${marginView === k ? 'bg-blue-600 text-white' : 'text-gray-600 dark:text-gray-300'}`}>{l}</button>
          ))}
        </div>
      </div>
      <Table>
        <thead><tr>
          <TH right={false}>SKU</TH><TH right={false}>Sold as</TH><TH>Cost/bottle</TH><TH>Price/bottle</TH><TH>Gross margin/btl</TH>
          {marginView !== 'nonvat' && <><TH>VAT/btl (16%, if registered)</TH><TH>Net margin/btl — VAT-registered</TH></>}
          {marginView !== 'vat' && <><TH>Net margin/btl — non-VAT (now)</TH><TH>Net % — non-VAT</TH></>}
          <TH>Margin after full cost</TH>
        </tr></thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
          {c.sku_margins.map((r: any) => (
            <tr key={r.code}>
              <TD right={false} className="font-medium">{r.sku}</TD>
              <TD right={false}>{r.sold_as}</TD>
              <TD>{dec(r.cost_per_bottle)}</TD><TD>{dec(r.price_per_bottle)}</TD>
              <TD className={`font-semibold ${neg(r.gross_margin)}`}>{dec(r.gross_margin)}</TD>
              {marginView !== 'nonvat' && <><TD>{dec(r.vat_per_bottle)}</TD><TD className={neg(r.net_margin_vat)}>{dec(r.net_margin_vat)}</TD></>}
              {marginView !== 'vat' && <><TD className={neg(r.net_margin_non_vat)}>{dec(r.net_margin_non_vat)}</TD><TD>{pct(r.net_pct_non_vat)}</TD></>}
              <TD className={neg(r.margin_after_full_cost)}>{dec(r.margin_after_full_cost)}</TD>
            </tr>
          ))}
        </tbody>
      </Table>
      <p className="text-xs text-gray-500">
        VAT-registered applies Kenya’s 16% VAT to the selling side only (net = gross margin − VAT fraction of it). Non-VAT is the current position below the registration threshold. Purchase-side VAT is already inside cost/bottle.
      </p>
    </section>
  );

  /* ───────────────────────── Trip P&L ───────────────────────── */
  const A = trip.a;
  const monthlyRows: [string, string, string?][] = [
    ['Bottled revenue', 'bottled_revenue'], ['Total revenue', 'total_revenue'],
    ['Cost of production (materials+wage+transport)', 'cost_of_production'], ['Gross trading margin', 'gross_margin', 'bold'],
    ['VAT payable, if registered', 'vat_payable'], ['Gross profit after VAT', 'gross_profit_after_vat'],
    ['Jim’s stipend (by trip tier)', 'jim_stipend'], ['Fixed payroll, LOW (driver+sales base)', 'fixed_payroll_low'],
    ['Fixed payroll, HIGH (driver+sales with bonus)', 'fixed_payroll_high'], ['Overheads', 'overheads'],
    ['NET PROFIT — VAT scenario, LOW payroll', 'net_vat_low', 'bold'], ['NET PROFIT — VAT scenario, HIGH payroll', 'net_vat_high', 'bold'],
    ['NET PROFIT — non-VAT, LOW payroll (current reality)', 'net_non_vat_low', 'key'], ['NET PROFIT — non-VAT, HIGH payroll (current reality)', 'net_non_vat_high', 'key'],
  ];
  const tripTab = (
    <div className="space-y-4">
      <section className="mara-panel p-5 space-y-3">
        <div>
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">A. Cost of production per bottle &amp; revenue — one trip</h3>
          <p className="text-xs text-gray-500 mt-0.5">Prices are the application’s Premium retail prices (per bottle = bale price ÷ bottles per bale).</p>
        </div>
        <Table>
          <thead><tr>
            <TH right={false}>SKU</TH><TH>Qty</TH><TH>Bottle+cap</TH><TH>Label</TH><TH>Bailing</TH><TH>Seal</TH><TH>Wage</TH><TH>Transport/btl</TH>
            <TH>Cost/btl</TH><TH>Price/btl</TH><TH>Revenue</TH><TH>Cost total</TH><TH>Gross margin</TH>
          </tr></thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
            {A.lines.map((l: any) => (
              <tr key={l.size}>
                <TD right={false} className="font-medium">{l.sku}</TD><TD>{Number(l.qty).toLocaleString()}</TD>
                <TD>{dec(l.bottle_cap, 3)}</TD><TD>{dec(l.label, 3)}</TD><TD>{dec(l.bailing_paper, 3)}</TD><TD>{dec(l.seal, 3)}</TD>
                <TD>{dec(l.wage, 3)}</TD><TD>{dec(l.transport, 3)}</TD>
                <TD className="font-semibold">{dec(l.cost_per_bottle, 3)}</TD><TD>{dec(l.price_per_bottle, 3)}</TD>
                <TD>{money(l.revenue)}</TD><TD>{money(l.cost_total)}</TD><TD className={`font-semibold ${neg(l.gross_margin)}`}>{money(l.gross_margin)}</TD>
              </tr>
            ))}
            <tr className="bg-gray-50 dark:bg-gray-800/60 font-semibold">
              <TD right={false}>BOTTLED, SUBTOTAL</TD><TD>{Number(A.qty_total).toLocaleString()}</TD>
              <TD>{''}</TD><TD>{''}</TD><TD>{''}</TD><TD>{''}</TD><TD>{''}</TD><TD>{''}</TD><TD>{''}</TD><TD>{''}</TD>
              <TD>{money(A.revenue)}</TD><TD>{money(A.cost_total)}</TD><TD>{money(A.gross_margin)}</TD>
            </tr>
          </tbody>
        </Table>
        <div className="grid sm:grid-cols-3 gap-3 text-sm">
          <div className="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3"><p className="text-xs text-gray-500">VAT payable, if registered (×0.16/1.16)</p><p className={`font-semibold ${neg(A.vat_payable)}`}>{money(A.vat_payable)}</p></div>
          <div className="rounded-lg bg-gray-50 dark:bg-gray-900/40 p-3"><p className="text-xs text-gray-500">Gross profit after VAT — VAT-registered</p><p className="font-semibold">{money(A.gross_profit_after_vat)}</p></div>
          <div className="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-3"><p className="text-xs text-gray-500">Gross profit — non-VAT, current reality</p><p className="font-bold text-emerald-800 dark:text-emerald-200">{money(A.gross_profit_non_vat)}</p></div>
        </div>
      </section>

      <section className="mara-panel p-5 space-y-3">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">B. Monthly P&amp;L by trip count (same SKU mix repeated)</h3>
        <Table>
          <thead><tr><TH right={false}>Line item</TH>{trip.b.map((m: any) => <TH key={m.trips}>{m.trips} trip{m.trips > 1 ? 's' : ''}</TH>)}</tr></thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
            {monthlyRows.map(([label, key, style]) => (
              <tr key={key} className={style === 'key' ? 'bg-emerald-50/60 dark:bg-emerald-900/10' : style === 'bold' ? 'bg-gray-50 dark:bg-gray-800/40' : ''}>
                <TD right={false} className={style ? 'font-semibold' : ''}>{label}</TD>
                {trip.b.map((m: any) => <TD key={m.trips} className={`${style ? 'font-semibold' : ''} ${neg(m[key])}`}>{money(m[key])}</TD>)}
              </tr>
            ))}
          </tbody>
        </Table>
        <p className="text-xs text-gray-500">
          NET PROFIT rows use non-VAT (no VAT charged on sales — current reality) as the primary basis for Profit Split and Funding; VAT rows are the Kenya-law comparison. Overheads here include electricity.
        </p>
      </section>
    </div>
  );

  /* ───────────────────────── Profit Split ───────────────────────── */
  const splitBlock = (title: string, key: string, withNet: boolean) => (
    <section className="mara-panel p-5 space-y-3" key={key}>
      <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">{title}</h3>
      <Table>
        <thead><tr><TH right={false}>Partner</TH>{[1, 2, 3, 4, 5].map((t) => <TH key={t}>{t} trip{t > 1 ? 's' : ''}</TH>)}</tr></thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
          {withNet && (
            <tr className="bg-gray-50 dark:bg-gray-800/40 font-semibold">
              <TD right={false}>Net profit / month</TD>
              {split.net_profit.map((v: number, i: number) => <TD key={i} className={neg(v)}>{money(v)}</TD>)}
            </tr>
          )}
          {split.partners.map((p: string) => (
            <tr key={p}>
              <TD right={false} className="font-medium">{p}</TD>
              {split[key][p].map((v: number, i: number) => <TD key={i} className={neg(v)}>{money(v)}</TD>)}
            </tr>
          ))}
        </tbody>
      </Table>
    </section>
  );
  const d = split.derrick;
  const splitTab = (
    <div className="space-y-4">
      <p className="text-xs text-gray-500">
        Basis: <strong>{split.basis_label}</strong>. Phase 1 at exactly 1 trip: Jim {pct(draft.profit_split.phase1_one.Jim, 0)} / Derrick {pct(draft.profit_split.phase1_one.Derrick, 0)} / Diana {pct(draft.profit_split.phase1_one.Diana, 0)} / Onroy {pct(draft.profit_split.phase1_one.Onroy, 0)} / Company {pct(draft.profit_split.phase1_one.Company, 0)};
        at 2–5 trips: Jim {pct(draft.profit_split.phase1_multi.Jim, 0)} / Derrick {pct(draft.profit_split.phase1_multi.Derrick, 0)} / Diana {pct(draft.profit_split.phase1_multi.Diana, 0)} / Onroy {pct(draft.profit_split.phase1_multi.Onroy, 0)} / Company {pct(draft.profit_split.phase1_multi.Company, 0)}.
        Edit percentages on the Assumptions tab.
      </p>
      {(['phase1_one', 'phase1_multi', 'phase2'] as const).some((k) => Math.abs(split.split_sums[k] - 1) > 0.0001) && (
        <p className="flex items-center gap-2 text-sm text-red-700 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">
          <AlertTriangle className="w-4 h-4" /> One of the splits doesn’t add up to 100% — fix it on the Assumptions tab.
        </p>
      )}
      {splitBlock('A. Phase 1 monthly distribution (Nov–Dec)', 'phase1_monthly', true)}
      {splitBlock('B. Phase 1, 2-month cumulative (Nov + Dec)', 'phase1_two_month', false)}
      <section className="mara-panel p-5 space-y-3">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">C. Derrick’s {money(d.total_committed)} capital — recovery after Nov + Dec</h3>
        <Table>
          <thead><tr><TH right={false}>Measure</TH>{[1, 2, 3, 4, 5].map((t) => <TH key={t}>{t} trip{t > 1 ? 's' : ''}</TH>)}</tr></thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
            <tr><TD right={false}>Derrick received, Nov + Dec</TD>{d.received_nov_dec.map((v: number, i: number) => <TD key={i}>{money(v)}</TD>)}</tr>
            <tr><TD right={false}>% of capital recovered</TD>{d.pct_recovered.map((v: number, i: number) => <TD key={i}>{pct(v)}</TD>)}</tr>
          </tbody>
        </Table>
      </section>
      {splitBlock('D. Phase 2 (Jan 2027 onward) monthly — same split regardless of trip count', 'phase2_monthly', true)}
      <section className="mara-panel p-5 space-y-3">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">E. Months needed from January to fully recover Derrick’s remaining balance</h3>
        <Table>
          <thead><tr><TH right={false}>Measure</TH>{[1, 2, 3, 4, 5].map((t) => <TH key={t}>{t} trip{t > 1 ? 's' : ''}</TH>)}</tr></thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
            <tr><TD right={false}>Remaining after Nov + Dec</TD>{d.remaining_after_nov_dec.map((v: number, i: number) => <TD key={i} className={neg(v)}>{money(v)}</TD>)}</tr>
            <tr><TD right={false}>Months needed at Phase 2 rate</TD>{d.months_needed_phase2.map((v: number | null, i: number) => <TD key={i}>{v == null ? 'n/a' : dec(v, 1)}</TD>)}</tr>
          </tbody>
        </Table>
      </section>
    </div>
  );

  /* ───────────────────────── Funding & Startup ───────────────────────── */
  const fundingTab = (
    <div className="space-y-4">
      <section className="mara-panel p-5 space-y-3">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">A. Total spend to date</h3>
        <Table>
          <thead><tr><TH right={false}>Item</TH><TH>Full value</TH><TH>Due now / paid</TH><TH right={false}>Note</TH></tr></thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
            {fund.spend_items.map((r: any, i: number) => (
              <tr key={i}><TD right={false}>{r.label}</TD><TD>{money(r.full)}</TD><TD>{money(r.paid)}</TD><TD right={false} className="text-gray-500">{r.note}</TD></tr>
            ))}
            <tr className="bg-gray-50 dark:bg-gray-800/60 font-semibold"><TD right={false}>TOTAL</TD><TD>{money(fund.spend_full)}</TD><TD>{money(fund.spend_paid)}</TD><TD right={false}>{`Retainage still owed ${money(fund.retainage_pending)}`}</TD></tr>
          </tbody>
        </Table>
      </section>

      <div className="grid lg:grid-cols-2 gap-4">
        <section className="mara-panel p-5 space-y-2">
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">B. Loan — elder brother</h3>
          <dl className="text-sm divide-y divide-gray-100 dark:divide-gray-700">
            {[['Principal', money(fund.loan.principal)], ['Interest rate (flat)', pct(fund.loan.rate, 0)], ['Interest amount', money(fund.loan.interest)], ['TOTAL REPAYMENT', money(fund.loan.total_repayment)], ['Repayment due', fund.loan.due]].map(([k, v]) => (
              <div key={k} className="flex justify-between py-1.5"><dt className="text-gray-500">{k}</dt><dd className="font-semibold tabular-nums">{v}</dd></div>
            ))}
          </dl>
        </section>
        <section className="mara-panel p-5 space-y-2">
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">C. Funding reconciliation</h3>
          <dl className="text-sm divide-y divide-gray-100 dark:divide-gray-700">
            {[['Partner capital received to date', money(fund.reconciliation.capital_received)], ['Partner capital pending (Derrick)', money(fund.reconciliation.capital_pending)], ['Loan from elder brother', money(fund.reconciliation.loan)], ['TOTAL FUNDING LINED UP', money(fund.reconciliation.total_funding)], ['less: TOTAL SPEND TO DATE (full)', money(-fund.reconciliation.total_spend)]].map(([k, v]) => (
              <div key={k} className="flex justify-between py-1.5"><dt className="text-gray-500">{k}</dt><dd className="font-semibold tabular-nums">{v}</dd></div>
            ))}
            <div className="flex justify-between py-1.5"><dt className="font-semibold">BUFFER REMAINING</dt><dd className={`font-bold tabular-nums ${neg(fund.reconciliation.buffer)}`}>{money(fund.reconciliation.buffer)}</dd></div>
          </dl>
        </section>
      </div>

      <section className="mara-panel p-5 space-y-3">
        <div>
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">D. Bailing paper — one trip minus what’s in the warehouse</h3>
          <p className="text-xs text-gray-500 mt-0.5">Need = bottles on the trip ÷ bottles per bale (one sheet per bale). On hand = live inventory unless overridden on Assumptions. Bags of {draft.sheets_per_bag} sheets, priced at the listed per-sheet rate.</p>
        </div>
        <Table>
          <thead><tr><TH right={false}>SKU</TH><TH>Need (sheets)</TH><TH>On hand</TH><TH>Deficit</TH><TH>Bags needed</TH><TH>Rate/sheet</TH><TH>Cost (KES)</TH></tr></thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
            {fund.bailing_paper.rows.map((r: any) => (
              <tr key={r.size}>
                <TD right={false} className="font-medium">{r.size}</TD><TD>{Number(r.need_sheets).toLocaleString()}</TD>
                <TD>{Number(r.on_hand_sheets).toLocaleString()} <span className="text-[10px] text-gray-400">{r.on_hand_source}</span></TD>
                <TD>{Number(r.deficit_sheets).toLocaleString()}</TD><TD>{r.bags_needed}</TD><TD>{dec(r.rate_per_sheet)}</TD><TD className="font-semibold">{money(r.cost)}</TD>
              </tr>
            ))}
            <tr className="bg-gray-50 dark:bg-gray-800/60 font-semibold"><TD right={false}>TOTAL</TD><TD>{''}</TD><TD>{''}</TD><TD>{''}</TD><TD>{''}</TD><TD>{''}</TD><TD>{money(fund.bailing_paper.total)}</TD></tr>
          </tbody>
        </Table>
      </section>

      <section className="mara-panel p-5 space-y-2">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">E. Bare-minimum cash needed to fully start operations</h3>
        <dl className="text-sm divide-y divide-gray-100 dark:divide-gray-700">
          {[['Bailing paper deficit (block D)', fund.startup.bailing_deficit], ['Buffer remaining after spend to date (block C)', fund.startup.buffer_remaining]].map(([k, v]: any) => (
            <div key={k} className="flex justify-between py-1.5"><dt className="text-gray-500">{k}</dt><dd className="font-semibold tabular-nums">{money(v)}</dd></div>
          ))}
          <div className="flex justify-between py-1.5"><dt className="font-semibold">SHORTFALL / (surplus) after buying bailing paper</dt><dd className={`font-bold tabular-nums ${neg(fund.startup.shortfall_or_surplus)}`}>{money(fund.startup.shortfall_or_surplus)}</dd></div>
          {[['Reference: 1 full month fixed cost, low payroll', fund.startup.month_fixed_low], ['Reference: 1 full month fixed cost, high payroll', fund.startup.month_fixed_high]].map(([k, v]: any) => (
            <div key={k} className="flex justify-between py-1.5"><dt className="text-gray-500">{k}</dt><dd className="font-semibold tabular-nums">{money(v)}</dd></div>
          ))}
        </dl>
        <p className="text-xs text-gray-500">The old production-rate / conveyor block was removed for this model.</p>
      </section>
    </div>
  );

  return (
    <PageShell>
      <PageHero
        tone="ink"
        eyebrow="Admin · Feasibility model"
        title="Planning"
        description="MARA WATER Hypothesis Model — live, using the application’s own selling prices."
        actions={
          <>
            <Link to="/operations" className="inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-semibold bg-white/15 text-white border border-white/30 hover:bg-white/25">
              <ArrowLeft className="w-4 h-4 mr-2" /> Operations
            </Link>
            <button type="button" onClick={download} className="inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-semibold bg-white/15 text-white border border-white/30 hover:bg-white/25">
              <Download className="w-4 h-4 mr-2" /> Excel
            </button>
            <button type="button" onClick={reset} disabled={saving} className="inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-semibold bg-white/15 text-white border border-white/30 hover:bg-white/25 disabled:opacity-50">
              <RefreshCw className="w-4 h-4 mr-2" /> Reset
            </button>
            <button type="button" onClick={save} disabled={saving || !dirty}
              className="inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-semibold bg-white text-slate-900 hover:bg-slate-100 disabled:opacity-50">
              {saving ? <Loader2 className="w-4 h-4 mr-2 animate-spin" /> : <Save className="w-4 h-4 mr-2" />} Save &amp; recalculate
            </button>
          </>
        }
        stats={[
          { label: 'Trip revenue', value: money(A.revenue) },
          { label: 'Trip gross margin', value: money(A.gross_profit_non_vat) },
          { label: 'Net / mo @ 2 trips', value: money(trip.b[1][split.basis]) },
          { label: 'Funding buffer', value: money(fund.reconciliation.buffer) },
        ]}
      />

      {dirty && (
        <p className="text-sm text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl px-4 py-2">
          You have unsaved assumption changes — press <strong>Save &amp; recalculate</strong> to update the sheets and Operations alerts.
        </p>
      )}

      <div className="flex flex-wrap gap-1.5">
        {TABS.map((t) => (
          <button key={t.id} type="button" onClick={() => setTab(t.id)}
            className={`px-4 py-2 rounded-full text-sm font-medium transition-colors ${tab === t.id ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'}`}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'assumptions' && assumptionsTab}
      {tab === 'cost' && costTab}
      {tab === 'margins' && marginsTab}
      {tab === 'trip' && tripTab}
      {tab === 'split' && splitTab}
      {tab === 'funding' && fundingTab}
    </PageShell>
  );
};

export default DirectorPlanningPage;
