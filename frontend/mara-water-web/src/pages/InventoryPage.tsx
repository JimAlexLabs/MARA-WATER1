import React, { useState, useEffect } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { 
  Plus, 
  Search, 
  Filter, 
  Download, 
  BarChart3, 
  Package,
  Warehouse,
  TrendingUp,
  TrendingDown,
  AlertTriangle,
  Eye,
  Edit,
  Trash2,
  ArrowUpDown
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { PageShell, PageHero, HeroButton } from '../components/PageShell';
import { usePermissions } from '../contexts/AuthContext';
import { locksFromSettingsPayload } from '../utils/managerLocks';

interface StockItem {
  id?: string;
  item_type: 'material' | 'sku';
  material_id?: string | null;
  sku_id?: string | null;
  warehouse_id?: string | null;
  material?: {
    name?: string;
    code?: string;
    uom?: string;
    min_level?: string;
    lead_time_days?: number;
  };
  sku?: {
    name?: string;
    code?: string;
    reorder_threshold?: number | null;
  };
  warehouse?: {
    name?: string;
    code?: string;
  };
  qty?: string;
}

interface RefItem {
  id: string;
  code: string;
  name: string;
  uom?: string;
}

interface RefWarehouse {
  id: string;
  code: string;
  name: string;
}

interface StockCardDay { date: string; opening: number; in: number; out: number; returns: number; closing: number; }
interface StockCardData { opening_balance: number; days: StockCardDay[]; }

// SKUs don't always have a reorder_threshold set yet -- this is the same
// fallback InventoryController::lowStock() uses server-side.
const DEFAULT_SKU_REORDER_THRESHOLD = 10;

interface StockMove {
  id?: string;
  move_type?: 'grn' | 'issue' | 'produce' | 'adjust' | 'transfer';
  item_type: 'material' | 'sku';
  material?: {
    name?: string;
  };
  sku?: {
    name?: string;
  };
  warehouse_from?: {
    name?: string;
  };
  warehouse_to?: {
    name?: string;
  };
  qty?: string;
  uom?: string;
  unit_cost?: string;
  ref_id?: string;
  moved_by?: {
    first_name?: string;
    last_name?: string;
  };
  created_at?: string;
}

const InventoryPage: React.FC = () => {
  const [stockItems, setStockItems] = useState<StockItem[]>([]);
  const [stockMoves, setStockMoves] = useState<StockMove[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [searchParams] = useSearchParams();
  const { isDirector, accessTier } = usePermissions();
  const director = isDirector() || accessTier() === 'director';
  const [canAlterInventory, setCanAlterInventory] = useState(director);
  const [corrections, setCorrections] = useState<Array<{ id: string; status: string; reason?: string | null; payload?: { qty?: number; item_type?: string; uom?: string }; requester?: { first_name?: string; last_name?: string } | null }>>([]);

  useEffect(() => {
    if (director) {
      setCanAlterInventory(true);
      return;
    }
    api.get('/settings/ui-locks')
      .then((res) => {
        const locks = locksFromSettingsPayload(res.data?.data);
        // Unlock manager_edit_inventory = Admin approved alters
        setCanAlterInventory(locks.manager_edit_inventory === false);
      })
      .catch(() => setCanAlterInventory(false));
  }, [director]);

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
    const tab = searchParams.get('tab');
    if (tab) setActiveTab(tab);
  }, [searchParams]);
  const [filterType, setFilterType] = useState('all');
  const [activeTab, setActiveTab] = useState('stock');
  const [showStockMoveForm, setShowStockMoveForm] = useState(false);
  const [materials, setMaterials] = useState<RefItem[]>([]);
  const [skus, setSkus] = useState<RefItem[]>([]);
  const [warehouses, setWarehouses] = useState<RefWarehouse[]>([]);

  // Round 2 Phase 12: Warehouse Stock Card drill-down.
  const [stockCardForm, setStockCardForm] = useState({
    item_type: 'sku', item_id: '', warehouse_id: '',
    date_from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10),
    date_to: new Date().toISOString().slice(0, 10),
  });
  const [stockCard, setStockCard] = useState<StockCardData | null>(null);
  const [stockCardLoading, setStockCardLoading] = useState(false);
  const [prodAlerts, setProdAlerts] = useState<Array<{
    level: string; size: string; code: string; message: string; bales_left: number;
    stock?: { labels: number; bailing: number | null; bottles: number; caps: number; seals: number };
  }>>([]);
  const [prodBalesLeft, setProdBalesLeft] = useState<number | null>(null);

  // One warehouse for the whole plant — arrivals and corrections post there.
  const [stockForm, setStockForm] = useState({
    purpose: 'arrival' as 'arrival' | 'correct_add' | 'correct_set',
    item_type: 'material',
    material_id: '',
    sku_id: '',
    warehouse_id: '',
    qty: '',
    unit_cost: '',
    notes: ''
  });

  useEffect(() => {
    fetchData();
    api.get('/production/materials', { params: { limit: 200 } }).then(res => setMaterials(res.data.data || [])).catch(() => {});
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/production/capacity').then((res) => {
      setProdAlerts(res.data.alerts || []);
      setProdBalesLeft(res.data.summary?.total_bales_possible ?? null);
    }).catch(() => {});
    api.get('/inventory/warehouses').then(res => {
      const rows = res.data.data || [];
      setWarehouses(rows);
      const onlyId = rows[0]?.id || '';
      if (onlyId) {
        setStockForm((form) => form.warehouse_id ? form : { ...form, warehouse_id: onlyId });
        setStockCardForm((form) => form.warehouse_id ? form : { ...form, warehouse_id: onlyId });
      }
    }).catch(() => {});
  }, []);

  const fetchData = async () => {
    try {
      setLoading(true);
      const [stockResponse, movesResponse] = await Promise.all([
        api.get('/inventory/stock-items', { params: { limit: 500 } }),
        api.get('/inventory/stock-moves', { params: { limit: 200 } })
      ]);
      
      setStockItems(stockResponse.data.data);
      setStockMoves(movesResponse.data.data);
      api.get('/inventory/corrections', { params: { status: 'pending' } })
        .then((res) => setCorrections(res.data.data || []))
        .catch(() => setCorrections([]));
    } catch (error) {
      toast.error('Failed to fetch inventory data');
    } finally {
      setLoading(false);
    }
  };

  const fetchStockCard = async (e: React.FormEvent) => {
    e.preventDefault();
    const warehouseId = stockCardForm.warehouse_id || warehouses[0]?.id;
    if (!warehouseId) {
      toast.error('Main warehouse is not set up yet');
      return;
    }
    setStockCardLoading(true);
    try {
      const res = await api.get('/inventory/stock-card', { params: { ...stockCardForm, warehouse_id: warehouseId } });
      setStockCard(res.data.data);
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to fetch stock card');
      setStockCard(null);
    } finally {
      setStockCardLoading(false);
    }
  };

  const openStockForm = (purpose: 'arrival' | 'correct_add' | 'correct_set') => {
    setStockForm((form) => ({
      ...form,
      purpose,
      material_id: '',
      sku_id: '',
      qty: '',
      unit_cost: '',
      notes: '',
      item_type: purpose === 'arrival' ? 'material' : form.item_type,
      warehouse_id: form.warehouse_id || warehouses[0]?.id || '',
    }));
    setShowStockMoveForm(true);
  };

  /** Row Edit / eye: open correction with this item pre-filled (set counted qty). */
  const openAlterItem = (item: StockItem, purpose: 'correct_set' | 'correct_add' = 'correct_set') => {
    const warehouseId = item.warehouse_id || warehouses[0]?.id || '';
    const currentQty = parseFloat(item.qty || '0');
    setStockForm({
      purpose,
      item_type: item.item_type,
      material_id: item.item_type === 'material' ? (item.material_id || '') : '',
      sku_id: item.item_type === 'sku' ? (item.sku_id || '') : '',
      warehouse_id: warehouseId,
      qty: purpose === 'correct_set' ? String(currentQty) : '',
      unit_cost: '',
      notes: '',
    });
    setShowStockMoveForm(true);
  };

  const zeroOutItem = async (item: StockItem) => {
    const name = item.material?.name || item.sku?.name || 'this item';
    const currentQty = parseFloat(item.qty || '0');
    if (currentQty <= 0) {
      toast.success('Already at zero');
      return;
    }
    if (!window.confirm(`Set ${name} to 0 on hand? Current: ${currentQty.toLocaleString()}. Use this for test / wrong entries.`)) {
      return;
    }
    const warehouseId = item.warehouse_id || warehouses[0]?.id || '';
    if (!warehouseId) {
      toast.error('No warehouse is set up yet');
      return;
    }
    const uom = item.item_type === 'material' ? (item.material?.uom || 'pcs') : 'BALE';
    try {
      const zeroRes = await api.post('/inventory/stock-moves', {
        move_type: 'adjust',
        item_type: item.item_type,
        warehouse_from_id: warehouseId,
        qty: currentQty,
        uom,
        ref_entity: 'stock_correction',
        ref_id: 'Zeroed from inventory row',
        ...(item.item_type === 'material'
          ? { material_id: item.material_id }
          : { sku_id: item.sku_id }),
      });
      toast.success(zeroRes.data?.pending_approval ? 'Zero-out sent to the admin for approval' : `${name} set to 0`);
      fetchData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || error.response?.data?.error || 'Failed to zero stock');
    }
  };

  const handleStockFormSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const warehouseId = stockForm.warehouse_id || warehouses[0]?.id || '';
    const itemId = stockForm.item_type === 'material' ? stockForm.material_id : stockForm.sku_id;
    const qty = parseFloat(stockForm.qty);
    if (!itemId) {
      toast.error('Choose the item (labels, bailing paper, bottles, or a finished product)');
      return;
    }
    if (!warehouseId) {
      toast.error('No warehouse is set up yet. Ask a director to seed the main store.');
      return;
    }
    if (!Number.isFinite(qty) || qty < 0 || (stockForm.purpose !== 'correct_set' && qty <= 0)) {
      toast.error(stockForm.purpose === 'correct_set' ? 'Enter the counted quantity on hand (0 or more)' : 'Enter a quantity greater than zero');
      return;
    }
    if (stockForm.purpose !== 'arrival' && !stockForm.notes.trim()) {
      toast.error('Add a short reason for the correction (what was found or what changed)');
      return;
    }

    const material = materials.find(m => m.id === stockForm.material_id);
    const uom = stockForm.item_type === 'material' ? (material?.uom || 'pcs') : 'BALE';
    const onHand = stockItems
      .filter(item => item.item_type === stockForm.item_type && item.warehouse_id === warehouseId && (stockForm.item_type === 'material' ? item.material_id === itemId : item.sku_id === itemId))
      .reduce((sum, item) => sum + parseFloat(item.qty || '0'), 0);

    let payload: Record<string, string | number>;
    if (stockForm.purpose === 'correct_set') {
      const delta = Math.round((qty - onHand) * 1000) / 1000;
      if (Math.abs(delta) < 0.001) {
        toast.success('That quantity is already on hand');
        return;
      }
      payload = {
        move_type: 'adjust',
        item_type: stockForm.item_type,
        qty: Math.abs(delta),
        uom,
        ref_entity: 'stock_correction',
        ref_id: stockForm.notes.trim().slice(0, 100),
        ...(delta > 0 ? { warehouse_to_id: warehouseId } : { warehouse_from_id: warehouseId }),
        ...(stockForm.item_type === 'material' ? { material_id: itemId } : { sku_id: itemId }),
      };
    } else if (stockForm.purpose === 'correct_add') {
      payload = {
        move_type: 'adjust',
        item_type: stockForm.item_type,
        warehouse_to_id: warehouseId,
        qty,
        uom,
        ref_entity: 'stock_correction',
        ref_id: stockForm.notes.trim().slice(0, 100),
        ...(stockForm.item_type === 'material' ? { material_id: itemId } : { sku_id: itemId }),
      };
    } else {
      payload = {
        move_type: 'grn',
        item_type: stockForm.item_type,
        warehouse_to_id: warehouseId,
        qty,
        uom,
        ref_entity: 'stock_arrival',
        ref_id: (stockForm.notes.trim() || 'arrival').slice(0, 100),
        ...(stockForm.unit_cost !== '' ? { unit_cost: stockForm.unit_cost } : {}),
        ...(stockForm.item_type === 'material' ? { material_id: itemId } : { sku_id: itemId }),
      };
    }

    try {
      const posted = await api.post('/inventory/stock-moves', payload);
      toast.success(
        posted.data?.pending_approval
          ? 'Sent to the admin for approval. Stock stays as it is until approved.'
          : stockForm.purpose === 'arrival'
          ? 'Arrival recorded into stock'
          : stockForm.purpose === 'correct_add'
          ? 'Found stock added'
          : 'Counted stock corrected'
      );
      setShowStockMoveForm(false);
      setStockForm(form => ({ ...form, material_id: '', sku_id: '', qty: '', unit_cost: '', notes: '' }));
      fetchData();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || error.response?.data?.error || 'Failed to update stock');
    }
  };

  const activityLabel = (type?: string) => {
    switch (type) {
      case 'grn': return 'Arrival';
      case 'adjust': return 'Correction';
      case 'issue': return 'Used / issued';
      case 'produce': return 'Produced';
      case 'return': return 'Returned';
      case 'transfer': return 'Transfer';
      default: return type || 'Update';
    }
  };

  const filteredStockItems = stockItems.filter(item => {
    const matchesSearch = (item.material?.name || item.sku?.name || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                         (item.material?.code || item.sku?.code || '').toLowerCase().includes(searchTerm.toLowerCase());
    const matchesType = filterType === 'all' || item.item_type === filterType;
    return matchesSearch && matchesType;
  });

  const filteredStockMoves = stockMoves.filter(move => {
    return (move.material?.name || move.sku?.name || '').toLowerCase().includes(searchTerm.toLowerCase());
  });

  const getMoveTypeColor = (type: string) => {
    switch (type) {
      case 'grn': return 'text-green-600 bg-green-100';
      case 'issue': return 'text-red-600 bg-red-100';
      case 'produce': return 'text-blue-600 bg-blue-100';
      case 'adjust': return 'text-yellow-600 bg-yellow-100';
      case 'transfer': return 'text-purple-600 bg-purple-100';
      default: return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700';
    }
  };

  // Each item's own reorder point -- materials.min_level, or
  // skus.reorder_threshold (falling back to the old flat default for
  // SKUs that haven't had one set). Matches the server-side logic in
  // InventoryController::lowStock().
  const getReorderLevel = (item: StockItem): number => {
    if (item.item_type === 'material') {
      return parseFloat(item.material?.min_level || '0');
    }
    return item.sku?.reorder_threshold ?? DEFAULT_SKU_REORDER_THRESHOLD;
  };

  const getStockLevelColor = (qty: number, minLevel: number) => {
    if (qty <= minLevel) return 'text-red-600 bg-red-100';
    if (qty <= minLevel * 1.5) return 'text-yellow-600 bg-yellow-100';
    return 'text-green-600 bg-green-100';
  };

  const getStockLevelIcon = (qty: number, minLevel: number) => {
    if (qty <= minLevel) return <AlertTriangle className="w-4 h-4" />;
    if (qty <= minLevel * 1.5) return <TrendingDown className="w-4 h-4" />;
    return <TrendingUp className="w-4 h-4" />;
  };

  if (loading && stockItems.length === 0 && stockMoves.length === 0) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <PageShell>
      <PageHero
        eyebrow="Main store · one warehouse"
        title="Inventory"
        description="Record arrivals when labels, bailing papers, seals or bottles come in. Correct the count when missing stock is found."
        actions={(
          <>
            {director && (
              <HeroButton variant="solid" onClick={() => openStockForm('arrival')}>
                <Plus className="w-4 h-4 mr-2" /> Stock arrival
              </HeroButton>
            )}
            {(director || canAlterInventory) && (
              <HeroButton onClick={() => openStockForm('correct_add')}>
                Correct stock
              </HeroButton>
            )}
            {!director && !canAlterInventory && (
              <span className="text-xs text-amber-800 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-100 px-3 py-2 rounded-full">
                Stock alters locked — Contact Admin
              </span>
            )}
          </>
        )}
      />

      {(prodAlerts.length > 0 || prodBalesLeft != null) && (
        <div className="mara-panel p-4 border-l-4 border-amber-500 space-y-2">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
              <AlertTriangle className="w-4 h-4 text-amber-600" />
              Production left from current packaging stock
              {prodBalesLeft != null && (
                <span className="font-normal text-gray-600 dark:text-gray-400">
                  — {Number(prodBalesLeft).toLocaleString()} bales possible across sizes
                </span>
              )}
            </div>
            <Link to="/production?tab=capacity" className="text-sm text-blue-600 hover:underline">
              Open capacity table
            </Link>
          </div>
          {prodAlerts.slice(0, 4).map((a) => (
            <div
              key={`${a.size}-${a.code}`}
              className={`text-sm rounded-md px-3 py-2 ${
                a.level === 'critical'
                  ? 'bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-200'
                  : 'bg-amber-50 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100'
              }`}
            >
              {a.message}
              {a.stock && (
                <span className="block text-xs mt-0.5 opacity-80">
                  Stock left: labels {Number(a.stock.labels).toLocaleString()}
                  {a.stock.bailing != null ? ` · bailing ${Number(a.stock.bailing).toLocaleString()}` : ''}
                  {' · '}bottles {Number(a.stock.bottles).toLocaleString()}
                  {a.bales_left > 0 ? ` · ${a.bales_left} bales until depleted` : ''}
                </span>
              )}
            </div>
          ))}
        </div>
      )}

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="mara-stat">
          <div className="flex items-center">
            <Package className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Items</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{stockItems.length}</p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <Warehouse className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Stock</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {stockItems.reduce((sum, item) => sum + parseFloat(item.qty || '0'), 0).toLocaleString()}
              </p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <AlertTriangle className="w-8 h-8 text-red-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Low Stock Items</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {stockItems.filter(item => parseFloat(item.qty || '0') <= getReorderLevel(item)).length}
              </p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <ArrowUpDown className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Arrivals</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{stockMoves.filter(m => (m.move_type || '') === 'grn').length}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="mara-panel">
        <div className="border-b border-gray-200 dark:border-gray-700">
          <nav className="flex space-x-8 px-6">
            <button
              onClick={() => setActiveTab('stock')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'stock'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Stock Items ({stockItems.length})
            </button>
            <button
              onClick={() => setActiveTab('moves')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'moves'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Arrivals & corrections ({stockMoves.filter(m => ['grn', 'adjust'].includes(m.move_type || '')).length})
            </button>
            <button
              onClick={() => setActiveTab('stockcard')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'stockcard'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Stock Card
            </button>
          </nav>
        </div>

        {activeTab === 'stockcard' ? (
          <div className="p-6">
            {/* Round 2 Phase 12: "Warehouse Stock Card" (replaces "MAIN
                STOCK WARE HOUSE") -- per item, per warehouse, per day:
                opening, in, out, returns, closing. The backend endpoint
                (InventoryController::stockCard()) existed but was never
                actually reachable from the UI. */}
            <form onSubmit={fetchStockCard} className="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6 items-end">
              <div>
                <label className="block text-xs font-medium text-gray-500 dark:text-gray-400">Item Type</label>
                <select value={stockCardForm.item_type} onChange={(e) => setStockCardForm({ ...stockCardForm, item_type: e.target.value, item_id: '' })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-2 text-sm">
                  <option value="sku">Product</option>
                  <option value="material">Raw Material</option>
                </select>
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-500 dark:text-gray-400">Item</label>
                <select required value={stockCardForm.item_id} onChange={(e) => setStockCardForm({ ...stockCardForm, item_id: e.target.value })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-2 text-sm">
                  <option value="">Select item</option>
                  {(stockCardForm.item_type === 'sku' ? skus : materials).map(i => <option key={i.id} value={i.id}>{i.name}</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className="block text-xs font-medium text-gray-500 dark:text-gray-400">From</label>
                  <input required type="date" value={stockCardForm.date_from} onChange={(e) => setStockCardForm({ ...stockCardForm, date_from: e.target.value })}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-2 text-sm" />
                </div>
                <div>
                  <label className="block text-xs font-medium text-gray-500 dark:text-gray-400">To</label>
                  <input required type="date" value={stockCardForm.date_to} onChange={(e) => setStockCardForm({ ...stockCardForm, date_to: e.target.value })}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-2 text-sm" />
                </div>
              </div>
              <button type="submit" disabled={stockCardLoading} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 text-sm">
                {stockCardLoading ? 'Loading…' : 'View Card'}
              </button>
            </form>

            {/* Round 3 Phase 9: exact-format export -- all finished-goods
                SKUs at this warehouse, one sheet per day in range
                (doesn't need a single item selected, unlike the card
                above). */}
            <div className="mb-6">
              <button
                type="button"
                onClick={() => {
                  const warehouseId = stockCardForm.warehouse_id || warehouses[0]?.id;
                  if (!warehouseId) { toast.error('Main warehouse is not set up yet'); return; }
                  api.get('/inventory/stock-reconciliation-export', {
                    params: { warehouse_id: warehouseId, date_from: stockCardForm.date_from, date_to: stockCardForm.date_to },
                    responseType: 'blob',
                  }).then((res) => {
                    const url = window.URL.createObjectURL(new Blob([res.data]));
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `stock-reconciliation-${stockCardForm.date_from}-to-${stockCardForm.date_to}.xlsx`;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                  }).catch(() => toast.error('Failed to export stock reconciliation'));
                }}
                className="flex items-center text-sm px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                <Download className="w-4 h-4 mr-2" /> Export stock reconciliation
              </button>
            </div>

            {stockCard && (
              <div className="overflow-x-auto">
                <p className="text-sm text-gray-500 dark:text-gray-400 mb-2">Opening balance before this period: <span className="font-medium text-gray-900 dark:text-gray-100">{stockCard.opening_balance.toLocaleString()}</span></p>
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase border-b">
                      <th className="py-2">Date</th>
                      <th className="py-2">Opening</th>
                      <th className="py-2">In</th>
                      <th className="py-2">Out</th>
                      <th className="py-2">Returns</th>
                      <th className="py-2">Closing</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {stockCard.days.map((d) => (
                      <tr key={d.date}>
                        <td className="py-2">{new Date(d.date).toLocaleDateString()}</td>
                        <td className="py-2">{d.opening.toLocaleString()}</td>
                        <td className="py-2 text-green-600">{d.in > 0 ? `+${d.in.toLocaleString()}` : '—'}</td>
                        <td className="py-2 text-red-600">{d.out > 0 ? `-${d.out.toLocaleString()}` : '—'}</td>
                        <td className="py-2 text-green-600">{d.returns > 0 ? `+${d.returns.toLocaleString()}` : '—'}</td>
                        <td className="py-2 font-medium">{d.closing.toLocaleString()}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        ) : (
        <div className="p-6">
          {/* Search and Filters */}
          <div className="flex flex-col md:flex-row gap-4 mb-6">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500 w-4 h-4" />
                <input
                  type="text"
                  placeholder={`Search ${activeTab}...`}
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>
            <div className="flex gap-2">
              {activeTab === 'stock' && (
                <select
                  value={filterType}
                  onChange={(e) => setFilterType(e.target.value)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                  <option value="all">All Types</option>
                  <option value="material">Materials</option>
                  <option value="sku">SKUs</option>
                </select>
              )}
              <button className="flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                <Filter className="w-4 h-4 mr-2" />
                More Filters
              </button>
              <button className="flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                <Download className="w-4 h-4 mr-2" />
                Export
              </button>
            </div>
          </div>

          {corrections.length > 0 && (
            <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 space-y-2">
              <p className="text-sm font-medium text-amber-900">
                {director ? 'Stock corrections waiting for your approval' : 'Your corrections waiting for admin approval'}
              </p>
              {corrections.map((c) => (
                <div key={c.id} className="flex flex-wrap items-center justify-between gap-2 text-sm bg-white rounded-md px-3 py-2 border border-amber-100">
                  <span>
                    {(c.requester ? `${c.requester.first_name || ''} ${c.requester.last_name || ''}`.trim() : 'Manager')}
                    {' · '}{c.payload?.item_type} {c.payload?.qty} {c.payload?.uom}
                    {c.reason ? ` · ${c.reason}` : ''}
                  </span>
                  {director && (
                    <span className="flex gap-2">
                      <button type="button" className="text-emerald-700 font-medium" onClick={async () => {
                        await api.post(`/inventory/corrections/${c.id}/approve`);
                        toast.success('Correction applied');
                        fetchData();
                      }}>Approve</button>
                      <button type="button" className="text-red-700 font-medium" onClick={async () => {
                        await api.post(`/inventory/corrections/${c.id}/reject`);
                        toast.success('Correction rejected');
                        fetchData();
                      }}>Reject</button>
                    </span>
                  )}
                </div>
              ))}
            </div>
          )}

          {/* Stock Items Table */}
          {activeTab === 'stock' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Item Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Warehouse
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Stock Level
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Min/Max
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                  {filteredStockItems.map((item) => (
                    <tr key={item.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {item.material?.name || item.sku?.name}
                          </div>
                          <div className="text-sm text-gray-500 dark:text-gray-400">
                            {item.material?.code || item.sku?.code} ({item.item_type})
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{item.warehouse?.name || 'Unknown'}</div>
                          <div className="text-sm text-gray-500 dark:text-gray-400">{item.warehouse?.code || 'N/A'}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                          {parseFloat(item.qty || '0').toLocaleString()} {item.material?.uom || 'pcs'}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900 dark:text-gray-100">
                          Reorder at: {getReorderLevel(item).toLocaleString()}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStockLevelColor(parseFloat(item.qty || '0'), getReorderLevel(item))}`}>
                          {getStockLevelIcon(parseFloat(item.qty || '0'), getReorderLevel(item))}
                          <span className="ml-1">
                            {parseFloat(item.qty || '0') <= getReorderLevel(item) ? 'Low Stock' :
                             parseFloat(item.qty || '0') <= getReorderLevel(item) * 1.5 ? 'Warning' : 'Good'}
                          </span>
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center space-x-2">
                          <button
                            type="button"
                            title="View / alter quantity"
                            onClick={() => openAlterItem(item, 'correct_set')}
                            className="text-blue-600 hover:text-blue-900"
                          >
                            <Eye className="w-4 h-4" />
                          </button>
                          <button
                            type="button"
                            title="Alter stock (set counted qty)"
                            onClick={() => openAlterItem(item, 'correct_set')}
                            className="text-green-600 hover:text-green-900"
                          >
                            <Edit className="w-4 h-4" />
                          </button>
                          <button
                            type="button"
                            title="Set stock to zero"
                            onClick={() => zeroOutItem(item)}
                            className="text-red-600 hover:text-red-900"
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'moves' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">When</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Item</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quantity</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Cost (KES)</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Note</th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                  {filteredStockMoves.map((move) => (
                    <tr key={move.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900 dark:text-gray-100">
                          {move.created_at ? new Date(move.created_at).toLocaleString() : '—'}
                        </div>
                        <div className="text-xs text-gray-400 dark:text-gray-500">
                          {move.moved_by?.first_name || 'Unknown'} {move.moved_by?.last_name || ''}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                          {move.material?.name || move.sku?.name || 'Unknown item'}
                        </div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                          {move.item_type === 'material' ? 'Material' : 'Finished product'}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                          {parseFloat(move.qty || '0').toLocaleString()} {move.uom || 'pcs'}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900 dark:text-gray-100">
                          {move.unit_cost != null && move.unit_cost !== '' ? `KES ${parseFloat(move.unit_cost).toFixed(2)}` : '—'}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${getMoveTypeColor(move.move_type || 'unknown')}`}>
                          {activityLabel(move.move_type)}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm text-gray-600 dark:text-gray-300 max-w-xs truncate">
                          {move.ref_id || '—'}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
        )}
      </div>

      {showStockMoveForm && !(stockForm.purpose === 'arrival' && !director) && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
              {stockForm.purpose === 'arrival' ? 'Stock arrival' : 'Alter / correct stock'}
            </h3>
            <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">
              {stockForm.purpose === 'arrival'
                ? 'Use this when labels, bailing papers, seals, bottles or other packaging arrive. Quantity is added to the main store.'
                : 'Change the quantity on hand for test entries or floor counts. Set counted quantity replaces the current figure; add found stock increases it.'}
            </p>
            {stockForm.purpose === 'correct_set' && (stockForm.material_id || stockForm.sku_id) && (
              <p className="text-xs text-sky-700 dark:text-sky-300 bg-sky-50 dark:bg-sky-900/30 rounded px-3 py-2 mb-3">
                System on hand now:{' '}
                {stockItems
                  .filter((i) =>
                    i.item_type === stockForm.item_type
                    && (stockForm.item_type === 'material'
                      ? i.material_id === stockForm.material_id
                      : i.sku_id === stockForm.sku_id)
                  )
                  .reduce((s, i) => s + parseFloat(i.qty || '0'), 0)
                  .toLocaleString()}
                . Enter the new counted figure below and a reason, then save.
              </p>
            )}
            <form onSubmit={handleStockFormSubmit} className="space-y-4">
              {stockForm.purpose !== 'arrival' && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Correction type</label>
                  <select
                    value={stockForm.purpose}
                    onChange={(e) => setStockForm({ ...stockForm, purpose: e.target.value as 'correct_add' | 'correct_set' })}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  >
                    <option value="correct_add">Add found stock (increase by this amount)</option>
                    <option value="correct_set">Set counted quantity on hand</option>
                  </select>
                </div>
              )}
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Item type</label>
                <select
                  value={stockForm.item_type}
                  onChange={(e) => setStockForm({ ...stockForm, item_type: e.target.value, material_id: '', sku_id: '' })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                >
                  <option value="material">Material (labels, bailing paper, bottles, seals)</option>
                  <option value="sku">Finished product</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Item</label>
                <select
                  required
                  value={stockForm.item_type === 'material' ? stockForm.material_id : stockForm.sku_id}
                  onChange={(e) => {
                    if (stockForm.item_type === 'material') {
                      setStockForm({ ...stockForm, material_id: e.target.value, sku_id: '' });
                    } else {
                      setStockForm({ ...stockForm, sku_id: e.target.value, material_id: '' });
                    }
                  }}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                >
                  <option value="">Select item</option>
                  {(stockForm.item_type === 'material' ? materials : skus).map(i => (
                    <option key={i.id} value={i.id}>{i.name} ({i.code})</option>
                  ))}
                </select>
              </div>
              <div className={stockForm.purpose === 'arrival' ? 'grid grid-cols-2 gap-4' : ''}>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {stockForm.purpose === 'correct_set' ? 'Counted quantity on hand' : stockForm.purpose === 'correct_add' ? 'Quantity found / to add' : 'Quantity arrived'}
                  </label>
                  <input
                    required
                    type="number"
                    min={stockForm.purpose === 'correct_set' ? 0 : 0.001}
                    step="0.001"
                    value={stockForm.qty}
                    onChange={(e) => setStockForm({ ...stockForm, qty: e.target.value })}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  />
                </div>
                {stockForm.purpose === 'arrival' && (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Unit cost (KES)</label>
                    <input
                      type="number"
                      step="0.01"
                      min={0}
                      value={stockForm.unit_cost}
                      onChange={(e) => setStockForm({ ...stockForm, unit_cost: e.target.value })}
                      className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                    />
                  </div>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                  {stockForm.purpose === 'arrival' ? 'Note (optional)' : 'Reason (required)'}
                </label>
                <textarea
                  required={stockForm.purpose !== 'arrival'}
                  value={stockForm.notes}
                  onChange={(e) => setStockForm({ ...stockForm, notes: e.target.value })}
                  placeholder={stockForm.purpose === 'arrival' ? 'Supplier, invoice no., or delivery note' : 'e.g. Found 2 bales of labels in packing room'}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  rows={3}
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowStockMoveForm(false)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                  Cancel
                </button>
                <button type="submit" className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                  {stockForm.purpose === 'arrival' ? 'Save arrival' : 'Save correction'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </PageShell>
  );
};

export default InventoryPage;
