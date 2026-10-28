import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Plus,
  Search,
  Package,
  Calendar,
  ChevronLeft,
  Trash2,
  AlertTriangle,
  Gauge,
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { usePermissions } from '../contexts/AuthContext';
import { PageShell, PageHero, HeroButton } from '../components/PageShell';
import { CONTACT_ADMIN_MSG } from '../utils/managerLocks';

interface Sku {
  id: string;
  code: string;
  name: string;
  brand?: string | null;
  size_liters: string;
}

interface Material {
  id: string;
  code: string;
  name: string;
  category: string;
  uom: string;
  min_level: string;
  is_consumable: boolean;
}

interface BomItem {
  id: string;
  sku_id: string;
  material_id: string;
  qty_per_unit: string;
  uom: string;
  material: Material;
}

interface DailySummary {
  date: string;
  batch_count: number;
  sku_count: number;
  total_bales: number;
}

interface DailySkuRow {
  sku_id: string;
  sku_code: string;
  sku_name: string;
  brand?: string | null;
  size_liters?: string | number;
  qty_bales: number;
  batch_count: number;
  batches: Array<{
    id: string;
    code: string;
    planned_qty: number;
    actual_qty?: number | null;
    status: string;
  }>;
}

interface DailyDetail {
  date: string;
  total_bales: number;
  sku_count: number;
  batch_count: number;
  skus: DailySkuRow[];
}

interface CapacityRow {
  size: string;
  size_liters: number;
  bottles_per_bale: number;
  uses_bailing: boolean;
  labels_on_hand: number;
  bailing_on_hand: number | null;
  bailing_display?: string;
  bottles_on_hand: number;
  caps_on_hand: number;
  seals_on_hand: number;
  binding_constraint: string;
  bales_possible: number;
  bottles_possible: number;
  production_left?: { bales: number; bottles: number; until: string | null };
}

interface CapacityAlert {
  level: 'critical' | 'warning' | 'info';
  size: string;
  code: string;
  message: string;
  bales_left: number;
  stock?: {
    labels: number;
    bailing: number | null;
    bottles: number;
    caps: number;
    seals: number;
  };
}

const todayIso = () => new Date().toISOString().slice(0, 10);

const ProductionPage: React.FC = () => {
  const [searchParams] = useSearchParams();
  const { isDirector, accessTier } = usePermissions();
  const director = isDirector() || accessTier() === 'director';
  const [canEditProduction, setCanEditProduction] = useState(director);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [activeTab, setActiveTab] = useState('capacity');
  const [showBatchForm, setShowBatchForm] = useState(false);
  const [submittingBatch, setSubmittingBatch] = useState(false);

  useEffect(() => {
    if (director) {
      setCanEditProduction(true);
      return;
    }
    api.get('/settings')
      .then((res) => {
        const locks = res.data?.data?.manager_ui_locks || {};
        setCanEditProduction(locks.manager_edit_production === false);
      })
      .catch(() => setCanEditProduction(false));
  }, [director]);

  const [skus, setSkus] = useState<Sku[]>([]);
  const [materials, setMaterials] = useState<Material[]>([]);
  const [bomItems, setBomItems] = useState<BomItem[]>([]);
  const [bomSkuId, setBomSkuId] = useState('');
  const [bomForm, setBomForm] = useState({ material_id: '', qty_per_unit: '1', uom: 'PCS' });

  const [dailyDays, setDailyDays] = useState<DailySummary[]>([]);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [dayDetail, setDayDetail] = useState<DailyDetail | null>(null);
  const [dayLoading, setDayLoading] = useState(false);
  const [capacityRows, setCapacityRows] = useState<CapacityRow[]>([]);
  const [capacityAlerts, setCapacityAlerts] = useState<CapacityAlert[]>([]);
  const [capacitySummary, setCapacitySummary] = useState<{ total_bales_possible?: number; sizes_blocked?: number; rules?: Record<string, string> } | null>(null);

  const [batchForm, setBatchForm] = useState({
    sku_id: '',
    planned_qty: '',
    manufacture_date: todayIso(),
  });

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
    const tab = searchParams.get('tab');
    if (tab === 'materials' || tab === 'bom' || tab === 'daily' || tab === 'capacity') setActiveTab(tab);
  }, [searchParams]);

  useEffect(() => {
    fetchDaily();
    fetchCapacity();
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/production/materials').then(res => setMaterials(res.data.data)).catch(() => {});
  }, []);

  useEffect(() => {
    if (!bomSkuId) { setBomItems([]); return; }
    api.get(`/production/bom?sku_id=${bomSkuId}`).then(res => setBomItems(res.data.data)).catch(() => {});
  }, [bomSkuId]);

  const fetchDaily = async () => {
    try {
      setLoading(true);
      const res = await api.get('/production/daily', { params: { limit: 90 } });
      setDailyDays(res.data.data || []);
    } catch {
      toast.error('Failed to fetch daily production');
    } finally {
      setLoading(false);
    }
  };

  const fetchCapacity = async () => {
    try {
      const res = await api.get('/production/capacity');
      setCapacityRows(res.data.data || []);
      setCapacityAlerts(res.data.alerts || []);
      setCapacitySummary(res.data.summary || null);
    } catch {
      /* capacity endpoint may be deploying */
    }
  };

  const openDay = async (date: string) => {
    setSelectedDate(date);
    setDayLoading(true);
    try {
      const res = await api.get('/production/daily', { params: { date } });
      setDayDetail(res.data.data);
    } catch {
      toast.error('Failed to load production for that date');
      setSelectedDate(null);
      setDayDetail(null);
    } finally {
      setDayLoading(false);
    }
  };

  const handleBatchSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmittingBatch(true);
    const mfgDate = batchForm.manufacture_date;
    try {
      const res = await api.post('/qa/batches', {
        sku_id: batchForm.sku_id,
        planned_qty: Number(batchForm.planned_qty),
        manufacture_date: mfgDate,
      });
      toast.success('Batch recorded — packaging deducted from inventory');
      // Do not toast material_warnings (negative balances). Production must
      // never block or scare the operator; capacity/alerts live on the Capacity tab.
      setShowBatchForm(false);
      setBatchForm({ sku_id: '', planned_qty: '', manufacture_date: todayIso() });
      await fetchDaily();
      await fetchCapacity();
      if (selectedDate === mfgDate) {
        await openDay(mfgDate);
      }
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error(
        (Array.isArray(firstError) ? firstError[0] : firstError) ||
          error.response?.data?.message ||
          'Failed to create batch'
      );
    } finally {
      setSubmittingBatch(false);
    }
  };

  const handleAddBomLine = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!bomSkuId) { toast.error('Select a product first'); return; }
    try {
      const res = await api.post('/production/bom', { sku_id: bomSkuId, ...bomForm });
      setBomItems([...bomItems, res.data.data.bom_item]);
      setBomForm({ material_id: '', qty_per_unit: '1', uom: 'PCS' });
      toast.success('Added to bill of materials');
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to add BOM line');
    }
  };

  const handleRemoveBomLine = async (id: string) => {
    if (!window.confirm('Remove this material from the bill of materials?')) return;
    try {
      await api.delete(`/production/bom/${id}`);
      setBomItems(bomItems.filter(b => b.id !== id));
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to remove BOM line');
    }
  };

  const filteredDays = dailyDays.filter(d =>
    !searchTerm || d.date.includes(searchTerm)
  );

  const filteredMaterials = materials.filter(m =>
    m.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
    m.code.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const todayTotal = dailyDays.find(d => d.date === todayIso())?.total_bales ?? 0;
  const recentDays = dailyDays.slice(0, 7);
  const weekTotal = recentDays.reduce((s, d) => s + (d.total_bales || 0), 0);

  if (loading && dailyDays.length === 0) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <PageShell>
      <PageHero
        eyebrow="Batches · packaging"
        title="Production"
        description="Each bale deducts bottles, labels, caps, seals, and bailing paper from inventory."
        actions={
          (director || canEditProduction) ? (
            <HeroButton onClick={() => setShowBatchForm(true)} variant="solid">
              <Plus className="w-4 h-4 mr-2" />
              New Batch
            </HeroButton>
          ) : undefined
        }
      />
      {!director && !canEditProduction && (
        <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2">
          {CONTACT_ADMIN_MSG}
        </p>
      )}

      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="mara-stat">
          <div className="flex items-center">
            <Package className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Today (bales)</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{todayTotal.toLocaleString()}</p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <Calendar className="w-8 h-8 text-indigo-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Last 7 days</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{weekTotal.toLocaleString()}</p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <Gauge className="w-8 h-8 text-sky-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Bales still possible</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {Number(capacitySummary?.total_bales_possible ?? 0).toLocaleString()}
              </p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <AlertTriangle className={`w-8 h-8 ${(capacitySummary?.sizes_blocked || 0) > 0 ? 'text-red-600' : 'text-green-600'}`} />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Sizes blocked</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{capacitySummary?.sizes_blocked ?? 0}</p>
            </div>
          </div>
        </div>
      </div>

      {capacityAlerts.length > 0 && (
        <div className="mara-panel p-4 space-y-2 border-l-4 border-amber-500">
          <div className="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
            <AlertTriangle className="w-4 h-4 text-amber-600" />
            Stock &amp; production runway
          </div>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Production left until the binding material depletes. Pending orders are not counted until received.
          </p>
          <ul className="space-y-1.5">
            {capacityAlerts.map((a) => (
              <li
                key={`${a.size}-${a.code}`}
                className={`text-sm rounded-md px-3 py-2 ${
                  a.level === 'critical'
                    ? 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-200'
                    : 'bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100'
                }`}
              >
                <span className="font-medium">{a.message}</span>
                {a.stock && (
                  <span className="block text-xs mt-0.5 opacity-80">
                    Stock left — labels {Number(a.stock.labels).toLocaleString()}
                    {a.stock.bailing != null ? ` · bailing ${Number(a.stock.bailing).toLocaleString()}` : ''}
                    {' · '}bottles {Number(a.stock.bottles).toLocaleString()}
                    {' · '}caps {Number(a.stock.caps).toLocaleString()}
                    {' · '}seals {Number(a.stock.seals).toLocaleString()}
                    {a.bales_left > 0 ? ` · ${a.bales_left} bales left` : ''}
                  </span>
                )}
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="mara-panel">
        <div className="border-b border-gray-200 dark:border-gray-700">
          <nav className="flex space-x-8 px-6 overflow-x-auto">
            {[
              { id: 'capacity', label: 'Capacity & stock' },
              { id: 'daily', label: 'Daily Production' },
              { id: 'materials', label: `Materials (${materials.length})` },
              { id: 'bom', label: 'Bill of Materials' },
            ].map(tab => (
              <button
                key={tab.id}
                onClick={() => { setActiveTab(tab.id); setSelectedDate(null); setDayDetail(null); }}
                className={`py-4 px-1 border-b-2 font-medium text-sm ${
                  activeTab === tab.id
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </nav>
        </div>

        <div className="p-6">
          {(activeTab === 'daily' || activeTab === 'materials') && (
            <div className="mb-6">
              <div className="relative max-w-md">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500 w-4 h-4" />
                <input
                  type="text"
                  placeholder={activeTab === 'daily' ? 'Filter by date (YYYY-MM-DD)…' : 'Search materials…'}
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>
          )}

          {activeTab === 'capacity' && (
            <div className="space-y-4">
              <p className="text-sm text-gray-600 dark:text-gray-400">
                Based on current on-hand bottles, labels, caps, seals and bailing paper
                (nothing from pending orders counts until landed). Per bottle: 1 label + 1 cap + 1 seal.
                Bailing: 1 sheet = 24×0.5L, 12×1L / 1.5L, 4×5L; 10L / 20L not baled.
              </p>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                  <thead className="bg-gray-50 dark:bg-gray-900">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">SKU</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Labels</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bailing</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bottles</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Caps</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Seals</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Binding</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bales left</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bottles left</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                    {capacityRows.map((row) => (
                      <tr key={row.size} className={row.bales_possible <= 0 ? 'bg-red-50/60 dark:bg-red-900/10' : ''}>
                        <td className="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{row.size}</td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{Number(row.labels_on_hand).toLocaleString()}</td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-300">
                          {row.uses_bailing
                            ? (Number(row.bailing_on_hand || 0) > 500
                              ? 'More than enough'
                              : `${Number(row.bailing_on_hand || 0).toLocaleString()} sheets`)
                            : 'Not baled'}
                        </td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{Number(row.bottles_on_hand).toLocaleString()}</td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{Number(row.caps_on_hand).toLocaleString()}</td>
                        <td className="px-4 py-3 text-gray-700 dark:text-gray-300">{Number(row.seals_on_hand).toLocaleString()}</td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${
                            row.bales_possible <= 0 ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-900'
                          }`}>
                            {row.binding_constraint}
                          </span>
                        </td>
                        <td className="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{Number(row.bales_possible).toLocaleString()}</td>
                        <td className="px-4 py-3 text-gray-900 dark:text-gray-100">{Number(row.bottles_possible).toLocaleString()}</td>
                      </tr>
                    ))}
                    {capacityRows.length === 0 && (
                      <tr>
                        <td colSpan={9} className="px-4 py-8 text-center text-gray-500">
                          No capacity data yet — packaging stock will appear after sync.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {activeTab === 'daily' && !selectedDate && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKUs</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Batches</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total bales</th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                  {filteredDays.map((day) => (
                    <tr
                      key={day.date}
                      onClick={() => openDay(day.date)}
                      className="hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer"
                    >
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-blue-600">
                        {new Date(day.date + 'T12:00:00').toLocaleDateString(undefined, {
                          weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
                        })}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{day.sku_count}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{day.batch_count}</td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {Number(day.total_bales || 0).toLocaleString()}
                      </td>
                    </tr>
                  ))}
                  {filteredDays.length === 0 && (
                    <tr>
                      <td colSpan={4} className="px-6 py-8 text-sm text-gray-400 dark:text-gray-500 text-center">
                        No production days yet — click New Batch to record today&apos;s output.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'daily' && selectedDate && (
            <div>
              <button
                onClick={() => { setSelectedDate(null); setDayDetail(null); }}
                className="flex items-center text-sm text-blue-600 hover:text-blue-800 mb-4"
              >
                <ChevronLeft className="w-4 h-4 mr-1" />
                Back to all days
              </button>

              {dayLoading || !dayDetail ? (
                <div className="flex items-center justify-center h-40">
                  <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                </div>
              ) : (
                <>
                  <div className="flex flex-wrap items-end justify-between gap-4 mb-6">
                    <div>
                      <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {new Date(dayDetail.date + 'T12:00:00').toLocaleDateString(undefined, {
                          weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
                        })}
                      </h2>
                      <p className="text-sm text-gray-500 dark:text-gray-400">
                        {dayDetail.sku_count} SKU{dayDetail.sku_count === 1 ? '' : 's'} · {dayDetail.batch_count} batch{dayDetail.batch_count === 1 ? '' : 'es'}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Day total</p>
                      <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                        {Number(dayDetail.total_bales || 0).toLocaleString()} <span className="text-sm font-normal text-gray-500">bales</span>
                      </p>
                    </div>
                  </div>

                  <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                      <thead className="bg-gray-50 dark:bg-gray-900">
                        <tr>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Batches</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Bales</th>
                        </tr>
                      </thead>
                      <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        {dayDetail.skus.map((row) => (
                          <tr key={row.sku_id || row.sku_code}>
                            <td className="px-6 py-4 whitespace-nowrap">
                              <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {row.brand ? `${row.brand} — ` : ''}{row.sku_name}
                              </div>
                              <div className="text-sm text-gray-500 dark:text-gray-400">
                                {row.sku_code}{row.size_liters != null ? ` · ${row.size_liters}L` : ''}
                              </div>
                              <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                {row.batches.map(b => b.code).join(', ')}
                              </div>
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{row.batch_count}</td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-gray-100">
                              {Number(row.qty_bales || 0).toLocaleString()}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                      <tfoot>
                        <tr className="bg-gray-50 dark:bg-gray-900">
                          <td className="px-6 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100" colSpan={2}>Total (all SKUs)</td>
                          <td className="px-6 py-3 text-sm font-bold text-gray-900 dark:text-gray-100">
                            {Number(dayDetail.total_bales || 0).toLocaleString()}
                          </td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </>
              )}
            </div>
          )}

          {activeTab === 'materials' && (
            <div>
              <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Display only. To record arrivals or correct counts for labels, bailing papers and bottles, use Inventory → Stock arrival / Correct stock. Production deducts these via each product&apos;s bill of materials.
              </p>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                  <thead className="bg-gray-50 dark:bg-gray-900">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Material</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Category</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">UoM</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Reorder Level</th>
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    {filteredMaterials.map((m) => (
                      <tr key={m.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{m.name}</div>
                          <div className="text-sm text-gray-500 dark:text-gray-400">{m.code}</div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{m.category}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{m.uom}</td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{parseFloat(m.min_level || '0').toLocaleString()}</td>
                      </tr>
                    ))}
                    {materials.length === 0 && (
                      <tr>
                        <td colSpan={4} className="px-6 py-4 text-sm text-gray-400 dark:text-gray-500">
                          No materials yet — add them under Inventory when receiving stock.
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {activeTab === 'bom' && (
            <div>
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Product</label>
                <select
                  value={bomSkuId}
                  onChange={(e) => setBomSkuId(e.target.value)}
                  className="mt-1 block w-full max-w-md border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select a product to view/edit its recipe</option>
                  {skus.map(s => <option key={s.id} value={s.id}>{s.brand ? `${s.brand} -- ` : ''}{s.name}</option>)}
                </select>
              </div>

              {bomSkuId && (
                <>
                  <div className="overflow-x-auto mb-4">
                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                      <thead className="bg-gray-50 dark:bg-gray-900">
                        <tr>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Material</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Qty per unit</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">UoM</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                      </thead>
                      <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        {bomItems.map((b) => (
                          <tr key={b.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{b.material?.name}</td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{parseFloat(b.qty_per_unit).toLocaleString()}</td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{b.uom}</td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm">
                              <button onClick={() => handleRemoveBomLine(b.id)} className="text-red-600 hover:text-red-900">
                                <Trash2 className="w-4 h-4" />
                              </button>
                            </td>
                          </tr>
                        ))}
                        {bomItems.length === 0 && (
                          <tr>
                            <td colSpan={4} className="px-6 py-4 text-sm text-gray-400 dark:text-gray-500">
                              No recipe set for this product yet — add a line below.
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>

                  <form onSubmit={handleAddBomLine} className="flex items-end gap-3 bg-gray-50 dark:bg-gray-900 p-4 rounded-lg">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Material</label>
                      <select
                        required
                        value={bomForm.material_id}
                        onChange={(e) => setBomForm({ ...bomForm, material_id: e.target.value })}
                        className="mt-1 block border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      >
                        <option value="">Select material</option>
                        {materials.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
                      </select>
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Qty per unit</label>
                      <input
                        required type="number" min={0.0001} step="0.0001"
                        value={bomForm.qty_per_unit}
                        onChange={(e) => setBomForm({ ...bomForm, qty_per_unit: e.target.value })}
                        className="mt-1 block w-28 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">UoM</label>
                      <input
                        required type="text"
                        value={bomForm.uom}
                        onChange={(e) => setBomForm({ ...bomForm, uom: e.target.value })}
                        className="mt-1 block w-24 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <button type="submit" className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                      Add
                    </button>
                  </form>
                </>
              )}
            </div>
          )}
        </div>
      </div>

      {showBatchForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">New Batch</h3>
            <form onSubmit={handleBatchSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">SKU</label>
                <select
                  required
                  value={batchForm.sku_id}
                  onChange={(e) => setBatchForm({ ...batchForm, sku_id: e.target.value })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select SKU</option>
                  {skus.map(s => (
                    <option key={s.id} value={s.id}>{s.brand ? `${s.brand} -- ` : ''}{s.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Quantity needed today (bales)</label>
                <input
                  required
                  type="number"
                  min={1}
                  step={1}
                  value={batchForm.planned_qty}
                  onChange={(e) => setBatchForm({ ...batchForm, planned_qty: e.target.value })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Manufacture date</label>
                <input
                  required
                  type="date"
                  value={batchForm.manufacture_date}
                  onChange={(e) => setBatchForm({ ...batchForm, manufacture_date: e.target.value })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Expiry is set from the SKU shelf life. Finished goods land in the main warehouse and materials are drawn via the bill of materials.
              </p>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowBatchForm(false)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={submittingBatch}
                  className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                >
                  {submittingBatch ? 'Saving…' : 'Create Batch'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </PageShell>
  );
};

export default ProductionPage;
