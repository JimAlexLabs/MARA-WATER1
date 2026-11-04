import React, { useCallback, useEffect, useState } from 'react';
import { Clock, LogIn, LogOut } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { PageShell, PageHero } from '../components/PageShell';

// Independent check-in/out per field team member (e.g. Danish + Dickens).
// Multiple sessions per day are allowed — finishing a cycle never locks
// the person out; overnight open sessions close with an alert then allow
// check-in again.

interface FieldTeamMember {
  id: string;
  full_name: string;
  role_code: string | null;
  role_name: string | null;
  clocked_in: boolean;
  clocked_out: boolean;
  has_open_session?: boolean;
  overnight_open?: boolean;
  open_session_date?: string | null;
  clock_in_time: string | null;
  clock_out_time: string | null;
  sessions_today?: number;
}

const statusLine = (m: FieldTeamMember) => {
  const open = m.has_open_session ?? (m.clocked_in && !m.clocked_out);
  if (open) {
    if (m.overnight_open && m.open_session_date) {
      return `Still in from ${m.open_session_date} · since ${m.clock_in_time ?? '—'}`;
    }
    return `In since ${m.clock_in_time ?? '—'}${(m.sessions_today ?? 0) > 1 ? ` · session ${m.sessions_today} today` : ''}`;
  }
  if (m.clock_out_time) {
    return `Last out ${m.clock_out_time}${(m.sessions_today ?? 0) > 0 ? ` · ${m.sessions_today} session${(m.sessions_today ?? 0) === 1 ? '' : 's'} today` : ''} · ready to check in`;
  }
  return 'Not in yet';
};

const DriverAttendancePage: React.FC = () => {
  const [loading, setLoading] = useState(true);
  const [fieldTeam, setFieldTeam] = useState<FieldTeamMember[]>([]);
  const [teamActionLoading, setTeamActionLoading] = useState<string | null>(null);

  const fetchFieldTeam = useCallback(() => {
    return api.get('/hr/field-team')
      .then(res => setFieldTeam(res.data.data || []))
      .catch(() => toast.error('Failed to load team attendance'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { fetchFieldTeam(); }, [fetchFieldTeam]);

  const isOpen = (m: FieldTeamMember) =>
    m.has_open_session ?? (m.clocked_in && !m.clocked_out);

  const clockIn = async (memberId: string) => {
    setTeamActionLoading(memberId);
    try {
      await api.post('/hr/attendance/clock-in', { user_id: memberId });
      toast.success('Checked in');
      await fetchFieldTeam();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to check in');
      await fetchFieldTeam();
    } finally {
      setTeamActionLoading(null);
    }
  };

  const clockOut = async (memberId: string) => {
    setTeamActionLoading(memberId);
    try {
      const res = await api.post('/hr/attendance/clock-out', { user_id: memberId });
      const msg = res.data?.message || `Checked out · ${res.data.data?.total_hours ?? ''}h`;
      if (res.data.data?.overnight_closed) {
        toast(msg, { icon: '⚠️', duration: 5000 });
      } else {
        toast.success(msg);
      }
      await fetchFieldTeam();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to check out');
      await fetchFieldTeam();
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
    <PageShell>
      <PageHero
        eyebrow="Clock"
        title="Check in"
        description="Each person checks in and out on their own. Multiple times a day is fine — every event goes to admin."
        tone="ink"
      />

      {fieldTeam.length === 0 ? (
        <p className="text-sm text-gray-500 dark:text-gray-400">No teammates listed yet. Your Director assigns Driver, Sales, and Field Work under Users.</p>
      ) : (
        <div className="space-y-4">
          {fieldTeam.map(m => {
            const busy = teamActionLoading === m.id;
            const open = isOpen(m);
            return (
              <div
                key={m.id}
                className={`rounded-2xl p-5 shadow ${
                  open
                    ? m.overnight_open
                      ? 'bg-amber-50 dark:bg-amber-950/30 border-2 border-amber-500'
                      : 'bg-green-50 dark:bg-green-950/40 border-2 border-green-600'
                    : 'bg-white dark:bg-gray-800 border-2 border-blue-600'
                }`}
              >
                <div className="flex items-center gap-3 mb-4">
                  <Clock className={`w-8 h-8 ${open ? (m.overnight_open ? 'text-amber-600' : 'text-green-600') : 'text-blue-600'}`} />
                  <div>
                    <p className="text-sm text-gray-500 dark:text-gray-400">{m.role_name || m.role_code || 'Field'}</p>
                    <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{m.full_name}</p>
                    <p className="text-base font-medium text-gray-700 dark:text-gray-300">{statusLine(m)}</p>
                  </div>
                </div>
                {!open ? (
                  <button
                    type="button"
                    onClick={() => clockIn(m.id)}
                    disabled={busy}
                    className="w-full min-h-16 rounded-2xl bg-green-600 text-white text-xl font-bold disabled:opacity-50 flex items-center justify-center gap-2"
                  >
                    <LogIn className="w-6 h-6" /> Check in
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={() => clockOut(m.id)}
                    disabled={busy}
                    className="w-full min-h-16 rounded-2xl bg-gray-800 text-white text-xl font-bold disabled:opacity-50 flex items-center justify-center gap-2"
                  >
                    <LogOut className="w-6 h-6" /> Check out
                  </button>
                )}
              </div>
            );
          })}
        </div>
      )}
    </PageShell>
  );
};

export default DriverAttendancePage;
