import React, { useState, useEffect } from 'react';
import { 
  BarChart3, 
  TrendingUp, 
  TrendingDown, 
  DollarSign,
  Package,
  Users,
  Truck,
  Download,
  Calendar,
  Filter
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

interface DashboardStats {
  total_revenue: number;
  total_orders: number;
  total_customers: number;
  total_vehicles: number;
  production_efficiency: number;
  water_quality_score: number;
  inventory_turnover: number;
  employee_productivity: number;
}

interface SalesBreakdownRow {
  warehouse_id?: string;
  sku_id?: string;
  warehouse?: { name: string; code: string };
  sku?: { name: string; code: string };
  qty_dispatched: number;
  qty_returned: number;
  qty_net_sold: number;
  revenue: number;
}

interface ReconciliationRow {
  sku_id: string;
  sku: { code: string; name: string; brand: string | null };
  opening_qty: number;
  produced_qty: number;
  issued_qty: number;
  returned_qty: number;
  closing_qty: number;
  closing_value: number;
}

interface MaterialUsageRow {
  material_id: string;
  material: { code: string; name: string; category: string; uom: string };
  opening_balance: number;
  received: number;
  used: number;
  closing_balance: number;
  below_min_level: boolean;
}

interface RefillProductionRow { sku_id: string; sku: { name: string; size_liters: string }; qty_produced: number; }
interface RefillSalesRow { sku_id: string; sku: { name: string; size_liters: string }; qty_dispatched: number; qty_returned: number; qty_net_sold: number; }
interface RefillsSummary { sku_id: string; name: string; qty_produced: number; qty_dispatched: number; qty_returned: number; qty_net_sold: number; }

const ReportsPage: React.FC = () => {
  const [dashboardStats, setDashboardStats] = useState<DashboardStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [dateRange, setDateRange] = useState('30');
  const [showSalesReport, setShowSalesReport] = useState(false);
  const [salesReportLoading, setSalesReportLoading] = useState(false);
  const [byOutlet, setByOutlet] = useState<SalesBreakdownRow[]>([]);
  const [bySku, setBySku] = useState<SalesBreakdownRow[]>([]);
  const [showProductionReport, setShowProductionReport] = useState(false);
  const [productionReportLoading, setProductionReportLoading] = useState(false);
  const [reconciliation, setReconciliation] = useState<ReconciliationRow[]>([]);
  const [materialsUsage, setMaterialsUsage] = useState<MaterialUsageRow[]>([]);
  const [refills, setRefills] = useState<RefillsSummary[]>([]);

  useEffect(() => {
    fetchData();
    if (showSalesReport) fetchSalesReport();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dateRange]);

  const fetchData = async () => {
    try {
      setLoading(true);
      const response = await api.get(`/reports/dashboard?days=${dateRange}`);
      setDashboardStats(response.data.data);
    } catch (error) {
      toast.error('Failed to fetch dashboard data');
    } finally {
      setLoading(false);
    }
  };

  const fetchSalesReport = async () => {
    try {
      setSalesReportLoading(true);
      const dateTo = new Date().toISOString().slice(0, 10);
      const dateFrom = new Date(Date.now() - Number(dateRange) * 86400000).toISOString().slice(0, 10);
      const response = await api.get(`/reports/sales?date_from=${dateFrom}&date_to=${dateTo}`);
      setByOutlet(response.data.data.by_outlet || []);
      setBySku(response.data.data.by_sku || []);
    } catch (error) {
      toast.error('Failed to fetch sales report');
    } finally {
      setSalesReportLoading(false);
    }
  };

  const toggleSalesReport = () => {
    const next = !showSalesReport;
    setShowSalesReport(next);
    if (next && byOutlet.length === 0 && bySku.length === 0) fetchSalesReport();
  };

  const fetchProductionReport = async () => {
    try {
      setProductionReportLoading(true);
      const dateTo = new Date().toISOString().slice(0, 10);
      const dateFrom = new Date(Date.now() - Number(dateRange) * 86400000).toISOString().slice(0, 10);
      const [reconRes, usageRes, refillsRes] = await Promise.all([
        api.get(`/inventory/reconciliation?date_from=${dateFrom}&date_to=${dateTo}`),
        api.get(`/inventory/materials-usage?date_from=${dateFrom}&date_to=${dateTo}`),
        api.get(`/inventory/refills?date_from=${dateFrom}&date_to=${dateTo}`),
      ]);
      setReconciliation(reconRes.data.data.items || []);
      setMaterialsUsage(usageRes.data.data.materials || []);

      // Refills tracking (spec: "daily refill quantities by size ... a
      // filtered slice of the same production/sales data") -- collapsed
      // to one row per size for this period, rather than a day-by-day
      // grid, to match the reconciliation/materials-usage tables above.
      const production: RefillProductionRow[] = refillsRes.data.data.production_by_day || [];
      const sales: RefillSalesRow[] = refillsRes.data.data.sales_by_day || [];
      const bySkuId = new Map<string, RefillsSummary>();
      production.forEach(r => {
        const row = bySkuId.get(r.sku_id) || { sku_id: r.sku_id, name: r.sku?.name || 'Refill', qty_produced: 0, qty_dispatched: 0, qty_returned: 0, qty_net_sold: 0 };
        row.qty_produced += r.qty_produced;
        bySkuId.set(r.sku_id, row);
      });
      sales.forEach(r => {
        const row = bySkuId.get(r.sku_id) || { sku_id: r.sku_id, name: r.sku?.name || 'Refill', qty_produced: 0, qty_dispatched: 0, qty_returned: 0, qty_net_sold: 0 };
        row.qty_dispatched += r.qty_dispatched;
        row.qty_returned += r.qty_returned;
        row.qty_net_sold += r.qty_net_sold;
        bySkuId.set(r.sku_id, row);
      });
      setRefills(Array.from(bySkuId.values()));
    } catch (error) {
      toast.error('Failed to fetch production report');
    } finally {
      setProductionReportLoading(false);
    }
  };

  const toggleProductionReport = () => {
    const next = !showProductionReport;
    setShowProductionReport(next);
    if (next && reconciliation.length === 0 && materialsUsage.length === 0) fetchProductionReport();
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Analytics & Reports</h1>
          <p className="text-gray-600 dark:text-gray-400">Comprehensive business insights and performance metrics</p>
        </div>
        <div className="flex space-x-3">
          <select
            value={dateRange}
            onChange={(e) => setDateRange(e.target.value)}
            className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          >
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="90">Last 90 days</option>
            <option value="365">Last year</option>
          </select>
          <button className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <Download className="w-4 h-4 mr-2" />
            Export Report
          </button>
        </div>
      </div>

      {/* Key Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <DollarSign className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Revenue</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                KES {dashboardStats?.total_revenue?.toLocaleString() || '0'}
              </p>
              <div className="flex items-center text-sm text-green-600">
                <TrendingUp className="w-4 h-4 mr-1" />
                +12.5%
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Package className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Orders</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {dashboardStats?.total_orders?.toLocaleString() || '0'}
              </p>
              <div className="flex items-center text-sm text-blue-600">
                <TrendingUp className="w-4 h-4 mr-1" />
                +8.2%
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Users className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Customers</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {dashboardStats?.total_customers?.toLocaleString() || '0'}
              </p>
              <div className="flex items-center text-sm text-purple-600">
                <TrendingUp className="w-4 h-4 mr-1" />
                +15.3%
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Truck className="w-8 h-8 text-orange-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Active Vehicles</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {dashboardStats?.total_vehicles || '0'}
              </p>
              <div className="flex items-center text-sm text-orange-600">
                <TrendingUp className="w-4 h-4 mr-1" />
                +5.7%
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Performance Metrics */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Production Efficiency</h3>
          <div className="flex items-center justify-between">
            <div className="text-3xl font-bold text-blue-600">
              {dashboardStats?.production_efficiency || 0}%
            </div>
            <div className="w-24 h-24 relative">
              <svg className="w-24 h-24 transform -rotate-90" viewBox="0 0 36 36">
                <path
                  className="text-gray-200"
                  stroke="currentColor"
                  strokeWidth="3"
                  fill="none"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
                <path
                  className="text-blue-600"
                  stroke="currentColor"
                  strokeWidth="3"
                  strokeDasharray={`${dashboardStats?.production_efficiency || 0}, 100`}
                  strokeLinecap="round"
                  fill="none"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
              </svg>
              <div className="absolute inset-0 flex items-center justify-center">
                <span className="text-sm font-medium text-gray-600 dark:text-gray-400">
                  {dashboardStats?.production_efficiency || 0}%
                </span>
              </div>
            </div>
          </div>
          <p className="text-sm text-gray-600 dark:text-gray-400 mt-2">
            Target: 95% | Current: {dashboardStats?.production_efficiency || 0}%
          </p>
        </div>

        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Water Quality Score</h3>
          <div className="flex items-center justify-between">
            <div className="text-3xl font-bold text-green-600">
              {dashboardStats?.water_quality_score || 0}/100
            </div>
            <div className="w-24 h-24 relative">
              <svg className="w-24 h-24 transform -rotate-90" viewBox="0 0 36 36">
                <path
                  className="text-gray-200"
                  stroke="currentColor"
                  strokeWidth="3"
                  fill="none"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
                <path
                  className="text-green-600"
                  stroke="currentColor"
                  strokeWidth="3"
                  strokeDasharray={`${dashboardStats?.water_quality_score || 0}, 100`}
                  strokeLinecap="round"
                  fill="none"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
              </svg>
              <div className="absolute inset-0 flex items-center justify-center">
                <span className="text-sm font-medium text-gray-600 dark:text-gray-400">
                  {dashboardStats?.water_quality_score || 0}
                </span>
              </div>
            </div>
          </div>
          <p className="text-sm text-gray-600 dark:text-gray-400 mt-2">
            Target: 98+ | Current: {dashboardStats?.water_quality_score || 0}
          </p>
        </div>
      </div>

      {/* Additional Metrics */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Inventory Turnover</h3>
          <div className="space-y-4">
            <div className="flex justify-between items-center">
              <span className="text-sm text-gray-600 dark:text-gray-400">Current Ratio</span>
              <span className="text-sm font-medium text-gray-900 dark:text-gray-100">
                {dashboardStats?.inventory_turnover || 0}
              </span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
              <div 
                className="bg-blue-600 h-2 rounded-full" 
                style={{ width: `${Math.min((dashboardStats?.inventory_turnover || 0) * 10, 100)}%` }}
              ></div>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400">
              Industry average: 8.5 | Target: 10+
            </p>
          </div>
        </div>

        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Employee Productivity</h3>
          <div className="space-y-4">
            <div className="flex justify-between items-center">
              <span className="text-sm text-gray-600 dark:text-gray-400">Efficiency Score</span>
              <span className="text-sm font-medium text-gray-900 dark:text-gray-100">
                {dashboardStats?.employee_productivity || 0}%
              </span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
              <div 
                className="bg-green-600 h-2 rounded-full" 
                style={{ width: `${dashboardStats?.employee_productivity || 0}%` }}
              ></div>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400">
              Target: 85% | Current: {dashboardStats?.employee_productivity || 0}%
            </p>
          </div>
        </div>
      </div>

      {/* Quick Actions */}
      <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
        <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Quick Reports</h3>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <button onClick={toggleSalesReport} className="flex items-center p-4 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
            <BarChart3 className="w-6 h-6 text-blue-600 mr-3" />
            <div className="text-left">
              <div className="font-medium text-gray-900 dark:text-gray-100">Sales Report</div>
              <div className="text-sm text-gray-600 dark:text-gray-400">By outlet & product, dispatched vs returned vs net</div>
            </div>
          </button>

          <button onClick={toggleProductionReport} className="flex items-center p-4 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
            <Package className="w-6 h-6 text-green-600 mr-3" />
            <div className="text-left">
              <div className="font-medium text-gray-900 dark:text-gray-100">Production Report</div>
              <div className="text-sm text-gray-600 dark:text-gray-400">Stock reconciliation & raw materials usage</div>
            </div>
          </button>
          
          <button className="flex items-center p-4 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
            <Truck className="w-6 h-6 text-orange-600 mr-3" />
            <div className="text-left">
              <div className="font-medium text-gray-900 dark:text-gray-100">Fleet Report</div>
              <div className="text-sm text-gray-600 dark:text-gray-400">Vehicle performance & maintenance</div>
            </div>
          </button>
        </div>
      </div>

      {/* Sales Report -- replaces the 31-tabs-per-month-per-outlet Excel
          pattern with a live report, grouped by outlet and by product. */}
      {showSalesReport && (
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Sales Report — last {dateRange} days</h3>
            <button onClick={() => setShowSalesReport(false)} className="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Hide</button>
          </div>
          {salesReportLoading ? (
            <div className="flex justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div>
                <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">By Outlet / Branch</h4>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b">
                        <th className="py-2">Outlet</th>
                        <th className="py-2">Dispatched</th>
                        <th className="py-2">Returned</th>
                        <th className="py-2">Net Sold</th>
                        <th className="py-2 text-right">Revenue (KES)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {byOutlet.length === 0 && (
                        <tr><td colSpan={5} className="py-3 text-gray-400 dark:text-gray-500">No sales in this period</td></tr>
                      )}
                      {byOutlet.map((row, i) => (
                        <tr key={row.warehouse_id || i}>
                          <td className="py-2">{row.warehouse?.name || 'Unassigned'}</td>
                          <td className="py-2">{row.qty_dispatched}</td>
                          <td className="py-2">{row.qty_returned}</td>
                          <td className="py-2">{row.qty_net_sold}</td>
                          <td className="py-2 text-right font-medium">{row.revenue.toLocaleString()}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
              <div>
                <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">By Product (Brand & Size)</h4>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b">
                        <th className="py-2">Product</th>
                        <th className="py-2">Dispatched</th>
                        <th className="py-2">Returned</th>
                        <th className="py-2">Net Sold</th>
                        <th className="py-2 text-right">Revenue (KES)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {bySku.length === 0 && (
                        <tr><td colSpan={5} className="py-3 text-gray-400 dark:text-gray-500">No sales in this period</td></tr>
                      )}
                      {bySku.map((row, i) => (
                        <tr key={row.sku_id || i}>
                          <td className="py-2">{row.sku?.name || 'Unknown'}</td>
                          <td className="py-2">{row.qty_dispatched}</td>
                          <td className="py-2">{row.qty_returned}</td>
                          <td className="py-2">{row.qty_net_sold}</td>
                          <td className="py-2 text-right font-medium">{row.revenue.toLocaleString()}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Production Report -- stock reconciliation (opening/produced/
          issued/returned/closing, in qty and value) and raw materials
          usage (opening/received/used/closing), read straight from the
          movement ledger instead of a manually re-entered table. */}
      {showProductionReport && (
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Production Report — last {dateRange} days</h3>
            <button onClick={() => setShowProductionReport(false)} className="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">Hide</button>
          </div>
          {productionReportLoading ? (
            <div className="flex justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
          ) : (
            <div className="space-y-8">
              <div>
                <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Stock Reconciliation (Finished Goods)</h4>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b">
                        <th className="py-2">Product</th>
                        <th className="py-2">Opening</th>
                        <th className="py-2">Produced</th>
                        <th className="py-2">Issued</th>
                        <th className="py-2">Returned</th>
                        <th className="py-2">Closing</th>
                        <th className="py-2 text-right">Closing Value (KES)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {reconciliation.filter(r => r.produced_qty || r.issued_qty || r.returned_qty || r.opening_qty || r.closing_qty).length === 0 && (
                        <tr><td colSpan={7} className="py-3 text-gray-400 dark:text-gray-500">No production movement in this period</td></tr>
                      )}
                      {reconciliation.filter(r => r.produced_qty || r.issued_qty || r.returned_qty || r.opening_qty || r.closing_qty).map((row) => (
                        <tr key={row.sku_id}>
                          <td className="py-2">{row.sku?.brand ? `${row.sku.brand} — ` : ''}{row.sku?.name}</td>
                          <td className="py-2">{row.opening_qty}</td>
                          <td className="py-2">{row.produced_qty}</td>
                          <td className="py-2">{row.issued_qty}</td>
                          <td className="py-2">{row.returned_qty}</td>
                          <td className="py-2">{row.closing_qty}</td>
                          <td className="py-2 text-right font-medium">{row.closing_value.toLocaleString()}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              <div>
                <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Raw Materials Usage</h4>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b">
                        <th className="py-2">Material</th>
                        <th className="py-2">Opening</th>
                        <th className="py-2">Received</th>
                        <th className="py-2">Used</th>
                        <th className="py-2">Closing</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {materialsUsage.filter(m => m.received || m.used || m.opening_balance || m.closing_balance).length === 0 && (
                        <tr><td colSpan={5} className="py-3 text-gray-400 dark:text-gray-500">No material movement in this period</td></tr>
                      )}
                      {materialsUsage.filter(m => m.received || m.used || m.opening_balance || m.closing_balance).map((row) => (
                        <tr key={row.material_id}>
                          <td className="py-2">{row.material?.name}</td>
                          <td className="py-2">{row.opening_balance.toLocaleString()}</td>
                          <td className="py-2">{row.received.toLocaleString()}</td>
                          <td className="py-2">{row.used.toLocaleString()}</td>
                          <td className={`py-2 font-medium ${row.below_min_level ? 'text-red-600' : ''}`}>
                            {row.closing_balance.toLocaleString()}{row.below_min_level ? ' ⚠' : ''}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              <div>
                <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Refills Tracking</h4>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b">
                        <th className="py-2">Size</th>
                        <th className="py-2">Produced</th>
                        <th className="py-2">Dispatched</th>
                        <th className="py-2">Returned</th>
                        <th className="py-2">Net Sold</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {refills.length === 0 && (
                        <tr><td colSpan={5} className="py-3 text-gray-400 dark:text-gray-500">No refill activity in this period</td></tr>
                      )}
                      {refills.map((row) => (
                        <tr key={row.sku_id}>
                          <td className="py-2">{row.name}</td>
                          <td className="py-2">{row.qty_produced.toLocaleString()}</td>
                          <td className="py-2">{row.qty_dispatched.toLocaleString()}</td>
                          <td className="py-2">{row.qty_returned.toLocaleString()}</td>
                          <td className="py-2 font-medium">{row.qty_net_sold.toLocaleString()}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default ReportsPage;
