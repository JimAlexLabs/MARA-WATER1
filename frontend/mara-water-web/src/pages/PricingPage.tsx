import React, { useEffect, useMemo, useState } from 'react';
import { Plus, Save, X, Tag, Download } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { usePermissions } from '../contexts/AuthContext';

// Ops brief §5: three tiers —
// 1) Default (Director only)
// 2) Corporate (Manager + Director)
// 3) Competitive comparison table (Manager logs named competitor prices)

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
interface CompetitorCompany { id: string; name: string; }
interface MatrixRow {
  sku_id: string; code: string; name: string; brand: string | null; size_liters: string;
  mara_default: number | null; mara_corporate: number | null;
  competitors: Record<string, number | null>;
}

const BRAND_ORDER = ['Premium', 'Platinum', 'Grace', 'Refill'];
const brandLabel = (brand: string | null) => brand || 'Uncategorized';
const groupByBrand = (skus: Sku[]) => {
  const groups = new Map<string, Sku[]>();
  for (const s of skus) {
    const label = brandLabel(s.brand);
    if (!groups.has(label)) groups.set(label, []);
    groups.get(label)!.push(s);
  }
  return Array.from(groups.entries()).sort(([a], [b]) => {
    const ia = BRAND_ORDER.indexOf(a);
    const ib = BRAND_ORDER.indexOf(b);
    if (ia !== -1 || ib !== -1) return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
    return a.localeCompare(b);
  });
};

const EMPTY_SKU_FORM = { code: '', name: '', brand: '', size_liters: '', unit: 'BOTTLE', reorder_threshold: '' };

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

type TabKind = 'default' | 'corporate' | 'competitive';

const PricingPage: React.FC = () => {
  const { isDirector, accessTier } = usePermissions();
  const director = isDirector();
  const tier = accessTier();
  const canEditCorporate = director || tier === 'manager';
  const canEditCompetitive = canEditCorporate;

  const [priceLists, setPriceLists] = useState<PriceListRef[]>([]);
  const [activeKind, setActiveKind] = useState<TabKind>('default');
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

  const [companies, setCompanies] = useState<CompetitorCompany[]>([]);
  const [matrixRows, setMatrixRows] = useState<MatrixRow[]>([]);
  const [compDrafts, setCompDrafts] = useState<Record<string, string>>({});
  const [savingComp, setSavingComp] = useState<Record<string, boolean>>({});

  const canEditSelected = useMemo(() => {
    if (activeKind === 'competitive') return canEditCompetitive;
    const list = priceLists.find(l => l.id === selectedListId);
    const kind = list?.list_kind || (list?.is_default ? 'default' : 'other');
    if (kind === 'default') return director;
    if (kind === 'corporate') return canEditCorporate;
    return director;
  }, [priceLists, selectedListId, director, canEditCorporate, canEditCompetitive, activeKind]);

  const fetchSkus = () => api.get('/production/skus').then(res => setSkus(res.data.data)).catch(() => toast.error('Failed to load products'));

  const fetchPriceLists = async () => {
    const res = await api.get('/sales/price-lists');
    setPriceLists(res.data.data);
  };

  const fetchCompetitive = async () => {
    try {
      const res = await api.get('/sales/competitive/matrix');
      setCompanies(res.data.data.companies);
      setMatrixRows(res.data.data.rows);
      setCompDrafts({});
    } catch {
      toast.error('Failed to load competitive matrix');
    }
  };

  const listsForKind = useMemo(() => {
    return priceLists.filter(l => {
      const kind = l.list_kind || (l.is_default ? 'default' : 'other');
      if (activeKind === 'default') return kind === 'default' || l.is_default;
      if (activeKind === 'corporate') return kind === 'corporate';
      return false;
    });
  }, [priceLists, activeKind]);

  useEffect(() => {
    setLoading(true);
    Promise.all([fetchSkus(), fetchPriceLists(), fetchCompetitive()]).finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (activeKind === 'competitive') return;
    const preferred = listsForKind.find(l => l.is_default) || listsForKind[0];
    setSelectedListId(preferred?.id || '');
  }, [activeKind, priceLists]); // eslint-disable-line react-hooks/exhaustive-deps

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
    if (activeKind !== 'competitive') fetchItems(selectedListId);
  }, [selectedListId, activeKind]); // eslint-disable-line react-hooks/exhaustive-deps

  const visibleSkus = useMemo(() => showInactive ? skus : skus.filter(s => s.active), [skus, showInactive]);
  const grouped = useMemo(() => groupByBrand(visibleSkus), [visibleSkus]);

  const currentValue = (skuId: string) => drafts[skuId] ?? items[skuId]?.unit_price ?? '';
  const isDirty = (skuId: string) => drafts[skuId] !== undefined && drafts[skuId] !== (items[skuId]?.unit_price ?? '');

  const savePrice = async (skuId: string) => {
    if (!canEditSelected) {
      toast.error(activeKind === 'default'
        ? 'Only the Director can edit the default price list'
        : 'Only Manager or Director can edit the corporate list');
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
      toast.success('Price updated');
      fetchCompetitive();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update price');
    } finally {
      setSaving(prev => ({ ...prev, [skuId]: false }));
    }
  };

  const compKey = (skuId: string, companyId: string) => `${skuId}:${companyId}`;
  const saveCompetitorPrice = async (skuId: string, companyId: string) => {
    if (!canEditCompetitive) {
      toast.error('Only Manager or Director can log competitor prices');
      return;
    }
    const key = compKey(skuId, companyId);
    const price = parseFloat(compDrafts[key] ?? '');
    if (isNaN(price) || price < 0) { toast.error('Enter a valid price'); return; }
    setSavingComp(prev => ({ ...prev, [key]: true }));
    try {
      await api.put('/sales/competitive/prices', {
        competitor_company_id: companyId,
        sku_id: skuId,
        unit_price: price,
      });
      toast.success('Competitor price saved');
      await fetchCompetitive();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to save');
    } finally {
      setSavingComp(prev => ({ ...prev, [key]: false }));
    }
  };

  const handleSkuSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!director) { toast.error('Only the Director can create products'); return; }
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
      fetchCompetitive();
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
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Pricing</h1>
          <p className="text-gray-600 dark:text-gray-400">
            Default (Director), Corporate (Manager), and Competitive comparison (named competitors vs MARA).
          </p>
        </div>
        <div className="flex gap-2">
          {activeKind === 'competitive' ? (
            <button
              onClick={() => downloadBlob('/sales/competitive/export', 'competitive-pricing.xlsx')}
              className="flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
            >
              <Download className="w-4 h-4 mr-2" /> Export Excel
            </button>
          ) : selectedListId ? (
            <button
              onClick={() => downloadBlob(`/sales/price-lists/${selectedListId}/export`, `price-list-${activeKind}.xlsx`)}
              className="flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
            >
              <Download className="w-4 h-4 mr-2" /> Export Excel
            </button>
          ) : null}
          {director && (
            <button onClick={() => setShowSkuForm(true)} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
              <Plus className="w-4 h-4 mr-2" /> New Product
            </button>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        {([
          ['default', `Default (retail)${director ? '' : ' — view only'}`],
          ['corporate', 'Corporate / Wholesale'],
          ['competitive', 'Competitive comparison'],
        ] as [TabKind, string][]).map(([kind, label]) => (
          <button
            key={kind}
            onClick={() => setActiveKind(kind)}
            className={`px-4 py-2 rounded-lg text-sm font-medium ${activeKind === kind ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200'}`}
          >
            {label}
          </button>
        ))}
      </div>

      {activeKind !== 'competitive' && (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <select value={selectedListId} onChange={e => setSelectedListId(e.target.value)}
              className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 text-sm">
              {listsForKind.length === 0 && <option value="">No list for this kind</option>}
              {listsForKind.map(l => (
                <option key={l.id} value={l.id}>
                  {l.name}{l.is_default ? ' (default)' : ''}{l.valid_from ? ` · ${l.valid_from}` : ''}{l.valid_to ? `–${l.valid_to}` : ''}
                </option>
              ))}
            </select>
            {!canEditSelected && (
              <span className="text-xs text-amber-800 bg-amber-100 px-2 py-1 rounded">
                Read-only for your role
              </span>
            )}
            <label className="flex items-center text-sm text-gray-600 dark:text-gray-400 ml-auto">
              <input type="checkbox" checked={showInactive} onChange={e => setShowInactive(e.target.checked)} className="mr-2" />
              Show inactive products
            </label>
          </div>

          <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
            <div className="p-6 space-y-6">
              {grouped.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">No products yet.</p>
              ) : grouped.map(([brand, brandSkus]) => (
                <div key={brand}>
                  <h3 className="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2 flex items-center">
                    <Tag className="w-3.5 h-3.5 mr-1.5" />{brand}
                  </h3>
                  <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                      <thead>
                        <tr>
                          <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Product</th>
                          <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Size</th>
                          <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                          <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Price (KES)</th>
                          <th className="px-4 py-2"></th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                        {brandSkus.map(sku => (
                          <tr key={sku.id} className={!sku.active ? 'opacity-50' : ''}>
                            <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{sku.name}</td>
                            <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{parseFloat(sku.size_liters)}L</td>
                            <td className="px-4 py-2 text-sm">
                              {sku.active ? (
                                <span className="text-xs text-green-700 bg-green-100 px-2 py-0.5 rounded-full">Active</span>
                              ) : (
                                <span className="text-xs text-gray-600 bg-gray-100 px-2 py-0.5 rounded-full">Inactive</span>
                              )}
                            </td>
                            <td className="px-4 py-2">
                              <input
                                type="number" min="0" step="0.01"
                                value={currentValue(sku.id)}
                                onChange={e => setDrafts(prev => ({ ...prev, [sku.id]: e.target.value }))}
                                placeholder="Not set"
                                disabled={!canEditSelected}
                                className="w-28 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm disabled:opacity-60"
                              />
                            </td>
                            <td className="px-4 py-2">
                              <button
                                onClick={() => savePrice(sku.id)}
                                disabled={!canEditSelected || !isDirty(sku.id) || saving[sku.id]}
                                className="flex items-center px-2.5 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed"
                              >
                                <Save className="w-3.5 h-3.5 mr-1" /> {saving[sku.id] ? 'Saving…' : 'Save'}
                              </button>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </>
      )}

      {activeKind === 'competitive' && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
          <div className="p-4 border-b border-gray-200 dark:border-gray-700">
            <p className="text-sm text-gray-600 dark:text-gray-400">
              Log each competitor&apos;s price per SKU. MARA default and corporate columns are read-only here — edit them on their own tabs.
              {!canEditCompetitive && <span className="ml-2 text-amber-700">Read-only for your role.</span>}
            </p>
          </div>
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead>
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase sticky left-0 bg-white dark:bg-gray-800">Product</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">MARA Default</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">MARA Corporate</th>
                {companies.map(c => (
                  <th key={c.id} className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">{c.name}</th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
              {matrixRows.length === 0 ? (
                <tr><td colSpan={3 + companies.length} className="px-4 py-8 text-center text-sm text-gray-500">No products yet.</td></tr>
              ) : matrixRows.map(row => (
                <tr key={row.sku_id}>
                  <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100 sticky left-0 bg-white dark:bg-gray-800">
                    {row.name}
                    <span className="block text-xs text-gray-500">{parseFloat(String(row.size_liters))}L</span>
                  </td>
                  <td className="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                    {row.mara_default != null ? Number(row.mara_default).toLocaleString() : '—'}
                  </td>
                  <td className="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                    {row.mara_corporate != null ? Number(row.mara_corporate).toLocaleString() : '—'}
                  </td>
                  {companies.map(c => {
                    const key = compKey(row.sku_id, c.id);
                    const stored = row.competitors[c.id];
                    const draft = compDrafts[key];
                    const dirty = draft !== undefined && draft !== (stored != null ? String(stored) : '');
                    return (
                      <td key={c.id} className="px-3 py-2">
                        <div className="flex items-center gap-1">
                          <input
                            type="number" min="0" step="0.01"
                            value={draft ?? (stored != null ? String(stored) : '')}
                            onChange={e => setCompDrafts(prev => ({ ...prev, [key]: e.target.value }))}
                            disabled={!canEditCompetitive}
                            placeholder="—"
                            className="w-24 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1 text-sm disabled:opacity-60"
                          />
                          <button
                            onClick={() => saveCompetitorPrice(row.sku_id, c.id)}
                            disabled={!canEditCompetitive || !dirty || savingComp[key]}
                            className="px-2 py-1 text-xs bg-blue-600 text-white rounded disabled:opacity-40"
                          >
                            {savingComp[key] ? '…' : 'Save'}
                          </button>
                        </div>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {showSkuForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">New Product</h3>
              <button onClick={() => setShowSkuForm(false)} className="text-gray-400 hover:text-gray-600"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleSkuSubmit} className="space-y-3">
              <input required placeholder="Code" value={skuForm.code} onChange={e => setSkuForm({ ...skuForm, code: e.target.value })} className="w-full border rounded-md px-3 py-2 dark:bg-gray-700 dark:border-gray-600" />
              <input required placeholder="Name" value={skuForm.name} onChange={e => setSkuForm({ ...skuForm, name: e.target.value })} className="w-full border rounded-md px-3 py-2 dark:bg-gray-700 dark:border-gray-600" />
              <input placeholder="Brand" value={skuForm.brand} onChange={e => setSkuForm({ ...skuForm, brand: e.target.value })} className="w-full border rounded-md px-3 py-2 dark:bg-gray-700 dark:border-gray-600" />
              <div className="grid grid-cols-2 gap-3">
                <input required type="number" step="0.01" placeholder="Size (L)" value={skuForm.size_liters} onChange={e => setSkuForm({ ...skuForm, size_liters: e.target.value })} className="border rounded-md px-3 py-2 dark:bg-gray-700 dark:border-gray-600" />
                <input required placeholder="Unit" value={skuForm.unit} onChange={e => setSkuForm({ ...skuForm, unit: e.target.value })} className="border rounded-md px-3 py-2 dark:bg-gray-700 dark:border-gray-600" />
              </div>
              <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={() => setShowSkuForm(false)} className="px-4 py-2 border rounded-md">Cancel</button>
                <button type="submit" disabled={savingSku} className="px-4 py-2 bg-blue-600 text-white rounded-md">{savingSku ? 'Saving…' : 'Create'}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default PricingPage;
