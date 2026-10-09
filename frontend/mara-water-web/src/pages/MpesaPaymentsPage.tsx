import React, { useEffect, useState } from 'react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

type Row = {
  id: string;
  reference: string;
  phone: string;
  amount: string;
  status: string;
  mpesa_receipt: string | null;
  channel: string;
  location: string | null;
  result_desc: string | null;
  order_id?: string | null;
  driver_trip_sale_id?: string | null;
  created_at: string;
  initiator?: { first_name?: string; last_name?: string } | null;
};

const MpesaPaymentsPage: React.FC = () => {
  const [rows, setRows] = useState<Row[]>([]);
  const [status, setStatus] = useState('');
  const [unmatched, setUnmatched] = useState(false);
  const [mode, setMode] = useState('');
  const [loading, setLoading] = useState(true);
  const [assignId, setAssignId] = useState<string | null>(null);
  const [saleId, setSaleId] = useState('');
  const [saleType, setSaleType] = useState<'order' | 'trip'>('order');

  const load = async () => {
    setLoading(true);
    try {
      const [list, meta] = await Promise.all([
        api.get('/payments', { params: { status: status || undefined, unmatched: unmatched ? 1 : undefined, per_page: 50 } }),
        api.get('/payments/meta'),
      ]);
      setRows(list.data.data?.data || []);
      setMode(meta.data.data?.mode || '');
    } catch {
      toast.error('Could not load M-Pesa payments');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, [status, unmatched]);

  const recheck = async (id: string) => {
    await api.post(`/payments/${id}/recheck`);
    toast.success('Status refreshed');
    load();
  };

  const download = async () => {
    const res = await api.get('/payments/export', { responseType: 'blob', params: { status: status || undefined, unmatched: unmatched ? 1 : undefined } });
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'mpesa-payments.xlsx';
    a.click();
    URL.revokeObjectURL(url);
  };

  const assign = async () => {
    if (!assignId || !saleId.trim()) return;
    try {
      await api.post(`/payments/${assignId}/assign`, { sale_type: saleType, sale_id: saleId.trim() });
      toast.success('Payment linked to the sale');
      setAssignId(null);
      setSaleId('');
      load();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Could not assign');
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">M-Pesa payments</h1>
          <p className="text-sm text-gray-500">Confirmed receipts only count as paid. Mode: {mode || '…'}</p>
        </div>
        <div className="flex gap-2">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className="border rounded-md px-2 py-2 text-sm dark:bg-gray-800">
            <option value="">All statuses</option>
            {['pending', 'success', 'failed', 'cancelled', 'timeout'].map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
          <button type="button" onClick={() => setUnmatched((v) => !v)} className={`px-3 py-2 text-sm rounded-md border ${unmatched ? 'bg-amber-100' : ''}`}>
            {unmatched ? 'Unmatched only' : 'Show unmatched'}
          </button>
          <button type="button" onClick={download} className="px-3 py-2 text-sm rounded-md bg-blue-600 text-white">Excel</button>
        </div>
      </div>
      <div className="bg-white dark:bg-gray-800 rounded-xl border overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead>
            <tr className="text-left text-gray-500 border-b">
              <th className="p-3">When</th>
              <th className="p-3">Reference</th>
              <th className="p-3">Phone</th>
              <th className="p-3">Amount</th>
              <th className="p-3">Status</th>
              <th className="p-3">Receipt</th>
              <th className="p-3">Channel</th>
              <th className="p-3"></th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td className="p-3" colSpan={8}>Loading…</td></tr>
            ) : rows.length === 0 ? (
              <tr><td className="p-3" colSpan={8}>No payments</td></tr>
            ) : rows.map((row) => (
              <tr key={row.id} className="border-b border-gray-100 dark:border-gray-700">
                <td className="p-3">{row.created_at?.slice(0, 16).replace('T', ' ')}</td>
                <td className="p-3">{row.reference}</td>
                <td className="p-3">{row.phone}</td>
                <td className="p-3">{Number(row.amount).toLocaleString()}</td>
                <td className="p-3">{row.status}</td>
                <td className="p-3">{row.mpesa_receipt || '—'}</td>
                <td className="p-3">{row.channel}{row.location ? ` · ${row.location}` : ''}</td>
                <td className="p-3 space-x-2">
                  {row.status === 'pending' && <button type="button" className="text-blue-600" onClick={() => recheck(row.id)}>Re-check</button>}
                  {!row.order_id && !row.driver_trip_sale_id && row.status === 'success' && (
                    <button type="button" className="text-amber-700" onClick={() => setAssignId(row.id)}>Assign</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {assignId && (
        <div className="bg-white dark:bg-gray-800 rounded-xl border p-4 space-y-2 max-w-lg">
          <h2 className="font-medium">Link this payment to a sale</h2>
          <div className="flex gap-2">
            <select value={saleType} onChange={(e) => setSaleType(e.target.value as 'order' | 'trip')} className="border rounded-md px-2 py-2 text-sm dark:bg-gray-900">
              <option value="order">Outlet sale</option>
              <option value="trip">Trip sale</option>
            </select>
            <input value={saleId} onChange={(e) => setSaleId(e.target.value)} placeholder="Sale id" className="flex-1 border rounded-md px-2 py-2 text-sm dark:bg-gray-900" />
            <button type="button" onClick={assign} className="px-3 py-2 text-sm bg-blue-600 text-white rounded-md">Save</button>
            <button type="button" onClick={() => setAssignId(null)} className="px-3 py-2 text-sm border rounded-md">Cancel</button>
          </div>
        </div>
      )}
    </div>
  );
};

export default MpesaPaymentsPage;
