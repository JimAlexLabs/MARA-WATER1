import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

type Lady = { id: string; name: string; monthly_cap: number };
type SkuCol = { id: string; name: string; code?: string; wage_per_bale: number; bottles_per_bale: number; wage_per_bottle: number };
type Entry = { user_id: string; sku_id: string; kind: 'produce' | 'refill'; bales: number; amount: number };
type Recon = { sku_id: string; name: string; production_bales: number; sheet_bales: number; matches: boolean };
type DayPayload = { date: string; ladies: Lady[]; skus: SkuCol[]; entries: Entry[]; reconciliation: Recon[]; day_pay: number };
type MonthLady = { user_id: string; name: string; earned: number; monthly_cap: number; payable: number };

const keyOf = (userId: string, skuId: string, kind: string) => `${userId}|${skuId}|${kind}`;
const todayIso = () => new Date().toISOString().slice(0, 10);
const money = (n: number) => `KES ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const ProductionWagesPanel: React.FC = () => {
  const [date, setDate] = useState(todayIso());
  const [month, setMonth] = useState(todayIso().slice(0, 7));
  const [view, setView] = useState<'day' | 'month'>('day');
  const [day, setDay] = useState<DayPayload | null>(null);
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [caps, setCaps] = useState<Record<string, string>>({});
  const [monthRows, setMonthRows] = useState<MonthLady[]>([]);
  const [monthTotals, setMonthTotals] = useState({ earned: 0, payable: 0 });
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  const loadDay = useCallback(async (d: string) => {
    setLoading(true);
    try {
      const res = await api.get('/hr/production-wages', { params: { date: d } });
      const data = res.data.data as DayPayload;
      setDay(data);
      const next: Record<string, string> = {};
      data.entries.forEach((e) => { next[keyOf(e.user_id, e.sku_id, e.kind)] = String(e.bales); });
      setDraft(next);
      const capDraft: Record<string, string> = {};
      data.ladies.forEach((l) => { capDraft[l.id] = String(l.monthly_cap); });
      setCaps(capDraft);
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Could not load the wage book');
    } finally {
      setLoading(false);
    }
  }, []);

  const loadMonth = useCallback(async (m: string) => {
    setLoading(true);
    try {
      const res = await api.get('/hr/production-wages/month', { params: { month: m } });
      setMonthRows(res.data.data.ladies || []);
      setMonthTotals({ earned: res.data.data.earned_total || 0, payable: res.data.data.payable_total || 0 });
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Could not load the month');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { if (view === 'day') loadDay(date); }, [view, date, loadDay]);
  useEffect(() => { if (view === 'month') loadMonth(month); }, [view, month, loadMonth]);

  const previewPay = useMemo(() => {
    if (!day) return 0;
    let total = 0;
    day.ladies.forEach((lady) => {
      day.skus.forEach((sku) => {
        (['produce', 'refill'] as const).forEach((kind) => {
          const bales = parseFloat(draft[keyOf(lady.id, sku.id, kind)] || '0') || 0;
          const existing = day.entries.find((e) => e.user_id === lady.id && e.sku_id === sku.id && e.kind === kind);
          const perBale = existing && existing.bales > 0
            ? existing.amount / existing.bales
            : sku.wage_per_bale;
          total += bales * perBale;
        });
      });
    });
    return total;
  }, [day, draft]);

  const save = async () => {
    if (!day) return;
    setSaving(true);
    try {
      const lines: Array<{ user_id: string; sku_id: string; kind: 'produce' | 'refill'; bales: number }> = [];
      day.ladies.forEach((lady) => {
        day.skus.forEach((sku) => {
          (['produce', 'refill'] as const).forEach((kind) => {
            lines.push({
              user_id: lady.id,
              sku_id: sku.id,
              kind,
              bales: parseFloat(draft[keyOf(lady.id, sku.id, kind)] || '0') || 0,
            });
          });
        });
      });
      const res = await api.put('/hr/production-wages', {
        date,
        lines,
        caps: day.ladies.map((l) => ({ user_id: l.id, monthly_cap: parseFloat(caps[l.id] || '0') || 0 })),
      });
      const data = res.data.data as DayPayload;
      setDay(data);
      toast.success('Wage book saved');
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Could not save');
    } finally {
      setSaving(false);
    }
  };

  const download = async () => {
    try {
      const params = view === 'month' ? { month } : { date };
      const res = await api.get('/hr/production-wages/export', { params, responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = view === 'month' ? `production-wages-${month}.xlsx` : `production-wages-${date}.xlsx`;
      a.click();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Excel download failed');
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <div>
          <label className="block text-xs text-gray-500">Book</label>
          <select value={view} onChange={(e) => setView(e.target.value as 'day' | 'month')} className="mt-1 border rounded-md px-3 py-2 text-sm dark:bg-gray-700 dark:border-gray-600">
            <option value="day">One day</option>
            <option value="month">Month</option>
          </select>
        </div>
        {view === 'day' ? (
          <div>
            <label className="block text-xs text-gray-500">Date</label>
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="mt-1 border rounded-md px-3 py-2 text-sm dark:bg-gray-700 dark:border-gray-600" />
          </div>
        ) : (
          <div>
            <label className="block text-xs text-gray-500">Month</label>
            <input type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="mt-1 border rounded-md px-3 py-2 text-sm dark:bg-gray-700 dark:border-gray-600" />
          </div>
        )}
        <button type="button" onClick={download} className="px-3 py-2 text-sm border rounded-md">Download Excel</button>
        {view === 'day' && (
          <button type="button" onClick={save} disabled={saving || !day} className="px-3 py-2 text-sm bg-blue-600 text-white rounded-md disabled:opacity-50">
            {saving ? 'Saving…' : 'Save day'}
          </button>
        )}
        <p className="text-sm text-gray-600 dark:text-gray-300">
          {view === 'day' ? `Day pay ${money(previewPay)}` : `Payable ${money(monthTotals.payable)} (earned ${money(monthTotals.earned)})`}
        </p>
      </div>

      <p className="text-xs text-gray-500">
        Rates come from Operations / Planning (KES per bottle × bottles per bale). A later rate change does not rewrite days already saved.
        Monthly payable is the piece-rate total, capped at each lady&apos;s salary (default KES 10,000). Change the cap on her row.
      </p>

      {loading && <p className="text-sm text-gray-500">Loading…</p>}

      {view === 'day' && day && !loading && (
        <>
          {day.reconciliation.some((r) => !r.matches) && (
            <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
              This day does not match production yet. New bales plus refills for each SKU should equal the batches recorded that day.
              <ul className="mt-1 list-disc pl-5">
                {day.reconciliation.filter((r) => !r.matches).map((r) => (
                  <li key={r.sku_id}>{r.name}: production {r.production_bales} bales, wage book {r.sheet_bales} bales</li>
                ))}
              </ul>
            </div>
          )}
          {day.ladies.length === 0 ? (
            <p className="text-sm text-gray-500">No active production ladies (role BP). Add them under Users.</p>
          ) : (
            <div className="overflow-x-auto border rounded-lg">
              <table className="min-w-full text-sm">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-3 py-2 text-left">Lady</th>
                    <th className="px-3 py-2 text-left">Monthly cap</th>
                    {day.skus.map((sku) => (
                      <th key={sku.id} className="px-3 py-2 text-left whitespace-nowrap">
                        {sku.name}
                        <div className="font-normal text-xs text-gray-500">{money(sku.wage_per_bale)} / bale</div>
                      </th>
                    ))}
                    <th className="px-3 py-2 text-left">Pay</th>
                  </tr>
                </thead>
                <tbody>
                  {day.ladies.map((lady) => {
                    let pay = 0;
                    day.skus.forEach((sku) => {
                      (['produce', 'refill'] as const).forEach((kind) => {
                        const bales = parseFloat(draft[keyOf(lady.id, sku.id, kind)] || '0') || 0;
                        const existing = day.entries.find((e) => e.user_id === lady.id && e.sku_id === sku.id && e.kind === kind);
                        const perBale = existing && existing.bales > 0 ? existing.amount / existing.bales : sku.wage_per_bale;
                        pay += bales * perBale;
                      });
                    });
                    return (
                      <tr key={lady.id} className="border-t">
                        <td className="px-3 py-2 font-medium whitespace-nowrap">{lady.name}</td>
                        <td className="px-3 py-2">
                          <input
                            type="number" min={0} step="1"
                            value={caps[lady.id] ?? ''}
                            onChange={(e) => setCaps({ ...caps, [lady.id]: e.target.value })}
                            className="w-24 border rounded px-2 py-1 dark:bg-gray-700 dark:border-gray-600"
                          />
                        </td>
                        {day.skus.map((sku) => (
                          <td key={sku.id} className="px-3 py-2">
                            <div className="flex gap-1">
                              <input
                                type="number" min={0} step="0.5" placeholder="new"
                                title="New bales"
                                value={draft[keyOf(lady.id, sku.id, 'produce')] ?? ''}
                                onChange={(e) => setDraft({ ...draft, [keyOf(lady.id, sku.id, 'produce')]: e.target.value })}
                                className="w-16 border rounded px-1 py-1 dark:bg-gray-700 dark:border-gray-600"
                              />
                              <input
                                type="number" min={0} step="0.5" placeholder="refill"
                                title="Refill bales"
                                value={draft[keyOf(lady.id, sku.id, 'refill')] ?? ''}
                                onChange={(e) => setDraft({ ...draft, [keyOf(lady.id, sku.id, 'refill')]: e.target.value })}
                                className="w-16 border rounded px-1 py-1 dark:bg-gray-700 dark:border-gray-600"
                              />
                            </div>
                          </td>
                        ))}
                        <td className="px-3 py-2 whitespace-nowrap">{money(pay)}</td>
                      </tr>
                    );
                  })}
                  <tr className="border-t bg-gray-50 dark:bg-gray-900 font-medium">
                    <td className="px-3 py-2">All ladies</td>
                    <td className="px-3 py-2" />
                    {day.skus.map((sku) => {
                      let bales = 0;
                      let pay = 0;
                      day.ladies.forEach((lady) => {
                        (['produce', 'refill'] as const).forEach((kind) => {
                          const n = parseFloat(draft[keyOf(lady.id, sku.id, kind)] || '0') || 0;
                          const existing = day.entries.find((e) => e.user_id === lady.id && e.sku_id === sku.id && e.kind === kind);
                          const perBale = existing && existing.bales > 0 ? existing.amount / existing.bales : sku.wage_per_bale;
                          bales += n;
                          pay += n * perBale;
                        });
                      });
                      return (
                        <td key={sku.id} className="px-3 py-2 whitespace-nowrap text-xs">
                          {bales.toLocaleString()} bales
                          <div>{money(pay)}</div>
                        </td>
                      );
                    })}
                    <td className="px-3 py-2 whitespace-nowrap">{money(previewPay)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          )}
          <p className="text-xs text-gray-500">Each SKU cell is two numbers: new bales, then refill bales.</p>
        </>
      )}

      {view === 'month' && !loading && (
        <div className="overflow-x-auto border rounded-lg">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-900">
              <tr>
                <th className="px-3 py-2 text-left">Lady</th>
                <th className="px-3 py-2 text-left">Earned</th>
                <th className="px-3 py-2 text-left">Cap</th>
                <th className="px-3 py-2 text-left">Payable</th>
              </tr>
            </thead>
            <tbody>
              {monthRows.map((row) => (
                <tr key={row.user_id} className="border-t">
                  <td className="px-3 py-2">{row.name}</td>
                  <td className="px-3 py-2">{money(row.earned)}</td>
                  <td className="px-3 py-2">{money(row.monthly_cap)}</td>
                  <td className="px-3 py-2 font-medium">{money(row.payable)}</td>
                </tr>
              ))}
              {monthRows.length === 0 && (
                <tr><td colSpan={4} className="px-3 py-6 text-center text-gray-500">No wage entries this month.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

export default ProductionWagesPanel;
