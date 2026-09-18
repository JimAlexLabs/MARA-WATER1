import React, { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip } from 'recharts';
import { ArrowLeft, Phone, MapPin, Building2 } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

// Round 3 Phase 3: "keeping an account with our customer" -- clicking
// any customer name anywhere in the app opens this profile: full
// purchase history, running debt balance, payment method breakdown, and
// a simple chart of purchases over time. Combines Order (counter/outlet
// sales) and DriverTripSale (on-the-road sales) via the same
// SalesRevenueService every other report already uses for "both revenue
// sources" -- this page doesn't introduce a third one.

interface Customer {
  id: string; code: string; name: string; contact_person: string | null;
  type: string; phone: string; email: string | null; address: string | null;
  route: { name: string } | null; debtor_balance: number; status: string;
}
interface Stats {
  lifetime_purchases: number; last_purchase_date: string | null;
  preferred_payment_method: string | null; payment_method_breakdown: Record<string, number>;
  order_count: number; trip_sale_count: number;
}
interface HistoryRow {
  source: 'order' | 'driver_trip'; id: string; date: string; reference: string;
  amount: number; payment_method: string;
}

const TYPE_LABELS: Record<string, string> = {
  retail: 'Retail Shop', wholesale: 'Distributor / Reseller', corporate: 'Institution',
  hotel_restaurant: 'Hotel / Restaurant', walk_in: 'Walk-in',
};

const CustomerDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [stats, setStats] = useState<Stats | null>(null);
  const [history, setHistory] = useState<HistoryRow[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!id) return;
    setLoading(true);
    Promise.all([
      api.get(`/sales/customers/${id}`),
      api.get(`/sales/customers/${id}/purchase-history`),
    ]).then(([customerRes, historyRes]) => {
      setCustomer(customerRes.data.data.customer);
      setStats(historyRes.data.data.stats);
      setHistory(historyRes.data.data.history);
    }).catch(() => toast.error('Failed to load customer'))
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  if (!customer) {
    return <p className="text-gray-500 dark:text-gray-400">Customer not found.</p>;
  }

  // Purchases-over-time: cumulative-by-date, oldest first, for the chart.
  const chartData = [...history].sort((a, b) => a.date.localeCompare(b.date)).map(h => ({ date: h.date, amount: h.amount }));

  return (
    <div className="space-y-6">
      <Link to="/sales?tab=customers" className="inline-flex items-center text-sm text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">
        <ArrowLeft className="w-4 h-4 mr-1" /> Back to Customers
      </Link>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{customer.name}</h1>
            <p className="text-gray-500 dark:text-gray-400">{customer.code} · {TYPE_LABELS[customer.type] || customer.type}</p>
          </div>
          <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${customer.status === 'inactive' ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' : 'bg-green-100 text-green-800'}`}>{customer.status || 'active'}</span>
        </div>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4 text-sm">
          <div className="flex items-center text-gray-700 dark:text-gray-300"><Phone className="w-4 h-4 mr-2 text-gray-400" />{customer.phone || '—'}</div>
          <div className="flex items-center text-gray-700 dark:text-gray-300"><MapPin className="w-4 h-4 mr-2 text-gray-400" />{customer.address || customer.route?.name || '—'}</div>
          <div className="flex items-center text-gray-700 dark:text-gray-300"><Building2 className="w-4 h-4 mr-2 text-gray-400" />{customer.contact_person || '—'}</div>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Lifetime Purchases</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">KES {(stats?.lifetime_purchases ?? 0).toLocaleString()}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Debt Outstanding</p>
          <p className={`text-2xl font-bold ${customer.debtor_balance > 0 ? 'text-red-600' : 'text-gray-900 dark:text-gray-100'}`}>KES {(customer.debtor_balance ?? 0).toLocaleString()}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Last Purchase</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{stats?.last_purchase_date ? new Date(stats.last_purchase_date).toLocaleDateString() : '—'}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Preferred Payment</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100 capitalize">{stats?.preferred_payment_method?.replace('_', ' ') || '—'}</p>
        </div>
      </div>

      {stats && Object.keys(stats.payment_method_breakdown).length > 0 && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Payment Method Breakdown (by number of sales)</h3>
          <div className="flex flex-wrap gap-4">
            {Object.entries(stats.payment_method_breakdown).map(([method, count]) => (
              <div key={method} className="bg-gray-50 dark:bg-gray-900 rounded-lg px-4 py-2">
                <p className="text-xs text-gray-500 dark:text-gray-400 capitalize">{method.replace('_', ' ')}</p>
                <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{count}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      {chartData.length > 1 && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Purchases Over Time</h3>
          <ResponsiveContainer width="100%" height={220}>
            <LineChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
              <XAxis dataKey="date" tick={{ fontSize: 10 }} />
              <YAxis tick={{ fontSize: 10 }} />
              <Tooltip formatter={(val: number) => `KES ${val.toLocaleString()}`} />
              <Line type="monotone" dataKey="amount" stroke="#4f46e5" strokeWidth={2} dot={{ r: 3 }} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      )}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700"><h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Purchase History</h3></div>
        <div className="p-6">
          {history.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">No purchases recorded yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Source</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Reference</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Payment</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {history.map(h => (
                    <tr key={`${h.source}-${h.id}`}>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{new Date(h.date).toLocaleDateString()}</td>
                      <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{h.source === 'order' ? 'Counter Sale' : 'Driver Trip'}</td>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{h.reference}</td>
                      <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400 capitalize">{h.payment_method?.replace('_', ' ')}</td>
                      <td className="px-4 py-2 text-sm font-medium text-gray-900 dark:text-gray-100">KES {h.amount.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default CustomerDetailPage;
