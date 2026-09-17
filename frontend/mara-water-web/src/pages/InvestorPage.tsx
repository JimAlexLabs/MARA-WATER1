import React, { useEffect, useState } from 'react';
import {
  ResponsiveContainer, LineChart, Line, CartesianGrid, XAxis, YAxis, Tooltip,
} from 'recharts';
import { DollarSign, Factory, Wallet, AlertTriangle } from 'lucide-react';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 2 Phase 11: the Investor role's own dashboard -- "a daily
// performance summary ... explicitly not line-level detail like
// individual salaries, individual debtor names, or petty cash line
// items. Build this as a distinct, deliberately limited dashboard, not
// the full app with buttons hidden." This page has no navigation to any
// operational section at all (see Layout.tsx) -- there is nothing to
// click through to, not just something hidden.

interface Summary {
  today: { revenue: number; production_liters: number };
  this_month: { revenue: number; production_liters: number; cash_collected: number; mpesa_collected: number };
  sales_trend: { date: string; revenue: number }[];
  financial_health: { cash_and_mpesa_collected_month: number; outstanding_debt_total: number };
}

const money = (n: number) => `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const InvestorPage: React.FC = () => {
  const { user } = useAuth();
  const [data, setData] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get('/investor/summary')
      .then(res => setData(res.data.data))
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

  const trend = data.sales_trend.map(p => ({ ...p, label: new Date(p.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) }));

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

      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Sales Trend (last 30 days)</h3>
        {trend.length === 0 ? (
          <div className="h-[220px] flex items-center justify-center text-sm text-gray-400 dark:text-gray-500">No sales in the last 30 days</div>
        ) : (
          <ResponsiveContainer width="100%" height={220}>
            <LineChart data={trend}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
              <XAxis dataKey="label" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
              <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={60} />
              <Tooltip formatter={(v: number) => money(v)} />
              <Line type="monotone" dataKey="revenue" stroke="#7c3aed" strokeWidth={2} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        )}
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
    </div>
  );
};

export default InvestorPage;
