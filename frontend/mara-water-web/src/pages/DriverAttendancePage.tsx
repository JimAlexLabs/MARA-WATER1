import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Clock, LogIn, LogOut } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Daily check-in for the field phone. The logged-in person is the
// primary action. Teammates stay below, grouped by role, so Driver and
// Sales Executive are never mixed into one anonymous control.

interface Summary {
  today: { clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null };
}

interface FieldTeamMember {
  id: string; full_name: string; role_code: string | null; role_name: string | null;
  clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null;
}

const ROLE_ORDER = ['DRV', 'SALES', 'FIELDWORK'] as const;
const ROLE_LABEL: Record<string, string> = {
  DRV: 'Drivers',
  SALES: 'Sales',
  FIELDWORK: 'Field work',
};

const statusLine = (m: { clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null }) => {
  if (m.clocked_out) return `Done · in ${m.clock_in_time} · out ${m.clock_out_time}`;
  if (m.clocked_in) return `In since ${m.clock_in_time}`;
  return 'Not in yet';
};

const DriverAttendancePage: React.FC = () => {
  const { user } = useAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [clockLoading, setClockLoading] = useState(false);
  const [fieldTeam, setFieldTeam] = useState<FieldTeamMember[]>([]);
  const [teamActionLoading, setTeamActionLoading] = useState<string | null>(null);

  const fetchSummary = useCallback(() => {
    api.get('/driver/summary').then(res => setSummary(res.data.data)).catch(() => toast.error('Failed to load your attendance')).finally(() => setLoading(false));
  }, []);
  const fetchFieldTeam = useCallback(() => {
    api.get('/hr/field-team').then(res => setFieldTeam(res.data.data)).catch(() => {});
  }, []);
  useEffect(() => { fetchSummary(); fetchFieldTeam(); }, [fetchSummary, fetchFieldTeam]);

  const meToday = useMemo(() => {
    const fromTeam = fieldTeam.find(m => m.id === user?.id);
    if (fromTeam) return fromTeam;
    return {
      id: user?.id || '',
      full_name: [user?.first_name, user?.last_name].filter(Boolean).join(' ') || 'You',
      role_code: null,
      role_name: null,
      clocked_in: !!summary?.today.clocked_in,
      clocked_out: !!summary?.today.clocked_out,
      clock_in_time: summary?.today.clock_in_time ?? null,
      clock_out_time: summary?.today.clock_out_time ?? null,
    };
  }, [fieldTeam, summary, user]);

  const teammates = fieldTeam.filter(m => m.id !== user?.id);
  const rolesPresent = ROLE_ORDER.filter(code => teammates.some(m => m.role_code === code));

  const clockIn = async (memberId: string, isSelf: boolean) => {
    if (isSelf) setClockLoading(true); else setTeamActionLoading(memberId);
    try {
      await api.post('/hr/attendance/clock-in', { user_id: memberId });
      toast.success('Checked in');
      fetchFieldTeam();
      if (isSelf || memberId === user?.id) fetchSummary();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to check in');
    } finally {
      setClockLoading(false);
      setTeamActionLoading(null);
    }
  };

  const clockOut = async (memberId: string, isSelf: boolean) => {
    if (isSelf) setClockLoading(true); else setTeamActionLoading(memberId);
    try {
      const res = await api.post('/hr/attendance/clock-out', { user_id: memberId });
      toast.success(`Checked out · ${res.data.data?.total_hours ?? ''}h`);
      fetchFieldTeam();
      if (isSelf || memberId === user?.id) fetchSummary();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to check out');
    } finally {
      setClockLoading(false);
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

  const selfBusy = clockLoading || teamActionLoading === meToday.id;
  const selfDone = meToday.clocked_out;
  const selfIn = meToday.clocked_in && !meToday.clocked_out;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Check in</h1>
        <p className="text-gray-600 dark:text-gray-400">One tap for you. Then check in anyone else on the trip.</p>
      </div>

      <div className={`rounded-2xl p-5 shadow ${selfDone ? 'bg-gray-100 dark:bg-gray-800' : selfIn ? 'bg-green-50 dark:bg-green-950/40 border-2 border-green-600' : 'bg-white dark:bg-gray-800 border-2 border-blue-600'}`}>
        <div className="flex items-center gap-3 mb-4">
          <Clock className={`w-8 h-8 ${selfIn ? 'text-green-600' : 'text-blue-600'}`} />
          <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">You</p>
            <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{meToday.full_name}</p>
            <p className="text-base font-medium text-gray-700 dark:text-gray-300">{statusLine(meToday)}</p>
          </div>
        </div>
        {!selfDone && !selfIn && (
          <button type="button" onClick={() => clockIn(meToday.id, true)} disabled={selfBusy || !meToday.id}
            className="w-full min-h-16 rounded-2xl bg-green-600 text-white text-xl font-bold disabled:opacity-50 flex items-center justify-center gap-2">
            <LogIn className="w-6 h-6" /> Check in now
          </button>
        )}
        {selfIn && (
          <button type="button" onClick={() => clockOut(meToday.id, true)} disabled={selfBusy}
            className="w-full min-h-16 rounded-2xl bg-gray-800 text-white text-xl font-bold disabled:opacity-50 flex items-center justify-center gap-2">
            <LogOut className="w-6 h-6" /> Check out
          </button>
        )}
        {selfDone && (
          <p className="text-center text-base font-semibold text-gray-600 dark:text-gray-300 py-2">Finished for today</p>
        )}
      </div>

      {teammates.length > 0 && (
        <div className="space-y-4">
          <h2 className="text-lg font-bold text-gray-900 dark:text-gray-100">Rest of the team</h2>
          {rolesPresent.map(code => (
            <div key={code} className="space-y-2">
              <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-500">{ROLE_LABEL[code]}</h3>
              {teammates.filter(m => m.role_code === code).map(m => {
                const busy = teamActionLoading === m.id;
                const done = m.clocked_out;
                const inn = m.clocked_in && !m.clocked_out;
                return (
                  <div key={m.id} className="rounded-2xl bg-white dark:bg-gray-800 shadow p-4">
                    <div className="mb-3">
                      <p className="text-lg font-bold text-gray-900 dark:text-gray-100">{m.full_name}</p>
                      <p className="text-sm text-gray-500">{statusLine(m)}</p>
                    </div>
                    {!done && !inn && (
                      <button type="button" onClick={() => clockIn(m.id, false)} disabled={busy}
                        className="w-full min-h-12 rounded-xl bg-green-600 text-white text-base font-semibold disabled:opacity-50">
                        Check in
                      </button>
                    )}
                    {inn && (
                      <button type="button" onClick={() => clockOut(m.id, false)} disabled={busy}
                        className="w-full min-h-12 rounded-xl bg-gray-700 text-white text-base font-semibold disabled:opacity-50">
                        Check out
                      </button>
                    )}
                    {done && (
                      <p className="text-sm font-medium text-gray-500 text-center">Done</p>
                    )}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      )}

      {fieldTeam.length === 0 && (
        <p className="text-sm text-gray-500 dark:text-gray-400">No teammates listed yet. Your Director assigns Driver, Sales, and Field Work under Users.</p>
      )}
    </div>
  );
};

export default DriverAttendancePage;
