import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ResponsiveContainer, LineChart, Line, BarChart, Bar, PieChart, Pie, Cell,
  CartesianGrid, XAxis, YAxis, Tooltip, Legend,
} from 'recharts';
import {
  DollarSign, TrendingUp, TrendingDown, AlertTriangle, RefreshCw, Package, Truck, Users,
} from 'lucide-react';
import { api } from '../services/api';

// Round 2 Phase 9: a dedicated analytics view, separate from the Phase 2
// dashboard redesign. Every number here comes from a live query the
// moment the page loads or refreshes -- nothing cached -- and the page
// re-fetches on a short interval so it keeps reflecting what's actually
// happening, not a stale snapshot from when the page was opened.

interface SalesTrendPoint { period: string; orders_revenue: number; driver_trip_revenue: number; total_revenue: number; }
interface ByBrand { brand: string; qty_sold: number; revenue: number; }
interface ByOutlet { id: string; name: string; revenue: number; orders: number; }
interface ByRoute { route: string; revenue: number; }
interface SalesSection { trend: SalesTrendPoint[]; total_revenue: number; by_brand: ByBrand[]; by_outlet: ByOutlet[]; by_route: ByRoute[]; }

interface MoneyFlow {
  sold: { cash: number; mpesa: number; debt: number; total: number };
  collected: { cash: number; mpesa: number; total: number };
  outstanding_debt: { total_balance: number; overdue_count: number; overdue_total: number };
}

interface ProdVsSalesPoint { period: string; production_liters: number; sales_liters: number; }
interface ProdVsSalesByBrand { brand: string; produced_qty: number; sold_qty: number; variance: number; }
interface ProductionVsSales { trend: ProdVsSalesPoint[]; total_production_liters: number; total_sales_liters: number; by_brand: ProdVsSalesByBrand[]; }

interface TopDebtor { customer_id: string; customer: string; balance: number; }
interface Debtors { total_balance: number; total_debts: number; overdue_count: number; overdue_total: number; top_debtors: TopDebtor[]; }

interface PayrollTrendPoint { month: string; gross_pay: number; employer_nssf: number; total_cost: number; net_pay: number; employees: number; }
interface Payroll { trend: PayrollTrendPoint[]; latest_month_cost: number; }

interface AnalyticsOverview {
  period: { date_from: string; date_to: string; group_by: string };
  sales: SalesSection;
  money_flow: MoneyFlow;
  production_vs_sales: ProductionVsSales;
  debtors: Debtors;
  payroll: Payroll;
}

interface FleetStats {
  total_trips: number; total_km: number; total_fuel_liters: number; total_fuel_cost: number;
  total_collected: number; mismatched_trips: number; mismatched_variance_total: number;
}
interface MileageTrendVehicle {
  vehicle_id: string; reg_no: string; total_km: number; total_fuel_liters: number;
  trips: number; km_per_liter: number | null; daily: { date: string; km: number }[];
}

interface InventoryStats {
  stock_statistics: { total_items: number; sku_items: number; material_items: number; total_quantity: number; low_stock_items: number; out_of_stock_items: number };
  top_skus: { id: string; sku_id: string; qty: number; sku?: { name: string; brand: string | null } }[];
}
interface LowStockItem { id: string; qty: number; material?: { name: string }; sku?: { name: string; brand: string | null }; warehouse?: { name: string }; }

const money = (n: number) => `KES ${Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;

const periodLabel = (period: string, groupBy: string) => {
  if (groupBy === 'monthly') {
    const [y, m] = period.split('-');
    return new Date(Number(y), Number(m) - 1, 1).toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
  }
  if (groupBy === 'weekly' && period.length >= 6) {
    return `Wk ${period.slice(4)} '${period.slice(2, 4)}`;
  }
  const d = new Date(period);
  return isNaN(d.getTime()) ? period : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
};

const REFRESH_INTERVAL_MS = 60000;
const COLORS = { cash: '#16a34a', mpesa: '#2563eb', debt: '#dc2626' };
const BRAND_COLORS = ['#7c3aed', '#2563eb', '#16a34a', '#ea580c', '#db2777', '#0891b2'];

const AnalyticsPage: React.FC = () => {
  const today = new Date().toISOString().slice(0, 10);
  const thirtyDaysAgo = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);

  const [dateFrom, setDateFrom] = useState(thirtyDaysAgo);
  const [dateTo, setDateTo] = useState(today);
  const [groupBy, setGroupBy] = useState<'daily' | 'weekly' | 'monthly'>('daily');

  const [overview, setOverview] = useState<AnalyticsOverview | null>(null);
  const [fleetStats, setFleetStats] = useState<FleetStats | null>(null);
  const [mileageTrend, setMileageTrend] = useState<MileageTrendVehicle[]>([]);
  const [inventoryStats, setInventoryStats] = useState<InventoryStats | null>(null);
  const [lowStock, setLowStock] = useState<LowStockItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [lastUpdated, setLastUpdated] = useState<Date | null>(null);
  const [error, setError] = useState('');

  const fetchAll = useCallback(async (showSpinner: boolean) => {
    if (showSpinner) setLoading(true); else setRefreshing(true);
    try {
      const [overviewRes, fleetStatsRes, mileageRes, invStatsRes, lowStockRes] = await Promise.all([
        api.get('/analytics/overview', { params: { date_from: dateFrom, date_to: dateTo, group_by: groupBy } }),
        api.get('/fleet/trips/statistics', { params: { date_from: dateFrom, date_to: dateTo } }),
        api.get('/fleet/trips/mileage-trend', { params: { days: 30 } }),
        api.get('/inventory/statistics'),
        api.get('/inventory/low-stock', { params: { limit: 8 } }),
      ]);
      setOverview(overviewRes.data.data);
      setFleetStats(fleetStatsRes.data.data);
      setMileageTrend(mileageRes.data.data || []);
      setInventoryStats(invStatsRes.data.data);
      setLowStock(lowStockRes.data.data?.stock_items || []);
      setLastUpdated(new Date());
      setError('');
    } catch {
      setError('Could not load analytics data.');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateFrom, dateTo, groupBy]);

  useEffect(() => { fetchAll(true); }, [fetchAll]);

  // Refreshes on a short interval so this stays live without the user
  // having to reload the page -- the whole point of Phase 9.
  const fetchAllRef = useRef(fetchAll);
  fetchAllRef.current = fetchAll;
  useEffect(() => {
    const timer = setInterval(() => fetchAllRef.current(false), REFRESH_INTERVAL_MS);
    return () => clearInterval(timer);
  }, []);

  // Combine each vehicle's daily KM into one overall trend line.
  const combinedKmTrend = (() => {
    const byDate = new Map<string, number>();
    mileageTrend.forEach(v => v.daily.forEach(d => byDate.set(d.date, (byDate.get(d.date) || 0) + d.km)));
    return Array.from(byDate.entries()).sort(([a], [b]) => a.localeCompare(b)).map(([date, km]) => ({ date, km }));
  })();

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  if (error || !overview) {
    return <div className="text-center py-12 text-gray-500 dark:text-gray-400">{error || 'No data available.'}</div>;
  }

  const gb = overview.period.group_by;
  const salesTrendData = overview.sales.trend.map(p => ({ ...p, label: periodLabel(p.period, gb) }));
  const prodVsSalesData = overview.production_vs_sales.trend.map(p => ({ ...p, label: periodLabel(p.period, gb) }));
  const moneyFlowPie = [
    { name: 'Cash', value: overview.money_flow.sold.cash, color: COLORS.cash },
    { name: 'M-Pesa', value: overview.money_flow.sold.mpesa, color: COLORS.mpesa },
    { name: 'Debt', value: overview.money_flow.sold.debt, color: COLORS.debt },
  ].filter(s => s.value > 0);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col lg:flex-row lg:justify-between lg:items-center gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Analytics</h1>
          <p className="text-gray-600 dark:text-gray-400">
            Live figures across sales, money flow, production, inventory, debtors, fleet and payroll
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} max={dateTo}
            className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
          <span className="text-gray-400 dark:text-gray-500 text-sm">to</span>
          <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)} min={dateFrom} max={today}
            className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
          <select value={groupBy} onChange={e => setGroupBy(e.target.value as any)}
            className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
          </select>
          <button onClick={() => fetchAll(false)} disabled={refreshing}
            className="flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-sm hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
            <RefreshCw className={`w-4 h-4 mr-1.5 ${refreshing ? 'animate-spin' : ''}`} /> Refresh
          </button>
        </div>
      </div>
      {lastUpdated && (
        <p className="text-xs text-gray-400 dark:text-gray-500 -mt-4">
          Last updated {lastUpdated.toLocaleTimeString()} · refreshes automatically every minute
        </p>
      )}

      {/* KPI cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4">
        <KpiCard icon={<DollarSign className="w-6 h-6 text-violet-600" />} label="Revenue (period)" value={money(overview.sales.total_revenue)} />
        <KpiCard icon={<TrendingUp className="w-6 h-6 text-green-600" />} label="Cash + M-Pesa Collected" value={money(overview.money_flow.collected.total)} />
        <KpiCard icon={<AlertTriangle className="w-6 h-6 text-red-600" />} label="Outstanding Debt" value={money(overview.money_flow.outstanding_debt.total_balance)} sub={`${overview.money_flow.outstanding_debt.overdue_count} overdue`} />
        <KpiCard icon={<Package className="w-6 h-6 text-blue-600" />} label="Production (period)" value={`${overview.production_vs_sales.total_production_liters.toLocaleString()} L`} />
        <KpiCard icon={<Truck className="w-6 h-6 text-orange-600" />} label="Fleet KM (period)" value={(fleetStats?.total_km ?? 0).toLocaleString()} sub={`${fleetStats?.total_trips ?? 0} trips`} />
        <KpiCard icon={<Users className="w-6 h-6 text-indigo-600" />} label="Latest Payroll Cost" value={money(overview.payroll.latest_month_cost)} />
      </div>

      {/* Sales */}
      <SectionCard title="Sales Trend">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="lg:col-span-2">
            {salesTrendData.length === 0 ? <EmptyChart label="No sales in this period" /> : (
              <ResponsiveContainer width="100%" height={260}>
                <LineChart data={salesTrendData}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                  <XAxis dataKey="label" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                  <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={50} />
                  <Tooltip formatter={(v: number) => money(v)} />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Line type="monotone" dataKey="orders_revenue" name="Outlet/Direct Sales" stroke="#7c3aed" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey="driver_trip_revenue" name="Driver Trip Sales" stroke="#ea580c" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey="total_revenue" name="Total" stroke="#16a34a" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            )}
          </div>
          <div>
            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">By Brand</h5>
            {overview.sales.by_brand.length === 0 ? <EmptyChart label="No sales yet" /> : (
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={overview.sales.by_brand} layout="vertical" margin={{ left: 10 }}>
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="#eee" />
                  <XAxis type="number" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                  <YAxis type="category" dataKey="brand" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={80} />
                  <Tooltip formatter={(v: number) => money(v)} />
                  <Bar dataKey="revenue" radius={[0, 4, 4, 0]}>
                    {overview.sales.by_brand.map((_, i) => <Cell key={i} fill={BRAND_COLORS[i % BRAND_COLORS.length]} />)}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
          <MiniTable title="By Outlet" rows={overview.sales.by_outlet.map(o => ({ label: o.name, value: money(o.revenue), sub: `${o.orders} orders` }))} empty="No outlet sales in this period" />
          <MiniTable title="By Route (Driver Trips)" rows={overview.sales.by_route.map(r => ({ label: r.route, value: money(r.revenue) }))} empty="No driver trip sales in this period" />
        </div>
      </SectionCard>

      {/* Money flow */}
      <SectionCard title="Money Flow">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div>
            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Sold by Payment Method</h5>
            {moneyFlowPie.length === 0 ? <EmptyChart label="No sales in this period" /> : (
              <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                  <Pie data={moneyFlowPie} dataKey="value" nameKey="name" innerRadius={50} outerRadius={80} paddingAngle={2}>
                    {moneyFlowPie.map((s, i) => <Cell key={i} fill={s.color} />)}
                  </Pie>
                  <Tooltip formatter={(v: number) => money(v)} />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                </PieChart>
              </ResponsiveContainer>
            )}
          </div>
          <StatBlock title="Sold" rows={[
            { label: 'Cash', value: money(overview.money_flow.sold.cash) },
            { label: 'M-Pesa', value: money(overview.money_flow.sold.mpesa) },
            { label: 'Debt', value: money(overview.money_flow.sold.debt) },
            { label: 'Total', value: money(overview.money_flow.sold.total), bold: true },
          ]} />
          <StatBlock title="Collected vs. Outstanding" rows={[
            { label: 'Cash Collected', value: money(overview.money_flow.collected.cash) },
            { label: 'M-Pesa Collected', value: money(overview.money_flow.collected.mpesa) },
            { label: 'Outstanding Debt', value: money(overview.money_flow.outstanding_debt.total_balance), warn: true },
            { label: 'Overdue', value: `${overview.money_flow.outstanding_debt.overdue_count} (${money(overview.money_flow.outstanding_debt.overdue_total)})`, warn: overview.money_flow.outstanding_debt.overdue_count > 0 },
          ]} />
        </div>
      </SectionCard>

      {/* Production vs sales */}
      <SectionCard title="Production vs. Sales">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="lg:col-span-2">
            {prodVsSalesData.length === 0 ? <EmptyChart label="No production or sales in this period" /> : (
              <ResponsiveContainer width="100%" height={260}>
                <LineChart data={prodVsSalesData}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                  <XAxis dataKey="label" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
                  <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={50} />
                  <Tooltip formatter={(v: number) => `${v} L`} />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Line type="monotone" dataKey="production_liters" name="Produced (L)" stroke="#2563eb" strokeWidth={2} dot={false} />
                  <Line type="monotone" dataKey="sales_liters" name="Sold (L)" stroke="#16a34a" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            )}
          </div>
          <div>
            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">By Brand (units)</h5>
            {overview.production_vs_sales.by_brand.length === 0 ? <EmptyChart label="No data yet" /> : (
              <div className="space-y-2">
                {overview.production_vs_sales.by_brand.map(b => (
                  <div key={b.brand} className="text-sm">
                    <div className="flex justify-between text-gray-700 dark:text-gray-300">
                      <span>{b.brand}</span>
                      <span className={b.variance < 0 ? 'text-red-600' : 'text-gray-500 dark:text-gray-400'}>
                        {b.produced_qty.toLocaleString()} produced / {b.sold_qty.toLocaleString()} sold
                      </span>
                    </div>
                    {b.variance < 0 && (
                      <p className="text-xs text-red-600 flex items-center mt-0.5"><TrendingDown className="w-3 h-3 mr-1" />Selling {Math.abs(b.variance).toLocaleString()} more than produced this period</p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </SectionCard>

      {/* Inventory */}
      <SectionCard title="Inventory Levels">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="grid grid-cols-2 gap-3 content-start">
            <MiniStat label="Stock Items" value={inventoryStats?.stock_statistics.total_items ?? 0} />
            <MiniStat label="Low Stock" value={inventoryStats?.stock_statistics.low_stock_items ?? 0} warn={(inventoryStats?.stock_statistics.low_stock_items ?? 0) > 0} />
            <MiniStat label="Out of Stock" value={inventoryStats?.stock_statistics.out_of_stock_items ?? 0} warn={(inventoryStats?.stock_statistics.out_of_stock_items ?? 0) > 0} />
            <MiniStat label="Total Quantity" value={(inventoryStats?.stock_statistics.total_quantity ?? 0).toLocaleString()} />
          </div>
          <div>
            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Top SKUs on Hand</h5>
            {!inventoryStats || inventoryStats.top_skus.length === 0 ? <EmptyChart label="No stock recorded yet" /> : (
              <ResponsiveContainer width="100%" height={200}>
                <BarChart data={inventoryStats.top_skus.map(s => ({ name: s.sku?.name || 'SKU', qty: Number(s.qty) }))} layout="vertical" margin={{ left: 10 }}>
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="#eee" />
                  <XAxis type="number" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                  <YAxis type="category" dataKey="name" tick={{ fontSize: 10 }} axisLine={false} tickLine={false} width={90} />
                  <Tooltip />
                  <Bar dataKey="qty" fill="#2563eb" radius={[0, 4, 4, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
          <div>
            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Low Stock Alerts</h5>
            {lowStock.length === 0 ? (
              <p className="text-sm text-gray-400 dark:text-gray-500">Nothing below its reorder point.</p>
            ) : (
              <ul className="space-y-1.5 text-sm">
                {lowStock.map(item => (
                  <li key={item.id} className="flex justify-between text-gray-700 dark:text-gray-300">
                    <span className="truncate pr-2">{item.sku?.name || item.material?.name || 'Item'}</span>
                    <span className="text-red-600 font-medium whitespace-nowrap">{item.qty} left</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </SectionCard>

      {/* Debtors */}
      <SectionCard title="Debtor Balances">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="grid grid-cols-2 gap-3 content-start">
            <MiniStat label="Total Outstanding" value={money(overview.debtors.total_balance)} warn={overview.debtors.total_balance > 0} />
            <MiniStat label="Debtors" value={overview.debtors.total_debts} />
            <MiniStat label="Overdue" value={overview.debtors.overdue_count} warn={overview.debtors.overdue_count > 0} />
            <MiniStat label="Overdue Amount" value={money(overview.debtors.overdue_total)} warn={overview.debtors.overdue_total > 0} />
          </div>
          <div className="lg:col-span-2">
            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Top Debtors</h5>
            {overview.debtors.top_debtors.length === 0 ? (
              <p className="text-sm text-gray-400 dark:text-gray-500">No outstanding balances.</p>
            ) : (
              <ul className="space-y-1.5 text-sm">
                {overview.debtors.top_debtors.map(d => (
                  <li key={d.customer_id} className="flex justify-between text-gray-700 dark:text-gray-300">
                    <span>{d.customer}</span>
                    <span className="font-medium">{money(d.balance)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      </SectionCard>

      {/* Fleet */}
      <SectionCard title="Fleet Activity">
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div className="grid grid-cols-2 gap-3 content-start">
            <MiniStat label="Trips" value={fleetStats?.total_trips ?? 0} />
            <MiniStat label="KM Covered" value={(fleetStats?.total_km ?? 0).toLocaleString()} />
            <MiniStat label="Fuel Cost" value={money(fleetStats?.total_fuel_cost ?? 0)} />
            <MiniStat label="Reconciliation Mismatches" value={fleetStats?.mismatched_trips ?? 0} warn={(fleetStats?.mismatched_trips ?? 0) > 0} />
          </div>
          <div className="lg:col-span-2">
            <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">KM Covered (last 30 days, all vehicles)</h5>
            {combinedKmTrend.length === 0 ? <EmptyChart label="No trips logged in the last 30 days" /> : (
              <ResponsiveContainer width="100%" height={200}>
                <BarChart data={combinedKmTrend.map(d => ({ ...d, label: periodLabel(d.date, 'daily') }))}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} axisLine={false} tickLine={false} />
                  <YAxis tick={{ fontSize: 11 }} axisLine={false} tickLine={false} width={40} />
                  <Tooltip formatter={(v: number) => `${v} km`} />
                  <Bar dataKey="km" fill="#ea580c" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            )}
          </div>
        </div>
      </SectionCard>

      {/* Payroll */}
      <SectionCard title="Payroll Cost Trend (last 12 months, finalized runs)">
        {overview.payroll.trend.length === 0 ? <EmptyChart label="No finalized payroll runs yet" /> : (
          <ResponsiveContainer width="100%" height={240}>
            <BarChart data={overview.payroll.trend.map(p => ({ ...p, label: periodLabel(p.month + '-01', 'monthly') }))}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
              <XAxis dataKey="label" tick={{ fontSize: 12 }} axisLine={false} tickLine={false} />
              <YAxis tick={{ fontSize: 12 }} axisLine={false} tickLine={false} width={60} />
              <Tooltip formatter={(v: number) => money(v)} />
              <Legend wrapperStyle={{ fontSize: 12 }} />
              <Bar dataKey="total_cost" name="Total Cost (Gross + Employer NSSF)" fill="#4f46e5" radius={[4, 4, 0, 0]} />
              <Bar dataKey="net_pay" name="Net Pay" fill="#94a3b8" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        )}
      </SectionCard>
    </div>
  );
};

const SectionCard: React.FC<{ title: string; children: React.ReactNode }> = ({ title, children }) => (
  <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
    <h3 className="text-base font-semibold text-gray-900 dark:text-gray-100 mb-4">{title}</h3>
    {children}
  </div>
);

const EmptyChart: React.FC<{ label: string }> = ({ label }) => (
  <div className="h-[200px] flex items-center justify-center text-center px-4">
    <p className="text-sm text-gray-400 dark:text-gray-500">{label}</p>
  </div>
);

const KpiCard: React.FC<{ icon: React.ReactNode; label: string; value: string | number; sub?: string }> = ({ icon, label, value, sub }) => (
  <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
    <div className="flex items-center gap-3">
      {icon}
      <div className="min-w-0">
        <p className="text-xs text-gray-500 dark:text-gray-400 truncate">{label}</p>
        <p className="text-lg font-bold text-gray-900 dark:text-gray-100 truncate">{value}</p>
        {sub && <p className="text-xs text-gray-400 dark:text-gray-500">{sub}</p>}
      </div>
    </div>
  </div>
);

const MiniStat: React.FC<{ label: string; value: string | number; warn?: boolean }> = ({ label, value, warn }) => (
  <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
    <p className="text-xs text-gray-500 dark:text-gray-400">{label}</p>
    <p className={`text-base font-semibold ${warn ? 'text-red-600' : 'text-gray-900 dark:text-gray-100'}`}>{value}</p>
  </div>
);

const MiniTable: React.FC<{ title: string; rows: { label: string; value: string; sub?: string }[]; empty: string }> = ({ title, rows, empty }) => (
  <div>
    <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{title}</h5>
    {rows.length === 0 ? (
      <p className="text-sm text-gray-400 dark:text-gray-500">{empty}</p>
    ) : (
      <ul className="space-y-1.5 text-sm">
        {rows.map((r, i) => (
          <li key={i} className="flex justify-between text-gray-700 dark:text-gray-300">
            <span className="truncate pr-2">{r.label}{r.sub ? <span className="text-gray-400 dark:text-gray-500"> · {r.sub}</span> : null}</span>
            <span className="font-medium whitespace-nowrap">{r.value}</span>
          </li>
        ))}
      </ul>
    )}
  </div>
);

const StatBlock: React.FC<{ title: string; rows: { label: string; value: string; bold?: boolean; warn?: boolean }[] }> = ({ title, rows }) => (
  <div>
    <h5 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{title}</h5>
    <dl className="space-y-2 text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
      {rows.map((r, i) => (
        <div key={i} className="flex justify-between">
          <dt className="text-gray-500 dark:text-gray-400">{r.label}</dt>
          <dd className={`${r.bold ? 'font-bold' : 'font-medium'} ${r.warn ? 'text-red-600' : 'text-gray-900 dark:text-gray-100'}`}>{r.value}</dd>
        </div>
      ))}
    </dl>
  </div>
);

export default AnalyticsPage;
