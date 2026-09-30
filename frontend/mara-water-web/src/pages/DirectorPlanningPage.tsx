import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import {
  Calculator, Loader2, RefreshCw, Save, ArrowLeft, AlertTriangle, TrendingUp,
} from 'lucide-react';
import { api } from '../services/api';

type Assumptions = {
  payroll: Record<string, { label: string; count: number; pay_each: number }>;
  overheads: Record<string, number>;
  transport_per_trip: number;
  lorry_materials_cost: number;
  lorry_projected_revenue: number;
  md_stipend: number;
  alt_stipend: number;
  starting_capital: { jimal: number; diana: number };
  company_retain_pct: number;
  jimal_of_distributable_pct: number;
  restocks_per_month: number;
  profit_targets: number[];
  operating_days_per_month: number;
  sept_daily_pace: number;
  sept_weekly_pace: number;
  production: Record<string, number>;
  timeline: { when: string; action: string }[];
  sku_mix_note?: string;
};

type Computed = {
  assumptions: Assumptions;
  totals: Record<string, number>;
  payroll_rows: { key: string; label: string; count: number; pay_each: number; monthly_total: number }[];
  overhead_rows: { key: string; label: string; monthly_cost: number }[];
  restock_scenarios: any[];
  chosen_restocks: number;
  chosen_pnl: any;
  profit_share: any;
  targets: any[];
  weekly_cash: any[];
  capacity: any;
  kpis: any;
  timeline: { when: string; action: string }[];
};

const money = (n: number | null | undefined) =>
  `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const pct = (n: number | null | undefined) => `${(Number(n || 0) * 100).toFixed(1)}%`;

const DirectorPlanningPage: React.FC = () => {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [tab, setTab] = useState<'dashboard' | 'costs' | 'restock' | 'capital' | 'share' | 'timeline'>('dashboard');
  const [assumptions, setAssumptions] = useState<Assumptions | null>(null);
  const [computed, setComputed] = useState<Computed | null>(null);
  const [notes, setNotes] = useState('');
  const [name, setName] = useState('Premium Restart Plan');

  const applyPayload = (data: any) => {
    setName(data.name || 'Premium Restart Plan');
    setNotes(data.notes || '');
    setComputed(data.computed);
    setAssumptions(data.computed?.assumptions || null);
  };

  const load = async () => {
    setLoading(true);
    try {
      const res = await api.get('/operations/restart-plan');
      applyPayload(res.data.data);
    } catch {
      toast.error('Could not load restart plan');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  const save = async () => {
    if (!assumptions) return;
    setSaving(true);
    try {
      const res = await api.put('/operations/restart-plan', { name, notes, assumptions });
      applyPayload(res.data.data);
      toast.success('Plan saved — forecasts updated');
    } catch {
      toast.error('Save failed');
    } finally {
      setSaving(false);
    }
  };

  const reset = async () => {
    if (!window.confirm('Reset all inputs to workbook defaults?')) return;
    setSaving(true);
    try {
      const res = await api.post('/operations/restart-plan/reset');
      applyPayload({ ...res.data.data, name: 'Premium Restart Plan', notes: res.data.data?.notes });
      toast.success('Reset to defaults');
    } catch {
      toast.error('Reset failed');
    } finally {
      setSaving(false);
    }
  };

  const setPayroll = (key: string, field: 'count' | 'pay_each', value: number) => {
    if (!assumptions) return;
    setAssumptions({
      ...assumptions,
      payroll: {
        ...assumptions.payroll,
        [key]: { ...assumptions.payroll[key], [field]: value },
      },
    });
  };

  const setOverhead = (key: string, value: number) => {
    if (!assumptions) return;
    setAssumptions({
      ...assumptions,
      overheads: { ...assumptions.overheads, [key]: value },
    });
  };

  if (loading || !assumptions || !computed) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="w-8 h-8 animate-spin text-blue-600" />
      </div>
    );
  }

  const k = computed.kpis;
  const t = computed.totals;
  const share = computed.profit_share;
  const tabs = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'costs', label: 'Team & costs' },
    { id: 'restock', label: 'Restock what-if' },
    { id: 'capital', label: 'Capital & cash' },
    { id: 'share', label: 'Profit share' },
    { id: 'timeline', label: 'Timeline' },
  ] as const;

  return (
    <div className="space-y-6">
      <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div>
          <Link to="/operations" className="inline-flex items-center text-sm text-blue-600 hover:text-blue-800 mb-2">
            <ArrowLeft className="w-4 h-4 mr-1" /> Operations overview
          </Link>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <Calculator className="w-7 h-7 text-blue-600" />
            Director planning — restart foresight
          </h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1 max-w-3xl">
            Editable Premium-only restart model (from the restart workbook). Change costs, stipend, capital or restocks —
            see what happens to profit, cash buffer, daily sales target and capacity before you commit cash.
          </p>
          <p className="text-xs text-amber-700 dark:text-amber-300 mt-2 flex items-start gap-1">
            <AlertTriangle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
            Blue fields are inputs. Forecasts recalculate when you Save. This is a planning tool — live P&amp;L still comes from trips/inventory.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={reset} disabled={saving}
            className="inline-flex items-center px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-sm">
            <RefreshCw className="w-4 h-4 mr-1.5" /> Reset defaults
          </button>
          <button type="button" onClick={save} disabled={saving}
            className="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 disabled:opacity-50">
            {saving ? <Loader2 className="w-4 h-4 mr-1.5 animate-spin" /> : <Save className="w-4 h-4 mr-1.5" />}
            Save &amp; recalculate
          </button>
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        {tabs.map((x) => (
          <button key={x.id} type="button" onClick={() => setTab(x.id)}
            className={`px-3 py-1.5 rounded-full text-sm ${tab === x.id
              ? 'bg-blue-600 text-white'
              : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300'}`}>
            {x.label}
          </button>
        ))}
      </div>

      {tab === 'dashboard' && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            {[
              { label: `Net profit @ ${computed.chosen_restocks} restocks/mo`, value: money(k.net_profit_at_chosen_restocks), hint: `MD stipend ${money(assumptions.md_stipend)}` },
              { label: 'Cash buffer after first lorry', value: money(k.cash_buffer_at_start), hint: `Capital ${money(t.starting_capital)}` },
              { label: 'Breakeven restocks (at MD stipend)', value: String(k.breakeven_restocks_at_md_stipend ?? '—'), hint: 'Full lorries / month' },
              { label: 'Weekly pace gap vs full lorry', value: k.pace_gap_weekly_full_lorry ? `${k.pace_gap_weekly_full_lorry}×` : '—', hint: 'Need vs Sept proven week' },
            ].map((card) => (
              <div key={card.label} className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <p className="text-xs uppercase tracking-wide text-gray-500">{card.label}</p>
                <p className="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">{card.value}</p>
                <p className="text-xs text-gray-500 mt-1">{card.hint}</p>
              </div>
            ))}
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
              <h2 className="font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center gap-2">
                <TrendingUp className="w-4 h-4" /> Restock frequency → net profit
              </h2>
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-700">
                      <th className="py-2 pr-3">Restocks</th>
                      <th className="py-2 pr-3">Revenue</th>
                      <th className="py-2 pr-3">Net @ {money(assumptions.md_stipend)}</th>
                      <th className="py-2">Net @ {money(assumptions.alt_stipend)}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {computed.restock_scenarios.map((r: any) => (
                      <tr key={r.restocks} className={`border-b border-gray-100 dark:border-gray-700/60 ${r.restocks === computed.chosen_restocks ? 'bg-blue-50 dark:bg-blue-900/20' : ''}`}>
                        <td className="py-2 pr-3 font-medium">{r.restocks}/mo</td>
                        <td className="py-2 pr-3">{money(r.revenue)}</td>
                        <td className={`py-2 pr-3 ${r.net_at_md_stipend < 0 ? 'text-red-600' : 'text-emerald-700 dark:text-emerald-400'}`}>{money(r.net_at_md_stipend)}</td>
                        <td className={`py-2 ${r.net_at_alt_stipend < 0 ? 'text-red-600' : ''}`}>{money(r.net_at_alt_stipend)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <label className="block mt-4 text-sm text-gray-600 dark:text-gray-300">
                Chosen restocks / month
                <input type="number" min={1} max={8} value={assumptions.restocks_per_month}
                  onChange={(e) => setAssumptions({ ...assumptions, restocks_per_month: Number(e.target.value) })}
                  className="mt-1 block w-32 rounded-lg border border-blue-300 dark:border-blue-700 bg-blue-50/50 dark:bg-gray-900 px-3 py-2 text-sm" />
              </label>
            </div>

            <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
              <h2 className="font-semibold text-gray-900 dark:text-gray-100 mb-3">Daily sales needed for profit goals</h2>
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-700">
                      <th className="py-2 pr-3">Goal</th>
                      <th className="py-2 pr-3">Stipend</th>
                      <th className="py-2 pr-3">Daily sales</th>
                      <th className="py-2 pr-3">vs Sept</th>
                      <th className="py-2">Min lorries</th>
                    </tr>
                  </thead>
                  <tbody>
                    {computed.targets.map((row: any, i: number) => (
                      <tr key={i} className="border-b border-gray-100 dark:border-gray-700/60">
                        <td className="py-2 pr-3">{money(row.profit_target)}</td>
                        <td className="py-2 pr-3">{money(row.stipend)}</td>
                        <td className="py-2 pr-3 font-medium">{money(row.required_daily_sales)}</td>
                        <td className="py-2 pr-3">{row.vs_sept_daily_pace ? `${row.vs_sept_daily_pace}×` : '—'}</td>
                        <td className="py-2">{row.min_restock_orders}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="text-xs text-gray-500 mt-3">
                Sept proven daily pace ≈ {money(assumptions.sept_daily_pace)}. Capacity: 6 ladies ≈ {computed.capacity.projected_monthly_bottles?.toLocaleString()} bottles/mo
                ({pct(computed.capacity.capacity_vs_kept_demand_pct)} of kept-mix demand).
              </p>
            </div>
          </div>
        </div>
      )}

      {tab === 'costs' && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
            <h2 className="font-semibold">A. Payroll</h2>
            {Object.entries(assumptions.payroll).map(([key, row]) => (
              <div key={key} className="grid grid-cols-3 gap-2 items-end">
                <div className="col-span-3 sm:col-span-1 text-sm text-gray-700 dark:text-gray-300">{row.label}</div>
                <label className="text-xs text-gray-500">People
                  <input type="number" min={0} value={row.count} onChange={(e) => setPayroll(key, 'count', Number(e.target.value))}
                    className="mt-1 w-full rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-2 py-1.5 text-sm" />
                </label>
                <label className="text-xs text-gray-500">Pay each
                  <input type="number" min={0} step={500} value={row.pay_each} onChange={(e) => setPayroll(key, 'pay_each', Number(e.target.value))}
                    className="mt-1 w-full rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-2 py-1.5 text-sm" />
                </label>
              </div>
            ))}
            <p className="text-sm font-medium pt-2 border-t border-gray-200 dark:border-gray-700">Payroll total: {money(t.payroll)}</p>
          </div>

          <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
            <h2 className="font-semibold">B. Overheads + stipend + transport</h2>
            {Object.entries(assumptions.overheads).map(([key, val]) => (
              <label key={key} className="flex items-center justify-between gap-3 text-sm">
                <span className="capitalize text-gray-600 dark:text-gray-300">{key.replace(/_/g, ' ')}</span>
                <input type="number" min={0} value={val} onChange={(e) => setOverhead(key, Number(e.target.value))}
                  className="w-36 rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-2 py-1.5 text-sm text-right" />
              </label>
            ))}
            <label className="flex items-center justify-between gap-3 text-sm pt-2 border-t border-gray-200 dark:border-gray-700">
              <span>MD stipend (pre profit-share)</span>
              <input type="number" min={0} value={assumptions.md_stipend}
                onChange={(e) => setAssumptions({ ...assumptions, md_stipend: Number(e.target.value) })}
                className="w-36 rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-2 py-1.5 text-sm text-right" />
            </label>
            <label className="flex items-center justify-between gap-3 text-sm">
              <span>Alt stipend (compare)</span>
              <input type="number" min={0} value={assumptions.alt_stipend}
                onChange={(e) => setAssumptions({ ...assumptions, alt_stipend: Number(e.target.value) })}
                className="w-36 rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-2 py-1.5 text-sm text-right" />
            </label>
            <label className="flex items-center justify-between gap-3 text-sm">
              <span>Transport / trip (Nairobi→Rongo)</span>
              <input type="number" min={0} value={assumptions.transport_per_trip}
                onChange={(e) => setAssumptions({ ...assumptions, transport_per_trip: Number(e.target.value) })}
                className="w-36 rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-2 py-1.5 text-sm text-right" />
            </label>
            <p className="text-sm font-medium pt-2 border-t border-gray-200 dark:border-gray-700">
              Fixed (payroll+OH): {money(t.fixed_monthly)} · Cash cost before share: {money(t.cash_cost_before_profit_share)}
            </p>
          </div>
        </div>
      )}

      {tab === 'restock' && (
        <div className="space-y-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
            <label className="text-sm">Lorry materials cost
              <input type="number" value={assumptions.lorry_materials_cost}
                onChange={(e) => setAssumptions({ ...assumptions, lorry_materials_cost: Number(e.target.value) })}
                className="mt-1 w-full rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
            </label>
            <label className="text-sm">Lorry projected revenue (full sell-through)
              <input type="number" value={assumptions.lorry_projected_revenue}
                onChange={(e) => setAssumptions({ ...assumptions, lorry_projected_revenue: Number(e.target.value) })}
                className="mt-1 w-full rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
            </label>
            <label className="text-sm">Sept weekly pace (proven)
              <input type="number" value={assumptions.sept_weekly_pace}
                onChange={(e) => setAssumptions({ ...assumptions, sept_weekly_pace: Number(e.target.value) })}
                className="mt-1 w-full rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
            </label>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 overflow-x-auto">
            <h2 className="font-semibold mb-3">If we restock N times this month (full lorry each time)</h2>
            <table className="min-w-full text-sm">
              <thead>
                <tr className="text-left text-gray-500 border-b">
                  <th className="py-2 pr-2">N</th>
                  <th className="py-2 pr-2">Revenue</th>
                  <th className="py-2 pr-2">Materials</th>
                  <th className="py-2 pr-2">After VAT</th>
                  <th className="py-2 pr-2">Transport</th>
                  <th className="py-2 pr-2">Net @ MD stipend</th>
                </tr>
              </thead>
              <tbody>
                {computed.restock_scenarios.map((r: any) => (
                  <tr key={r.restocks} className="border-b border-gray-100 dark:border-gray-700/50">
                    <td className="py-2 pr-2">{r.restocks}</td>
                    <td className="py-2 pr-2">{money(r.revenue)}</td>
                    <td className="py-2 pr-2">{money(r.materials)}</td>
                    <td className="py-2 pr-2">{money(r.gross_after_vat)}</td>
                    <td className="py-2 pr-2">{money(r.transport)}</td>
                    <td className={`py-2 pr-2 font-medium ${r.net_at_md_stipend < 0 ? 'text-red-600' : 'text-emerald-700'}`}>{money(r.net_at_md_stipend)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="text-xs text-gray-500 mt-3">
              Contribution per KES revenue (after materials, VAT, per-lorry transport): <strong>{t.contrib_per_revenue}</strong>.
              Days to process one lorry with 6 ladies: <strong>{computed.capacity.days_to_process_lorry ?? '—'}</strong> ·
              Days to sell at Sept pace: <strong>{computed.capacity.days_to_sell_lorry_at_sept_pace ?? '—'}</strong>.
            </p>
          </div>
        </div>
      )}

      {tab === 'capital' && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
            <h2 className="font-semibold">Starting capital</h2>
            <label className="text-sm block">Jimal
              <input type="number" value={assumptions.starting_capital.jimal}
                onChange={(e) => setAssumptions({
                  ...assumptions,
                  starting_capital: { ...assumptions.starting_capital, jimal: Number(e.target.value) },
                })}
                className="mt-1 w-full rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
            </label>
            <label className="text-sm block">Diana
              <input type="number" value={assumptions.starting_capital.diana}
                onChange={(e) => setAssumptions({
                  ...assumptions,
                  starting_capital: { ...assumptions.starting_capital, diana: Number(e.target.value) },
                })}
                className="mt-1 w-full rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
            </label>
            <p className="text-sm">Total capital: <strong>{money(t.starting_capital)}</strong></p>
            <p className="text-sm">First outlay (materials + transport): <strong>{money(t.first_outlay)}</strong></p>
            <p className={`text-sm font-medium ${t.cash_buffer_at_start < 0 ? 'text-red-600' : 'text-emerald-700'}`}>
              Cash buffer at start: {money(t.cash_buffer_at_start)}
            </p>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 overflow-x-auto">
            <h2 className="font-semibold mb-3">Weekly cash if we order full lorries at Sept pace</h2>
            <table className="min-w-full text-sm">
              <thead>
                <tr className="text-left text-gray-500 border-b">
                  <th className="py-2 pr-2">Week</th>
                  <th className="py-2 pr-2">Outlay</th>
                  <th className="py-2 pr-2">Sales</th>
                  <th className="py-2">Cash position</th>
                </tr>
              </thead>
              <tbody>
                {computed.weekly_cash.map((w: any) => (
                  <tr key={w.week} className="border-b border-gray-100 dark:border-gray-700/50">
                    <td className="py-2 pr-2">{w.week}</td>
                    <td className="py-2 pr-2 text-red-600">{money(w.lorry_outlay)}</td>
                    <td className="py-2 pr-2">{money(w.sales_at_sept_pace)}</td>
                    <td className={`py-2 font-medium ${w.running_cash < 0 ? 'text-red-600' : ''}`}>{money(w.running_cash)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'share' && (
        <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-4 max-w-2xl">
          <h2 className="font-semibold">Profit sharing (at chosen restock scenario)</h2>
          <label className="text-sm block">Company retain %
            <input type="number" min={0} max={1} step={0.01} value={assumptions.company_retain_pct}
              onChange={(e) => setAssumptions({ ...assumptions, company_retain_pct: Number(e.target.value) })}
              className="mt-1 w-40 rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
          </label>
          <label className="text-sm block">Jimal % of distributable (Diana = remainder)
            <input type="number" min={0} max={1} step={0.01} value={assumptions.jimal_of_distributable_pct}
              onChange={(e) => setAssumptions({ ...assumptions, jimal_of_distributable_pct: Number(e.target.value) })}
              className="mt-1 w-40 rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
          </label>
          <dl className="grid grid-cols-2 gap-3 text-sm">
            <div><dt className="text-gray-500">Net company profit</dt><dd className="font-semibold text-lg">{money(share.net_company_profit)}</dd></div>
            <div><dt className="text-gray-500">Company retained</dt><dd className="font-semibold">{money(share.company_retained)}</dd></div>
            <div><dt className="text-gray-500">Jimal profit share</dt><dd className="font-semibold">{money(share.jimal_share)}</dd></div>
            <div><dt className="text-gray-500">Diana profit share</dt><dd className="font-semibold">{money(share.diana_share)}</dd></div>
            <div className="col-span-2 pt-2 border-t border-gray-200 dark:border-gray-700">
              <dt className="text-gray-500">Jimal total (stipend + share)</dt>
              <dd className="font-bold text-xl text-emerald-700 dark:text-emerald-400">{money(share.jimal_total_with_stipend)}</dd>
            </div>
          </dl>
        </div>
      )}

      {tab === 'timeline' && (
        <div className="space-y-4">
          <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h2 className="font-semibold mb-3">Dated start plan (editable)</h2>
            <div className="space-y-3">
              {assumptions.timeline.map((row, idx) => (
                <div key={idx} className="grid grid-cols-1 md:grid-cols-3 gap-2">
                  <input value={row.when}
                    onChange={(e) => {
                      const timeline = [...assumptions.timeline];
                      timeline[idx] = { ...timeline[idx], when: e.target.value };
                      setAssumptions({ ...assumptions, timeline });
                    }}
                    className="rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
                  <input value={row.action}
                    onChange={(e) => {
                      const timeline = [...assumptions.timeline];
                      timeline[idx] = { ...timeline[idx], action: e.target.value };
                      setAssumptions({ ...assumptions, timeline });
                    }}
                    className="md:col-span-2 rounded border border-blue-300 dark:border-blue-700 bg-blue-50/40 dark:bg-gray-900 px-3 py-2 text-sm" />
                </div>
              ))}
            </div>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
            <label className="text-sm block">Plan name
              <input value={name} onChange={(e) => setName(e.target.value)}
                className="mt-1 w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-2 text-sm" />
            </label>
            <label className="text-sm block">Director notes
              <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={4}
                className="mt-1 w-full rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-2 text-sm" />
            </label>
            <p className="text-xs text-gray-500">{assumptions.sku_mix_note}</p>
          </div>
        </div>
      )}
    </div>
  );
};

export default DirectorPlanningPage;
