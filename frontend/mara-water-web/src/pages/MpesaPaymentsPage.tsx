import React, { useCallback, useEffect, useState } from 'react';
import { Smartphone, Download, RefreshCw, AlertTriangle, X } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

// Round 6 Phase B5: Manager/Director's monitoring page for the shared
// AfriGig M-Pesa STK gateway -- every payment MARA has initiated or
// received across all four channels (field_trip/warehouse/refill/
// admin), live status, a retry/recheck action for one still pending,
// and the Unmatched tab for a manual-Paybill payment the gateway
// routed to mara_water but couldn't tie to a known sale.

interface PaymentRow {
  id: string; channel: string; reference: string; phone: string; amount: string;
  status: 'pending' | 'success' | 'failed' | 'cancelled' | 'timeout';
  result_desc: string | null; mpesa_receipt: string | null; created_at: string;
  driver_trip_sale?: { id: string; customer?: { name: string } } | null;
  order?: { id: string; order_no: string; customer?: { name: string } } | null;
  initiated_by?: { full_name: string } | null;
  location?: { name: string } | null;
}
interface UnmatchedRow extends PaymentRow { unmatched: boolean; }

const STATUS_COLOR: Record<string, string> = {
  pending: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
  success: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
  failed: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
  timeout: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
};
const CHANNEL_LABEL: Record<string, string> = { field_trip: 'Field Trip', warehouse: 'Warehouse', refill: 'Refill', admin: 'Admin' };

const MpesaPaymentsPage: React.FC = () => {
  const [tab, setTab] = useState<'all' | 'unmatched'>('all');
  const [rows, setRows] = useState<PaymentRow[]>([]);
  const [unmatchedRows, setUnmatchedRows] = useState<UnmatchedRow[]>([]);
  const [loading, setLoading] = useState(true);

  const today = new Date().toISOString().slice(0, 10);
  const monthStart = today.slice(0, 8) + '01';
  const [dateFrom, setDateFrom] = useState(monthStart);
  const [dateTo, setDateTo] = useState(today);
  const [channelFilter, setChannelFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');

  const [assigning, setAssigning] = useState<UnmatchedRow | null>(null);
  const [assignSaleType, setAssignSaleType] = useState<'driver_trip_sale' | 'order'>('driver_trip_sale');
  const [assignSaleId, setAssignSaleId] = useState('');
  const [savingAssign, setSavingAssign] = useState(false);

  const fetchAll = useCallback(() => {
    setLoading(true);
    api.get('/payments', {
      params: {
        date_from: dateFrom, date_to: dateTo, limit: 100,
        ...(channelFilter !== 'all' ? { channel: channelFilter } : {}),
        ...(statusFilter !== 'all' ? { status: statusFilter } : {}),
      },
    }).then(res => setRows(res.data.data)).catch(() => toast.error('Failed to load payments')).finally(() => setLoading(false));
  }, [dateFrom, dateTo, channelFilter, statusFilter]);

  const fetchUnmatched = useCallback(() => {
    api.get('/payments/unmatched').then(res => setUnmatchedRows(res.data.data)).catch(() => {});
  }, []);

  useEffect(() => { fetchAll(); fetchUnmatched(); }, [fetchAll, fetchUnmatched]);

  const retry = (id: string) => {
    api.post(`/payments/${id}/retry`).then(() => { toast.success('Rechecked'); fetchAll(); }).catch((e) => toast.error(e.response?.data?.message || 'Failed to recheck'));
  };

  const exportXlsx = () => {
    api.get('/payments/export', { responseType: 'blob' }).then((res) => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'mpesa-payments.xlsx';
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    }).catch(() => toast.error('Failed to export'));
  };

  const openAssign = (row: UnmatchedRow) => {
    setAssigning(row);
    setAssignSaleType('driver_trip_sale');
    setAssignSaleId('');
  };
  const submitAssign = async () => {
    if (!assigning || !assignSaleId.trim()) { toast.error('Enter the sale/order ID to assign this to'); return; }
    setSavingAssign(true);
    try {
      await api.post(`/payments/unmatched/${assigning.id}/assign`, { sale_type: assignSaleType, sale_id: assignSaleId.trim() });
      toast.success('Assigned');
      setAssigning(null);
      fetchUnmatched();
      fetchAll();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to assign');
    } finally {
      setSavingAssign(false);
    }
  };

  const buyerName = (r: PaymentRow) => r.driver_trip_sale?.customer?.name || r.order?.customer?.name || '—';

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2"><Smartphone className="w-6 h-6" /> M-Pesa Payments</h1>
          <p className="text-gray-600 dark:text-gray-400">Every STK push MARA has sent, across field trips, warehouse, refill and admin sales -- via the shared AfriGig gateway.</p>
        </div>
        <button onClick={exportXlsx} className="flex items-center text-sm px-3 py-2 rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
          <Download className="w-4 h-4 mr-1.5" /> Export
        </button>
      </div>

      <div className="flex gap-2 border-b border-gray-200 dark:border-gray-700">
        <button onClick={() => setTab('all')} className={`px-4 py-2 text-sm font-medium border-b-2 ${tab === 'all' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700'}`}>All Payments</button>
        <button onClick={() => setTab('unmatched')} className={`px-4 py-2 text-sm font-medium border-b-2 flex items-center gap-1.5 ${tab === 'unmatched' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700'}`}>
          Unmatched {unmatchedRows.length > 0 && <span className="inline-flex items-center justify-center text-xs bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300 rounded-full h-5 w-5">{unmatchedRows.length}</span>}
        </button>
      </div>

      {tab === 'all' ? (
        <>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4 flex flex-wrap gap-3 items-end">
            <div>
              <label className="block text-xs font-medium text-gray-500 dark:text-gray-400">From</label>
              <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} className="mt-1 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-500 dark:text-gray-400">To</label>
              <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)} className="mt-1 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-500 dark:text-gray-400">Channel</label>
              <select value={channelFilter} onChange={e => setChannelFilter(e.target.value)} className="mt-1 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
                <option value="all">All</option>
                <option value="field_trip">Field Trip</option>
                <option value="warehouse">Warehouse</option>
                <option value="refill">Refill</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-500 dark:text-gray-400">Status</label>
              <select value={statusFilter} onChange={e => setStatusFilter(e.target.value)} className="mt-1 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
                <option value="all">All</option>
                <option value="pending">Pending</option>
                <option value="success">Success</option>
                <option value="failed">Failed</option>
                <option value="cancelled">Cancelled</option>
                <option value="timeout">Timeout</option>
              </select>
            </div>
          </div>

          <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
            {loading ? (
              <div className="p-8 text-center text-sm text-gray-400">Loading…</div>
            ) : rows.length === 0 ? (
              <div className="p-8 text-center text-sm text-gray-400">No payments in this range.</div>
            ) : (
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead>
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Reference</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Channel</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Buyer / Phone</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">By</th>
                    <th className="px-4 py-2"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {rows.map(r => (
                    <tr key={r.id}>
                      <td className="px-4 py-2 text-gray-900 dark:text-gray-100">{new Date(r.created_at).toLocaleString()}</td>
                      <td className="px-4 py-2 text-gray-900 dark:text-gray-100">{r.reference}</td>
                      <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{CHANNEL_LABEL[r.channel] || r.channel}{r.location?.name ? ` · ${r.location.name}` : ''}</td>
                      <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{buyerName(r)} · {r.phone}</td>
                      <td className="px-4 py-2 text-gray-900 dark:text-gray-100">KES {parseFloat(r.amount).toLocaleString()}</td>
                      <td className="px-4 py-2">
                        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_COLOR[r.status]}`}>{r.status}</span>
                        {r.status !== 'pending' && r.status !== 'success' && r.result_desc && <p className="text-xs text-gray-400 mt-0.5">{r.result_desc}</p>}
                        {r.mpesa_receipt && <p className="text-xs text-gray-400 mt-0.5">{r.mpesa_receipt}</p>}
                      </td>
                      <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{r.initiated_by?.full_name || '—'}</td>
                      <td className="px-4 py-2 text-right">
                        {r.status === 'pending' && (
                          <button onClick={() => retry(r.id)} title="Recheck status with the gateway" className="text-gray-400 hover:text-blue-600"><RefreshCw className="w-4 h-4" /></button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </>
      ) : (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
          {unmatchedRows.length === 0 ? (
            <div className="p-8 text-center text-sm text-gray-400">No unmatched payments -- every M-Pesa payment MARA has received ties to a known sale.</div>
          ) : (
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
              <thead>
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Reference</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Phone</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">M-Pesa Receipt</th>
                  <th className="px-4 py-2"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                {unmatchedRows.map(r => (
                  <tr key={r.id}>
                    <td className="px-4 py-2 text-gray-900 dark:text-gray-100">{new Date(r.created_at).toLocaleString()}</td>
                    <td className="px-4 py-2 text-gray-900 dark:text-gray-100 flex items-center gap-1.5"><AlertTriangle className="w-3.5 h-3.5 text-amber-500" />{r.reference}</td>
                    <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{r.phone}</td>
                    <td className="px-4 py-2 text-gray-900 dark:text-gray-100">KES {parseFloat(r.amount).toLocaleString()}</td>
                    <td className="px-4 py-2 text-gray-500 dark:text-gray-400">{r.mpesa_receipt || '—'}</td>
                    <td className="px-4 py-2 text-right">
                      <button onClick={() => openAssign(r)} className="text-sm text-blue-600 hover:underline">Assign</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      )}

      {assigning && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Assign Unmatched Payment</h3>
              <button onClick={() => setAssigning(null)}><X className="w-5 h-5 text-gray-400" /></button>
            </div>
            <dl className="space-y-1 text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-3 mb-4">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Reference</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{assigning.reference}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Amount</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {parseFloat(assigning.amount).toLocaleString()}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Phone</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{assigning.phone}</dd></div>
            </dl>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Sale Type</label>
                <select value={assignSaleType} onChange={e => setAssignSaleType(e.target.value as any)} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                  <option value="driver_trip_sale">Field Trip Sale</option>
                  <option value="order">Order (Warehouse/Refill/Admin)</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Sale / Order ID</label>
                <input type="text" value={assignSaleId} onChange={e => setAssignSaleId(e.target.value)} placeholder="Paste the sale or order ID" className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
            </div>
            <div className="flex justify-end space-x-3 mt-4">
              <button onClick={() => setAssigning(null)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
              <button onClick={submitAssign} disabled={savingAssign} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingAssign ? 'Saving…' : 'Assign'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default MpesaPaymentsPage;
