import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Ops brief §2.5: driver/sales exec operational tool — KPIs, sell-through,
// fuel, repairs, customer base, trackable discrepancies/issues.

interface Summary {
  this_month: { trips: number; km_covered: number; total_collected: number };
}

interface DriverAnalytics {
  sales: { this_week: { kes: number; bales: number }; this_month: { kes: number; bales: number } };
  new_customers: { count: number };
  qty_by_brand_size: {
    current_trip: { sku_id: string; name: string; brand: string | null; qty_carried_bales: number }[];
    cumulative: { sku_id: string; name: string; brand: string | null; qty_sold: string }[];
  };
  debt: { outstanding: number; overdue: number; overdue_count: number };
}

interface OpsKpis {
  this_month: {
    km_covered: number;
    bales_dispatched: number;
    bales_sold: number;
    bales_returned: number;
    sell_through_pct: number | null;
    fuel_cost: number;
    repair_cost: number;
  };
  active_trip: {
    id: string;
    km_covered: number | null;
    bales_dispatched: number;
    bales_sold: number;
    bales_returned: number;
    sell_through_pct: number | null;
    fuel_cost: number;
  } | null;
  repairs: { id: string; repair_date: string; category: string; description: string; cost: number }[];
  flagged_trips: { id: string; trip_date: string; route: string | null; status: string; has_discrepancy: boolean }[];
  open_issues: { id: string; subject: string; status: string }[];
  discrepancies: { id: string; date: string; category: string; status: string; description?: string }[];
}

interface CustomerRow {
  id: string; name: string; phone: string | null; type: string; address: string | null; created_by_me: boolean;
}

const money = (n: number) => `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const DriverPage: React.FC = () => {
  const { user } = useAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [analytics, setAnalytics] = useState<DriverAnalytics | null>(null);
  const [ops, setOps] = useState<OpsKpis | null>(null);
  const [customers, setCustomers] = useState<CustomerRow[]>([]);

  const fetchSummary = useCallback(() => {
    api.get('/driver/summary').then(res => setSummary(res.data.data)).catch(() => toast.error('Failed to load your summary')).finally(() => setLoading(false));
  }, []);
  useEffect(() => { fetchSummary(); }, [fetchSummary]);

  useEffect(() => {
    api.get('/driver/analytics').then(res => setAnalytics(res.data.data)).catch(() => {});
    api.get('/driver/ops-kpis').then(res => setOps(res.data.data)).catch(() => {});
    api.get('/driver/customers', { params: { limit: 30 } }).then(res => setCustomers(res.data.data)).catch(() => {});
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Welcome, {user?.first_name}</h1>
        <p className="text-gray-600 dark:text-gray-400">Your operational overview — trips, sell-through, fuel, repairs, and customer base.</p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Trips This Month</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{summary?.this_month.trips ?? 0}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">KM Covered (month)</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{(ops?.this_month.km_covered ?? summary?.this_month.km_covered ?? 0).toLocaleString()}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Collected</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{money(summary?.this_month.total_collected ?? 0)}</p>
        </div>
      </div>

      {ops && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Sell-through &amp; costs</h3>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
              <p className="text-xs text-gray-500">Dispatched</p>
              <p className="text-lg font-bold">{ops.this_month.bales_dispatched}</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
              <p className="text-xs text-gray-500">Sold</p>
              <p className="text-lg font-bold">{ops.this_month.bales_sold}</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
              <p className="text-xs text-gray-500">Returned (D−S)</p>
              <p className="text-lg font-bold">{ops.this_month.bales_returned}</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
              <p className="text-xs text-gray-500">Sell-through</p>
              <p className="text-lg font-bold">{ops.this_month.sell_through_pct != null ? `${ops.this_month.sell_through_pct}%` : '—'}</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
              <p className="text-xs text-gray-500">Fuel (month)</p>
              <p className="text-lg font-bold">{money(ops.this_month.fuel_cost)}</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
              <p className="text-xs text-gray-500">Repairs (month)</p>
              <p className="text-lg font-bold">{money(ops.this_month.repair_cost)}</p>
            </div>
          </div>

          {ops.active_trip && (
            <div className="mb-4 p-3 border border-blue-200 dark:border-blue-800 rounded-lg bg-blue-50/50 dark:bg-blue-900/20">
              <p className="text-sm font-medium text-blue-900 dark:text-blue-200 mb-2">Active trip</p>
              <div className="flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-300">
                <span>KM: {ops.active_trip.km_covered ?? '—'}</span>
                <span>Dispatched: {ops.active_trip.bales_dispatched}</span>
                <span>Sold: {ops.active_trip.bales_sold}</span>
                <span>Returned: {ops.active_trip.bales_returned}</span>
                <span>Sell-through: {ops.active_trip.sell_through_pct != null ? `${ops.active_trip.sell_through_pct}%` : '—'}</span>
                <span>Fuel: {money(ops.active_trip.fuel_cost)}</span>
                <Link to="/driver/trips" className="text-blue-600 underline">Open trip</Link>
              </div>
            </div>
          )}

          {ops.repairs.length > 0 && (
            <div className="mb-4">
              <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Vehicle repairs</h4>
              <ul className="text-sm space-y-1">
                {ops.repairs.slice(0, 5).map(r => (
                  <li key={r.id} className="flex justify-between gap-2 text-gray-700 dark:text-gray-300">
                    <span>{r.repair_date} · {r.category} · {r.description}</span>
                    <span className="font-medium whitespace-nowrap">{money(r.cost)}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {(ops.flagged_trips.length > 0 || ops.discrepancies.length > 0 || ops.open_issues.length > 0) && (
            <div>
              <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1">
                <AlertTriangle className="w-4 h-4 text-amber-500" /> Issues &amp; discrepancies
              </h4>
              <ul className="text-sm space-y-1 text-gray-700 dark:text-gray-300">
                {ops.discrepancies.map(d => (
                  <li key={d.id}>Discrepancy · {d.date} · {d.category} · {d.status}</li>
                ))}
                {ops.flagged_trips.map(t => (
                  <li key={t.id}>Flagged trip · {t.trip_date} · {t.route || '—'}</li>
                ))}
                {ops.open_issues.map(i => (
                  <li key={i.id}>
                    Issue · {i.subject} · {i.status}
                    {' · '}<Link to="/driver/issues" className="text-blue-600 underline">View</Link>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}

      {analytics && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">My Analytics</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Sales This Week</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{money(analytics.sales.this_week.kes)}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">{analytics.sales.this_week.bales} bales net dispatched</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Sales This Month</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{money(analytics.sales.this_month.kes)}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">{analytics.sales.this_month.bales} bales net dispatched</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">New Customers Registered</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{analytics.new_customers.count}</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Debt Sales Outstanding</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{money(analytics.debt.outstanding)}</p>
              {analytics.debt.overdue_count > 0 && (
                <p className="text-xs text-red-600 flex items-center"><AlertTriangle className="w-3 h-3 mr-1" />{analytics.debt.overdue_count} overdue · {money(analytics.debt.overdue)}</p>
              )}
            </div>
          </div>

          {analytics.qty_by_brand_size.current_trip.length > 0 && (
            <div className="mb-4">
              <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">This Trip — Dispatched by Item</h4>
              <div className="flex flex-wrap gap-2">
                {analytics.qty_by_brand_size.current_trip.map(i => (
                  <span key={i.sku_id} className="text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 rounded-full px-3 py-1">
                    {i.name}: {i.qty_carried_bales} bales
                  </span>
                ))}
              </div>
            </div>
          )}

          {analytics.qty_by_brand_size.cumulative.length > 0 && (
            <div>
              <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Cumulative Quantity Sold, by Item</h4>
              <div className="flex flex-wrap gap-2">
                {analytics.qty_by_brand_size.cumulative.map(i => (
                  <span key={i.sku_id} className="text-xs bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-full px-3 py-1">
                    {i.name}: {i.qty_sold}
                  </span>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Customer base</h3>
          <span className="text-xs text-gray-500">{customers.length} shown</span>
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400 mb-3">Customers you registered or sold to — for repeat sales and route planning.</p>
        {customers.length === 0 ? (
          <p className="text-sm text-gray-400">No customers linked to you yet. Add them when logging a sale.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-gray-500 uppercase">
                  <th className="py-2 pr-3">Name</th>
                  <th className="py-2 pr-3">Phone</th>
                  <th className="py-2 pr-3">Type</th>
                  <th className="py-2">Address</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                {customers.map(c => (
                  <tr key={c.id}>
                    <td className="py-2 pr-3 text-gray-900 dark:text-gray-100">{c.name}</td>
                    <td className="py-2 pr-3">{c.phone || '—'}</td>
                    <td className="py-2 pr-3">{c.type}</td>
                    <td className="py-2 text-gray-600 dark:text-gray-400">{c.address || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};

export default DriverPage;
