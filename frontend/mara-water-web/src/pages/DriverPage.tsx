import React, { useCallback, useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 2 Phase 11 / Round 3 Phase 2 / Round 4: a driver's own dashboard
// -- "no visibility into other drivers, finance, or HR." Round 4 splits
// what used to be one long single-page dashboard into proper sidebar-
// navigable pages (Dashboard / Trips / Check In-Out / Sales / Issues),
// matching the Manager/Director sidebar pattern instead of one page
// with everything stacked on it. This file is now purely the overview:
// this month's stats + read-only analytics. Attendance lives on
// DriverAttendancePage, trip work on DriverTripsPage, sales history on
// DriverSalesPage, issue reporting on DriverIssuesPage -- each its own
// route.

interface Summary {
  this_month: { trips: number; km_covered: number; total_collected: number };
}

// Round 3 Phase 4: driver/salesperson's own analytics.
interface DriverAnalytics {
  sales: { this_week: { kes: number; bales: number }; this_month: { kes: number; bales: number } };
  new_customers: { count: number };
  qty_by_brand_size: {
    current_trip: { sku_id: string; name: string; brand: string | null; qty_carried_bales: number }[];
    cumulative: { sku_id: string; name: string; brand: string | null; qty_sold: string }[];
  };
  debt: { outstanding: number; overdue: number; overdue_count: number };
}

const DriverPage: React.FC = () => {
  const { user } = useAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchSummary = useCallback(() => {
    api.get('/driver/summary').then(res => setSummary(res.data.data)).catch(() => toast.error('Failed to load your summary')).finally(() => setLoading(false));
  }, []);
  useEffect(() => { fetchSummary(); }, [fetchSummary]);

  const [analytics, setAnalytics] = useState<DriverAnalytics | null>(null);
  const fetchAnalytics = useCallback(() => {
    api.get('/driver/analytics').then(res => setAnalytics(res.data.data)).catch(() => {});
  }, []);
  useEffect(() => { fetchAnalytics(); }, [fetchAnalytics]);

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
        <p className="text-gray-600 dark:text-gray-400">Your overview -- attendance is under "Check In/Out", trips under "Trips", sales history under "Sales".</p>
      </div>

      {/* This month */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Trips This Month</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{summary?.this_month.trips ?? 0}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">KM Covered</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{(summary?.this_month.km_covered ?? 0).toLocaleString()}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Collected</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">KES {(summary?.this_month.total_collected ?? 0).toLocaleString()}</p>
        </div>
      </div>

      {/* Round 3 Phase 4: driver's own analytics -- read-only, scoped
          only to their own records (enforced server-side, same rule as
          everywhere else on this page). */}
      {analytics && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">My Analytics</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Sales This Week</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">KES {analytics.sales.this_week.kes.toLocaleString()}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">{analytics.sales.this_week.bales} bales net dispatched</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Sales This Month</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">KES {analytics.sales.this_month.kes.toLocaleString()}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">{analytics.sales.this_month.bales} bales net dispatched</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">New Customers Registered</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{analytics.new_customers.count}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">all time</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Debt Sales Outstanding</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">KES {analytics.debt.outstanding.toLocaleString()}</p>
              {analytics.debt.overdue_count > 0 && (
                <p className="text-xs text-red-600 flex items-center"><AlertTriangle className="w-3 h-3 mr-1" />{analytics.debt.overdue_count} overdue · KES {analytics.debt.overdue.toLocaleString()}</p>
              )}
            </div>
          </div>

          {analytics.qty_by_brand_size.current_trip.length > 0 && (
            <div className="mb-4">
              <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">This Trip -- Dispatched by Item</h4>
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
    </div>
  );
};

export default DriverPage;
