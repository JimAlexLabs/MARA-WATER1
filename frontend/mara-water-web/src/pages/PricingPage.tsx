import React, { useEffect, useMemo, useState } from 'react';
import { Plus, Save, X, Tag } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

// Round 2 Phase 10: "Add a reference page listing every product ...
// with its current selling price. This becomes the single source of
// truth that Sales, Driver Trip Logs, and Stock Reconciliation pull unit
// prices from" -- price_lists/price_list_items already existed (Sales
// and Stock Reconciliation already read the default one); this page is
// what makes it actually editable, and Driver Trip Log now pre-fills
// from it too (see FleetPage's trip form).

interface PriceListRef { id: string; name: string; is_default: boolean; }
interface Sku {
  id: string; code: string; name: string; brand: string | null;
  size_liters: string; unit: string; active: boolean; reorder_threshold: number | null;
}
interface PriceListItem { id: string; sku_id: string; unit_price: string; }

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

const PricingPage: React.FC = () => {
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

  const fetchSkus = () => api.get('/production/skus').then(res => setSkus(res.data.data)).catch(() => toast.error('Failed to load products'));

  const fetchPriceLists = async () => {
    const res = await api.get('/sales/price-lists');
    const lists: PriceListRef[] = res.data.data;
    setPriceLists(lists);
    if (!selectedListId) {
      setSelectedListId((lists.find(l => l.is_default) || lists[0])?.id || '');
    }
  };

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
    setLoading(true);
    Promise.all([fetchSkus(), fetchPriceLists()]).finally(() => setLoading(false));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => { fetchItems(selectedListId); }, [selectedListId]);

  const visibleSkus = useMemo(() => showInactive ? skus : skus.filter(s => s.active), [skus, showInactive]);
  const grouped = useMemo(() => groupByBrand(visibleSkus), [visibleSkus]);

  const currentValue = (skuId: string) => drafts[skuId] ?? items[skuId]?.unit_price ?? '';
  const isDirty = (skuId: string) => drafts[skuId] !== undefined && drafts[skuId] !== (items[skuId]?.unit_price ?? '');

  const savePrice = async (skuId: string) => {
    const value = drafts[skuId];
    const price = parseFloat(value);
    if (isNaN(price) || price < 0) { toast.error('Enter a valid price'); return; }
    setSaving(prev => ({ ...prev, [skuId]: true }));
    try {
      const res = await api.put(`/sales/price-lists/${selectedListId}/items/${skuId}`, { unit_price: price });
      setItems(prev => ({ ...prev, [skuId]: res.data.data }));
      setDrafts(prev => { const next = { ...prev }; delete next[skuId]; return next; });
      toast.success('Price updated');
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update price');
    } finally {
      setSaving(prev => ({ ...prev, [skuId]: false }));
    }
  };

  const handleSkuSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
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

  const selectedList = priceLists.find(l => l.id === selectedListId);

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
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Products &amp; Prices</h1>
          <p className="text-gray-600 dark:text-gray-400">
            The current selling price for every product -- Sales, Driver Trip Logs, and Stock Reconciliation all read from this.
          </p>
        </div>
        <button onClick={() => setShowSkuForm(true)} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
          <Plus className="w-4 h-4 mr-2" /> New Product
        </button>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <select value={selectedListId} onChange={e => setSelectedListId(e.target.value)}
          className="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 text-sm">
          {priceLists.map(l => <option key={l.id} value={l.id}>{l.name}{l.is_default ? ' (default)' : ''}</option>)}
        </select>
        {selectedList && !selectedList.is_default && (
          <span className="text-xs text-amber-700 bg-amber-100 px-2 py-1 rounded-full">
            Not the default list -- Sales/Driver Trips/Stock Reconciliation use "{priceLists.find(l => l.is_default)?.name}" unless a sale explicitly picks this one
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
                            className="w-28 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm"
                          />
                        </td>
                        <td className="px-4 py-2">
                          <button
                            onClick={() => savePrice(sku.id)}
                            disabled={!isDirty(sku.id) || saving[sku.id]}
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

      {showSkuForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">New Product</h3>
              <button onClick={() => setShowSkuForm(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleSkuSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Product Code</label>
                <input required type="text" placeholder="e.g. PREM-CUSTOM-0.5L" value={skuForm.code} onChange={e => setSkuForm({ ...skuForm, code: e.target.value.toUpperCase() })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                <input required type="text" placeholder="e.g. Premium Custom 0.5L Bottle" value={skuForm.name} onChange={e => setSkuForm({ ...skuForm, name: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Brand</label>
                  <input type="text" placeholder="e.g. Premium" value={skuForm.brand} onChange={e => setSkuForm({ ...skuForm, brand: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Size (Litres)</label>
                  <input required type="number" step="0.01" min="0.01" value={skuForm.size_liters} onChange={e => setSkuForm({ ...skuForm, size_liters: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Unit</label>
                  <select value={skuForm.unit} onChange={e => setSkuForm({ ...skuForm, unit: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="BOTTLE">Bottle</option>
                    <option value="CONTAINER">Container</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Reorder Threshold</label>
                  <input type="number" min="0" placeholder="Default" value={skuForm.reorder_threshold} onChange={e => setSkuForm({ ...skuForm, reorder_threshold: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowSkuForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={savingSku} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingSku ? 'Creating…' : 'Create Product'}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default PricingPage;
