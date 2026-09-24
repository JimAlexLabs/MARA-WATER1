import React, { useEffect, useState } from 'react';
import {
  ResponsiveContainer, LineChart, Line, CartesianGrid, XAxis, YAxis, Tooltip,
} from 'recharts';
import { DollarSign, Factory, Wallet, AlertTriangle, PackageX, UserPlus } from 'lucide-react';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 2 Phase 11 / Round 5A Phase 2: the Investor role's own dashboard
// -- "a daily performance summary ... explicitly not line-level detail
// like individual salaries, individual debtor names, or petty cash line
// items. Build this as a distinct, deliberately limited dashboard, not
// the full app with buttons hidden." This page has no navigation to any
// operational section at all (see Layout.tsx) -- there is nothing to
// click through to, not just something hidden.
//
// Round 5A Phase 2 fills this out properly: a Production trend to match
// Sales, and a "debt and stock-depletion alerts" summary -- counts and
// totals only, never a debtor-by-name list or a per-item Inventory link.

interface Summary {
  today: { revenue: number; production_liters: number };
  this_month: { revenue: number; production_liters: number; cash_collected: number; mpesa_collected: number };
  sales_trend: { date: string; revenue: number }[];
  production_trend: { date: string; liters: number }[];
  financial_health: { cash_and_mpesa_collected_month: number; outstanding_debt_total: number };
  alerts: {
    new_debt: { window_days: number; count: number; total: number };
    overdue_debt: { count: number; total: number };
    low_stock: { count: number; items: string[] };
  };
}

const money = (n: number) => `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const InvestorPage: React.FC = () => {
  const { user } = useAuth();
  const [data, setData] = useState<Summary | null>(null);
  const [ops, setOps] = useState<{
    inventory?: { bags_on_hand: number };
    production?: { bales_produced_month: number };
    warehouse?: { finished_qty_on_hand: number };
    dispatch_sales?: { returned_bales_computed: number; dispatched_bales_month: number; sold_bales_month: number };
    site?: { operations_base: string; sourcing_city: string };
  } | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    Promise.all([
      api.get('/investor/summary'),
      api.get('/investor/operations-overview').catch(() => null),
    ])
      .then(([sumRes, opsRes]) => {
        setData(sumRes.data.data);
        if (opsRes?.data?.data) setOps(opsRes.data.data);
      })
      .catch(() => setError('Could not load the summary.'))
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  if (error || !data) {
    return <div className="text-center py-12 text-gray-500 dark:text-gray-400">{error || 'No data available.'}</div>;
  }

  const salesTrend = data.sales_trend.map(p => ({ ...p, label: new Date(p.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) }));
  const productionTrend = data.production_trend.map(p => ({ ...p, label: new Date(p.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) }));

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Welcome, {user?.first_name}</h1>
        <p className="text-gray-600 dark:text-gray-400">Daily performance summary -- Homa Springs Limited</p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <div className="flex items-center gap-3">
            <DollarSign className="w-7 h-7 text-violet-600" />
            <div>
              <p className="text-xs text-gray-500 dark:text-gray-400">Revenue Today</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{money(data.today.revenue)}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <div className="flex items-center gap-3">
            <DollarSign className="w-7 h-7 text-violet-600" />
            <div>
              <p className="text-xs text-gray-500 dark:text-gray-400">Revenue This Month</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{money(data.this_month.revenue)}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <div className="flex items-center gap-3">
            <Factory className="w-7 h-7 text-blue-600" />
            <div>
              <p className="text-xs text-gray-500 dark:text-gray-400">Production This Month</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{data.this_month.production_liters.toLocaleString()} L</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <div className="flex items-center gap-3">
            <Wallet className="w-7 h-7 text-green-600" />
            <div>
              <p className="text-xs text-gray-500 dark:text-gray-400">Cash + M-Pesa Collected (Month)</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{money(data.financial_health.cash_and_mpesa_collected_month)}</p>
            </div>
          </div>
        </div>
      </div>

      {ops && (
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Supply chain snapshot</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
            {ops.site?.operations_base || 'Rongo'} ops · bottles from {ops.site?.sourcing_city || 'Nairobi'} (bags → bales). No payroll detail.
          </p>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div>
              <p className="text-xs text-gray-500">Bags on hand</p>
              <p className="font-bold text-gray-900 dark:text-gray-100">{ops.inventory?.bags_on_hand ?? 0}</p>
            </div>
            <div>
              <p className="text-xs text-gray-500">Bales produced (mo)</p>
              <p className="font-bold text-gray-900 dark:text-gray-100">{ops.production?.bales_produced_month ?? 0}</p>
            </div>
            <div>
              <p className="text-xs text-gray-500">Warehouse stock</p>
              <p className="font-bold text-gray-900 dark:text-gray-100">{ops.warehouse?.finished_qty_on_hand ?? 0}</p>
            </div>
            <div>
              <p className="text-xs text-gray-500">Returns (D − S)</p>
              <p className="font-bold text-gray-900 dark:text-gray-100">{ops.dispatch_sales?.returned_bales_computed ?? 0}</p>
            </div>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Sales Trend (last 30 days)</h3>
          {salesTrend.length === 0 ? (
            <div className="h-[220px] flex items-center justify-center text-sm text-gray-400 dark:text-gray-500">No sales in the last 30 days</div>
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <LineChart data={salesTrend}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                <XAxis dataKey="label" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={60} />
                <Tooltip formatter={(v: number) => money(v)} />
                <Line type="monotone" dataKey="revenue" stroke="#7c3aed" strokeWidth={2} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          )}
        </div>

        {/* Round 5A Phase 2: Production needed its own trend, not just
            today/month totals -- same 30-day shape as Sales. */}
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Production Trend (last 30 days)</h3>
          {productionTrend.length === 0 ? (
            <div className="h-[220px] flex items-center justify-center text-sm text-gray-400 dark:text-gray-500">No production in the last 30 days</div>
          ) : (
            <ResponsiveContainer width="100%" height={220}>
              <LineChart data={productionTrend}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                <XAxis dataKey="label" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={60} />
                <Tooltip formatter={(v: number) => `${Number(v).toLocaleString()} L`} />
                <Line type="monotone" dataKey="liters" stroke="#2563eb" strokeWidth={2} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          )}
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Financial Health</h3>
        <div className="flex items-center justify-between max-w-md">
          <div className="flex items-center gap-2 text-gray-600 dark:text-gray-400">
            <AlertTriangle className="w-5 h-5 text-amber-500" />
            <span className="text-sm">Total Outstanding Debt</span>
          </div>
          <span className="font-semibold text-gray-900 dark:text-gray-100">{money(data.financial_health.outstanding_debt_total)}</span>
        </div>
        <p className="text-xs text-gray-400 dark:text-gray-500 mt-3">
          High-level totals only -- individual debtor names, salaries, and petty cash line items are not part of this view.
        </p>
      </div>

      {/* Round 5A Phase 2: "Debt and stock-depletion alerts -- a simple
          summary of new debt entries and low-stock/depletion warnings, so
          the investor stays aware of financial and operational risk
          without needing operational access." Counts and totals only. */}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <div className="flex items-center gap-2 mb-3">
            <UserPlus className="w-5 h-5 text-amber-500" />
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Debt Alerts</h3>
          </div>
          <div className="space-y-2 text-sm">
            <div className="flex items-center justify-between">
              <span className="text-gray-600 dark:text-gray-400">New debt (last {data.alerts.new_debt.window_days} days)</span>
              <span className="font-semibold text-gray-900 dark:text-gray-100">
                {data.alerts.new_debt.count} · {money(data.alerts.new_debt.total)}
              </span>
            </div>
            <div className="flex items-center justify-between">
              <span className="text-gray-600 dark:text-gray-400">Overdue</span>
              <span className={`font-semibold ${data.alerts.overdue_debt.count > 0 ? 'text-red-600' : 'text-gray-900 dark:text-gray-100'}`}>
                {data.alerts.overdue_debt.count} · {money(data.alerts.overdue_debt.total)}
              </span>
            </div>
          </div>
          {data.alerts.new_debt.count === 0 && data.alerts.overdue_debt.count === 0 && (
            <p className="text-xs text-gray-400 dark:text-gray-500 mt-3">No new or overdue debt to flag right now.</p>
          )}
        </div>

        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
          <div className="flex items-center gap-2 mb-3">
            <PackageX className="w-5 h-5 text-red-500" />
            <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Stock-Depletion Alerts</h3>
          </div>
          {data.alerts.low_stock.count === 0 ? (
            <p className="text-sm text-gray-400 dark:text-gray-500">No items are low on stock right now.</p>
          ) : (
            <>
              <p className="text-sm text-gray-900 dark:text-gray-100 font-semibold mb-2">{data.alerts.low_stock.count} item(s) at or below reorder point</p>
              <div className="flex flex-wrap gap-2">
                {data.alerts.low_stock.items.map((name, i) => (
                  <span key={i} className="text-xs bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 rounded-full px-3 py-1">{name}</span>
                ))}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default InvestorPage;
