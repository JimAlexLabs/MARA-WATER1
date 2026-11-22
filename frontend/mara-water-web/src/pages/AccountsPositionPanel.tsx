import React, { useCallback, useEffect, useState } from 'react';
import { Download, Loader2, RefreshCw, Trash2 } from 'lucide-react';
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
  borrowing: {
    in_account: number; pot1_in: number; bottles: number; transport: number; pot1_balance: number;
    diana: number; derrick_topup: number; pot2_in: number; labels: number; phones: number; capital_extra: number;
    label_transport: number; later_operations: number; other_purchases: number; later_spend: number;
    customer_money_used: number; outside_deficit: number;
  };
  finance_to_repay: { on_statement: number; outside_loan: number; outside_loan_due: string; outside_loan_rate: number; outside_deficit: number; total_to_repay: number; promised_not_taken: number };
  ops: {
    capital_extra: number; customer_paybills_used: number; noted_withdrawals: number;
    withdrawn_for_operations: number; expenses: number; difference: number;
    outside_borrowed: number; still_to_pay: number; to_pay_out: number;
  };
  ledger: { id?: string | null; date: string; section: string; title: string; amount: number; explanation: string; source: string }[];
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
  const [form, setForm] = useState({ entry_date: new Date().toISOString().slice(0, 10), kind: 'expense', title: '', explanation: '', amount: '' });

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

  const saveLine = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const res = await api.post('/operations/accounts/entries', form);
      setData(res.data.data);
      setForm({ ...form, title: '', explanation: '', amount: '' });
      toast.success('Added. The totals have been recalculated.');
    } catch (err: any) {
      toast.error(err?.response?.data?.message || 'Could not add that line');
    }
  };

  const removeLine = async (id: string) => {
    try {
      const res = await api.delete(`/operations/accounts/entries/${id}`);
      setData(res.data.data);
    } catch {
      toast.error('Could not remove that line');
    }
  };

  const download = async () => {
    try {
      const res = await api.get('/operations/accounts/export', { responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = 'mara-accounts.csv';
      a.click();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Could not download the CSV');
    }
  };

  if (loading && !data) {
    return <div className="flex items-center justify-center h-40"><Loader2 className="w-7 h-7 animate-spin text-blue-600" /></div>;
  }
  if (!data) return <p className="text-sm text-gray-500">Could not load the accounts.</p>;

  const f = data.finance_to_repay;
  const b = data.borrowing;
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
        <Stat label="Withdrawn for operations" value={money(data.ops.withdrawn_for_operations)} note={`${money(data.ops.capital_extra)} left from the capital pot, plus ${money(data.ops.customer_paybills_used)} of customer paybills already used`} />
        <Stat label="Expenses so far" value={money(data.ops.expenses)} note="Fuel once, tokens, petty cash without the phone, both Shadey payments, wiring, plus anything you add" />
        <Stat label="Difference" value={money(data.ops.difference)} note="Withdrawn for operations, minus expenses. A minus means the expenses ran ahead of what left the account." tone={data.ops.difference < 0 ? 'warn' : 'good'} />
        <Stat label="To pay out" value={money(data.ops.to_pay_out)} note="Outside loans and amounts still to pay. Diana, Derrick and customer debts are not in this." tone="warn" />
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
          M-Pesa already on the statement ({money(data.cash_sales.mpesa_already_on_statement)} in the sales book) is not added again.
          Customer debts of {money(data.debts_total)} are not in the cash we should be holding.
        </p>
      </section>

      <section className="mara-panel p-5 space-y-3">
        <h3 className="text-sm font-semibold">In-account borrowing, and the outside deficit</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div className="space-y-1.5">
            <p className="font-medium">Derrick’s first {money(b.pot1_in)} — bottles, caps and transport</p>
            <Row label="Bottles and caps" value={b.bottles} />
            <Row label="Transport, Nairobi to Rongo" value={b.transport} />
            <Row label="Balance carried forward" value={b.pot1_balance} strong />
          </div>
          <div className="space-y-1.5">
            <p className="font-medium">That balance, plus Diana {money(b.diana)} and Derrick’s further {money(b.derrick_topup)}</p>
            <Row label="Pot" value={b.pot2_in} />
            <Row label="Labels" value={b.labels} />
            <Row label="Company phone on the books" value={b.phones} />
            <Row label="Extra left in the account, moved into operations" value={b.capital_extra} strong />
          </div>
        </div>
        <p className="text-xs text-gray-500">
          That extra {money(b.capital_extra)} is added to the {money(b.customer_money_used)} of customer paybills already used.
          Together that is {money(data.ops.withdrawn_for_operations)} taken from the account for operations.
          The {money(b.in_account)} from Derrick and Diana is still their capital. It is not part of the amount to pay out below.
          Customer debts of {money(data.debts_total)} are money still to collect, not money we pay out.
          Derrick’s further {money(f.promised_not_taken)} has not come in.
        </p>
      </section>

      <section className="mara-panel p-5 space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold">The account, line by line</h3>
          <button type="button" onClick={download} className="inline-flex items-center px-3 py-1.5 rounded-lg text-sm bg-green-700 text-white">
            <Download className="w-3.5 h-3.5 mr-1.5" /> Download CSV
          </button>
        </div>
        <p className="text-xs text-gray-500">Add an expense, a withdrawal from the account, money borrowed outside, or an amount still to pay. The totals above recalculate from the live books plus these lines.</p>
        <form onSubmit={saveLine} className="grid grid-cols-1 md:grid-cols-6 gap-2 items-end text-sm">
          <label className="text-xs text-gray-500">Date
            <input type="date" required value={form.entry_date} onChange={(e) => setForm({ ...form, entry_date: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-2 py-1.5" />
          </label>
          <label className="text-xs text-gray-500">Kind
            <select value={form.kind} onChange={(e) => setForm({ ...form, kind: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-2 py-1.5">
              <option value="expense">Expense</option>
              <option value="withdrawal">Withdrawal from the account</option>
              <option value="outside_loan">Borrowed outside</option>
              <option value="payable">Still to pay</option>
            </select>
          </label>
          <label className="text-xs text-gray-500 md:col-span-2">What
            <input required value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-2 py-1.5" />
          </label>
          <label className="text-xs text-gray-500">Amount
            <input required type="number" min="1" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-2 py-1.5" />
          </label>
          <button type="submit" className="px-3 py-1.5 bg-blue-600 text-white rounded-md">Add</button>
          <label className="text-xs text-gray-500 md:col-span-6">Why
            <input required value={form.explanation} onChange={(e) => setForm({ ...form, explanation: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-2 py-1.5" />
          </label>
        </form>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className="text-xs uppercase tracking-wide text-gray-500">
                <th className="text-left py-1 pr-2">Date</th>
                <th className="text-left py-1 pr-2">Section</th>
                <th className="text-left py-1 pr-2">What</th>
                <th className="text-right py-1 px-2">Amount</th>
                <th className="text-left py-1 pl-2">Why</th>
              </tr>
            </thead>
            <tbody>
              {data.ledger.map((row, i) => (
                <tr key={`${row.section}-${row.title}-${i}`} className="border-t border-gray-100 dark:border-gray-700 align-top">
                  <td className="py-1.5 pr-2 whitespace-nowrap">{row.date ? formatDate(row.date) : ''}</td>
                  <td className="py-1.5 pr-2 whitespace-nowrap">{row.section}</td>
                  <td className="py-1.5 pr-2">{row.title}</td>
                  <td className="py-1.5 px-2 text-right tabular-nums whitespace-nowrap">{money(row.amount)}</td>
                  <td className="py-1.5 pl-2 text-gray-600 dark:text-gray-300">
                    {row.explanation}
                    {row.source === 'Added by admin' && row.id && (
                      <button type="button" onClick={() => removeLine(row.id as string)} className="ml-2 text-red-600" title="Remove this line"><Trash2 className="w-3.5 h-3.5 inline" /></button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
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
