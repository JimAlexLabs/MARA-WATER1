import React, { useCallback, useEffect, useState } from 'react';
import { Send, X, CheckCircle } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 3 Phase 5: Manager/Director's inbox for issues raised from the
// Driver/salesperson dashboard (DriverPage.tsx has the matching
// report/reply UI on that side). A threaded message list per issue, not
// real-time chat -- reuses the same /issues API either side uses.

interface IssueMessageRow { id: string; sender_id: string; sender?: { full_name?: string }; body: string; photo_path: string | null; created_at: string; }
interface IssueRow {
  id: string; subject: string; status: 'open' | 'acknowledged' | 'resolved';
  creator?: { full_name?: string; id?: string };
  messages?: IssueMessageRow[]; messages_count?: number;
  latest_message?: IssueMessageRow | null; created_at: string; updated_at: string;
}
const STATUS_STYLE: Record<string, string> = {
  open: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  acknowledged: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
};

const IssuesPage: React.FC = () => {
  const { user } = useAuth();
  const [issues, setIssues] = useState<IssueRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState<string>('all');

  const [openId, setOpenId] = useState<string | null>(null);
  const [thread, setThread] = useState<IssueRow | null>(null);
  const [replyText, setReplyText] = useState('');
  const [sendingReply, setSendingReply] = useState(false);
  const [updatingStatus, setUpdatingStatus] = useState(false);

  const fetchIssues = useCallback(() => {
    setLoading(true);
    api.get('/issues', { params: statusFilter === 'all' ? {} : { status: statusFilter } })
      .then(res => setIssues(res.data.data))
      .catch(() => toast.error('Failed to load issues'))
      .finally(() => setLoading(false));
  }, [statusFilter]);
  useEffect(() => { fetchIssues(); }, [fetchIssues]);

  const openThread = (id: string) => {
    setOpenId(id);
    api.get(`/issues/${id}`).then(res => setThread(res.data.data.issue)).catch(() => toast.error('Failed to load issue'));
  };

  const submitReply = async () => {
    if (!openId || !replyText.trim()) return;
    setSendingReply(true);
    try {
      await api.post(`/issues/${openId}/reply`, { body: replyText });
      setReplyText('');
      openThread(openId);
      fetchIssues();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to send reply');
    } finally {
      setSendingReply(false);
    }
  };

  const setStatus = async (status: 'open' | 'acknowledged' | 'resolved') => {
    if (!openId) return;
    setUpdatingStatus(true);
    try {
      await api.put(`/issues/${openId}/status`, { status });
      openThread(openId);
      fetchIssues();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update status');
    } finally {
      setUpdatingStatus(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Issues</h1>
          <p className="text-gray-600 dark:text-gray-400">Issues reported by drivers/salespeople from their dashboard.</p>
        </div>
        <select value={statusFilter} onChange={e => setStatusFilter(e.target.value)} className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 text-sm">
          <option value="all">All statuses</option>
          <option value="open">Open</option>
          <option value="acknowledged">Acknowledged</option>
          <option value="resolved">Resolved</option>
        </select>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        {loading ? (
          <div className="p-6 text-sm text-gray-500 dark:text-gray-400">Loading…</div>
        ) : issues.length === 0 ? (
          <div className="p-6 text-sm text-gray-500 dark:text-gray-400">No issues{statusFilter !== 'all' ? ` with status "${statusFilter}"` : ''}.</div>
        ) : (
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-gray-50 dark:bg-gray-900">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Subject</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Raised By</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Latest</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Updated</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
              {issues.map(issue => (
                <tr key={issue.id} onClick={() => openThread(issue.id)} className="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700">
                  <td className="px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100">{issue.subject}</td>
                  <td className="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{issue.creator?.full_name ?? '—'}</td>
                  <td className="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate">{issue.latest_message?.body ?? ''}</td>
                  <td className="px-6 py-4"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_STYLE[issue.status]}`}>{issue.status}</span></td>
                  <td className="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{new Date(issue.updated_at).toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {openId && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[85vh] flex flex-col">
            <div className="flex justify-between items-center mb-4">
              <div>
                <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">{thread?.subject ?? 'Loading…'}</h3>
                {thread && (
                  <div className="flex items-center gap-2 mt-1">
                    <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_STYLE[thread.status]}`}>{thread.status}</span>
                    <span className="text-xs text-gray-500 dark:text-gray-400">by {thread.creator?.full_name ?? '—'}</span>
                  </div>
                )}
              </div>
              <button onClick={() => { setOpenId(null); setThread(null); }}><X className="h-5 w-5 text-gray-400" /></button>
            </div>

            <div className="flex-1 overflow-y-auto space-y-3 mb-4">
              {(thread?.messages ?? []).map(m => (
                <div key={m.id} className={`p-3 rounded-lg text-sm ${m.sender_id === user?.id ? 'bg-blue-50 dark:bg-blue-900/30 ml-6' : 'bg-gray-100 dark:bg-gray-700 mr-6'}`}>
                  <p className="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{m.sender?.full_name ?? '—'} · {new Date(m.created_at).toLocaleString()}</p>
                  <p className="text-gray-900 dark:text-gray-100">{m.body}</p>
                  {m.photo_path && <img src={m.photo_path} alt="attachment" className="mt-2 rounded-md max-h-48" />}
                </div>
              ))}
            </div>

            {thread && thread.status !== 'resolved' && (
              <div className="flex gap-2 mb-3">
                {thread.status === 'open' && (
                  <button onClick={() => setStatus('acknowledged')} disabled={updatingStatus} className="text-xs px-3 py-1.5 rounded-md border border-amber-300 text-amber-700 dark:text-amber-300 dark:border-amber-700 hover:bg-amber-50 dark:hover:bg-amber-900/30">Mark Acknowledged</button>
                )}
                <button onClick={() => setStatus('resolved')} disabled={updatingStatus} className="flex items-center text-xs px-3 py-1.5 rounded-md border border-green-300 text-green-700 dark:text-green-300 dark:border-green-700 hover:bg-green-50 dark:hover:bg-green-900/30">
                  <CheckCircle className="h-3.5 w-3.5 mr-1" /> Mark Resolved
                </button>
              </div>
            )}

            <div className="flex space-x-2">
              <input type="text" value={replyText} onChange={e => setReplyText(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') submitReply(); }} placeholder="Reply..." className="flex-1 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              <button onClick={submitReply} disabled={sendingReply || !replyText.trim()} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"><Send className="h-4 w-4" /></button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default IssuesPage;
