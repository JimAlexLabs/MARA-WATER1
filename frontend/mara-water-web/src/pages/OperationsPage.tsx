import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ResponsiveContainer, AreaChart, Area, ComposedChart, Bar, Line,
  XAxis, YAxis, CartesianGrid, Tooltip, Legend,
} from 'recharts';
import {
  Users, Package, Factory, Warehouse, Truck, ShoppingCart, RotateCcw, FileSpreadsheet,
  Download, ArrowRight, MapPin, Loader2, TrendingUp, Lock, RefreshCw, Plus, AlertTriangle,
  CheckCircle2, Clock, Wallet, X, Trash2,
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { isHrUnlocked, setHrUnlock, clearHrUnlock } from '../utils/hrUnlock';
import { PageShell, PageHero } from '../components/PageShell';

/* eslint-disable @typescript-eslint/no-explicit-any */

type Stage = { key: string; label: string; href: string };
type Overview = {
  site: { company_name: string; operations_base: string; sourcing_city: string; bailing_papers_from: string };
  stages: Stage[];
  bottle_transport_cost_kes: number;
  payroll: { target_monthly_operational_kes: number; actual_monthly_operational_kes: number; headcount_operational: number };
  production: { bales_produced_month: number; trend_30d: { date: string; bales: number }[] };
  warehouse: { finished_qty_on_hand: number; unit: string };
  dispatch_sales: {
    dispatched_bales_month: number; sold_bales_month: number;
    returned_bales_logged: number; returned_bales_computed: number;
    returns_formula: string; in_house_sales_kes: number; trip_sales_kes: number;
    sales_trend_30d: { date: string; amount: number }[];
  };
};

const money = (n: number | null | undefined) =>
  `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
const num = (n: number | null | undefined, d = 0) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
const shortDate = (d?: string | null) =>
  d ? new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '—';

const STAGE_ICONS: Record<string, React.ElementType> = {
  people: Users, sourcing: MapPin, inventory: Package, production: Factory,
  warehouse: Warehouse, dispatch: Truck, sales: ShoppingCart, returns: RotateCcw, reports: FileSpreadsheet,
};

const STATUS_STYLE: Record<string, { label: string; cls: string }> = {
  critical: { label: 'Critical', cls: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200' },
  order_now: { label: 'Order now', cls: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200' },
  soon: { label: 'Order soon', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' },
  ok: { label: 'OK', cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' },
  idle: { label: 'No demand', cls: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' },
};
const ORDER_STATUS: Record<string, string> = {
  placed: 'bg-sky-100 text-sky-800', in_transit: 'bg-indigo-100 text-indigo-800',
  received: 'bg-emerald-100 text-emerald-800', cancelled: 'bg-gray-100 text-gray-500 line-through',
};
const GROUP_LABEL: Record<string, string> = {
  bottles: 'Bottles & caps — Intripet (Nairobi trip)',
  labels: 'Labels — NEOPACK',
  bailing: 'Bailing paper',
  seals: 'Seals',
};

const downloadBlob = async (path: string, filename: string, params?: Record<string, string | number>) => {
  const res = await api.get(path, { params, responseType: 'blob' });
  const url = window.URL.createObjectURL(new Blob([res.data]));
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  window.URL.revokeObjectURL(url);
};

type OrderLine = { item_code: string; label: string; qty: number; unit_cost_ex_vat: number };
type OrderForm = {
  kind: string; supplier: string; reference_no: string; order_date: string; lead_business_days: number;
  transport_cost: number; vat_applies: boolean; notes: string; lines: OrderLine[];
};
const today = () => new Date().toISOString().slice(0, 10);

const OperationsPage: React.FC = () => {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [data, setData] = useState<Overview | null>(null);
  const [adv, setAdv] = useState<any>(null);
  const [advError, setAdvError] = useState(false);
  const [loading, setLoading] = useState(true);
  const [dlKey, setDlKey] = useState<string | null>(null);
  const [order, setOrder] = useState<OrderForm | null>(null);
  const [savingOrder, setSavingOrder] = useState(false);
  const [profit, setProfit] = useState<{ profit: number; gross_revenue: number; month: string } | null>(null);
  const [profitLocked, setProfitLocked] = useState(!isHrUnlocked());
  const [showProfitUnlock, setShowProfitUnlock] = useState(false);
  const [profitPassword, setProfitPassword] = useState('');
  const [profitUnlocking, setProfitUnlocking] = useState(false);

  const monthParam = `${year}-${String(month).padStart(2, '0')}`;

  const loadProfit = () => {
    if (!isHrUnlocked()) {
      setProfitLocked(true);
      setProfit(null);
      return;
    }
    api.get('/finance/profit-summary', { params: { month: monthParam } })
      .then((res) => { setProfit(res.data.data); setProfitLocked(false); })
      .catch((err) => {
        if (err.response?.data?.code === 'hr_unlock_required') {
          clearHrUnlock();
          setProfitLocked(true);
          setProfit(null);
        }
      });
  };

  const loadAdvisor = useCallback(() => {
    setAdvError(false);
    return api.get('/operations/advisor')
      .then((res) => setAdv(res.data.data))
      .catch(() => { setAdvError(true); });
  }, []);

  const load = useCallback(() => {
    setLoading(true);
    return Promise.all([
      api.get('/operations/overview').then((res) => setData(res.data.data)).catch(() => toast.error('Could not load operations overview')),
      loadAdvisor(),
    ]).finally(() => setLoading(false));
  }, [loadAdvisor]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { loadProfit(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [year, month]);

  const unlockProfit = async (e: React.FormEvent) => {
    e.preventDefault();
    setProfitUnlocking(true);
    try {
      const res = await api.post('/hr/secure-unlock', { password: profitPassword });
      setHrUnlock({ token: res.data.data.token, expires_at: res.data.data.expires_at });
      setShowProfitUnlock(false);
      setProfitPassword('');
      setProfitLocked(false);
      loadProfit();
      toast.success('Unlocked');
    } catch (err: any) {
      toast.error(err.response?.data?.message || 'Unlock failed');
    } finally {
      setProfitUnlocking(false);
    }
  };

  const exportFile = async (key: string, path: string, name: string) => {
    setDlKey(key);
    try {
      await downloadBlob(path, name, { year, month });
      toast.success('Download started');
    } catch {
      toast.error('Export failed');
    } finally {
      setDlKey(null);
    }
  };

  /* ── supply-order helpers ─────────────────────────────────── */
  const openTripOrder = (plan: 'standard' | 'suggested') => {
    if (!adv) return;
    const lines: OrderLine[] = [];
    let capQty = 0;
    let capEx = 0.8;
    (adv.trip[plan].lines as any[]).forEach((l) => {
      if (Number(l.qty) > 0) {
        lines.push({ item_code: `BOTTLE-${l.size}`, label: `Bottle ${l.size}`, qty: Number(l.qty), unit_cost_ex_vat: Number(l.bottle_ex_vat) });
        capQty += Number(l.qty);
        capEx = Number(l.cap_ex_vat);
      }
    });
    if (capQty > 0) lines.push({ item_code: 'CAP-28MM-DARK', label: 'Caps 28mm', qty: capQty, unit_cost_ex_vat: capEx });
    setOrder({
      kind: 'bottle_trip',
      supplier: adv.settings.suppliers?.bottles || 'Intripet Limited',
      reference_no: '',
      order_date: today(),
      lead_business_days: adv.settings.lead_business_days.bottles,
      transport_cost: adv.trip.transport,
      vat_applies: true,
      notes: '',
      lines: lines.length ? lines : [{ item_code: 'BOTTLE-0.5L', label: 'Bottle 0.5L', qty: 0, unit_cost_ex_vat: 5.2 }],
    });
  };

  const openItemOrder = (it: any) => {
    if (it.trip_item) {
      openTripOrder(Number(adv.trip.suggested.total_qty) > 0 ? 'suggested' : 'standard');
      return;
    }
    const vat = !!it.vat_applies;
    const ex = vat ? Number(it.unit_cost_incl_vat) / (1 + Number(adv.settings.vat_rate)) : Number(it.unit_cost_incl_vat);
    setOrder({
      kind: it.group === 'labels' ? 'labels' : it.group === 'bailing' ? 'bailing' : it.group === 'seals' ? 'seals' : 'other',
      supplier: it.supplier || (it.group === 'bailing' ? 'Bailing paper supplier (Kisumu)' : it.group === 'seals' ? 'Seals supplier' : ''),
      reference_no: '',
      order_date: today(),
      lead_business_days: it.lead_business_days,
      transport_cost: 0,
      vat_applies: vat,
      notes: '',
      lines: [{ item_code: it.code, label: it.label, qty: Number(it.suggest_qty) || Number(it.moq) || 0, unit_cost_ex_vat: Math.round(ex * 10000) / 10000 }],
    });
  };

  const orderTotals = useMemo(() => {
    if (!order || !adv) return { goods: 0, vat: 0, total: 0 };
    const goods = order.lines.reduce((s, l) => s + Number(l.qty) * Number(l.unit_cost_ex_vat), 0);
    const vat = order.vat_applies ? goods * Number(adv.settings.vat_rate) : 0;
    return { goods, vat, total: goods + vat + Number(order.transport_cost || 0) };
  }, [order, adv]);

  const submitOrder = async () => {
    if (!order) return;
    const lines = order.lines.filter((l) => Number(l.qty) > 0);
    if (!order.supplier.trim() || lines.length === 0) {
      toast.error('Add a supplier and at least one line with a quantity');
      return;
    }
    setSavingOrder(true);
    try {
      await api.post('/operations/supply-orders', { ...order, lines, reference_no: order.reference_no || null, notes: order.notes || null });
      toast.success('Supply order recorded — alerts updated');
      setOrder(null);
      await loadAdvisor();
    } catch (e: any) {
      toast.error(e?.response?.data?.message || 'Could not record order');
    } finally {
      setSavingOrder(false);
    }
  };

  const setOrderStatus = async (id: string, status: string) => {
    try {
      await api.patch(`/operations/supply-orders/${id}`, { status });
      toast.success(status === 'received' ? 'Marked received — remember to post the GRN in Inventory' : 'Order updated');
      await loadAdvisor();
    } catch {
      toast.error('Could not update order');
    }
  };

  const removeOrder = async (id: string) => {
    if (!window.confirm('Remove this supply order?')) return;
    try {
      await api.delete(`/operations/supply-orders/${id}`);
      await loadAdvisor();
    } catch {
      toast.error('Could not remove order');
    }
  };

  if (loading && !data) {
    return <div className="flex items-center justify-center h-64"><Loader2 className="w-8 h-8 animate-spin text-blue-600" /></div>;
  }
  if (!data) {
    return <div className="text-center py-12 text-gray-500">No operations data available.</div>;
  }

  const prodTrend = data.production.trend_30d.map((p) => ({
    ...p, label: new Date(p.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
  }));
  const salesTrend = data.dispatch_sales.sales_trend_30d.map((p) => ({
    ...p, label: new Date(p.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }),
  }));
  const histChart = (adv?.history || []).map((h: any) => ({ ...h, label: shortDate(h.date) }));

  const exports = [
    { key: 'inv', label: 'Inventory Control', path: '/operations/exports/inventory-control', file: `inventory-control-${year}-${month}.xlsx` },
    { key: 'raw', label: 'Raw Materials (bags)', path: '/operations/exports/raw-materials', file: `raw-materials-${year}-${month}.xlsx` },
    { key: 'prod', label: 'Production Data', path: '/reports/production-export', file: `production-${year}-${month}.xlsx` },
    { key: 'wh', label: 'Main Stock Warehouse', path: '/operations/exports/warehouse-stock', file: `warehouse-${year}-${month}.xlsx` },
    { key: 'ref', label: 'Refills', path: '/operations/exports/refills', file: `refills-${year}-${month}.xlsx` },
    { key: 'drv', label: 'Driver Work Sheet', path: '/operations/exports/driver-worksheet', file: `driver-worksheet-${year}-${month}.xlsx` },
    { key: 'sales', label: 'HSL Sales Control', path: '/operations/exports/sales-control', file: `sales-control-${year}-${month}.xlsx` },
    { key: 'debt', label: 'Debtors Ledger', path: '/finance/debtors/export', file: `debtors-${year}.xlsx` },
  ];

  const cash = adv?.cash;
  const urgent = adv ? (adv.headline.critical || 0) + (adv.headline.order_now || 0) : 0;
  const groups = ['bottles', 'labels', 'bailing', 'seals'];
  const fundedPct = cash ? Math.max(0, Math.min(1, cash.funded_pct)) : 0;
  const triggerTone = cash?.trigger === 'order_now'
    ? 'border-emerald-300 bg-emerald-50 dark:bg-emerald-900/20 dark:border-emerald-800'
    : cash?.trigger === 'almost'
      ? 'border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800'
      : 'border-sky-200 bg-sky-50 dark:bg-sky-900/20 dark:border-sky-800';

  return (
    <PageShell>
      <PageHero
        tone="forest"
        eyebrow="Supply chain"
        title="Operations"
        description={`${data.site.company_name} · Base ${data.site.operations_base} · Bottles from Intripet Limited · Papers from ${data.site.bailing_papers_from}`}
        actions={
          <>
            <Link to="/operations/planning" className="inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-semibold bg-white text-emerald-900 hover:bg-emerald-50">
              Planning model
            </Link>
            <button type="button" onClick={() => load()} className="inline-flex items-center px-4 py-2.5 rounded-xl text-sm font-semibold bg-white/15 text-white border border-white/30 hover:bg-white/25">
              <RefreshCw className="w-4 h-4 mr-2" /> Refresh
            </button>
            <select value={month} onChange={(e) => setMonth(Number(e.target.value))}
              className="rounded-xl border border-white/25 bg-white/15 text-white px-3 py-2.5 text-sm backdrop-blur-sm">
              {Array.from({ length: 12 }, (_, i) => (
                <option key={i + 1} value={i + 1} className="text-slate-900">{new Date(2000, i, 1).toLocaleString(undefined, { month: 'long' })}</option>
              ))}
            </select>
            <input type="number" value={year} onChange={(e) => setYear(Number(e.target.value))}
              className="w-24 rounded-xl border border-white/25 bg-white/15 text-white px-3 py-2.5 text-sm backdrop-blur-sm" />
          </>
        }
      />

      {/* End-to-end flow */}
      <section id="flow" className="mara-panel p-5">
        <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">End-to-end flow</h2>
        <div className="flex flex-wrap items-center gap-2">
          {data.stages.map((s, i) => {
            const Icon = STAGE_ICONS[s.key] || Package;
            const cls = 'inline-flex items-center gap-2 rounded-full bg-sky-50 dark:bg-sky-900/30 text-sky-900 dark:text-sky-100 px-3 py-1.5 text-sm font-medium hover:bg-sky-100 dark:hover:bg-sky-900/50';
            return (
              <React.Fragment key={s.key}>
                {s.href.startsWith('/')
                  ? <Link to={s.href} className={cls}><Icon className="w-4 h-4" />{s.label}</Link>
                  : <a href={s.href} className={cls}><Icon className="w-4 h-4" />{s.label}</a>}
                {i < data.stages.length - 1 && <ArrowRight className="w-4 h-4 text-gray-400 shrink-0" />}
              </React.Fragment>
            );
          })}
        </div>
        <p className="mt-3 text-sm text-gray-600 dark:text-gray-400">
          Empty bottles arrive from Intripet (Nairobi) + {money(data.bottle_transport_cost_kes)} haulage to Rongo, are labelled, sealed and baled, then flow to warehouse, dispatch and sales. Returns = dispatched − sold.
        </p>
      </section>

      {/* KPI strip */}
      <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div className="mara-stat">
          <p className="text-xs text-gray-500">Stock alerts</p>
          <p className={`text-xl font-bold mt-1 ${urgent > 0 ? 'text-red-600' : 'text-emerald-700'}`}>{adv ? urgent : '—'}</p>
          <p className="text-xs text-gray-500 mt-1">{adv ? `${adv.headline.soon || 0} more soon · ${adv.headline.ok || 0} healthy` : 'advisor unavailable'}</p>
        </div>
        <div className="mara-stat">
          <p className="text-xs text-gray-500">Cash toward next bottle trip</p>
          <p className="text-xl font-bold mt-1">{cash ? `${Math.round(fundedPct * 100)}%` : '—'}</p>
          <p className="text-xs text-gray-500 mt-1">{cash ? `${money(cash.cash_available)} of ${money(cash.trip_target)}` : ''}</p>
        </div>
        <div className="mara-stat">
          <p className="text-xs text-gray-500">Open supply orders</p>
          <p className="text-xl font-bold mt-1">{adv ? adv.orders_open : '—'}</p>
          <p className="text-xs text-gray-500 mt-1">placed / in transit</p>
        </div>
        <div className="mara-stat">
          <p className="text-xs text-gray-500">Bales produced (month)</p>
          <p className="text-xl font-bold mt-1">{num(data.production.bales_produced_month)}</p>
          <p className="text-xs text-gray-500 mt-1">Warehouse {num(data.warehouse.finished_qty_on_hand)} · Returns {num(data.dispatch_sales.returned_bales_computed)}</p>
        </div>
        <div className="bg-emerald-50 dark:bg-emerald-900/20 rounded-xl border border-emerald-200 dark:border-emerald-800 p-4 flex flex-col justify-between">
          <div className="flex items-center gap-2">
            <TrendingUp className="w-4 h-4 text-emerald-700 dark:text-emerald-300" />
            <p className="text-xs text-emerald-800 dark:text-emerald-200 font-medium">Live profit ({monthParam})</p>
          </div>
          {profitLocked ? (
            <button type="button" onClick={() => setShowProfitUnlock(true)}
              className="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-emerald-800 dark:text-emerald-100 hover:underline">
              <Lock className="w-3.5 h-3.5" /> Unlock to view
            </button>
          ) : (
            <>
              <p className="text-xl font-bold text-emerald-900 dark:text-emerald-100 mt-1">{profit ? money(profit.profit) : '—'}</p>
              <Link to="/hr?tab=profit" className="text-xs text-emerald-700 dark:text-emerald-300 mt-1 hover:underline">Breakdown &amp; Finance →</Link>
            </>
          )}
        </div>
      </div>

      {showProfitUnlock && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <form onSubmit={unlockProfit} className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-sm space-y-3">
            <h3 className="font-medium text-gray-900 dark:text-gray-100">Confirm your password</h3>
            <p className="text-xs text-gray-500">Same password you use to log in.</p>
            <input type="password" autoFocus value={profitPassword} onChange={(e) => setProfitPassword(e.target.value)}
              className="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-3 py-2 text-sm" />
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setShowProfitUnlock(false)} className="px-3 py-1.5 text-sm border rounded-md">Cancel</button>
              <button type="submit" disabled={profitUnlocking} className="px-3 py-1.5 text-sm bg-emerald-600 text-white rounded-md disabled:opacity-50">{profitUnlocking ? '…' : 'Unlock'}</button>
            </div>
          </form>
        </div>
      )}

      {advError && !adv && (
        <div className="mara-panel p-5 text-sm text-red-700 flex items-center justify-between">
          <span>The reorder advisor could not be loaded.</span>
          <button type="button" onClick={() => loadAdvisor()} className="font-semibold underline">Retry</button>
        </div>
      )}

      {adv && (
        <>
          {/* Bottle-trip cash trigger */}
          <section id="reorder" className={`rounded-2xl border p-5 space-y-4 ${triggerTone}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="flex items-start gap-3">
                <Wallet className="w-6 h-6 mt-0.5 text-gray-700 dark:text-gray-200" />
                <div>
                  <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">Next bottle trip — cash trigger</h2>
                  <p className="text-sm text-gray-700 dark:text-gray-300 mt-0.5 max-w-3xl">{cash.headline}</p>
                </div>
              </div>
              <div className="flex flex-wrap gap-2">
                <button type="button" onClick={() => openTripOrder('standard')}
                  className={`inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold ${cash.trigger === 'order_now' ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-50'}`}>
                  <Plus className="w-4 h-4" /> Record standard trip order
                </button>
                {Number(adv.trip.suggested.total_qty) > 0 && (
                  <button type="button" onClick={() => openTripOrder('suggested')}
                    className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 hover:bg-gray-50">
                    <Plus className="w-4 h-4" /> Forecast-sized trip
                  </button>
                )}
              </div>
            </div>

            <div>
              <div className="h-3 rounded-full bg-white/70 dark:bg-gray-900/40 overflow-hidden border border-black/5">
                <div className={`h-full rounded-full ${cash.trigger === 'order_now' ? 'bg-emerald-500' : cash.trigger === 'almost' ? 'bg-amber-500' : 'bg-sky-500'}`} style={{ width: `${fundedPct * 100}%` }} />
              </div>
              <div className="flex justify-between text-xs text-gray-600 dark:text-gray-400 mt-1">
                <span>{money(cash.cash_available)}</span>
                <span>Target {money(cash.trip_target)} · {adv.trip.cash_target_source}</span>
              </div>
            </div>

            {cash.warning && (
              <p className="flex items-start gap-2 text-sm text-amber-900 dark:text-amber-200 bg-white/70 dark:bg-gray-900/40 rounded-lg px-3 py-2">
                <AlertTriangle className="w-4 h-4 mt-0.5 shrink-0" /> {cash.warning}
              </p>
            )}

            <div className="grid lg:grid-cols-2 gap-4 text-sm">
              <div className="bg-white/70 dark:bg-gray-900/40 rounded-xl p-4 space-y-1.5">
                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Every shilling — since {shortDate(cash.tracking_start)}</p>
                {[
                  ['Opening cash', cash.opening_cash, 1], ['+ Sales collected (cash & M-Pesa)', cash.collected_sales, 1],
                  ['− Petty cash out', cash.petty_cash_out, -1], ['− Trip fuel', cash.trip_fuel, -1], ['− Supply orders placed', cash.supply_orders_placed, -1],
                ].map(([k, v, s]: any) => (
                  <div key={k} className="flex justify-between"><span className="text-gray-600 dark:text-gray-400">{k}</span><span className="tabular-nums">{s < 0 ? '−' : ''}{money(v)}</span></div>
                ))}
                <div className="flex justify-between border-t border-gray-200 dark:border-gray-700 pt-1.5 font-semibold"><span>Cash available</span><span className="tabular-nums">{money(cash.cash_available)}</span></div>
                <div className="flex justify-between text-xs text-gray-500"><span>Monthly fixed costs (payroll + stipend + overheads)</span><span className="tabular-nums">{money(cash.monthly_fixed_costs)}</span></div>
                <div className="flex justify-between text-xs text-gray-500"><span>Still due this month</span><span className="tabular-nums">{money(cash.fixed_reserve_remaining)}</span></div>
                <div className={`flex justify-between text-xs ${cash.after_fixed_costs < 0 ? 'text-red-600' : 'text-gray-500'}`}><span>Cash after fixed costs</span><span className="tabular-nums">{money(cash.after_fixed_costs)}</span></div>
              </div>
              <div className="bg-white/70 dark:bg-gray-900/40 rounded-xl p-4 space-y-1.5">
                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Standard trip cost (bottles + caps incl. 16% VAT)</p>
                {adv.trip.standard.lines.map((l: any) => (
                  <div key={l.size} className="flex justify-between"><span className="text-gray-600 dark:text-gray-400">{num(l.qty)} × {l.size}</span><span className="tabular-nums">{money(l.cost)}</span></div>
                ))}
                <div className="flex justify-between"><span className="text-gray-600 dark:text-gray-400">Transport Nairobi → Rongo</span><span className="tabular-nums">{money(adv.trip.transport)}</span></div>
                <div className="flex justify-between border-t border-gray-200 dark:border-gray-700 pt-1.5 font-semibold"><span>Trip total</span><span className="tabular-nums">{money(adv.trip.standard.total)}</span></div>
                {Number(adv.trip.suggested.total_qty) > 0 && (
                  <p className="text-xs text-gray-500">Forecast-sized mix needs {num(adv.trip.suggested.total_qty)} bottles → {money(adv.trip.suggested.total)} incl. transport.</p>
                )}
                <p className="text-xs text-gray-500">Arrives ~{adv.trip.lead_business_days} business days after the order.</p>
              </div>
            </div>
          </section>

          {/* Reorder alerts */}
          <section className="mara-panel p-5 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Reorder alerts &amp; auto-advice</h2>
                <p className="text-xs text-gray-500 mt-0.5">
                  Labels: MOQ 0.5L = {num(adv.settings.moq['label_0.5L'])} · 1L = {num(adv.settings.moq['label_1L'])} · 1.5L = {num(adv.settings.moq['label_1.5L'])} pcs · lead time {adv.settings.lead_business_days.labels} business days.
                  Safety {adv.settings.safety_days} days · target cover {adv.settings.target_cover_days} days.
                </p>
              </div>
              <div className="flex gap-1.5 text-xs">
                {(['critical', 'order_now', 'soon', 'ok'] as const).map((k) => (
                  <span key={k} className={`px-2 py-1 rounded-full font-semibold ${STATUS_STYLE[k].cls}`}>{adv.headline[k] || 0} {STATUS_STYLE[k].label}</span>
                ))}
              </div>
            </div>

            {groups.map((g) => {
              const rows = (adv.items as any[]).filter((i) => i.group === g);
              if (!rows.length) return null;
              return (
                <div key={g} className="space-y-1.5">
                  <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">{GROUP_LABEL[g]}</h3>
                  <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                      <thead>
                        <tr className="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                          <th className="py-2 pr-3">Item</th><th className="pr-3 text-right">On hand</th><th className="pr-3 text-right">On order</th>
                          <th className="pr-3 text-right">Use / day</th><th className="pr-3 text-right">Cover</th><th className="pr-3">Runs out</th>
                          <th className="pr-3">Status</th><th className="pr-3">Advice</th><th />
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                        {rows.map((it) => (
                          <tr key={it.code} className="align-top">
                            <td className="py-2 pr-3 font-medium whitespace-nowrap">{it.label}</td>
                            <td className={`pr-3 text-right tabular-nums ${it.on_hand < 0 ? 'text-red-600' : ''}`}>{num(it.on_hand)}</td>
                            <td className="pr-3 text-right tabular-nums">{it.on_order ? num(it.on_order) : '—'}</td>
                            <td className="pr-3 text-right tabular-nums">{num(it.daily_use, it.daily_use < 10 ? 1 : 0)}</td>
                            <td className="pr-3 text-right tabular-nums">{it.days_cover == null ? '—' : `${num(it.days_cover, 1)} d`}</td>
                            <td className="pr-3 whitespace-nowrap">{shortDate(it.stockout_date)}</td>
                            <td className="pr-3"><span className={`px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap ${STATUS_STYLE[it.status].cls}`}>{STATUS_STYLE[it.status].label}</span></td>
                            <td className="pr-3 text-xs text-gray-600 dark:text-gray-400 min-w-[16rem]">
                              {it.advice}
                              {it.suggest_qty > 0 && !it.trip_item && <span className="block text-gray-500">≈ {money(it.est_cost)} incl. VAT where charged · arrives ~{shortDate(it.arrival_if_ordered_today)}</span>}
                            </td>
                            <td className="text-right">
                              {['critical', 'order_now', 'soon'].includes(it.status) && (
                                <button type="button" onClick={() => openItemOrder(it)}
                                  className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-900 text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900">
                                  <Plus className="w-3 h-3" /> Order
                                </button>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              );
            })}
          </section>

          {/* Supply orders */}
          <section id="orders" className="mara-panel p-5 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Supply orders</h2>
                <p className="text-xs text-gray-500 mt-0.5">Orders placed with suppliers. Open orders count as stock on the way, and their cost is deducted from available cash. Post the delivery as a GRN in Inventory when it arrives.</p>
              </div>
              <button type="button" onClick={() => setOrder({ kind: 'labels', supplier: adv.settings.suppliers?.labels || '', reference_no: '', order_date: today(), lead_business_days: adv.settings.lead_business_days.labels, transport_cost: 0, vat_applies: true, notes: '', lines: [{ item_code: 'LABEL-0.5L', label: 'Labels 0.5L', qty: 0, unit_cost_ex_vat: 0.6 }] })}
                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-slate-900 text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900">
                <Plus className="w-4 h-4" /> New order
              </button>
            </div>
            {adv.orders.length === 0 ? (
              <p className="text-sm text-gray-500 py-4 text-center">No supply orders recorded yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                      <th className="py-2 pr-3">Ordered</th><th className="pr-3">Supplier / ref</th><th className="pr-3">Items</th>
                      <th className="pr-3 text-right">Total</th><th className="pr-3">Expected</th><th className="pr-3">Status</th><th />
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {(adv.orders as any[]).map((o) => (
                      <tr key={o.id} className="align-top">
                        <td className="py-2 pr-3 whitespace-nowrap">{shortDate(o.order_date)}</td>
                        <td className="pr-3"><span className="font-medium">{o.supplier}</span>{o.reference_no && <span className="block text-xs text-gray-500">{o.reference_no}</span>}</td>
                        <td className="pr-3 text-xs text-gray-600 dark:text-gray-400">{(o.lines as any[]).map((l) => `${num(l.qty)} ${l.label}`).join(' · ')}</td>
                        <td className="pr-3 text-right tabular-nums">{money(o.total_cost)}</td>
                        <td className="pr-3 whitespace-nowrap">
                          {o.status === 'received' ? `Got ${shortDate(o.received_date)}` : shortDate(o.expected_date)}
                          {o.overdue && <span className="ml-1 text-xs font-semibold text-red-600">overdue</span>}
                        </td>
                        <td className="pr-3"><span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${ORDER_STATUS[o.status]}`}>{o.status.replace('_', ' ')}</span></td>
                        <td className="text-right whitespace-nowrap">
                          {o.status === 'placed' && <button type="button" onClick={() => setOrderStatus(o.id, 'in_transit')} className="text-xs font-semibold text-indigo-700 hover:underline mr-2"><Clock className="inline w-3 h-3 mr-0.5" />In transit</button>}
                          {['placed', 'in_transit'].includes(o.status) && <button type="button" onClick={() => setOrderStatus(o.id, 'received')} className="text-xs font-semibold text-emerald-700 hover:underline mr-2"><CheckCircle2 className="inline w-3 h-3 mr-0.5" />Received</button>}
                          {['placed', 'in_transit'].includes(o.status) && <button type="button" onClick={() => setOrderStatus(o.id, 'cancelled')} className="text-xs text-gray-500 hover:underline mr-2">Cancel</button>}
                          <button type="button" aria-label="Remove order" onClick={() => removeOrder(o.id)} className="text-gray-400 hover:text-red-600"><Trash2 className="inline w-3.5 h-3.5" /></button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          {/* Forecast */}
          <section className="mara-panel p-5 space-y-3">
            <div>
              <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">Demand forecast</h2>
              <p className="text-xs text-gray-500 mt-0.5">Bottles sold per day (orders + driver trips, last 28 days) against the model’s forecast. The forecast blends your plan ({adv.settings.trips_per_month} trips/month) with real sales — the more history, the more the data leads.</p>
            </div>
            <div className="h-56">
              <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={histChart}>
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="label" tick={{ fontSize: 10 }} />
                  <YAxis tick={{ fontSize: 10 }} />
                  <Tooltip />
                  <Legend />
                  <Bar dataKey="bottles" name="Bottles sold" fill="#7dd3fc" />
                  <Line type="monotone" dataKey="forecast" name="Forecast / day" stroke="#0f766e" strokeWidth={2} dot={false} />
                </ComposedChart>
              </ResponsiveContainer>
            </div>
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                    <th className="py-2 pr-3">Size</th><th className="pr-3 text-right">Sold (28d)</th><th className="pr-3 text-right">Observed / day</th>
                    <th className="pr-3 text-right">Plan / day</th><th className="pr-3 text-right">Forecast / day</th><th className="pr-3 text-right">Production / day</th>
                    <th className="pr-3 text-right">Data weight</th><th>Method</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {Object.entries(adv.forecast as Record<string, any>).filter(([, f]) => f.forecast_daily > 0 || f.sold_28d > 0).map(([size, f]) => (
                    <tr key={size}>
                      <td className="py-1.5 pr-3 font-medium">{size}</td>
                      <td className="pr-3 text-right tabular-nums">{num(f.sold_28d)}</td>
                      <td className="pr-3 text-right tabular-nums">{f.observed_daily == null ? '—' : num(f.observed_daily, 1)}</td>
                      <td className="pr-3 text-right tabular-nums">{num(f.prior_daily, 1)}</td>
                      <td className="pr-3 text-right tabular-nums font-semibold">{num(f.forecast_daily, 1)}</td>
                      <td className="pr-3 text-right tabular-nums">{f.production_daily ? num(f.production_daily, 1) : '—'}</td>
                      <td className="pr-3 text-right tabular-nums">{Math.round(f.data_weight * 100)}%</td>
                      <td className="text-xs text-gray-500">{f.method}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <ul className="text-xs text-gray-500 list-disc pl-5 space-y-0.5">
              {(adv.model_notes as string[]).map((n) => <li key={n}>{n}</li>)}
            </ul>
          </section>
        </>
      )}

      {/* Production & sales charts */}
      <section id="production" className="grid lg:grid-cols-2 gap-4">
        <div className="mara-panel p-5">
          <h2 className="text-lg font-semibold mb-3">Production (bales) — 30 days</h2>
          <div className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={prodTrend}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="label" tick={{ fontSize: 10 }} />
                <YAxis tick={{ fontSize: 10 }} />
                <Tooltip />
                <Area type="monotone" dataKey="bales" stroke="#0369a1" fill="#7dd3fc" name="Bales" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </div>
        <div id="sales" className="mara-panel p-5">
          <h2 className="text-lg font-semibold mb-3">Sales (KES) — 30 days</h2>
          <div className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={salesTrend}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="label" tick={{ fontSize: 10 }} />
                <YAxis tick={{ fontSize: 10 }} />
                <Tooltip formatter={(v: number) => money(v)} />
                <Area type="monotone" dataKey="amount" stroke="#0f766e" fill="#99f6e4" name="Sales" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
          <p className="text-xs text-gray-500 mt-2">
            In-house {money(data.dispatch_sales.in_house_sales_kes)} · Trips {money(data.dispatch_sales.trip_sales_kes)} ·
            Dispatched {data.dispatch_sales.dispatched_bales_month} · Sold {data.dispatch_sales.sold_bales_month}
          </p>
        </div>
      </section>

      {/* Dispatch, returns & Excel pack */}
      <section id="dispatch" className="mara-panel p-5 space-y-4">
        <div className="flex items-center gap-2">
          <RotateCcw className="w-5 h-5 text-sky-700" />
          <h2 className="text-lg font-semibold">Dispatch, sales, returns &amp; Excel pack</h2>
        </div>
        <div className="grid sm:grid-cols-3 gap-3 text-sm">
          <div className="rounded-lg bg-gray-50 dark:bg-gray-900/50 p-3"><p className="text-gray-500">Dispatched (mo)</p><p className="text-xl font-bold">{data.dispatch_sales.dispatched_bales_month}</p></div>
          <div className="rounded-lg bg-gray-50 dark:bg-gray-900/50 p-3"><p className="text-gray-500">Sold (mo)</p><p className="text-xl font-bold">{data.dispatch_sales.sold_bales_month}</p></div>
          <div className="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-3">
            <p className="text-gray-500">Returns = D − S</p>
            <p className="text-xl font-bold text-emerald-800 dark:text-emerald-200">{data.dispatch_sales.returned_bales_computed}</p>
            <p className="text-xs text-gray-500">Logged returns: {data.dispatch_sales.returned_bales_logged}</p>
          </div>
        </div>
        <div id="exports" className="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
          {exports.map((ex) => (
            <button key={ex.key} type="button" onClick={() => exportFile(ex.key, ex.path, ex.file)} disabled={dlKey === ex.key}
              className="flex items-center justify-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 px-3 py-2.5 text-sm font-medium hover:bg-sky-50 dark:hover:bg-sky-900/30 disabled:opacity-50">
              {dlKey === ex.key ? <Loader2 className="w-4 h-4 animate-spin" /> : <Download className="w-4 h-4" />}
              {ex.label}
            </button>
          ))}
        </div>
        <p className="text-xs text-gray-500">
          Payroll: create/open a run on HR → Payroll, then use Payroll / Payslips / Bank Transfer downloads. Production export uses the same month selectors above.
        </p>
      </section>

      {/* Order modal */}
      {order && adv && (
        <div className="fixed inset-0 bg-black/50 flex items-start justify-center z-50 p-4 overflow-y-auto">
          <div className="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-2xl my-8 p-6 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-semibold">Record supply order</h3>
              <button type="button" aria-label="Close" onClick={() => setOrder(null)} className="text-gray-400 hover:text-gray-700"><X className="w-5 h-5" /></button>
            </div>
            <div className="grid grid-cols-2 gap-3 text-sm">
              <label className="block"><span className="text-xs text-gray-500">Type</span>
                <select value={order.kind} onChange={(e) => setOrder({ ...order, kind: e.target.value })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2">
                  <option value="bottle_trip">Bottle trip (Intripet)</option><option value="labels">Labels</option><option value="bailing">Bailing paper</option><option value="seals">Seals</option><option value="other">Other</option>
                </select></label>
              <label className="block"><span className="text-xs text-gray-500">Supplier</span>
                <input value={order.supplier} onChange={(e) => setOrder({ ...order, supplier: e.target.value })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2" /></label>
              <label className="block"><span className="text-xs text-gray-500">Reference (SO / invoice)</span>
                <input value={order.reference_no} onChange={(e) => setOrder({ ...order, reference_no: e.target.value })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2" /></label>
              <label className="block"><span className="text-xs text-gray-500">Order date</span>
                <input type="date" value={order.order_date} onChange={(e) => setOrder({ ...order, order_date: e.target.value })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2" /></label>
              <label className="block"><span className="text-xs text-gray-500">Lead time (business days)</span>
                <input type="number" min={0} value={order.lead_business_days} onChange={(e) => setOrder({ ...order, lead_business_days: Number(e.target.value) })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2" /></label>
              <label className="block"><span className="text-xs text-gray-500">Transport / haulage (KES)</span>
                <input type="number" min={0} value={order.transport_cost} onChange={(e) => setOrder({ ...order, transport_cost: Number(e.target.value) })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2" /></label>
            </div>
            <div className="space-y-2">
              <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Lines (unit cost ex-VAT)</p>
              {order.lines.map((l, i) => (
                <div key={i} className="grid grid-cols-12 gap-2 items-end text-sm">
                  <label className="col-span-5"><span className="text-xs text-gray-500">Item</span>
                    <input value={l.label} onChange={(e) => setOrder({ ...order, lines: order.lines.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)) })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2" /></label>
                  <label className="col-span-3"><span className="text-xs text-gray-500">Qty</span>
                    <input type="number" min={0} value={l.qty} onChange={(e) => setOrder({ ...order, lines: order.lines.map((x, j) => (j === i ? { ...x, qty: Number(e.target.value) } : x)) })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2" /></label>
                  <label className="col-span-3"><span className="text-xs text-gray-500">Unit ex-VAT</span>
                    <input type="number" step="0.0001" min={0} value={l.unit_cost_ex_vat} onChange={(e) => setOrder({ ...order, lines: order.lines.map((x, j) => (j === i ? { ...x, unit_cost_ex_vat: Number(e.target.value) } : x)) })} className="mt-1 w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-2" /></label>
                  <button type="button" aria-label="Remove line" onClick={() => setOrder({ ...order, lines: order.lines.filter((_, j) => j !== i) })} className="col-span-1 pb-2 text-gray-400 hover:text-red-600"><Trash2 className="w-4 h-4" /></button>
                </div>
              ))}
              <button type="button" onClick={() => setOrder({ ...order, lines: [...order.lines, { item_code: 'OTHER', label: '', qty: 0, unit_cost_ex_vat: 0 }] })} className="text-xs font-semibold text-blue-700 hover:underline">+ Add line</button>
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={order.vat_applies} onChange={(e) => setOrder({ ...order, vat_applies: e.target.checked })} /> Supplier charges {Math.round(adv.settings.vat_rate * 100)}% VAT on the total</label>
            <div className="rounded-xl bg-gray-50 dark:bg-gray-900/50 p-3 text-sm space-y-1">
              <div className="flex justify-between"><span className="text-gray-500">Goods (ex-VAT)</span><span className="tabular-nums">{money(orderTotals.goods)}</span></div>
              <div className="flex justify-between"><span className="text-gray-500">VAT</span><span className="tabular-nums">{money(orderTotals.vat)}</span></div>
              <div className="flex justify-between"><span className="text-gray-500">Transport</span><span className="tabular-nums">{money(order.transport_cost)}</span></div>
              <div className="flex justify-between border-t border-gray-200 dark:border-gray-700 pt-1 font-semibold"><span>Total cash out</span><span className="tabular-nums">{money(orderTotals.total)}</span></div>
            </div>
            <div className="flex justify-end gap-2">
              <button type="button" onClick={() => setOrder(null)} className="px-4 py-2 text-sm border rounded-lg">Cancel</button>
              <button type="button" onClick={submitOrder} disabled={savingOrder} className="px-4 py-2 text-sm font-semibold bg-emerald-600 text-white rounded-lg disabled:opacity-50">
                {savingOrder ? 'Saving…' : 'Record order'}
              </button>
            </div>
          </div>
        </div>
      )}
    </PageShell>
  );
};

export default OperationsPage;
