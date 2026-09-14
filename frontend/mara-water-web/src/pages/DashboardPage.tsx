import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  ResponsiveContainer, BarChart, Bar, LineChart, Line,
  XAxis, YAxis, CartesianGrid, Tooltip,
} from 'recharts';
import { useAuth } from '../contexts/AuthContext';
import { api } from '../services/api';
import {
  Droplets,
  Package,
  Truck,
  TrendingUp,
  CheckCircle,
  DollarSign,
  Users,
  AlertCircle,
  AlertTriangle,
  UserPlus,
  Loader2,
} from 'lucide-react';

interface Overview {
  today: {
    production_liters: number;
    sales_amount: number;
    orders: number;
    qa_tests: number;
    qa_pass_rate: number | null;
    staff_present: number;
  };
  this_month: {
    revenue: number;
    orders: number;
    batches: number;
    water_tests: number;
  };
  snapshot: {
    low_stock_items: number;
    debtor_balance_outstanding: number;
    pending_orders: number;
    active_vehicles: number;
    total_vehicles: number;
  };
  trends: {
    sales_7d: { date: string; amount: string; orders: number }[];
    production_liters_7d: { date: string; liters: string }[];
    fuel_cost_7d: { date: string; cost: string; liters: string }[];
  };
  alerts: { type: string; severity: 'high' | 'medium' | 'low'; message: string; route: string }[];
  recent_activity: { type: string; message: string; at: string; route: string }[];
}

// One place for severity -> color, so alerts here and status badges
// elsewhere in the app can share the same convention.
const SEVERITY_STYLES: Record<string, { border: string; bg: string; text: string; icon: string }> = {
  high: { border: 'border-l-red-500', bg: 'bg-red-50', text: 'text-red-800', icon: 'text-red-500' },
  medium: { border: 'border-l-amber-500', bg: 'bg-amber-50', text: 'text-amber-800', icon: 'text-amber-500' },
  low: { border: 'border-l-green-500', bg: 'bg-green-50', text: 'text-green-800', icon: 'text-green-500' },
};

const ACTIVITY_ICON: Record<string, React.ElementType> = {
  order: Truck,
  batch: Package,
  water_test: Droplets,
  invoice: DollarSign,
};

const money = (n: number) => `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
const dayLabel = (iso: string) => new Date(iso).toLocaleDateString(undefined, { weekday: 'short' });

const DashboardPage: React.FC = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [currentTime, setCurrentTime] = useState(new Date());
  const [data, setData] = useState<Overview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const timer = setInterval(() => setCurrentTime(new Date()), 1000);
    return () => clearInterval(timer);
  }, []);

  useEffect(() => {
    api.get('/dashboard/overview')
      .then((res) => setData(res.data.data))
      .catch(() => setError('Could not load dashboard data.'))
      .finally(() => setLoading(false));
  }, []);

  const quickActions = [
    { name: 'Log a Sale', description: 'Create a customer order', icon: Truck, color: 'bg-purple-600 hover:bg-purple-700', route: '/sales?tab=orders' },
    { name: 'Add a Customer', description: 'Register a new customer', icon: UserPlus, color: 'bg-blue-600 hover:bg-blue-700', route: '/sales?tab=customers' },
    { name: 'Add Stock Movement', description: 'Log stock in/out', icon: Package, color: 'bg-green-600 hover:bg-green-700', route: '/inventory' },
    { name: 'New Water Test', description: 'Record QA parameters', icon: Droplets, color: 'bg-cyan-600 hover:bg-cyan-700', route: '/qa' },
    { name: 'Record Payment', description: 'Mark an invoice paid', icon: DollarSign, color: 'bg-orange-600 hover:bg-orange-700', route: '/finance' },
    { name: 'Staff Attendance', description: 'Record today’s attendance', icon: Users, color: 'bg-pink-600 hover:bg-pink-700', route: '/hr' },
  ];

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="h-8 w-8 text-blue-600 animate-spin" />
      </div>
    );
  }

  const d = data;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="bg-white rounded-xl shadow-sm border border-gray-200 px-4 py-5 sm:px-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-gray-900">
            Welcome back, {user?.first_name}
          </h1>
          <p className="text-sm text-gray-600 mt-0.5">Here's what's happening at MARA-WATER today</p>
        </div>
        <div className="text-left sm:text-right">
          <div className="text-lg font-semibold text-gray-900">{currentTime.toLocaleTimeString()}</div>
          <div className="text-xs text-gray-500">{currentTime.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</div>
        </div>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">{error}</div>
      )}

      {d && (
        <>
          {/* Today -- the numbers that matter most, first */}
          <div>
            <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Today</h2>
            <div className="grid grid-cols-2 lg:grid-cols-5 gap-4">
              <KpiCard label="Sales Today" value={money(d.today.sales_amount)} icon={TrendingUp} color="from-purple-500 to-purple-600" onClick={() => navigate('/sales?tab=orders')} />
              <KpiCard label="Litres Produced" value={`${d.today.production_liters.toLocaleString()} L`} icon={Droplets} color="from-blue-500 to-blue-600" onClick={() => navigate('/production')} />
              <KpiCard
                label="Stock Alerts"
                value={String(d.snapshot.low_stock_items)}
                icon={AlertTriangle}
                color={d.snapshot.low_stock_items > 0 ? 'from-red-500 to-red-600' : 'from-green-500 to-green-600'}
                onClick={() => navigate('/inventory')}
              />
              <KpiCard label="Debtor Balance" value={money(d.snapshot.debtor_balance_outstanding)} icon={DollarSign} color="from-amber-500 to-amber-600" onClick={() => navigate('/finance')} />
              <KpiCard
                label="QA Pass Rate"
                value={d.today.qa_pass_rate === null ? 'No tests yet' : `${d.today.qa_pass_rate}%`}
                icon={CheckCircle}
                color="from-teal-500 to-teal-600"
                onClick={() => navigate('/qa')}
              />
            </div>
          </div>

          {/* Operational snapshot -- everything the previous dashboard surfaced, kept */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <KpiCard label="Pending Orders" value={String(d.snapshot.pending_orders)} icon={Package} color="from-orange-500 to-orange-600" onClick={() => navigate('/sales?tab=orders')} compact />
            <KpiCard label="Active Vehicles" value={`${d.snapshot.active_vehicles} / ${d.snapshot.total_vehicles}`} icon={Truck} color="from-indigo-500 to-indigo-600" onClick={() => navigate('/fleet')} compact />
            <KpiCard label="Staff Present Today" value={String(d.today.staff_present)} icon={Users} color="from-pink-500 to-pink-600" onClick={() => navigate('/hr')} compact />
            <KpiCard label="Orders This Month" value={String(d.this_month.orders)} icon={TrendingUp} color="from-slate-500 to-slate-600" onClick={() => navigate('/sales?tab=orders')} compact />
          </div>

          {/* Trends */}
          <div>
            <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Last 7 Days</h2>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
              <ChartCard title="Sales">
                {d.trends.sales_7d.length === 0 ? (
                  <EmptyChart label="No sales in the last 7 days" />
                ) : (
                  <ResponsiveContainer width="100%" height={200}>
                    <BarChart data={d.trends.sales_7d.map(r => ({ ...r, day: dayLabel(r.date), amount: Number(r.amount) }))}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                      <XAxis dataKey="day" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                      <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={40} />
                      <Tooltip formatter={(v: number) => money(v)} />
                      <Bar dataKey="amount" fill="#7c3aed" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </ChartCard>

              <ChartCard title="Production (Litres)">
                {d.trends.production_liters_7d.length === 0 ? (
                  <EmptyChart label="No completed packaging runs in the last 7 days" />
                ) : (
                  <ResponsiveContainer width="100%" height={200}>
                    <LineChart data={d.trends.production_liters_7d.map(r => ({ ...r, day: dayLabel(r.date), liters: Number(r.liters) }))}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                      <XAxis dataKey="day" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                      <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={40} />
                      <Tooltip formatter={(v: number) => `${v} L`} />
                      <Line type="monotone" dataKey="liters" stroke="#2563eb" strokeWidth={2} dot={{ r: 3 }} />
                    </LineChart>
                  </ResponsiveContainer>
                )}
              </ChartCard>

              <ChartCard title="Fleet Fuel Cost">
                {d.trends.fuel_cost_7d.length === 0 ? (
                  <EmptyChart label="No fuel logged yet" />
                ) : (
                  <ResponsiveContainer width="100%" height={200}>
                    <BarChart data={d.trends.fuel_cost_7d.map(r => ({ ...r, day: dayLabel(r.date), cost: Number(r.cost) }))}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                      <XAxis dataKey="day" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                      <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={40} />
                      <Tooltip formatter={(v: number) => money(v)} />
                      <Bar dataKey="cost" fill="#ea580c" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </ChartCard>
            </div>
          </div>

          {/* Alerts + Recent Activity */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div className="lg:col-span-1 bg-white rounded-xl shadow-sm border border-gray-200">
              <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 className="text-lg font-semibold text-gray-900">Alerts</h3>
                {d.alerts.filter(a => a.severity === 'high').length > 0 && (
                  <span className="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                    {d.alerts.filter(a => a.severity === 'high').length} High Priority
                  </span>
                )}
              </div>
              <div className="p-4 space-y-3 max-h-96 overflow-y-auto">
                {d.alerts.length === 0 ? (
                  <p className="text-sm text-gray-500 px-2 py-4 text-center">Nothing needs attention right now.</p>
                ) : (
                  d.alerts.map((alert, i) => {
                    const style = SEVERITY_STYLES[alert.severity] || SEVERITY_STYLES.low;
                    return (
                      <button
                        key={i}
                        onClick={() => navigate(alert.route)}
                        className={`w-full text-left border-l-4 p-3 rounded-r-lg ${style.border} ${style.bg} hover:brightness-95 transition`}
                      >
                        <div className="flex items-start">
                          <AlertCircle className={`h-4 w-4 mt-0.5 flex-shrink-0 ${style.icon}`} />
                          <p className={`ml-2 text-sm font-medium ${style.text}`}>{alert.message}</p>
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            </div>

            <div className="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200">
              <div className="px-6 py-4 border-b border-gray-200">
                <h3 className="text-lg font-semibold text-gray-900">Recent Activity</h3>
              </div>
              <div className="p-4 space-y-2 max-h-96 overflow-y-auto">
                {d.recent_activity.length === 0 ? (
                  <p className="text-sm text-gray-500 px-2 py-4 text-center">No activity yet.</p>
                ) : (
                  d.recent_activity.map((activity, i) => {
                    const Icon = ACTIVITY_ICON[activity.type] || Package;
                    return (
                      <button
                        key={i}
                        onClick={() => navigate(activity.route)}
                        className="w-full text-left flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-50 transition"
                      >
                        <div className="h-8 w-8 flex-shrink-0 bg-blue-100 rounded-full flex items-center justify-center">
                          <Icon className="h-4 w-4 text-blue-600" />
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-sm text-gray-900 truncate">{activity.message}</p>
                          <p className="text-xs text-gray-500">{new Date(activity.at).toLocaleString()}</p>
                        </div>
                      </button>
                    );
                  })
                )}
              </div>
            </div>
          </div>

          {/* Quick Actions */}
          <div className="bg-white rounded-xl shadow-sm border border-gray-200">
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-semibold text-gray-900">Quick Actions</h3>
            </div>
            <div className="p-4 sm:p-6">
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {quickActions.map((action) => {
                  const Icon = action.icon;
                  return (
                    <button
                      key={action.name}
                      onClick={() => navigate(action.route)}
                      className={`${action.color} text-white p-4 rounded-lg text-left transition-transform duration-150 hover:scale-[1.02]`}
                    >
                      <div className="flex items-center">
                        <Icon className="h-6 w-6 mr-3 flex-shrink-0" />
                        <div>
                          <div className="font-medium">{action.name}</div>
                          <div className="text-sm opacity-90">{action.description}</div>
                        </div>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
};

const KpiCard: React.FC<{
  label: string; value: string; icon: React.ElementType; color: string; onClick?: () => void; compact?: boolean;
}> = ({ label, value, icon: Icon, color, onClick, compact }) => (
  <button
    onClick={onClick}
    className={`text-left bg-white rounded-xl shadow-sm border border-gray-200 hover:shadow-md transition-shadow ${compact ? 'p-4' : 'p-4 sm:p-5'}`}
  >
    <div className={`bg-gradient-to-r ${color} rounded-lg p-2.5 inline-flex mb-3`}>
      <Icon className="h-5 w-5 text-white" />
    </div>
    <p className="text-xs font-medium text-gray-500">{label}</p>
    <p className={`font-bold text-gray-900 mt-0.5 ${compact ? 'text-lg' : 'text-xl sm:text-2xl'}`}>{value}</p>
  </button>
);

const ChartCard: React.FC<{ title: string; children: React.ReactNode }> = ({ title, children }) => (
  <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
    <h4 className="text-sm font-semibold text-gray-700 mb-2">{title}</h4>
    {children}
  </div>
);

const EmptyChart: React.FC<{ label: string }> = ({ label }) => (
  <div className="h-[200px] flex items-center justify-center text-center px-4">
    <p className="text-sm text-gray-400">{label}</p>
  </div>
);

export default DashboardPage;
