import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Plus,
  Search,
  Filter,
  Download,
  BarChart3,
  Package,
  Settings,
  Clock,
  CheckCircle,
  XCircle,
  TrendingUp,
  Eye,
  Edit,
  Trash2
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

interface Sku {
  id: string;
  code: string;
  name: string;
  brand?: string | null;
  size_liters: string;
}

interface Warehouse {
  id: string;
  code: string;
  name: string;
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

interface Batch {
  id: string;
  code: string;
  sku_id: string;
  sku: {
    name: string;
    size_liters: number;
  };
  manufacture_date: string;
  expiry_date: string;
  planned_qty: number;
  actual_qty?: number | null;
  status: 'open' | 'in_progress' | 'closed';
  opened_by: {
    first_name: string;
    last_name: string;
  };
}

interface PackagingRun {
  id: string;
  batch: {
    code: string;
  };
  sku: {
    name: string;
  };
  warehouse?: {
    name: string;
  } | null;
  run_start: string;
  run_end: string;
  good_qty: number;
  scrap_qty: number;
  downtime_minutes: number;
  run_by: {
    first_name: string;
    last_name: string;
  };
}

const ProductionPage: React.FC = () => {
  const [batches, setBatches] = useState<Batch[]>([]);
  const [packagingRuns, setPackagingRuns] = useState<PackagingRun[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [searchParams] = useSearchParams();

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
    const tab = searchParams.get('tab');
    if (tab) setActiveTab(tab);
  }, [searchParams]);
  const [filterStatus, setFilterStatus] = useState('all');
  const [activeTab, setActiveTab] = useState('batches');
  const [showBatchForm, setShowBatchForm] = useState(false);
  const [showPackagingForm, setShowPackagingForm] = useState(false);
  const [showMaterialForm, setShowMaterialForm] = useState(false);
  const [skus, setSkus] = useState<Sku[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [materials, setMaterials] = useState<Material[]>([]);
  const [bomItems, setBomItems] = useState<BomItem[]>([]);
  const [bomSkuId, setBomSkuId] = useState('');
  const [bomForm, setBomForm] = useState({ material_id: '', qty_per_unit: '1', uom: 'PCS' });

  // Batch Form State
  const [batchForm, setBatchForm] = useState({
    sku_id: '',
    planned_qty: '',
    manufacture_date: '',
    expiry_date: ''
  });

  // Packaging Form State
  const [packagingForm, setPackagingForm] = useState({
    batch_id: '',
    sku_id: '',
    warehouse_id: '',
    run_start: '',
    run_end: '',
    good_qty: '',
    scrap_qty: '',
    downtime_minutes: '',
    notes: ''
  });

  // Material Form State
  const [materialForm, setMaterialForm] = useState({
    code: '', name: '', category: '', uom: 'PCS', min_level: '0', lead_time_days: '0',
  });

  useEffect(() => {
    fetchData();
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/inventory/warehouses').then(res => setWarehouses(res.data.data)).catch(() => {});
    api.get('/production/materials').then(res => setMaterials(res.data.data)).catch(() => {});
  }, []);

  useEffect(() => {
    if (!bomSkuId) { setBomItems([]); return; }
    api.get(`/production/bom?sku_id=${bomSkuId}`).then(res => setBomItems(res.data.data)).catch(() => {});
  }, [bomSkuId]);

  const fetchData = async () => {
    try {
      setLoading(true);
      const [batchesResponse, packagingResponse] = await Promise.all([
        api.get('/qa/batches'),
        api.get('/production/packaging-runs')
      ]);

      setBatches(batchesResponse.data.data);
      setPackagingRuns(packagingResponse.data.data);
    } catch (error) {
      toast.error('Failed to fetch production data');
    } finally {
      setLoading(false);
    }
  };

  const handleBatchSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api.post('/qa/batches', batchForm);
      toast.success('Batch created successfully');
      setShowBatchForm(false);
      setBatchForm({
        sku_id: '',
        planned_qty: '',
        manufacture_date: '',
        expiry_date: ''
      });
      fetchData();
    } catch (error) {
      toast.error('Failed to create batch');
    }
  };

  const handlePackagingSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const res = await api.post('/production/packaging-runs', packagingForm);
      toast.success('Packaging run created successfully');
      const warnings = res.data?.data?.material_warnings || [];
      warnings.forEach((w: { message: string }) => toast.error(w.message, { duration: 8000 }));
      setShowPackagingForm(false);
      setPackagingForm({
        batch_id: '',
        sku_id: '',
        warehouse_id: '',
        run_start: '',
        run_end: '',
        good_qty: '',
        scrap_qty: '',
        downtime_minutes: '',
        notes: ''
      });
      fetchData();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to create packaging run');
    }
  };

  const handleMaterialSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const res = await api.post('/production/materials', materialForm);
      toast.success('Material created successfully');
      setMaterials([...materials, res.data.data.material]);
      setShowMaterialForm(false);
      setMaterialForm({ code: '', name: '', category: '', uom: 'PCS', min_level: '0', lead_time_days: '0' });
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to create material');
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

  const handleStartBatch = async (batchId: string) => {
    try {
      await api.put(`/qa/batches/${batchId}/status`, { status: 'in_progress' });
      toast.success('Batch started -- ready for a packaging run');
      fetchData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to start batch');
    }
  };

  const filteredBatches = batches.filter(batch => {
    const matchesSearch = batch.code.toLowerCase().includes(searchTerm.toLowerCase()) ||
                         (batch.sku?.name ?? '').toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'all' || batch.status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  const filteredPackagingRuns = packagingRuns.filter(run => {
    return (run.batch?.code ?? '').toLowerCase().includes(searchTerm.toLowerCase()) ||
           (run.sku?.name ?? '').toLowerCase().includes(searchTerm.toLowerCase());
  });

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'open': return 'text-blue-600 bg-blue-100';
      case 'in_progress': return 'text-yellow-600 bg-yellow-100';
      case 'closed': return 'text-green-600 bg-green-100';
      default: return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'open': return <Settings className="w-4 h-4" />;
      case 'in_progress': return <Clock className="w-4 h-4" />;
      case 'closed': return <CheckCircle className="w-4 h-4" />;
      default: return <Settings className="w-4 h-4" />;
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
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Production Management</h1>
          <p className="text-gray-600 dark:text-gray-400">Manage batches and packaging runs</p>
        </div>
        <div className="flex space-x-3">
          <button
            onClick={() => setShowBatchForm(true)}
            className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            <Plus className="w-4 h-4 mr-2" />
            New Batch
          </button>
          <button
            onClick={() => setShowPackagingForm(true)}
            className="flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
          >
            <Plus className="w-4 h-4 mr-2" />
            New Packaging Run
          </button>
          {activeTab === 'materials' && (
            <button
              onClick={() => setShowMaterialForm(true)}
              className="flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700"
            >
              <Plus className="w-4 h-4 mr-2" />
              New Material
            </button>
          )}
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Package className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Batches</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{batches.length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Settings className="w-8 h-8 text-yellow-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Active Batches</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {batches.filter(b => b.status === 'open' || b.status === 'in_progress').length}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <CheckCircle className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Completed Batches</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {batches.filter(b => b.status === 'closed').length}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <TrendingUp className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Packaging Runs</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{packagingRuns.length}</p>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="border-b border-gray-200 dark:border-gray-700">
          <nav className="flex space-x-8 px-6">
            <button
              onClick={() => setActiveTab('batches')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'batches'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Batches ({batches.length})
            </button>
            <button
              onClick={() => setActiveTab('packaging')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'packaging'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Packaging Runs ({packagingRuns.length})
            </button>
            <button
              onClick={() => setActiveTab('materials')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'materials'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Materials ({materials.length})
            </button>
            <button
              onClick={() => setActiveTab('bom')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'bom'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Bill of Materials
            </button>
          </nav>
        </div>

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
              {activeTab === 'batches' && (
                <select
                  value={filterStatus}
                  onChange={(e) => setFilterStatus(e.target.value)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                  <option value="all">All Status</option>
                  <option value="open">Open</option>
                  <option value="in_progress">In Progress</option>
                  <option value="closed">Closed</option>
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

          {/* Batches Table */}
          {activeTab === 'batches' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Batch Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Product
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Dates
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Quantity
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
                  {filteredBatches.map((batch) => (
                    <tr key={batch.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{batch.code}</div>
                          <div className="text-sm text-gray-500 dark:text-gray-400">
                            By: {batch.opened_by.first_name} {batch.opened_by.last_name}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{batch.sku?.name ?? '—'}</div>
                          <div className="text-sm text-gray-500 dark:text-gray-400">{batch.sku?.size_liters ?? '—'}L</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900 dark:text-gray-100">
                          <div>MFG: {new Date(batch.manufacture_date).toLocaleDateString()}</div>
                          <div>EXP: {new Date(batch.expiry_date).toLocaleDateString()}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                        <div>Planned: {batch.planned_qty.toLocaleString()}</div>
                        {batch.actual_qty != null && <div className="text-gray-500 dark:text-gray-400 font-normal">Actual: {batch.actual_qty.toLocaleString()}</div>}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusColor(batch.status)}`}>
                          {getStatusIcon(batch.status)}
                          <span className="ml-1">{batch.status.replace('_', ' ')}</span>
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        {batch.status === 'open' && (
                          <button
                            onClick={() => handleStartBatch(batch.id)}
                            className="text-blue-600 hover:text-blue-900"
                          >
                            Start
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Packaging Runs Table */}
          {activeTab === 'packaging' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Run Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Batch & Product
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Warehouse
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Duration
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Output
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Efficiency
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                  {filteredPackagingRuns.map((run) => {
                    const startTime = new Date(run.run_start);
                    const endTime = new Date(run.run_end);
                    const duration = Math.round((endTime.getTime() - startTime.getTime()) / (1000 * 60));
                    const totalQty = run.good_qty + run.scrap_qty;
                    const efficiency = totalQty > 0 ? Math.round((run.good_qty / totalQty) * 100) : 0;
                    
                    return (
                      <tr key={run.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div>
                            <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                              {new Date(run.run_start).toLocaleDateString()}
                            </div>
                            <div className="text-sm text-gray-500 dark:text-gray-400">
                              {new Date(run.run_start).toLocaleTimeString()} - {new Date(run.run_end).toLocaleTimeString()}
                            </div>
                            <div className="text-xs text-gray-400 dark:text-gray-500">
                              By: {run.run_by ? `${run.run_by.first_name} ${run.run_by.last_name}` : '—'}
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div>
                            <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{run.batch?.code ?? '—'}</div>
                            <div className="text-sm text-gray-500 dark:text-gray-400">{run.sku?.name ?? '—'}</div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                          {run.warehouse?.name || '—'}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                          {duration} min
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="text-sm text-gray-900 dark:text-gray-100">
                            <div>Good: {run.good_qty.toLocaleString()}</div>
                            <div>Scrap: {run.scrap_qty.toLocaleString()}</div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center">
                            <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{efficiency}%</div>
                            <div className="ml-2 w-16 bg-gray-200 rounded-full h-2">
                              <div 
                                className="bg-green-600 h-2 rounded-full" 
                                style={{ width: `${efficiency}%` }}
                              ></div>
                            </div>
                          </div>
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
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {/* Materials Table */}
          {activeTab === 'materials' && (
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
                  {materials.filter(m => m.name.toLowerCase().includes(searchTerm.toLowerCase()) || m.code.toLowerCase().includes(searchTerm.toLowerCase())).map((m) => (
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
                    <tr><td colSpan={4} className="px-6 py-4 text-sm text-gray-400 dark:text-gray-500">No materials yet -- add one to start tracking raw materials.</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          )}

          {/* Bill of Materials */}
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
                          <tr><td colSpan={4} className="px-6 py-4 text-sm text-gray-400 dark:text-gray-500">No recipe set for this product yet -- add a line below.</td></tr>
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
                        onChange={(e) => setBomForm({...bomForm, material_id: e.target.value})}
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
                        onChange={(e) => setBomForm({...bomForm, qty_per_unit: e.target.value})}
                        className="mt-1 block w-28 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">UoM</label>
                      <input
                        required type="text"
                        value={bomForm.uom}
                        onChange={(e) => setBomForm({...bomForm, uom: e.target.value})}
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

      {/* Batch Form Modal */}
      {showBatchForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">New Batch</h3>
            <form onSubmit={handleBatchSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">SKU</label>
                <select
                  required
                  value={batchForm.sku_id}
                  onChange={(e) => setBatchForm({...batchForm, sku_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select SKU</option>
                  {skus.map(s => (
                    <option key={s.id} value={s.id}>{s.brand ? `${s.brand} -- ` : ''}{s.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Planned Quantity</label>
                <input
                  type="number"
                  value={batchForm.planned_qty}
                  onChange={(e) => setBatchForm({...batchForm, planned_qty: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Manufacture Date</label>
                  <input
                    type="date"
                    value={batchForm.manufacture_date}
                    onChange={(e) => setBatchForm({...batchForm, manufacture_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Expiry Date</label>
                  <input
                    type="date"
                    value={batchForm.expiry_date}
                    onChange={(e) => setBatchForm({...batchForm, expiry_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
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
                  className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                >
                  Create Batch
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Packaging Form Modal */}
      {showPackagingForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">New Packaging Run</h3>
            <form onSubmit={handlePackagingSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Batch</label>
                <select
                  required
                  value={packagingForm.batch_id}
                  onChange={(e) => {
                    const batch = batches.find(b => b.id === e.target.value);
                    setPackagingForm({...packagingForm, batch_id: e.target.value, sku_id: batch?.sku_id || ''});
                  }}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select Batch (in progress)</option>
                  {batches.filter(b => b.status === 'in_progress').map(batch => (
                    <option key={batch.id} value={batch.id}>
                      {batch.code} - {batch.sku?.name ?? 'Unknown product'}
                    </option>
                  ))}
                </select>
                {batches.filter(b => b.status === 'in_progress').length === 0 && (
                  <p className="mt-1 text-xs text-amber-600">No batches in progress -- start one from the Batches tab first.</p>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Outlet / Warehouse (finished goods land here)</label>
                <select
                  required
                  value={packagingForm.warehouse_id}
                  onChange={(e) => setPackagingForm({...packagingForm, warehouse_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select warehouse</option>
                  {warehouses.map(w => <option key={w.id} value={w.id}>{w.name} ({w.code})</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Start Time</label>
                  <input
                    type="datetime-local"
                    value={packagingForm.run_start}
                    onChange={(e) => setPackagingForm({...packagingForm, run_start: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">End Time</label>
                  <input
                    type="datetime-local"
                    value={packagingForm.run_end}
                    onChange={(e) => setPackagingForm({...packagingForm, run_end: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Good Qty</label>
                  <input
                    type="number"
                    value={packagingForm.good_qty}
                    onChange={(e) => setPackagingForm({...packagingForm, good_qty: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Scrap Qty</label>
                  <input
                    type="number"
                    value={packagingForm.scrap_qty}
                    onChange={(e) => setPackagingForm({...packagingForm, scrap_qty: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Downtime (min)</label>
                  <input
                    type="number"
                    value={packagingForm.downtime_minutes}
                    onChange={(e) => setPackagingForm({...packagingForm, downtime_minutes: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea
                  value={packagingForm.notes}
                  onChange={(e) => setPackagingForm({...packagingForm, notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={3}
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowPackagingForm(false)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
                >
                  Create Run
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Material Form Modal */}
      {showMaterialForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">New Material</h3>
            <form onSubmit={handleMaterialSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Code</label>
                  <input
                    required type="text"
                    value={materialForm.code}
                    onChange={(e) => setMaterialForm({...materialForm, code: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                  <input
                    required type="text" placeholder="e.g. preform, label, cap, bailing paper"
                    value={materialForm.category}
                    onChange={(e) => setMaterialForm({...materialForm, category: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                <input
                  required type="text"
                  value={materialForm.name}
                  onChange={(e) => setMaterialForm({...materialForm, name: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Unit</label>
                  <input
                    required type="text"
                    value={materialForm.uom}
                    onChange={(e) => setMaterialForm({...materialForm, uom: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Reorder Level</label>
                  <input
                    type="number" min={0}
                    value={materialForm.min_level}
                    onChange={(e) => setMaterialForm({...materialForm, min_level: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Lead Time (days)</label>
                  <input
                    type="number" min={0}
                    value={materialForm.lead_time_days}
                    onChange={(e) => setMaterialForm({...materialForm, lead_time_days: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowMaterialForm(false)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700"
                >
                  Create Material
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default ProductionPage;
