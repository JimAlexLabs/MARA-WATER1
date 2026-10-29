import React, { useEffect, useMemo, useState } from 'react';
import { Plus, Save, X, Download } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { usePermissions } from '../contexts/AuthContext';
import { PageShell, PageHero, HeroButton } from '../components/PageShell';

interface PriceListRef {
  id: string;
  name: string;
  is_default: boolean;
  list_kind?: string;
  valid_from?: string | null;
  valid_to?: string | null;
}
interface Sku {
  id: string; code: string; name: string; brand: string | null;
  size_liters: string; unit: string; active: boolean; reorder_threshold: number | null;
}
interface PriceListItem { id: string; sku_id: string; unit_price: string; }

const BRAND_ORDER = ['Premium', 'Platinum', 'Grace', 'Refill'];
const ALLOWED_SIZES = [0.5, 1, 1.5, 5, 10, 20];

const brandLabel = (brand: string | null) => brand || 'Other';
const sizeNum = (v: string | number) => Math.round(parseFloat(String(v)) * 100) / 100;
const sizeLabel = (v: string | number) => {
  const n = sizeNum(v);
  if (n === 0.5) return '0.5L';
  if (Number.isInteger(n)) return `${n}L`;
  return `${n}L`;
};

const isOperationalSku = (sku: Sku) => {
  if (!ALLOWED_SIZES.includes(sizeNum(sku.size_liters))) return false;
  // Premium Custom 0.5L (KES 500) is operational — draws PREM-0.5L stock.
  const code = (sku.code || '').toUpperCase();
  if (/CUSTOM/i.test(code) && code !== 'PREM-CUSTOM-0.5L') return false;
  if (/CUSTOM/i.test(sku.name || '') && code !== 'PREM-CUSTOM-0.5L') return false;
  if (/Mara\s*Water/i.test(sku.brand || '') || /^WATER-/i.test(sku.code)) return false;
  return true;
};

const groupByBrand = (skus: Sku[]) => {
  const groups = new Map<string, Sku[]>();
  for (const s of skus) {
    const label = brandLabel(s.brand);
    if (!groups.has(label)) groups.set(label, []);
    groups.get(label)!.push(s);
  }
  Array.from(groups.values()).forEach((list) => {
    list.sort((a, b) => sizeNum(a.size_liters) - sizeNum(b.size_liters) || a.name.localeCompare(b.name));
  });
  return Array.from(groups.entries()).sort(([a], [b]) => {
    const ia = BRAND_ORDER.indexOf(a);
    const ib = BRAND_ORDER.indexOf(b);
    if (ia !== -1 || ib !== -1) return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
    return a.localeCompare(b);
  });
};

const EMPTY_SKU_FORM = { code: '', name: '', brand: 'Premium', size_liters: '0.5', unit: 'BOTTLE', reorder_threshold: '' };

const downloadBlob = (path: string, filename: string) => {
  api.get(path, { responseType: 'blob' }).then((res) => {
    const url = window.URL.createObjectURL(new Blob([res.data]));
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  }).catch(() => toast.error('Export failed'));
};

const dateOnly = (v?: string | null) => (v ? String(v).slice(0, 10) : null);

const PricingPage: React.FC = () => {
  const { isDirector } = usePermissions();
  const director = isDirector();

  const [priceLists, setPriceLists] = useState<PriceListRef[]>([]);
  const [selectedListId, setSelectedListId] = useState('');
  const [skus, setSkus] = useState<Sku[]>([]);
  const [items, setItems] = useState<Record<string, PriceListItem>>({});
  const [showInactive, setShowInactive] = useState(false);
  const [loading, setLoading] = useState(true);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState<Record<string, boolean>>({});
  const [showSkuForm, setShowSkuForm] = useState(false);
  const [skuForm, setSkuForm] = useState(EMPTY_SKU_FORM);
  const [savingSku, setSavingSku] = useState(false);

  const canEditSelected = director;

  const fetchSkus = () => api.get('/production/skus').then(res => setSkus(res.data.data)).catch(() => toast.error('Failed to load products'));

  const fetchPriceLists = async () => {
    const res = await api.get('/sales/price-lists');
    const lists: PriceListRef[] = res.data.data;
    setPriceLists(lists);
    const retail = lists.find(l => l.is_default || (l.list_kind || 'default') === 'default') || lists[0];
    setSelectedListId(retail?.id || '');
  };

  useEffect(() => {
    setLoading(true);
    Promise.all([fetchSkus(), fetchPriceLists()]).finally(() => setLoading(false));
  }, []);

  const fetchItems = (listId: string) => {
    if (!listId) return;
    api.get(`/sales/price-lists/${listId}/items`).then(res => {
      const map: Record<string, PriceListItem> = {};
      (res.data.data as PriceListItem[]).forEach(item => { map[item.sku_id] = item; });
      setItems(map);
      setDrafts({});
    }).catch(() => toast.error('Failed to load prices'));
  };

  useEffect(() => {
    fetchItems(selectedListId);
  }, [selectedListId]); // eslint-disable-line react-hooks/exhaustive-deps

  const visibleSkus = useMemo(() => {
    const base = showInactive ? skus : skus.filter(s => s.active);
    return base.filter(isOperationalSku);
  }, [skus, showInactive]);
  const grouped = useMemo(() => groupByBrand(visibleSkus), [visibleSkus]);

  const selectedList = priceLists.find(l => l.id === selectedListId);
  const premiumHighlight = useMemo(() => {
    const prem = visibleSkus.filter(s => s.brand === 'Premium');
    return prem.map(s => ({
      sku: s,
      price: parseFloat(drafts[s.id] ?? items[s.id]?.unit_price ?? '') || null,
    }));
  }, [visibleSkus, drafts, items]);

  const currentValue = (skuId: string) => drafts[skuId] ?? items[skuId]?.unit_price ?? '';
  const isDirty = (skuId: string) => drafts[skuId] !== undefined && drafts[skuId] !== (items[skuId]?.unit_price ?? '');

  const savePrice = async (skuId: string) => {
    if (!canEditSelected) {
      toast.error('Only Admin can edit retail prices');
      return;
    }
    const value = drafts[skuId];
    const price = parseFloat(value);
    if (isNaN(price) || price < 0) { toast.error('Enter a valid price'); return; }
    setSaving(prev => ({ ...prev, [skuId]: true }));
    try {
      const res = await api.put(`/sales/price-lists/${selectedListId}/items/${skuId}`, { unit_price: price });
      setItems(prev => ({ ...prev, [skuId]: res.data.data }));
      setDrafts(prev => { const next = { ...prev }; delete next[skuId]; return next; });
      toast.success('Price updated — drivers and sales will use this');
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update price');
    } finally {
      setSaving(prev => ({ ...prev, [skuId]: false }));
    }
  };

  const handleSkuSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!director) { toast.error('Only Admin can create products'); return; }
    if (!ALLOWED_SIZES.includes(sizeNum(skuForm.size_liters))) {
      toast.error('Use one of: 0.5, 1, 1.5, 5, 10, 20 L');
      return;
    }
    setSavingSku(true);
    try {
      await api.post('/production/skus', {
        ...skuForm,
        brand: skuForm.brand || undefined,
        reorder_threshold: skuForm.reorder_threshold ? parseInt(skuForm.reorder_threshold, 10) : undefined,
      });
      toast.success('Product created');
      setShowSkuForm(false);
      setSkuForm(EMPTY_SKU_FORM);
      fetchSkus();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to create product');
    } finally {
      setSavingSku(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-sky-700"></div>
      </div>
    );
  }

  return (
    <PageShell>
      <PageHero
        eyebrow="Single price source"
        title="Pricing"
        description="Retail sheet drivers sell from. Change a price here and it shows on trips, sales, and dashboards."
        actions={(
          <>
            {selectedListId ? (
              <HeroButton onClick={() => downloadBlob(`/sales/price-lists/${selectedListId}/export`, 'price-list-retail.xlsx')}>
                <Download className="w-4 h-4 mr-2" /> Export
              </HeroButton>
            ) : null}
            {director && (
              <HeroButton variant="solid" onClick={() => setShowSkuForm(true)}>
                <Plus className="w-4 h-4 mr-2" /> New product
              </HeroButton>
            )}
          </>
        )}
        stats={premiumHighlight.map(({ sku, price }) => ({
          label: `Premium ${sizeLabel(sku.size_liters)}`,
          value: price != null ? Number(price).toLocaleString() : '—',
        }))}
      />

      <div className="flex flex-wrap items-center gap-3 text-sm">
        <select
          value={selectedListId}
          onChange={e => setSelectedListId(e.target.value)}
          className="border border-slate-200 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 rounded-xl px-3 py-2.5"
        >
          {priceLists.filter(l => l.is_default || (l.list_kind || 'default') === 'default').length === 0 && (
            <option value="">No retail list</option>
          )}
          {priceLists
            .filter(l => l.is_default || (l.list_kind || 'default') === 'default')
            .map(l => (
              <option key={l.id} value={l.id}>
                {l.name}{l.is_default ? ' · default' : ''}
              </option>
            ))}
        </select>
        {selectedList && (
          <span className="text-slate-500 dark:text-gray-400">
            Valid {dateOnly(selectedList.valid_from) || '—'} → {dateOnly(selectedList.valid_to) || '—'}
          </span>
        )}
        {!canEditSelected && (
          <span className="text-xs text-amber-900 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-100 px-2.5 py-1 rounded-full">
            Read-only — Contact Admin to change prices
          </span>
        )}
        <label className="flex items-center text-slate-600 dark:text-gray-400 ml-auto">
          <input type="checkbox" checked={showInactive} onChange={e => setShowInactive(e.target.checked)} className="mr-2 rounded" />
          Show inactive
        </label>
      </div>

      <p className="text-xs text-slate-500 dark:text-gray-400">
        Showing live sizes only: 0.5L · 1L · 1.5L · 5L · 10L · 20L
      </p>

      <div className="space-y-5">
        {grouped.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-slate-300 dark:border-gray-600 px-6 py-12 text-center text-slate-500">
            No products in the live size set.
          </div>
        ) : grouped.map(([brand, brandSkus]) => (
          <section key={brand} className="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 overflow-hidden">
            <div className="px-5 py-3 border-b border-slate-100 dark:border-gray-700 flex items-center justify-between bg-slate-50/80 dark:bg-gray-900/40">
              <h2 className="text-sm font-semibold tracking-wide text-slate-800 dark:text-gray-100 uppercase">{brand}</h2>
              <span className="text-xs text-slate-500">{brandSkus.length} sizes</span>
            </div>
            <div className="divide-y divide-slate-100 dark:divide-gray-700">
              {brandSkus.map(sku => {
                const dirty = isDirty(sku.id);
                return (
                  <div
                    key={sku.id}
                    className={`flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-4 ${!sku.active ? 'opacity-50' : ''}`}
                  >
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <p className="font-medium text-slate-900 dark:text-gray-100 truncate">{sku.name}</p>
                        <span className="text-xs font-semibold px-2 py-0.5 rounded-full bg-sky-50 text-sky-800 dark:bg-sky-900/40 dark:text-sky-100">
                          {sizeLabel(sku.size_liters)}
                        </span>
                        {!sku.active && (
                          <span className="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">Inactive</span>
                        )}
                      </div>
                      <p className="text-xs text-slate-500 mt-0.5 font-mono">{sku.code}</p>
                    </div>
                    <div className="flex items-center gap-2">
                      <div className="relative">
                        <span className="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400">KES</span>
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          value={currentValue(sku.id)}
                          onChange={e => setDrafts(prev => ({ ...prev, [sku.id]: e.target.value }))}
                          placeholder="Not set"
                          disabled={!canEditSelected}
                          className="w-36 pl-10 pr-3 py-2.5 rounded-xl border border-slate-200 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-base font-semibold tabular-nums disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-sky-600/30 focus:border-sky-600"
                        />
                      </div>
                      {director && (
                        <button
                          onClick={() => savePrice(sku.id)}
                          disabled={!canEditSelected || !dirty || saving[sku.id]}
                          className="inline-flex items-center px-3.5 py-2.5 rounded-xl text-sm font-medium bg-sky-800 text-white hover:bg-sky-900 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                          <Save className="w-3.5 h-3.5 mr-1.5" />
                          {saving[sku.id] ? 'Saving…' : dirty ? 'Save' : 'Saved'}
                        </button>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </section>
        ))}
      </div>

      {showSkuForm && (
        <div className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-xl max-w-md w-full p-6 space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">New product</h3>
              <button onClick={() => setShowSkuForm(false)} className="text-gray-400 hover:text-gray-600">
                <X className="w-5 h-5" />
              </button>
            </div>
            <form onSubmit={handleSkuSubmit} className="space-y-3">
              <input
                required
                placeholder="Code"
                value={skuForm.code}
                onChange={e => setSkuForm({ ...skuForm, code: e.target.value })}
                className="w-full border rounded-xl px-3 py-2 dark:bg-gray-900 dark:border-gray-600"
              />
              <input
                required
                placeholder="Name"
                value={skuForm.name}
                onChange={e => setSkuForm({ ...skuForm, name: e.target.value })}
                className="w-full border rounded-xl px-3 py-2 dark:bg-gray-900 dark:border-gray-600"
              />
              <select
                value={skuForm.brand}
                onChange={e => setSkuForm({ ...skuForm, brand: e.target.value })}
                className="w-full border rounded-xl px-3 py-2 dark:bg-gray-900 dark:border-gray-600"
              >
                {BRAND_ORDER.map(b => <option key={b} value={b}>{b}</option>)}
              </select>
              <select
                value={skuForm.size_liters}
                onChange={e => setSkuForm({ ...skuForm, size_liters: e.target.value })}
                className="w-full border rounded-xl px-3 py-2 dark:bg-gray-900 dark:border-gray-600"
              >
                {ALLOWED_SIZES.map(s => <option key={s} value={String(s)}>{s}L</option>)}
              </select>
              <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={() => setShowSkuForm(false)} className="px-4 py-2 rounded-xl border">Cancel</button>
                <button type="submit" disabled={savingSku} className="px-4 py-2 rounded-xl bg-sky-800 text-white disabled:opacity-50">
                  {savingSku ? 'Saving…' : 'Create'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </PageShell>
  );
};

export default PricingPage;
