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

const ReportsPage: React.FC = () => {
  const [dashboardStats, setDashboardStats] = useState<DashboardStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [dateRange, setDateRange] = useState('30');
  const [showSalesReport, setShowSalesReport] = useState(false);
  const [salesReportLoading, setSalesReportLoading] = useState(false);
  const [byOutlet, setByOutlet] = useState<SalesBreakdownRow[]>([]);
  const [bySku, setBySku] = useState<SalesBreakdownRow[]>([]);

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
          <h1 className="text-2xl font-bold text-gray-900">Analytics & Reports</h1>
          <p className="text-gray-600">Comprehensive business insights and performance metrics</p>
        </div>
        <div className="flex space-x-3">
          <select
            value={dateRange}
            onChange={(e) => setDateRange(e.target.value)}
            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
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
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <DollarSign className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Revenue</p>
              <p className="text-2xl font-bold text-gray-900">
                KES {dashboardStats?.total_revenue?.toLocaleString() || '0'}
              </p>
              <div className="flex items-center text-sm text-green-600">
                <TrendingUp className="w-4 h-4 mr-1" />
                +12.5%
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Package className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Orders</p>
              <p className="text-2xl font-bold text-gray-900">
                {dashboardStats?.total_orders?.toLocaleString() || '0'}
              </p>
              <div className="flex items-center text-sm text-blue-600">
                <TrendingUp className="w-4 h-4 mr-1" />
                +8.2%
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Users className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Customers</p>
              <p className="text-2xl font-bold text-gray-900">
                {dashboardStats?.total_customers?.toLocaleString() || '0'}
              </p>
              <div className="flex items-center text-sm text-purple-600">
                <TrendingUp className="w-4 h-4 mr-1" />
                +15.3%
              </div>
            </div>
          </div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Truck className="w-8 h-8 text-orange-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Active Vehicles</p>
              <p className="text-2xl font-bold text-gray-900">
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
        <div className="bg-white p-6 rounded-lg shadow">
          <h3 className="text-lg font-medium text-gray-900 mb-4">Production Efficiency</h3>
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
                <span className="text-sm font-medium text-gray-600">
                  {dashboardStats?.production_efficiency || 0}%
                </span>
              </div>
            </div>
          </div>
          <p className="text-sm text-gray-600 mt-2">
            Target: 95% | Current: {dashboardStats?.production_efficiency || 0}%
          </p>
        </div>

        <div className="bg-white p-6 rounded-lg shadow">
          <h3 className="text-lg font-medium text-gray-900 mb-4">Water Quality Score</h3>
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
                <span className="text-sm font-medium text-gray-600">
                  {dashboardStats?.water_quality_score || 0}
                </span>
              </div>
            </div>
          </div>
          <p className="text-sm text-gray-600 mt-2">
            Target: 98+ | Current: {dashboardStats?.water_quality_score || 0}
          </p>
        </div>
      </div>

      {/* Additional Metrics */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white p-6 rounded-lg shadow">
          <h3 className="text-lg font-medium text-gray-900 mb-4">Inventory Turnover</h3>
          <div className="space-y-4">
            <div className="flex justify-between items-center">
              <span className="text-sm text-gray-600">Current Ratio</span>
              <span className="text-sm font-medium text-gray-900">
                {dashboardStats?.inventory_turnover || 0}
              </span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
              <div 
                className="bg-blue-600 h-2 rounded-full" 
                style={{ width: `${Math.min((dashboardStats?.inventory_turnover || 0) * 10, 100)}%` }}
              ></div>
            </div>
            <p className="text-sm text-gray-600">
              Industry average: 8.5 | Target: 10+
            </p>
          </div>
        </div>

        <div className="bg-white p-6 rounded-lg shadow">
          <h3 className="text-lg font-medium text-gray-900 mb-4">Employee Productivity</h3>
          <div className="space-y-4">
            <div className="flex justify-between items-center">
              <span className="text-sm text-gray-600">Efficiency Score</span>
              <span className="text-sm font-medium text-gray-900">
                {dashboardStats?.employee_productivity || 0}%
              </span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
              <div 
                className="bg-green-600 h-2 rounded-full" 
                style={{ width: `${dashboardStats?.employee_productivity || 0}%` }}
              ></div>
            </div>
            <p className="text-sm text-gray-600">
              Target: 85% | Current: {dashboardStats?.employee_productivity || 0}%
            </p>
          </div>
        </div>
      </div>

      {/* Quick Actions */}
      <div className="bg-white p-6 rounded-lg shadow">
        <h3 className="text-lg font-medium text-gray-900 mb-4">Quick Reports</h3>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <button onClick={toggleSalesReport} className="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <BarChart3 className="w-6 h-6 text-blue-600 mr-3" />
            <div className="text-left">
              <div className="font-medium text-gray-900">Sales Report</div>
              <div className="text-sm text-gray-600">By outlet & product, dispatched vs returned vs net</div>
            </div>
          </button>

          <button className="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <Package className="w-6 h-6 text-green-600 mr-3" />
            <div className="text-left">
              <div className="font-medium text-gray-900">Production Report</div>
              <div className="text-sm text-gray-600">Production efficiency metrics</div>
            </div>
          </button>
          
          <button className="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
            <Truck className="w-6 h-6 text-orange-600 mr-3" />
            <div className="text-left">
              <div className="font-medium text-gray-900">Fleet Report</div>
              <div className="text-sm text-gray-600">Vehicle performance & maintenance</div>
            </div>
          </button>
        </div>
      </div>

      {/* Sales Report -- replaces the 31-tabs-per-month-per-outlet Excel
          pattern with a live report, grouped by outlet and by product. */}
      {showSalesReport && (
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-lg font-medium text-gray-900">Sales Report — last {dateRange} days</h3>
            <button onClick={() => setShowSalesReport(false)} className="text-sm text-gray-500 hover:text-gray-700">Hide</button>
          </div>
          {salesReportLoading ? (
            <div className="flex justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
          ) : (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div>
                <h4 className="text-sm font-semibold text-gray-700 mb-2">By Outlet / Branch</h4>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-gray-500 uppercase border-b">
                        <th className="py-2">Outlet</th>
                        <th className="py-2">Dispatched</th>
                        <th className="py-2">Returned</th>
                        <th className="py-2">Net Sold</th>
                        <th className="py-2 text-right">Revenue (KES)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {byOutlet.length === 0 && (
                        <tr><td colSpan={5} className="py-3 text-gray-400">No sales in this period</td></tr>
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
                <h4 className="text-sm font-semibold text-gray-700 mb-2">By Product (Brand & Size)</h4>
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs text-gray-500 uppercase border-b">
                        <th className="py-2">Product</th>
                        <th className="py-2">Dispatched</th>
                        <th className="py-2">Returned</th>
                        <th className="py-2">Net Sold</th>
                        <th className="py-2 text-right">Revenue (KES)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                      {bySku.length === 0 && (
                        <tr><td colSpan={5} className="py-3 text-gray-400">No sales in this period</td></tr>
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
    </div>
  );
};

export default ReportsPage;
