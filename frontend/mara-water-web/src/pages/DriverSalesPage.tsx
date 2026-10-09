import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Phone, X } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

type SalePaymentMethod = 'cash' | 'mpesa' | 'debt' | 'pay_direct';
interface SaleLine { sku_name: string; qty_bales: number; unit_price: number; }
interface DriverSaleRow {
  id: string; trip_date: string | null;
  customer: { id: string; name: string; phone?: string | null; type?: string | null } | null;
  payment_method: SalePaymentMethod; amount: number; items?: SaleLine[];
  debt: { balance: number; expected_repayment_date: string | null; signatory: string | null; days_overdue: number } | null;
}
interface CustomerDetail {
  id: string; name: string; phone: string | null; type: string | null;
  contact_person: string | null; address: string | null; payment_terms: string | null; notes: string | null;
  purchases: { id: string; trip_date: string | null; payment_method: string; amount: number; items: SaleLine[] }[];
}

const TYPE_LABEL: Record<string, string> = {
  retail: 'Retailer',
  wholesale: 'Distributor',
  corporate: 'Institution',
  walk_in: 'Walk-in',
  supplier: 'Supplier',
};

const typeLabel = (type?: string | null) => (type ? (TYPE_LABEL[type] || type) : 'Customer');
const payLabel = (method: string) => method === 'mpesa' ? 'M-Pesa' : method === 'cash' ? 'Cash' : method.replace('_', ' ');

const DriverSalesPage: React.FC = () => {
  const [mySales, setMySales] = useState<DriverSaleRow[]>([]);
  const [mySalesFilter, setMySalesFilter] = useState<'all' | 'debts'>('all');
  const [loading, setLoading] = useState(true);
  const [detail, setDetail] = useState<CustomerDetail | null>(null);
  const [loadingDetail, setLoadingDetail] = useState(false);

  const fetchMySales = useCallback((filter: 'all' | 'debts') => {
    setLoading(true);
    api.get('/driver/sales', { params: { limit: 50, ...(filter === 'debts' ? { debt_only: 1 } : {}) } })
      .then(res => setMySales(res.data.data)).catch(() => {}).finally(() => setLoading(false));
  }, []);
  useEffect(() => { fetchMySales(mySalesFilter); }, [fetchMySales, mySalesFilter]);

  const openCustomer = (id: string) => {
    setLoadingDetail(true);
    setDetail(null);
    api.get(`/driver/customers/${id}`)
      .then(res => setDetail(res.data.data))
      .catch(() => toast.error('Could not open this customer'))
      .finally(() => setLoadingDetail(false));
  };

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Sales</h1>
        <p className="text-gray-600 dark:text-gray-400">Tap a customer to see their phone, shop type, and what they bought. New sales start on <Link to="/driver/trips" className="text-blue-600 font-medium">Trips</Link>.</p>
      </div>

      <div className="flex gap-2">
        <button onClick={() => setMySalesFilter('all')} className={`min-h-12 flex-1 rounded-xl text-base font-semibold ${mySalesFilter === 'all' ? 'bg-blue-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-gray-600'}`}>Paid sales</button>
        <button onClick={() => setMySalesFilter('debts')} className={`min-h-12 flex-1 rounded-xl text-base font-semibold ${mySalesFilter === 'debts' ? 'bg-amber-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-gray-600'}`}>Old credit</button>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-12"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div></div>
      ) : mySales.length === 0 ? (
        <p className="text-base text-gray-500 dark:text-gray-400 text-center py-8">{mySalesFilter === 'debts' ? 'No old credit sales.' : 'No paid sales yet.'}</p>
      ) : (
        <div className="space-y-3">
          {mySales.map(s => (
            <button
              key={s.id}
              type="button"
              disabled={!s.customer?.id}
              onClick={() => s.customer?.id && openCustomer(s.customer.id)}
              className="w-full text-left rounded-2xl bg-white dark:bg-gray-800 shadow p-4 disabled:opacity-80"
            >
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-lg font-bold text-gray-900 dark:text-gray-100">{s.customer?.name || 'Walk-in'}</p>
                  <p className="text-sm text-gray-500">{s.trip_date ? new Date(s.trip_date).toLocaleDateString() : '—'} · {typeLabel(s.customer?.type)} · {payLabel(s.payment_method)}</p>
                </div>
                <p className="text-xl font-bold text-gray-900 dark:text-gray-100 whitespace-nowrap">KES {s.amount.toLocaleString()}</p>
              </div>
              {(s.items || []).length > 0 && (
                <ul className="mt-3 space-y-1">
                  {s.items!.map((line, i) => (
                    <li key={i} className="text-base text-gray-800 dark:text-gray-200">{line.sku_name} · {line.qty_bales} bales</li>
                  ))}
                </ul>
              )}
              {s.customer?.phone && <p className="mt-2 text-sm text-blue-700 dark:text-blue-300">{s.customer.phone}</p>}
              {mySalesFilter === 'debts' && s.debt && (
                <p className="mt-2 text-sm font-medium text-amber-700">Still owed KES {s.debt.balance.toLocaleString()}{s.debt.days_overdue > 0 ? ` · ${s.debt.days_overdue} days overdue` : ''}</p>
              )}
            </button>
          ))}
        </div>
      )}

      {(loadingDetail || detail) && (
        <div className="fixed inset-0 z-[70] bg-black/50 flex items-end sm:items-center justify-center" onClick={() => { setDetail(null); setLoadingDetail(false); }}>
          <div className="bg-white dark:bg-gray-800 w-full sm:max-w-lg sm:rounded-2xl rounded-t-2xl max-h-[90vh] overflow-y-auto p-5" onClick={e => e.stopPropagation()}>
            <div className="flex items-start justify-between gap-3 mb-4">
              <div>
                <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{detail?.name || 'Customer'}</h2>
                {detail && <p className="text-base text-gray-500">{typeLabel(detail.type)}</p>}
              </div>
              <button type="button" onClick={() => { setDetail(null); setLoadingDetail(false); }} className="p-2"><X className="w-6 h-6 text-gray-500" /></button>
            </div>
            {loadingDetail && !detail ? (
              <div className="flex justify-center py-10"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div></div>
            ) : detail && (
              <div className="space-y-4">
                {detail.phone ? (
                  <a href={`tel:${detail.phone}`} className="flex items-center justify-center gap-2 min-h-14 rounded-xl bg-green-600 text-white text-lg font-semibold">
                    <Phone className="w-5 h-5" /> Call {detail.phone}
                  </a>
                ) : (
                  <p className="text-base text-gray-500">No phone number on file.</p>
                )}
                <dl className="space-y-2 text-base">
                  {detail.contact_person && <div className="flex justify-between gap-3"><dt className="text-gray-500">Contact</dt><dd className="font-medium text-right">{detail.contact_person}</dd></div>}
                  {detail.address && <div className="flex justify-between gap-3"><dt className="text-gray-500">Address</dt><dd className="font-medium text-right">{detail.address}</dd></div>}
                  {detail.payment_terms && <div className="flex justify-between gap-3"><dt className="text-gray-500">Terms</dt><dd className="font-medium text-right">{detail.payment_terms}</dd></div>}
                </dl>
                {detail.notes && <p className="text-sm text-gray-600 dark:text-gray-300 bg-gray-50 dark:bg-gray-900 rounded-xl p-3">{detail.notes}</p>}
                <div>
                  <h3 className="text-lg font-bold text-gray-900 dark:text-gray-100 mb-2">What they bought from you</h3>
                  {detail.purchases.length === 0 ? (
                    <p className="text-gray-500">No paid sales yet.</p>
                  ) : detail.purchases.map(p => (
                    <div key={p.id} className="border-t border-gray-200 dark:border-gray-700 py-3">
                      <div className="flex justify-between gap-3">
                        <p className="font-medium">{p.trip_date ? new Date(p.trip_date).toLocaleDateString() : '—'} · {payLabel(p.payment_method)}</p>
                        <p className="font-bold">KES {p.amount.toLocaleString()}</p>
                      </div>
                      <ul className="mt-1">
                        {p.items.map((line, i) => (
                          <li key={i} className="text-base text-gray-700 dark:text-gray-200">{line.sku_name} · {line.qty_bales} bales · KES {line.unit_price.toLocaleString()} each</li>
                        ))}
                      </ul>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default DriverSalesPage;
