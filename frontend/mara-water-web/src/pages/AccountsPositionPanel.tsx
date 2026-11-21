import React, { useCallback, useEffect, useState } from 'react';
import { Loader2, RefreshCw } from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../services/api';
import { formatDate } from '../utils/dates';

type Line = { date?: string; description?: string; amount?: number; bucket?: string; reason?: string; party?: string; account?: string; balance?: number; due?: string; customer?: string };
type Purchase = { label: string; full: number; paid: number; still_owed: number; note: string };
type Movement = { date: string; time: string; receipt: string; party: string; account: string; paid_in: number; withdrawn: number; balance: number; kind: string };

type Position = {
  as_of: string;
  account: { holder: string; short_code: string; balance: number; paid_in: number; withdrawn: number };
  money_in: { collections: number; finance_on_statement: number; finance_lines: { date: string; party: string; account: string; amount: number }[] };
  deductions: { total: number; lines: { date: string; amount: number; balance_after: number }[] };
  bills: { tokens: number; fuel: number; petty: number; total: number; lines: Line[]; duplicates_left_out: Line[] };
  after_bills: number;
  purchases: Purchase[];
  purchases_paid: number;
  purchases_still_owed: number;
  paid_outside_account: number;
  unrecorded_sweep: number;
  cash_sales: { orders: number; trips: number; total: number; mpesa_already_on_statement: number };
  should_be_holding: number;
  debts: { customer: string; balance: number; due: string | null }[];
  debts_total: number;
  finance_to_repay: { on_statement: number; outside_loan: number; outside_loan_due: string; outside_loan_rate: number; total_to_repay: number; promised_not_taken: number };
  movements: Movement[];
};

const money = (n: number | null | undefined) => `KES ${Math.round(Number(n || 0)).toLocaleString()}`;

const Stat: React.FC<{ label: string; value: string; note?: string; tone?: 'good' | 'warn' | 'plain' }> = ({ label, value, note, tone = 'plain' }) => (
  <div className={`mara-stat ${tone === 'warn' ? 'ring-1 ring-amber-300' : ''}`}>
    <p className="text-xs text-gray-500 dark:text-gray-400">{label}</p>
    <p className={`text-xl font-bold tabular-nums ${tone === 'good' ? 'text-emerald-700 dark:text-emerald-300' : tone === 'warn' ? 'text-amber-800 dark:text-amber-200' : 'text-gray-900 dark:text-gray-100'}`}>{value}</p>
    {note && <p className="text-xs text-gray-500 mt-1">{note}</p>}
  </div>
);

const AccountsPositionPanel: React.FC = () => {
  const [data, setData] = useState<Position | null>(null);
  const [loading, setLoading] = useState(true);
  const [showAll, setShowAll] = useState(false);

  const load = useCallback(async (quiet = false) => {
    if (!quiet) setLoading(true);
    try {
      const res = await api.get('/operations/accounts');
      setData(res.data.data);
    } catch {
      if (!quiet) toast.error('Could not load the accounts position');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
    const timer = window.setInterval(() => load(true), 30000);
    return () => window.clearInterval(timer);
  }, [load]);

  if (loading && !data) {
    return <div className="flex items-center justify-center h-40"><Loader2 className="w-7 h-7 animate-spin text-blue-600" /></div>;
  }
  if (!data) return <p className="text-sm text-gray-500">Could not load the accounts.</p>;

  const f = data.finance_to_repay;
  const movements = showAll ? data.movements : data.movements.slice(0, 12);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-xs text-gray-500">
          {data.account.holder} utility account {data.account.short_code}. Statement at {data.as_of}. Sales, debts, tokens, fuel and petty cash refresh from the live books.
        </p>
        <button type="button" onClick={() => load()} className="inline-flex items-center px-3 py-1.5 rounded-lg text-sm bg-slate-900 text-white dark:bg-white dark:text-slate-900">
          <RefreshCw className="w-3.5 h-3.5 mr-1.5" /> Refresh
        </button>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
        <Stat label="In the BS account" value={money(data.account.balance)} note="Closing balance on the statement" tone="good" />
        <Stat label="Cash we should be holding" value={money(data.should_be_holding)} note="Account balance, plus cash sales, after spending that did not go through this account" />
        <Stat label="Customers still owe" value={money(data.debts_total)} note="Taken off the cash figure. This is not money in the account." tone="warn" />
        <Stat label="Finance to pay back" value={money(f.total_to_repay)} note={`${money(f.on_statement)} raised on the statement, ${money(f.outside_loan)} taken outside it`} tone="warn" />
      </div>

      <section className="mara-panel p-5 space-y-3">
        <h3 className="text-sm font-semibold">How the cash figure is built</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div className="space-y-1.5">
            <p className="font-medium">Money that came into the account</p>
            <Row label="Customer paybills" value={data.money_in.collections} />
            <Row label="Finance raised on the statement" value={data.money_in.finance_on_statement} />
            <Row label="Paid in" value={data.account.paid_in} strong />
            <Row label="Swept out (every deduction)" value={-data.account.withdrawn} />
            <Row label="Left in the account" value={data.account.balance} strong />
          </div>
          <div className="space-y-1.5">
            <p className="font-medium">Bills subtracted from those deductions</p>
            <Row label="Tokens" value={data.bills.tokens} />
            <Row label="Fuel log" value={data.bills.fuel} />
            <Row label="Petty cash, duplicates left out" value={data.bills.petty} />
            <Row label="Bills" value={data.bills.total} strong />
            <Row label="Deductions still to explain" value={data.after_bills} />
            <Row label="Purchases paid" value={data.purchases_paid} />
            <Row label="Of which, paid outside this account" value={data.paid_outside_account} />
            {data.unrecorded_sweep > 0 && <Row label="Swept out with no bill or purchase" value={data.unrecorded_sweep} />}
          </div>
        </div>
        <p className="text-xs text-gray-500">
          Cash sales recorded outside this account: {money(data.cash_sales.total)} ({money(data.cash_sales.orders)} on outlet tickets, {money(data.cash_sales.trips)} on field trips).
          The {money(data.paid_outside_account)} of purchases the account did not cover is taken off that cash.
          M-Pesa already on the statement ({money(data.cash_sales.mpesa_already_on_statement)} in the sales book) is not added again.
          Customer debts of {money(data.debts_total)} are not in the cash we should be holding.
        </p>
      </section>

      <section className="mara-panel p-5 space-y-3">
        <h3 className="text-sm font-semibold">Finance that has to be paid back</h3>
        <ul className="text-sm divide-y divide-gray-100 dark:divide-gray-700">
          {data.money_in.finance_lines.map((line) => (
            <li key={`${line.date}-${line.amount}-${line.account}`} className="flex justify-between gap-3 py-1.5">
              <span>{formatDate(line.date)} · {line.party}{line.account ? ` · ${line.account}` : ''}</span>
              <span className="tabular-nums font-medium">{money(line.amount)}</span>
            </li>
          ))}
          <li className="flex justify-between gap-3 py-1.5">
            <span>Raised on the statement</span>
            <span className="tabular-nums font-semibold">{money(f.on_statement)}</span>
          </li>
          <li className="flex justify-between gap-3 py-1.5">
            <span>Taken outside the statement — loan at {(f.outside_loan_rate * 100).toFixed(0)}%, due {f.outside_loan_due}</span>
            <span className="tabular-nums font-semibold">{money(f.outside_loan)}</span>
          </li>
          <li className="flex justify-between gap-3 py-1.5 text-gray-500">
            <span>Promised and not yet received</span>
            <span className="tabular-nums">{money(f.promised_not_taken)}</span>
          </li>
        </ul>
      </section>

      <section className="mara-panel p-5 space-y-3">
        <div className="flex flex-wrap justify-between gap-2">
          <h3 className="text-sm font-semibold">Purchases</h3>
          <p className="text-xs text-gray-500">Paid {money(data.purchases_paid)} · still to pay {money(data.purchases_still_owed)}</p>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-xs uppercase tracking-wide text-gray-500">
                <th className="text-left py-1 pr-3">Account</th>
                <th className="text-right py-1 px-2">Invoice</th>
                <th className="text-right py-1 px-2">Paid</th>
                <th className="text-right py-1 pl-2">Still owed</th>
              </tr>
            </thead>
            <tbody>
              {data.purchases.map((item) => (
                <tr key={item.label} className="border-t border-gray-100 dark:border-gray-700">
                  <td className="py-1.5 pr-3">
                    <div>{item.label}</div>
                    {item.note && <div className="text-xs text-gray-500">{item.note}</div>}
                  </td>
                  <td className="text-right tabular-nums px-2">{money(item.full)}</td>
                  <td className="text-right tabular-nums px-2">{money(item.paid)}</td>
                  <td className="text-right tabular-nums pl-2">{money(item.still_owed)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="mara-panel p-5 space-y-2">
        <h3 className="text-sm font-semibold">Customer debts subtracted from cash</h3>
        <ul className="text-sm divide-y divide-gray-100 dark:divide-gray-700">
          {data.debts.map((debt) => (
            <li key={debt.customer} className="flex justify-between gap-3 py-1.5">
              <span>{debt.customer}{debt.due ? ` · due ${formatDate(debt.due)}` : ''}</span>
              <span className="tabular-nums font-medium">{money(debt.balance)}</span>
            </li>
          ))}
          <li className="flex justify-between gap-3 py-1.5 font-semibold">
            <span>Total still owed to us</span>
            <span className="tabular-nums">{money(data.debts_total)}</span>
          </li>
        </ul>
      </section>

      <section className="mara-panel p-5 space-y-2">
        <h3 className="text-sm font-semibold">Bills counted against the deductions</h3>
        <ul className="text-sm divide-y divide-gray-100 dark:divide-gray-700">
          {data.bills.lines.map((line, i) => (
            <li key={`${line.date}-${line.description}-${i}`} className="flex justify-between gap-3 py-1.5">
              <span>{formatDate(line.date)} · {line.bucket} · {line.description}</span>
              <span className="tabular-nums">{money(line.amount)}</span>
            </li>
          ))}
        </ul>
        {data.bills.duplicates_left_out.length > 0 && (
          <div className="pt-2">
            <p className="text-xs font-medium text-gray-500 mb-1">Left out so they are not counted twice</p>
            <ul className="text-xs text-gray-500 space-y-1">
              {data.bills.duplicates_left_out.map((line, i) => (
                <li key={`${line.description}-${i}`}>{formatDate(line.date)} · {line.description} · {money(line.amount)} — {line.reason}</li>
              ))}
            </ul>
          </div>
        )}
      </section>

      <section className="mara-panel p-5 space-y-2">
        <div className="flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">Every movement on the statement</h3>
          <button type="button" onClick={() => setShowAll((v) => !v)} className="text-xs text-blue-700 dark:text-blue-300">
            {showAll ? 'Show the latest' : `Show all ${data.movements.length}`}
          </button>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-xs uppercase tracking-wide text-gray-500">
                <th className="text-left py-1 pr-2">Date</th>
                <th className="text-left py-1 pr-2">Who / what</th>
                <th className="text-right py-1 px-2">In</th>
                <th className="text-right py-1 px-2">Out</th>
                <th className="text-right py-1 pl-2">Balance</th>
              </tr>
            </thead>
            <tbody>
              {movements.map((row) => (
                <tr key={row.receipt} className="border-t border-gray-100 dark:border-gray-700">
                  <td className="py-1 pr-2 whitespace-nowrap">{formatDate(row.date)}</td>
                  <td className="py-1 pr-2">
                    <span className={`mr-2 text-[10px] uppercase tracking-wide ${row.kind === 'finance' ? 'text-amber-700' : row.kind === 'deduction' ? 'text-red-600' : 'text-gray-400'}`}>{row.kind === 'finance' ? 'Finance' : row.kind === 'deduction' ? 'Out' : 'Sale'}</span>
                    {row.party}{row.account ? ` · ${row.account}` : ''}
                  </td>
                  <td className="text-right tabular-nums px-2">{row.paid_in ? money(row.paid_in) : ''}</td>
                  <td className="text-right tabular-nums px-2">{row.withdrawn ? money(row.withdrawn) : ''}</td>
                  <td className="text-right tabular-nums pl-2">{money(row.balance)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
};

const Row: React.FC<{ label: string; value: number; strong?: boolean }> = ({ label, value, strong }) => (
  <div className={`flex justify-between gap-3 ${strong ? 'font-semibold border-t border-gray-100 dark:border-gray-700 pt-1' : ''}`}>
    <span>{label}</span>
    <span className="tabular-nums">{money(value)}</span>
  </div>
);

export default AccountsPositionPanel;
