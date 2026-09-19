import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../services/api';

// Round 4: split out of the old single-page DriverPage.tsx -- own
// sidebar-navigable page, "Sales". Round 3 Phase 12: "Invoices"/Debtors
// Ledger, scoped to only this driver's own sales -- there's no formal
// Invoice document for a trip sale (Round 2 Phase 7: informal shop
// credit, no invoice generated), so this is genuinely "my sales",
// labeled as such rather than implying documents that don't exist.
// Petty Cash and Costing & P&L are deliberately not added anywhere on
// this page (spec default for a driver-tier user).

type SalePaymentMethod = 'cash' | 'mpesa' | 'debt' | 'pay_direct';
interface DriverSaleRow {
  id: string; trip_date: string | null; customer: { id: string; name: string } | null;
  payment_method: SalePaymentMethod; amount: number; physical_receipt_no: string | null;
  debt: { balance: number; expected_repayment_date: string | null; signatory: string | null; days_overdue: number } | null;
}

const DriverSalesPage: React.FC = () => {
  const [mySales, setMySales] = useState<DriverSaleRow[]>([]);
  const [mySalesFilter, setMySalesFilter] = useState<'all' | 'debts'>('all');
  const [loading, setLoading] = useState(true);

  const fetchMySales = useCallback((filter: 'all' | 'debts') => {
    setLoading(true);
    api.get('/driver/sales', { params: filter === 'debts' ? { debt_only: 1 } : {} })
      .then(res => setMySales(res.data.data)).catch(() => {}).finally(() => setLoading(false));
  }, []);
  useEffect(() => { fetchMySales(mySalesFilter); }, [fetchMySales, mySalesFilter]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Sales</h1>
        <p className="text-gray-600 dark:text-gray-400">Your own sales and debtors -- to log a new sale, open an active trip under <Link to="/driver/trips" className="text-blue-600 hover:underline">Trips</Link>.</p>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">My Sales{mySalesFilter === 'debts' ? ' -- Debtors Ledger' : ''}</h3>
          <div className="flex bg-gray-100 dark:bg-gray-700 rounded-lg p-1 text-sm">
            <button onClick={() => setMySalesFilter('all')} className={`px-3 py-1 rounded-md ${mySalesFilter === 'all' ? 'bg-white dark:bg-gray-800 shadow text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400'}`}>All Sales</button>
            <button onClick={() => setMySalesFilter('debts')} className={`px-3 py-1 rounded-md ${mySalesFilter === 'debts' ? 'bg-white dark:bg-gray-800 shadow text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400'}`}>My Debtors</button>
          </div>
        </div>
        <div className="p-6">
          {loading ? (
            <div className="flex items-center justify-center py-12"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div></div>
          ) : mySales.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">{mySalesFilter === 'debts' ? 'No credit sales on your account.' : 'No sales logged yet.'}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Customer</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Payment</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                    {mySalesFilter === 'debts' && <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Debt Status</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {mySales.map(s => (
                    <tr key={s.id}>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{s.trip_date ? new Date(s.trip_date).toLocaleDateString() : '—'}</td>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{s.customer?.name || '—'}</td>
                      <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400 capitalize">{s.payment_method.replace('_', ' ')}</td>
                      <td className="px-4 py-2 text-sm font-medium text-gray-900 dark:text-gray-100">KES {s.amount.toLocaleString()}</td>
                      {mySalesFilter === 'debts' && (
                        <td className="px-4 py-2 text-sm">
                          {s.debt ? (
                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${s.debt.days_overdue > 0 ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'}`}>
                              KES {s.debt.balance.toLocaleString()}{s.debt.days_overdue > 0 ? ` · ${s.debt.days_overdue}d overdue` : ' outstanding'}
                            </span>
                          ) : '—'}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default DriverSalesPage;
