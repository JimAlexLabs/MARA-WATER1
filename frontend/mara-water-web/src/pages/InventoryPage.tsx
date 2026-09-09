import React, { useState, useEffect } from 'react';
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

interface StockItem {
  id?: string;
  item_type: 'material' | 'sku';
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
  };
  warehouse?: {
    name?: string;
    code?: string;
  };
  qty?: string;
}

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
  const [filterType, setFilterType] = useState('all');
  const [activeTab, setActiveTab] = useState('stock');
  const [showStockMoveForm, setShowStockMoveForm] = useState(false);

  // Stock Move Form State
  const [stockMoveForm, setStockMoveForm] = useState({
    move_type: 'grn',
    item_type: 'material',
    material_id: '',
    sku_id: '',
    warehouse_from_id: '',
    warehouse_to_id: '',
    qty: '',
    uom: 'pcs',
    unit_cost: '',
    ref_entity: '',
    ref_id: '',
    notes: ''
  });

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      setLoading(true);
      const [stockResponse, movesResponse] = await Promise.all([
        api.get('/inventory/stock-items'),
        api.get('/inventory/stock-moves')
      ]);
      
      setStockItems(stockResponse.data.data);
      setStockMoves(movesResponse.data.data);
    } catch (error) {
      toast.error('Failed to fetch inventory data');
    } finally {
      setLoading(false);
    }
  };

  const handleStockMoveSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api.post('/inventory/stock-moves', stockMoveForm);
      toast.success('Stock move created successfully');
      setShowStockMoveForm(false);
      setStockMoveForm({
        move_type: 'grn',
        item_type: 'material',
        material_id: '',
        sku_id: '',
        warehouse_from_id: '',
        warehouse_to_id: '',
        qty: '',
        uom: 'pcs',
        unit_cost: '',
        ref_entity: '',
        ref_id: '',
        notes: ''
      });
      fetchData();
    } catch (error) {
      toast.error('Failed to create stock move');
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
      default: return 'text-gray-600 bg-gray-100';
    }
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

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Inventory Management</h1>
          <p className="text-gray-600">Manage stock items and movements</p>
        </div>
        <div className="flex space-x-3">
          <button
            onClick={() => setShowStockMoveForm(true)}
            className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            <Plus className="w-4 h-4 mr-2" />
            New Stock Move
          </button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Package className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Items</p>
              <p className="text-2xl font-bold text-gray-900">{stockItems.length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Warehouse className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Stock</p>
              <p className="text-2xl font-bold text-gray-900">
                {stockItems.reduce((sum, item) => sum + parseFloat(item.qty || '0'), 0).toLocaleString()}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <AlertTriangle className="w-8 h-8 text-red-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Low Stock Items</p>
              <p className="text-2xl font-bold text-gray-900">
                {stockItems.filter(item => parseFloat(item.qty || '0') <= parseFloat(item.material?.min_level || '0')).length}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <ArrowUpDown className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Stock Moves</p>
              <p className="text-2xl font-bold text-gray-900">{stockMoves.length}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white rounded-lg shadow">
        <div className="border-b border-gray-200">
          <nav className="flex space-x-8 px-6">
            <button
              onClick={() => setActiveTab('stock')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'stock'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Stock Items ({stockItems.length})
            </button>
            <button
              onClick={() => setActiveTab('moves')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'moves'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Stock Moves ({stockMoves.length})
            </button>
          </nav>
        </div>

        <div className="p-6">
          {/* Search and Filters */}
          <div className="flex flex-col md:flex-row gap-4 mb-6">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
                <input
                  type="text"
                  placeholder={`Search ${activeTab}...`}
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>
            <div className="flex gap-2">
              {activeTab === 'stock' && (
                <select
                  value={filterType}
                  onChange={(e) => setFilterType(e.target.value)}
                  className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                  <option value="all">All Types</option>
                  <option value="material">Materials</option>
                  <option value="sku">SKUs</option>
                </select>
              )}
              <button className="flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                <Filter className="w-4 h-4 mr-2" />
                More Filters
              </button>
              <button className="flex items-center px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                <Download className="w-4 h-4 mr-2" />
                Export
              </button>
            </div>
          </div>

          {/* Stock Items Table */}
          {activeTab === 'stock' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Item Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Warehouse
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Stock Level
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Min/Max
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {filteredStockItems.map((item) => (
                    <tr key={item.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900">
                            {item.material?.name || item.sku?.name}
                          </div>
                          <div className="text-sm text-gray-500">
                            {item.material?.code || item.sku?.code} ({item.item_type})
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900">{item.warehouse?.name || 'Unknown'}</div>
                          <div className="text-sm text-gray-500">{item.warehouse?.code || 'N/A'}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">
                          {parseFloat(item.qty || '0').toLocaleString()} {item.material?.uom || 'pcs'}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900">
                          <div>Min: {parseFloat(item.material?.min_level || '0').toLocaleString()}</div>
                          <div>Max: N/A</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStockLevelColor(parseFloat(item.qty || '0'), parseFloat(item.material?.min_level || '0'))}`}>
                          {getStockLevelIcon(parseFloat(item.qty || '0'), parseFloat(item.material?.min_level || '0'))}
                          <span className="ml-1">
                            {parseFloat(item.qty || '0') <= parseFloat(item.material?.min_level || '0') ? 'Low Stock' : 
                             parseFloat(item.qty || '0') <= parseFloat(item.material?.min_level || '0') * 1.5 ? 'Warning' : 'Good'}
                          </span>
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center space-x-2">
                          <button className="text-blue-600 hover:text-blue-900">
                            <Eye className="w-4 h-4" />
                          </button>
                          <button className="text-green-600 hover:text-green-900">
                            <Edit className="w-4 h-4" />
                          </button>
                          <button className="text-red-600 hover:text-red-900">
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

          {/* Stock Moves Table */}
          {activeTab === 'moves' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Move Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Item
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      From/To
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Quantity
                    </th>
                                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Cost (KES)
                  </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {filteredStockMoves.map((move) => (
                    <tr key={move.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900">
                            {(move.move_type || 'unknown').toUpperCase()}
                          </div>
                          <div className="text-sm text-gray-500">
                            {move.created_at ? new Date(move.created_at).toLocaleDateString() : 'N/A'}
                          </div>
                          <div className="text-xs text-gray-400">
                            By: {move.moved_by?.first_name || 'Unknown'} {move.moved_by?.last_name || ''}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900">
                            {move.material?.name || move.sku?.name || 'Unknown Item'}
                          </div>
                          <div className="text-sm text-gray-500">
                            {move.item_type}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900">
                          <div>From: {move.warehouse_from?.name || 'N/A'}</div>
                          <div>To: {move.warehouse_to?.name || 'N/A'}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">
                          {parseFloat(move.qty || '0').toLocaleString()} {move.uom || 'pcs'}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900">
                          KES {parseFloat(move.unit_cost || '0').toFixed(2)}
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${getMoveTypeColor(move.move_type || 'unknown')}`}>
                          {move.move_type || 'unknown'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Stock Move Form Modal */}
      {showStockMoveForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 mb-4">New Stock Move</h3>
            <form onSubmit={handleStockMoveSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Move Type</label>
                  <select
                    value={stockMoveForm.move_type}
                    onChange={(e) => setStockMoveForm({...stockMoveForm, move_type: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="grn">Goods Receipt (GRN)</option>
                    <option value="issue">Issue</option>
                    <option value="produce">Produce</option>
                    <option value="adjust">Adjust</option>
                    <option value="transfer">Transfer</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Item Type</label>
                  <select
                    value={stockMoveForm.item_type}
                    onChange={(e) => setStockMoveForm({...stockMoveForm, item_type: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="material">Material</option>
                    <option value="sku">SKU</option>
                  </select>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Item</label>
                <select
                  value={stockMoveForm.material_id || stockMoveForm.sku_id}
                  onChange={(e) => {
                    if (stockMoveForm.item_type === 'material') {
                      setStockMoveForm({...stockMoveForm, material_id: e.target.value, sku_id: ''});
                    } else {
                      setStockMoveForm({...stockMoveForm, sku_id: e.target.value, material_id: ''});
                    }
                  }}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select Item</option>
                  {/* Add item options here */}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">From Warehouse</label>
                  <select
                    value={stockMoveForm.warehouse_from_id}
                    onChange={(e) => setStockMoveForm({...stockMoveForm, warehouse_from_id: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">Select Warehouse</option>
                    {/* Add warehouse options here */}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">To Warehouse</label>
                  <select
                    value={stockMoveForm.warehouse_to_id}
                    onChange={(e) => setStockMoveForm({...stockMoveForm, warehouse_to_id: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">Select Warehouse</option>
                    {/* Add warehouse options here */}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Quantity</label>
                  <input
                    type="number"
                    value={stockMoveForm.qty}
                    onChange={(e) => setStockMoveForm({...stockMoveForm, qty: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Unit Cost (KES)</label>
                  <input
                    type="number"
                    step="0.01"
                    value={stockMoveForm.unit_cost}
                    onChange={(e) => setStockMoveForm({...stockMoveForm, unit_cost: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Notes</label>
                <textarea
                  value={stockMoveForm.notes}
                  onChange={(e) => setStockMoveForm({...stockMoveForm, notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={3}
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowStockMoveForm(false)}
                  className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                >
                  Create Move
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default InventoryPage;
