import React, { useEffect, useState } from 'react';
import {
  ResponsiveContainer, AreaChart, Area, BarChart, Bar,
  XAxis, YAxis, CartesianGrid, Tooltip,
} from 'recharts';
import {
  Users, Package, Factory, Warehouse, Truck, ShoppingCart,
  RotateCcw, FileSpreadsheet, Download, Lightbulb, ArrowRight,
  MapPin, Loader2,
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

type Stage = { key: string; label: string; href: string };
type Band = {
  role_key: string; title: string; headcount_target: number;
  min_kes: number; max_kes: number; default_kes: number;
  include_in_payroll_export: boolean; notes: string;
};
type Overview = {
  site: { company_name: string; operations_base: string; sourcing_city: string; bailing_papers_from: string };
  stages: Stage[];
  bottle_suppliers: { code: string; name: string; city: string; package_unit: string; notes: string }[];
  bottle_transport_cost_kes: number;
  other_inputs: { name: string; from: string }[];
  salary_bands: Band[];
  payroll: {
    target_monthly_operational_kes: number;
    actual_monthly_operational_kes: number;
    director_allowance_kes: number;
    director_excluded_from_payroll_export: boolean;
    headcount_operational: number;
    by_role: Record<string, { count: number; total_salary: number; avg_salary: number }>;
  };
  inventory: { bags_on_hand: number; bags_by_supplier: Record<string, number>; package_unit: string; note: string };
  production: { bales_produced_month: number; trend_30d: { date: string; bales: number }[] };
  warehouse: { finished_qty_on_hand: number; unit: string };
  dispatch_sales: {
    dispatched_bales_month: number; sold_bales_month: number;
    returned_bales_logged: number; returned_bales_computed: number;
    returns_formula: string; in_house_sales_kes: number; trip_sales_kes: number;
    sales_trend_30d: { date: string; amount: number }[];
  };
  conversions: {
    id: string; supplier_code: string; sku_id: string;
    bottles_per_bag: number; bottles_per_bale: number; notes?: string;
    sku?: { id: string; name: string; brand?: string };
  }[];
  default_conversions: { bottles_per_bag: number; bottles_per_bale: number };
  automation_ideas: { title: string; detail: string }[];
};

const money = (n: number) => `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
const STAGE_ICONS: Record<string, React.ElementType> = {
  people: Users, sourcing: MapPin, inventory: Package, production: Factory,
  warehouse: Warehouse, dispatch: Truck, sales: ShoppingCart, returns: RotateCcw, reports: FileSpreadsheet,
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

const OperationsPage: React.FC = () => {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [data, setData] = useState<Overview | null>(null);
  const [loading, setLoading] = useState(true);
  const [dlKey, setDlKey] = useState<string | null>(null);
  const [skus, setSkus] = useState<{ id: string; name: string; brand?: string }[]>([]);
  const [convForm, setConvForm] = useState({
    supplier_code: 'FINELINE', sku_id: '', bottles_per_bag: 24, bottles_per_bale: 12, notes: '',
  });
  const [savingConv, setSavingConv] = useState(false);

  const load = () => {
    setLoading(true);
    api.get('/operations/overview')
      .then((res) => setData(res.data.data))
      .catch(() => toast.error('Could not load operations overview'))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
    api.get('/operations/skus').then((res) => {
      const list = res.data.data || [];
      setSkus(list);
      if (list[0]) setConvForm((f) => ({ ...f, sku_id: list[0].id }));
    }).catch(() => {});
  }, []);

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

  const saveConversion = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!convForm.sku_id) return;
    setSavingConv(true);
    try {
      await api.post('/operations/conversions', convForm);
      toast.success('Bag→bale conversion saved');
      load();
    } catch {
      toast.error('Could not save conversion');
    } finally {
      setSavingConv(false);
    }
  };

  if (loading && !data) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="w-8 h-8 animate-spin text-blue-600" />
      </div>
    );
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
  const supplierBars = Object.entries(data.inventory.bags_by_supplier || {}).map(([name, bags]) => ({ name, bags }));

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

  return (
    <div className="space-y-8">
      <div className="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Operations &amp; Supply Chain</h1>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            {data.site.company_name} · Base {data.site.operations_base} · Bottles from {data.site.sourcing_city}
            {' '}· Papers from {data.site.bailing_papers_from}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <select value={month} onChange={(e) => setMonth(Number(e.target.value))}
            className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm">
            {Array.from({ length: 12 }, (_, i) => (
              <option key={i + 1} value={i + 1}>{new Date(2000, i, 1).toLocaleString(undefined, { month: 'long' })}</option>
            ))}
          </select>
          <input type="number" value={year} onChange={(e) => setYear(Number(e.target.value))}
            className="w-24 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm" />
        </div>
      </div>

      {/* Flow */}
      <section id="flow" className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
        <h2 className="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">End-to-end flow</h2>
        <div className="flex flex-wrap items-center gap-2">
          {data.stages.map((s, i) => {
            const Icon = STAGE_ICONS[s.key] || Package;
            return (
              <React.Fragment key={s.key}>
                <a href={s.href}
                  className="inline-flex items-center gap-2 rounded-full bg-sky-50 dark:bg-sky-900/30 text-sky-900 dark:text-sky-100 px-3 py-1.5 text-sm font-medium hover:bg-sky-100 dark:hover:bg-sky-900/50">
                  <Icon className="w-4 h-4" />
                  {s.label}
                </a>
                {i < data.stages.length - 1 && <ArrowRight className="w-4 h-4 text-gray-400 shrink-0" />}
              </React.Fragment>
            );
          })}
        </div>
        <p className="mt-3 text-sm text-gray-600 dark:text-gray-400">
          Empty bottles arrive in <strong>bags</strong> from FineLine &amp; Blowplast (Nairobi) + KES {data.bottle_transport_cost_kes.toLocaleString()} haulage to Rongo.
          After production conversion they become <strong>bales</strong> for warehouse, dispatch, and sales. Returns = dispatched − sold.
        </p>
      </section>

      {/* KPI strip */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: 'Operational payroll / mo', value: money(data.payroll.actual_monthly_operational_kes), sub: `Target band ${money(data.payroll.target_monthly_operational_kes)}` },
          { label: 'Bags on hand', value: String(data.inventory.bags_on_hand), sub: 'FineLine / Blowplast' },
          { label: 'Bales produced (month)', value: String(data.production.bales_produced_month), sub: `Warehouse ${data.warehouse.finished_qty_on_hand}` },
          { label: 'Returns (computed)', value: String(data.dispatch_sales.returned_bales_computed), sub: data.dispatch_sales.returns_formula },
        ].map((k) => (
          <div key={k.label} className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <p className="text-xs text-gray-500">{k.label}</p>
            <p className="text-xl font-bold text-gray-900 dark:text-gray-100 mt-1">{k.value}</p>
            <p className="text-xs text-gray-500 mt-1">{k.sub}</p>
          </div>
        ))}
      </div>

      {/* People */}
      <section id="people" className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-4">
        <div className="flex items-center gap-2">
          <Users className="w-5 h-5 text-sky-700" />
          <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">1. People &amp; salary bands</h2>
        </div>
        <p className="text-sm text-gray-600 dark:text-gray-400">
          Director {money(data.payroll.director_allowance_kes)}/mo is shown for planning only — excluded from Finalis-style payroll Excel (comes later as allowance).
          Enter staff &amp; salaries under Users / HR, then download payroll from an HR run.
        </p>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-gray-500 border-b border-gray-200 dark:border-gray-700">
                <th className="py-2 pr-4">Role</th>
                <th className="py-2 pr-4">Headcount</th>
                <th className="py-2 pr-4">Band (KES)</th>
                <th className="py-2 pr-4">Default</th>
                <th className="py-2">In payroll Excel?</th>
              </tr>
            </thead>
            <tbody>
              {data.salary_bands.map((b) => (
                <tr key={b.role_key} className="border-b border-gray-100 dark:border-gray-700/50">
                  <td className="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{b.title}</td>
                  <td className="py-2 pr-4">{b.headcount_target}</td>
                  <td className="py-2 pr-4">{b.min_kes.toLocaleString()} – {b.max_kes.toLocaleString()}</td>
                  <td className="py-2 pr-4">{b.default_kes.toLocaleString()}</td>
                  <td className="py-2">{b.include_in_payroll_export ? 'Yes' : 'No (allowance)'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="text-sm text-gray-500">
          Live operational headcount: {data.payroll.headcount_operational} · Actual monthly salaries: {money(data.payroll.actual_monthly_operational_kes)}
        </p>
      </section>

      {/* Sourcing + inventory */}
      <section id="sourcing" className="grid lg:grid-cols-2 gap-4">
        <div id="inventory" className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
          <div className="flex items-center gap-2">
            <Package className="w-5 h-5 text-sky-700" />
            <h2 className="text-lg font-semibold">2–3. Sourcing &amp; bag inventory</h2>
          </div>
          <ul className="text-sm text-gray-600 dark:text-gray-400 space-y-1">
            {data.bottle_suppliers.map((s) => (
              <li key={s.code}><strong>{s.name}</strong> ({s.city}) — arrives in {s.package_unit}s. {s.notes}</li>
            ))}
            {data.other_inputs.map((o) => (
              <li key={o.name}>{o.name} — from {o.from}</li>
            ))}
            <li>Haulage Nairobi → Rongo: <strong>{money(data.bottle_transport_cost_kes)}</strong> per consignment</li>
          </ul>
          <p className="text-sm text-amber-800 dark:text-amber-200 bg-amber-50 dark:bg-amber-900/20 rounded-lg px-3 py-2">{data.inventory.note}</p>
          {supplierBars.length > 0 && (
            <div className="h-48">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={supplierBars}>
                  <CartesianGrid strokeDasharray="3 3" />
                  <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Bar dataKey="bags" fill="#0284c7" name="Bags on hand" />
                </BarChart>
              </ResponsiveContainer>
            </div>
          )}
        </div>

        <div id="production" className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
          <div className="flex items-center gap-2">
            <Factory className="w-5 h-5 text-sky-700" />
            <h2 className="text-lg font-semibold">4. Bag → bale conversion</h2>
          </div>
          <p className="text-sm text-gray-600 dark:text-gray-400">
            Set bottles/bag and bottles/bale per supplier × SKU. Defaults: {data.default_conversions.bottles_per_bag} / bag → {data.default_conversions.bottles_per_bale} / bale.
          </p>
          <form onSubmit={saveConversion} className="grid grid-cols-2 gap-2 text-sm">
            <select value={convForm.supplier_code} onChange={(e) => setConvForm({ ...convForm, supplier_code: e.target.value })}
              className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-2">
              <option value="FINELINE">FineLine</option>
              <option value="BLOWPLAST">Blowplast</option>
              <option value="OTHER">Other</option>
            </select>
            <select value={convForm.sku_id} onChange={(e) => setConvForm({ ...convForm, sku_id: e.target.value })}
              className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-2">
              {skus.map((s) => <option key={s.id} value={s.id}>{s.brand ? `${s.brand} · ` : ''}{s.name}</option>)}
            </select>
            <input type="number" step="0.001" value={convForm.bottles_per_bag}
              onChange={(e) => setConvForm({ ...convForm, bottles_per_bag: Number(e.target.value) })}
              className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-2" placeholder="Bottles / bag" />
            <input type="number" step="0.001" value={convForm.bottles_per_bale}
              onChange={(e) => setConvForm({ ...convForm, bottles_per_bale: Number(e.target.value) })}
              className="rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-2 py-2" placeholder="Bottles / bale" />
            <button type="submit" disabled={savingConv}
              className="col-span-2 rounded-lg bg-sky-700 text-white py-2 font-medium hover:bg-sky-800 disabled:opacity-50">
              {savingConv ? 'Saving…' : 'Save conversion'}
            </button>
          </form>
          {data.conversions.length > 0 && (
            <ul className="text-xs text-gray-500 space-y-1 max-h-28 overflow-y-auto">
              {data.conversions.map((c) => (
                <li key={c.id}>{c.supplier_code} · {c.sku?.name}: {c.bottles_per_bag}/bag → {c.bottles_per_bale}/bale</li>
              ))}
            </ul>
          )}
        </div>
      </section>

      {/* Charts */}
      <section id="warehouse" className="grid lg:grid-cols-2 gap-4">
        <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
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
        <div id="sales" className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
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

      {/* Returns + downloads */}
      <section id="returns" className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-4">
        <div className="flex items-center gap-2">
          <RotateCcw className="w-5 h-5 text-sky-700" />
          <h2 className="text-lg font-semibold">5–8. Dispatch, sales, returns &amp; Excel pack</h2>
        </div>
        <div className="grid sm:grid-cols-3 gap-3 text-sm">
          <div className="rounded-lg bg-gray-50 dark:bg-gray-900/50 p-3">
            <p className="text-gray-500">Dispatched (mo)</p>
            <p className="text-xl font-bold">{data.dispatch_sales.dispatched_bales_month}</p>
          </div>
          <div className="rounded-lg bg-gray-50 dark:bg-gray-900/50 p-3">
            <p className="text-gray-500">Sold (mo)</p>
            <p className="text-xl font-bold">{data.dispatch_sales.sold_bales_month}</p>
          </div>
          <div className="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 p-3">
            <p className="text-gray-500">Returns = D − S</p>
            <p className="text-xl font-bold text-emerald-800 dark:text-emerald-200">{data.dispatch_sales.returned_bales_computed}</p>
            <p className="text-xs text-gray-500">Logged returns: {data.dispatch_sales.returned_bales_logged}</p>
          </div>
        </div>

        <div id="reports" className="grid sm:grid-cols-2 lg:grid-cols-4 gap-2">
          {exports.map((ex) => (
            <button key={ex.key} type="button" onClick={() => exportFile(ex.key, ex.path, ex.file)}
              disabled={dlKey === ex.key}
              className="flex items-center justify-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 px-3 py-2.5 text-sm font-medium hover:bg-sky-50 dark:hover:bg-sky-900/30 disabled:opacity-50">
              {dlKey === ex.key ? <Loader2 className="w-4 h-4 animate-spin" /> : <Download className="w-4 h-4" />}
              {ex.label}
            </button>
          ))}
        </div>
        <p className="text-xs text-gray-500">
          Payroll (Finalis layout, Director excluded): create/open a run on HR → Payroll, then use Payroll / Payslips / Bank Transfer downloads.
          Production export uses the same month selectors above.
        </p>
      </section>

      {/* Automation */}
      <section className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 space-y-3">
        <div className="flex items-center gap-2">
          <Lightbulb className="w-5 h-5 text-amber-500" />
          <h2 className="text-lg font-semibold">Automation roadmap (less manual math)</h2>
        </div>
        <div className="grid md:grid-cols-2 gap-3">
          {data.automation_ideas.map((idea) => (
            <div key={idea.title} className="rounded-lg border border-gray-100 dark:border-gray-700 p-3">
              <p className="font-medium text-gray-900 dark:text-gray-100 text-sm">{idea.title}</p>
              <p className="text-xs text-gray-600 dark:text-gray-400 mt-1">{idea.detail}</p>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
};

export default OperationsPage;
