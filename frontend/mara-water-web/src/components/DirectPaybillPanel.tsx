import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Smartphone } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

export interface PaybillRow {
  id: string;
  phone: string;
  payer_name?: string | null;
  amount: string;
  mpesa_receipt: string | null;
  bill_ref?: string | null;
  created_at: string;
  unmatched?: boolean;
  claimable?: boolean;
  match_method?: string | null;
  customer?: { id: string; name: string } | null;
  driver_trip_sale?: { id: string; customer?: { name: string } } | null;
}

function asList<T>(payload: unknown): T[] {
  if (Array.isArray(payload)) return payload as T[];
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
    return (payload as { data: T[] }).data;
  }
  return [];
}

const DirectPaybillPanel: React.FC<{
  compact?: boolean;
  pollMs?: number;
  toastNew?: boolean;
}> = ({ compact = false, pollMs = 8000, toastNew = false }) => {
  const [rows, setRows] = useState<PaybillRow[]>([]);
  const [paybill, setPaybill] = useState<string>('');
  const seen = useRef<Set<string>>(new Set());
  const primed = useRef(false);

  const load = useCallback(() => {
    api.get('/payments/paybill', { params: { limit: 40 } })
      .then((res) => {
        const next = asList<PaybillRow>(res.data?.data);
        if (toastNew && primed.current) {
          next.forEach((r) => {
            if (!seen.current.has(r.id)) {
              const who = r.customer?.name || r.payer_name || r.phone;
              toast.success(`KES ${Number(r.amount).toLocaleString()} from ${who} — Paybill`);
            }
          });
        }
        next.forEach((r) => seen.current.add(r.id));
        primed.current = true;
        setRows(next);
      })
      .catch(() => {});
  }, [toastNew]);

  useEffect(() => {
    api.get('/payments/meta').then((res) => setPaybill(res.data?.data?.paybill || '')).catch(() => {});
    load();
    const t = setInterval(load, pollMs);
    return () => clearInterval(t);
  }, [load, pollMs]);

  const claim = (id: string) => {
    api.post(`/payments/${id}/claim`).then(() => {
      toast.success('Claimed — this Paybill is on your trip');
      load();
    }).catch((e: any) => toast.error(e.response?.data?.message || 'Could not claim'));
  };

  const who = (r: PaybillRow) => r.customer?.name || r.driver_trip_sale?.customer?.name || r.payer_name || '—';

  return (
    <div className={compact ? 'space-y-3' : 'mara-panel p-6 space-y-3'}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 flex items-center gap-2">
            <Smartphone className="w-5 h-5" /> Direct Paybill
          </h3>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Customers who paid Paybill {paybill || '—'}, account <span className="font-semibold">MARA</span>
            {compact ? ' — on this trip.' : '.'}
          </p>
        </div>
      </div>
      {rows.length === 0 ? (
        <p className="text-sm text-gray-400">No direct Paybill payments yet.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-500 uppercase">
                <th className="py-2 pr-3">When</th>
                <th className="py-2 pr-3">Customer / Phone</th>
                <th className="py-2 pr-3">Amount</th>
                <th className="py-2 pr-3">Receipt</th>
                {!compact && <th className="py-2 pr-3">Account</th>}
                <th className="py-2"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
              {rows.map((r) => (
                <tr key={r.id}>
                  <td className="py-2 pr-3 text-gray-700 dark:text-gray-300">{new Date(r.created_at).toLocaleString()}</td>
                  <td className="py-2 pr-3">{who(r)} · {r.phone}</td>
                  <td className="py-2 pr-3 font-medium">KES {Number(r.amount).toLocaleString()}</td>
                  <td className="py-2 pr-3 text-gray-500">{r.mpesa_receipt || '—'}</td>
                  {!compact && <td className="py-2 pr-3 text-gray-500">{r.bill_ref || 'MARA'}</td>}
                  <td className="py-2 text-right">
                    {r.claimable && (
                      <button type="button" onClick={() => claim(r.id)} className="text-sm text-blue-600 hover:underline">Claim</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

export default DirectPaybillPanel;
