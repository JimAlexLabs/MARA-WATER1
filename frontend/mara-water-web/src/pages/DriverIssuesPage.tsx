import React, { useCallback, useEffect, useState } from 'react';
import { Plus, X, Send, Paperclip } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 4: split out of the old single-page DriverPage.tsx -- own
// sidebar-navigable page, "Issues". Round 3 Phase 5: in-app issue
// reporting -- a lightweight ticket thread, not real-time chat.
// Manager/Director see the same issues in their own "Issues" nav item.

interface IssueMessageRow { id: string; sender_id: string; sender?: { full_name?: string }; body: string; photo_path: string | null; created_at: string; }
interface IssueRow {
  id: string; subject: string; status: 'open' | 'acknowledged' | 'resolved';
  messages?: IssueMessageRow[]; messages_count?: number;
  latest_message?: IssueMessageRow | null; created_at: string;
}
const ISSUE_STATUS_STYLE: Record<string, string> = {
  open: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  acknowledged: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
};

const DriverIssuesPage: React.FC = () => {
  const { user } = useAuth();
  const [issues, setIssues] = useState<IssueRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [showIssueForm, setShowIssueForm] = useState(false);
  const [issueSubject, setIssueSubject] = useState('');
  const [issueMessage, setIssueMessage] = useState('');
  const [issuePhoto, setIssuePhoto] = useState<File | null>(null);
  const [submittingIssue, setSubmittingIssue] = useState(false);
  const [openIssueId, setOpenIssueId] = useState<string | null>(null);
  const [issueThread, setIssueThread] = useState<IssueRow | null>(null);
  const [replyText, setReplyText] = useState('');
  const [sendingReply, setSendingReply] = useState(false);

  const fetchIssues = useCallback(() => {
    api.get('/issues').then(res => setIssues(res.data.data)).catch(() => {}).finally(() => setLoading(false));
  }, []);
  useEffect(() => { fetchIssues(); }, [fetchIssues]);

  const uploadIssuePhoto = async (): Promise<string | null> => {
    if (!issuePhoto) return null;
    const form = new FormData();
    form.append('file', issuePhoto);
    form.append('type', 'image');
    form.append('entity_type', 'issue');
    form.append('entity_id', 'pending');
    const res = await api.post('/files/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } });
    return res.data?.data?.url ?? null;
  };

  const submitIssue = async () => {
    if (!issueSubject.trim() || !issueMessage.trim()) { toast.error('Subject and message are required'); return; }
    setSubmittingIssue(true);
    try {
      const photo_path = await uploadIssuePhoto().catch(() => null);
      await api.post('/issues', { subject: issueSubject, message: issueMessage, photo_path });
      toast.success('Issue reported -- your Manager/Director has been notified');
      setShowIssueForm(false);
      setIssueSubject(''); setIssueMessage(''); setIssuePhoto(null);
      fetchIssues();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to report issue');
    } finally {
      setSubmittingIssue(false);
    }
  };

  const openThread = (id: string) => {
    setOpenIssueId(id);
    api.get(`/issues/${id}`).then(res => setIssueThread(res.data.data.issue)).catch(() => toast.error('Failed to load issue'));
  };

  const submitReply = async () => {
    if (!openIssueId || !replyText.trim()) return;
    setSendingReply(true);
    try {
      await api.post(`/issues/${openIssueId}/reply`, { body: replyText });
      setReplyText('');
      openThread(openIssueId);
      fetchIssues();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to send reply');
    } finally {
      setSendingReply(false);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Issues</h1>
        <p className="text-gray-600 dark:text-gray-400">Report a problem and follow up with Manager/Director</p>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div className="flex justify-between items-center mb-4">
          <div>
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Report an Issue</h3>
            <p className="text-sm text-gray-500 dark:text-gray-400">Something wrong with a vehicle, a delivery, stock, or anything else -- your Manager/Director will see it and can reply here.</p>
          </div>
          <button onClick={() => setShowIssueForm(true)} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            <Plus className="h-4 w-4 mr-1" /> New Issue
          </button>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-12"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div></div>
        ) : issues.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">No issues reported yet.</p>
        ) : (
          <div className="space-y-2">
            {issues.map(issue => (
              <button key={issue.id} onClick={() => openThread(issue.id)} className="w-full text-left flex items-center justify-between p-3 rounded-md border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{issue.subject}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400 truncate">{issue.latest_message?.body ?? ''}</p>
                </div>
                <span className={`ml-3 shrink-0 inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${ISSUE_STATUS_STYLE[issue.status]}`}>{issue.status}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      {showIssueForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Report an Issue</h3>
              <button onClick={() => setShowIssueForm(false)}><X className="h-5 w-5 text-gray-400" /></button>
            </div>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Subject</label>
                <input type="text" value={issueSubject} onChange={e => setIssueSubject(e.target.value)} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" placeholder="e.g. Vehicle brake issue" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Message</label>
                <textarea value={issueMessage} onChange={e => setIssueMessage(e.target.value)} rows={4} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" placeholder="Describe what's wrong..." />
              </div>
              <div>
                <label className="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                  <Paperclip className="h-4 w-4 mr-1" /> {issuePhoto ? issuePhoto.name : 'Attach a photo (optional)'}
                  <input type="file" accept="image/*" className="hidden" onChange={e => setIssuePhoto(e.target.files?.[0] ?? null)} />
                </label>
              </div>
              <div className="flex justify-end space-x-3 pt-2">
                <button type="button" onClick={() => setShowIssueForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="button" onClick={submitIssue} disabled={submittingIssue} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{submittingIssue ? 'Sending…' : 'Send'}</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {openIssueId && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[80vh] flex flex-col">
            <div className="flex justify-between items-center mb-4">
              <div>
                <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">{issueThread?.subject ?? 'Loading…'}</h3>
                {issueThread && <span className={`inline-flex mt-1 px-2 py-0.5 rounded-full text-xs font-medium ${ISSUE_STATUS_STYLE[issueThread.status]}`}>{issueThread.status}</span>}
              </div>
              <button onClick={() => { setOpenIssueId(null); setIssueThread(null); }}><X className="h-5 w-5 text-gray-400" /></button>
            </div>
            <div className="flex-1 overflow-y-auto space-y-3 mb-4">
              {(issueThread?.messages ?? []).map(m => (
                <div key={m.id} className={`p-3 rounded-lg text-sm ${m.sender_id === user?.id ? 'bg-blue-50 dark:bg-blue-900/30 ml-6' : 'bg-gray-100 dark:bg-gray-700 mr-6'}`}>
                  <p className="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{m.sender?.full_name ?? '—'} · {new Date(m.created_at).toLocaleString()}</p>
                  <p className="text-gray-900 dark:text-gray-100">{m.body}</p>
                  {m.photo_path && <img src={m.photo_path} alt="attachment" className="mt-2 rounded-md max-h-48" />}
                </div>
              ))}
            </div>
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

export default DriverIssuesPage;
