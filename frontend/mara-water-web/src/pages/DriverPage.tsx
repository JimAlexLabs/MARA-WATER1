import React, { useCallback, useEffect, useState } from 'react';
import { Clock, LogIn, LogOut, AlertTriangle } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 2 Phase 11 / Round 3 Phase 2 / Round 4: a driver's own dashboard
// -- "no visibility into other drivers, finance, or HR." Round 4 splits
// what used to be one long single-page dashboard into proper sidebar-
// navigable pages (Dashboard / Trips / Sales / Issues), matching the
// Manager/Director sidebar pattern instead of one page with everything
// stacked on it. This file is now just the overview: attendance
// (personal + shared Team Check In/Out) and read-only analytics. Trip
// work lives on DriverTripsPage, sales history on DriverSalesPage,
// issue reporting on DriverIssuesPage -- each its own route.

interface Summary {
  today: { clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null };
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

// Round 4 Phase 9: shared Driver/Sales/Field-Work dashboard roster.
interface FieldTeamMember {
  id: string; full_name: string; role_code: string | null; role_name: string | null;
  clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null;
}
const ROLE_SECTION_LABEL: Record<string, string> = { DRV: 'Driver', SALES: 'Sales', FIELDWORK: 'Field Work / Marketing' };

const DriverPage: React.FC = () => {
  const { user } = useAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [clockLoading, setClockLoading] = useState(false);

  const fetchSummary = useCallback(() => {
    api.get('/driver/summary').then(res => setSummary(res.data.data)).catch(() => toast.error('Failed to load your summary')).finally(() => setLoading(false));
  }, []);
  useEffect(() => { fetchSummary(); }, [fetchSummary]);

  const [analytics, setAnalytics] = useState<DriverAnalytics | null>(null);
  const fetchAnalytics = useCallback(() => {
    api.get('/driver/analytics').then(res => setAnalytics(res.data.data)).catch(() => {});
  }, []);
  useEffect(() => { fetchAnalytics(); }, [fetchAnalytics]);

  const handleClockIn = async () => {
    setClockLoading(true);
    try {
      await api.post('/hr/attendance/clock-in', { user_id: user?.id });
      toast.success('Clocked in');
      fetchSummary();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to clock in');
    } finally {
      setClockLoading(false);
    }
  };

  const handleClockOut = async () => {
    setClockLoading(true);
    try {
      const res = await api.post('/hr/attendance/clock-out', { user_id: user?.id });
      toast.success(`Clocked out -- ${res.data.data?.total_hours ?? ''}h worked`);
      fetchSummary();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to clock out');
    } finally {
      setClockLoading(false);
    }
  };

  // Round 4 Phase 9: the shared Driver/Sales/Field-Work dashboard --
  // independent Check In/Check Out per person, names driven by who the
  // Director assigned to those roles in HR (never freely typed).
  const [fieldTeam, setFieldTeam] = useState<FieldTeamMember[]>([]);
  const [teamActionLoading, setTeamActionLoading] = useState<string | null>(null);
  const fetchFieldTeam = useCallback(() => {
    api.get('/hr/field-team').then(res => setFieldTeam(res.data.data)).catch(() => {});
  }, []);
  useEffect(() => { fetchFieldTeam(); }, [fetchFieldTeam]);

  const teamClockIn = async (memberId: string) => {
    setTeamActionLoading(memberId);
    try {
      await api.post('/hr/attendance/clock-in', { user_id: memberId });
      toast.success('Checked in');
      fetchFieldTeam();
      if (memberId === user?.id) fetchSummary();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to check in');
    } finally {
      setTeamActionLoading(null);
    }
  };

  const teamClockOut = async (memberId: string) => {
    setTeamActionLoading(memberId);
    try {
      const res = await api.post('/hr/attendance/clock-out', { user_id: memberId });
      toast.success(`Checked out -- ${res.data.data?.total_hours ?? ''}h worked`);
      fetchFieldTeam();
      if (memberId === user?.id) fetchSummary();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to check out');
    } finally {
      setTeamActionLoading(null);
    }
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
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Welcome, {user?.first_name}</h1>
        <p className="text-gray-600 dark:text-gray-400">Your overview -- trips are under "Trips", sales history under "Sales".</p>
      </div>

      {/* Clock in/out */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div className="flex items-center justify-between flex-wrap gap-4">
          <div className="flex items-center">
            <Clock className="w-8 h-8 text-blue-600 mr-3" />
            <div>
              <p className="text-sm text-gray-500 dark:text-gray-400">Today</p>
              <p className="text-gray-900 dark:text-gray-100 font-medium">
                {summary?.today.clocked_in ? `Clocked in at ${summary.today.clock_in_time}` : 'Not clocked in yet'}
                {summary?.today.clocked_out ? ` · Clocked out at ${summary.today.clock_out_time}` : ''}
              </p>
            </div>
          </div>
          <div className="flex gap-3">
            <button onClick={handleClockIn} disabled={clockLoading || !!summary?.today.clocked_in}
              className="flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">
              <LogIn className="w-4 h-4 mr-2" /> Clock In
            </button>
            <button onClick={handleClockOut} disabled={clockLoading || !summary?.today.clocked_in || !!summary?.today.clocked_out}
              className="flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50">
              <LogOut className="w-4 h-4 mr-2" /> Clock Out
            </button>
          </div>
        </div>
      </div>

      {/* Round 4 Phase 9: shared Driver/Sales/Field-Work dashboard --
          independent Check In/Check Out per person on this trip. Names
          come only from who the Director has assigned to these roles in
          HR; nothing here is freely typed. */}
      {fieldTeam.length > 0 && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-1">Team Check In / Check Out</h3>
          <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">One shared dashboard, three independent check-in/checkout controls -- each person on this trip checks in/out here, whether or not they're the one logged in.</p>
          <div className="space-y-4">
            {(['DRV', 'SALES', 'FIELDWORK'] as const).filter(code => fieldTeam.some(m => m.role_code === code)).map(code => (
              <div key={code}>
                <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{ROLE_SECTION_LABEL[code]}</h4>
                <div className="space-y-2">
                  {fieldTeam.filter(m => m.role_code === code).map(m => (
                    <div key={m.id} className="flex items-center justify-between flex-wrap gap-2 bg-gray-50 dark:bg-gray-900 rounded-lg px-4 py-3">
                      <div>
                        <p className="font-medium text-gray-900 dark:text-gray-100">{m.full_name}{m.id === user?.id ? ' (you)' : ''}</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                          {m.clocked_in ? `Checked in at ${m.clock_in_time}` : 'Not checked in yet'}
                          {m.clocked_out ? ` · Checked out at ${m.clock_out_time}` : ''}
                        </p>
                      </div>
                      <div className="flex gap-2">
                        <button onClick={() => teamClockIn(m.id)} disabled={teamActionLoading === m.id || m.clocked_in}
                          className="flex items-center text-sm px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50">
                          <LogIn className="w-3.5 h-3.5 mr-1" /> Check In
                        </button>
                        <button onClick={() => teamClockOut(m.id)} disabled={teamActionLoading === m.id || !m.clocked_in || m.clocked_out}
                          className="flex items-center text-sm px-3 py-1.5 bg-gray-600 text-white rounded-md hover:bg-gray-700 disabled:opacity-50">
                          <LogOut className="w-3.5 h-3.5 mr-1" /> Check Out
                        </button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

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
