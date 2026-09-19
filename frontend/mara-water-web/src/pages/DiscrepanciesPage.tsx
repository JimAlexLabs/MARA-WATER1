import React, { useCallback, useEffect, useState } from 'react';
import { AlertTriangle, Download, X } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

// Round 4 Phase 6: dedicated Discrepancies page -- Manager/Director only
// (never reachable from the Driver dashboard). Rows are created
// automatically by DriverTripController::end() (cash self-consistency,
// stock oversell after a Director correction, mileage outliers); this
// page is purely for reviewing/resolving/exporting them.

interface DiscrepancyRow {
  id: string; date: string; category: 'cash' | 'stock' | 'mileage';
  amount: string | null; description: string; status: 'open' | 'reviewed' | 'resolved';
  resolution_note: string | null;
  trip?: { id: string; trip_date: string } | null;
  vehicle?: { id: string; reg_no: string } | null;
  driver?: { id: string; full_name: string } | null;
}

const CATEGORY_LABEL: Record<string, string> = { cash: 'Cash', stock: 'Stock', mileage: 'Mileage' };
const CATEGORY_COLOR: Record<string, string> = {
  cash: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  stock: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  mileage: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
};
const STATUS_COLOR: Record<string, string> = {
  open: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  reviewed: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
};

const downloadBlob = (path: string, params: Record<string, string>, filename: string, onError: () => void) => {
  api.get(path, { params, responseType: 'blob' }).then((res) => {
    const url = window.URL.createObjectURL(new Blob([res.data]));
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  }).catch(onError);
};

const DiscrepanciesPage: React.FC = () => {
  const [rows, setRows] = useState<DiscrepancyRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [categoryFilter, setCategoryFilter] = useState<'all' | 'cash' | 'stock' | 'mileage'>('all');
  const [statusFilter, setStatusFilter] = useState<'all' | 'open' | 'reviewed' | 'resolved'>('all');
  const [rollups, setRollups] = useState<{ by_category: Record<string, { count: number; total: number }> } | null>(null);

  const today = new Date().toISOString().slice(0, 10);
  const monthStart = today.slice(0, 8) + '01';
  const [dateFrom, setDateFrom] = useState(monthStart);
  const [dateTo, setDateTo] = useState(today);

  const [reviewing, setReviewing] = useState<DiscrepancyRow | null>(null);
  const [reviewStatus, setReviewStatus] = useState<'reviewed' | 'resolved'>('reviewed');
  const [reviewNote, setReviewNote] = useState('');
  const [savingReview, setSavingReview] = useState(false);

  const fetchData = useCallback(() => {
    setLoading(true);
    api.get('/fleet/discrepancies', {
      params: {
        ...(categoryFilter !== 'all' ? { category: categoryFilter } : {}),
        ...(statusFilter !== 'all' ? { status: statusFilter } : {}),
        date_from: dateFrom, date_to: dateTo, limit: 100,
      },
    }).then(res => {
      setRows(res.data.data);
      setRollups(res.data.rollups);
    }).catch(() => toast.error('Failed to load discrepancies')).finally(() => setLoading(false));
  }, [categoryFilter, statusFilter, dateFrom, dateTo]);

  useEffect(() => { fetchData(); }, [fetchData]);

  const openReview = (row: DiscrepancyRow) => {
    setReviewing(row);
    setReviewStatus(row.status === 'open' ? 'reviewed' : (row.status as 'reviewed' | 'resolved'));
    setReviewNote(row.resolution_note ?? '');
  };

  const submitReview = async () => {
    if (!reviewing) return;
    setSavingReview(true);
    try {
      await api.put(`/fleet/discrepancies/${reviewing.id}`, { status: reviewStatus, resolution_note: reviewNote || undefined });
      toast.success('Discrepancy updated');
      setReviewing(null);
      fetchData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update');
    } finally {
      setSavingReview(false);
    }
  };

  const exportLog = () => {
    downloadBlob('/fleet/discrepancies/export', { date_from: dateFrom, date_to: dateTo },
      `discrepancy-log-${dateFrom}-to-${dateTo}.xlsx`, () => toast.error('Failed to export'));
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Discrepancies</h1>
          <p className="text-gray-600 dark:text-gray-400">Flagged cash, stock, and mileage anomalies -- Manager/Director only. Never shown on the Driver dashboard.</p>
        </div>
        <button onClick={exportLog} className="flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
          <Download className="w-4 h-4 mr-2" /> Export
        </button>
      </div>

      {rollups && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          {(['cash', 'stock', 'mileage'] as const).map(cat => (
            <div key={cat} className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">{CATEGORY_LABEL[cat]} discrepancies</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{rollups.by_category[cat]?.count ?? 0}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">total off: {Number(rollups.by_category[cat]?.total ?? 0).toLocaleString()}</p>
            </div>
          ))}
        </div>
      )}

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
        <div className="flex flex-wrap gap-3 items-end">
          <div>
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From</label>
            <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To</label>
            <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)} className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Category</label>
            <select value={categoryFilter} onChange={e => setCategoryFilter(e.target.value as any)} className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
              <option value="all">All</option>
              <option value="cash">Cash</option>
              <option value="stock">Stock</option>
              <option value="mileage">Mileage</option>
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
            <select value={statusFilter} onChange={e => setStatusFilter(e.target.value as any)} className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
              <option value="all">All</option>
              <option value="open">Open</option>
              <option value="reviewed">Reviewed</option>
              <option value="resolved">Resolved</option>
            </select>
          </div>
        </div>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        {loading ? (
          <div className="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</div>
        ) : rows.length === 0 ? (
          <div className="p-8 text-center text-sm text-gray-500 dark:text-gray-400">No discrepancies in this period.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead>
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Driver</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Vehicle</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Category</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount/Qty Off</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Description</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                  <th className="px-4 py-2"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                {rows.map(row => (
                  <tr key={row.id}>
                    <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{new Date(row.date).toLocaleDateString()}</td>
                    <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{row.driver?.full_name ?? '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{row.vehicle?.reg_no ?? '—'}</td>
                    <td className="px-4 py-2 text-sm"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${CATEGORY_COLOR[row.category]}`}><AlertTriangle className="w-3 h-3 mr-1 mt-0.5" />{CATEGORY_LABEL[row.category]}</span></td>
                    <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{row.amount ? Number(row.amount).toLocaleString() : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate" title={row.description}>{row.description}</td>
                    <td className="px-4 py-2 text-sm"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLOR[row.status]}`}>{row.status}</span></td>
                    <td className="px-4 py-2 text-sm text-right">
                      <button onClick={() => openReview(row)} className="text-blue-600 hover:text-blue-800 text-sm">Review</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {reviewing && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Review Discrepancy</h3>
              <button onClick={() => setReviewing(null)}><X className="w-5 h-5 text-gray-400" /></button>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">{reviewing.description}</p>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select value={reviewStatus} onChange={e => setReviewStatus(e.target.value as any)} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                  <option value="reviewed">Reviewed</option>
                  <option value="resolved">Resolved</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Resolution Note</label>
                <textarea value={reviewNote} onChange={e => setReviewNote(e.target.value)} rows={3} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" placeholder="What happened / how was it resolved?" />
              </div>
            </div>
            <div className="flex justify-end space-x-3 mt-4">
              <button onClick={() => setReviewing(null)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
              <button onClick={submitReview} disabled={savingReview} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingReview ? 'Saving…' : 'Save'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default DiscrepanciesPage;
