import React, { useCallback, useEffect, useState } from 'react';
import { Clock, LogIn, LogOut } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 4 Phase 9: its own sidebar page -- "Check In / Check Out" --
// pulled out of the Dashboard so it isn't buried, and explicitly NOT
// one generic control for whoever's logged in: the shared Driver/Sales/
// Field-Work dashboard needs three independent check-in/checkout
// controls, one per person actually on the trip, each with their own
// time-in/time-out. Names are driven only by who the Director has
// assigned to the Driver, Sales, and Field Work roles in HR -- never
// freely typed here.

interface Summary {
  today: { clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null };
}

interface FieldTeamMember {
  id: string; full_name: string; role_code: string | null; role_name: string | null;
  clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null;
}
const ROLE_SECTION_LABEL: Record<string, string> = { DRV: 'Driver', SALES: 'Sales', FIELDWORK: 'Field Work / Marketing' };

const DriverAttendancePage: React.FC = () => {
  const { user } = useAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [clockLoading, setClockLoading] = useState(false);

  const fetchSummary = useCallback(() => {
    api.get('/driver/summary').then(res => setSummary(res.data.data)).catch(() => toast.error('Failed to load your attendance')).finally(() => setLoading(false));
  }, []);
  useEffect(() => { fetchSummary(); }, [fetchSummary]);

  const [fieldTeam, setFieldTeam] = useState<FieldTeamMember[]>([]);
  const [teamActionLoading, setTeamActionLoading] = useState<string | null>(null);
  const fetchFieldTeam = useCallback(() => {
    api.get('/hr/field-team').then(res => setFieldTeam(res.data.data)).catch(() => {});
  }, []);
  useEffect(() => { fetchFieldTeam(); }, [fetchFieldTeam]);

  const handleClockIn = async () => {
    setClockLoading(true);
    try {
      await api.post('/hr/attendance/clock-in', { user_id: user?.id });
      toast.success('Clocked in');
      fetchSummary();
      fetchFieldTeam();
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
      fetchFieldTeam();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to clock out');
    } finally {
      setClockLoading(false);
    }
  };

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
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Check In / Check Out</h1>
        <p className="text-gray-600 dark:text-gray-400">Your own attendance, plus independent controls for whoever else is on this trip -- Driver, Sales, and Field Work each have their own row, not one shared control.</p>
      </div>

      {/* Your own quick clock in/out */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div className="flex items-center justify-between flex-wrap gap-4">
          <div className="flex items-center">
            <Clock className="w-8 h-8 text-blue-600 mr-3" />
            <div>
              <p className="text-sm text-gray-500 dark:text-gray-400">Today (you)</p>
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

      {/* Round 4 Phase 9: three independent Check In/Check Out
          controls -- one per person on this trip, never one generic
          control standing in for everyone. */}
      {fieldTeam.length > 0 ? (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-1">Team Check In / Check Out</h3>
          <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">One shared dashboard, three independent check-in/checkout controls -- each person checks themselves in/out here, whether or not they're the one logged in.</p>
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
      ) : (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <p className="text-sm text-gray-500 dark:text-gray-400">No Driver, Sales, or Field Work team members are assigned yet -- your Director assigns these roles under Users/HR.</p>
        </div>
      )}
    </div>
  );
};

export default DriverAttendancePage;
