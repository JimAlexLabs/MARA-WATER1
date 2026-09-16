import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Plus,
  Search,
  Filter,
  Download,
  BarChart3,
  TestTube,
  CheckCircle,
  XCircle,
  Clock,
  AlertTriangle,
  X,
  Edit,
  Trash2
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

interface WaterTest {
  id: string;
  test_type: 'baseline' | 'random' | 'retest';
  recorded_at: string;
  ph: number;
  tds: number;
  chlorine: number;
  status: 'pass' | 'fail' | 'pending';
  location_text: string;
  recorded_by: {
    first_name: string;
    last_name: string;
  };
}

interface Sku {
  id: string;
  code: string;
  name: string;
  brand?: string | null;
}

interface Batch {
  id: string;
  code: string;
  sku: {
    name: string;
    size_liters: number;
  };
  manufacture_date: string;
  expiry_date: string;
  planned_qty: number;
  status: 'open' | 'in_progress' | 'closed';
}

interface MaterialWatchRow {
  material: { id: string; code: string; name: string; uom: string };
  qty_on_hand: number;
  reorder_level: number;
  status: string;
  severity: 'critical' | 'warning' | 'ok';
  message: string;
  expiry_date?: string | null;
  expiry_status?: string;
}

interface PpeWatchRow {
  material: { id: string; code: string; name: string };
  qty_on_hand: number;
  headcount: number;
  shortfall: number;
  status: string;
  severity: 'critical' | 'warning' | 'ok';
  message: string;
}

interface EquipmentItem {
  id: string;
  category: 'equipment' | 'test_equipment';
  name: string;
  unit: string;
  qty_on_hand: number | null;
  minimum_required: number | null;
  condition: 'good' | 'fair' | 'poor' | 'broken' | null;
  last_service_date: string | null;
  next_service_due: string | null;
  calibration_status: 'calibrated' | 'due' | 'not_calibrated' | null;
  notes: string | null;
}

interface EquipmentWatchRow {
  item: EquipmentItem;
  service_status: string;
  severity: 'critical' | 'warning' | 'ok';
  message: string;
}

interface CriticalGap {
  area: string;
  severity: 'critical' | 'warning';
  message: string;
}

const QAPage: React.FC = () => {
  const [waterTests, setWaterTests] = useState<WaterTest[]>([]);
  const [batches, setBatches] = useState<Batch[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [searchParams] = useSearchParams();

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
  }, [searchParams]);
  const [filterStatus, setFilterStatus] = useState('all');
  const [showWaterTestForm, setShowWaterTestForm] = useState(false);
  const [showBatchForm, setShowBatchForm] = useState(false);
  const [activeTab, setActiveTab] = useState<'tests' | 'audit'>('tests');
  const [skus, setSkus] = useState<Sku[]>([]);

  // Water Test Form State
  const [waterTestForm, setWaterTestForm] = useState({
    test_type: 'baseline',
    ph: '',
    tds: '',
    chlorine: '',
    location_text: '',
    unit_notes: ''
  });

  // Batch Form State
  const [batchForm, setBatchForm] = useState({
    sku_id: '',
    planned_qty: '',
    manufacture_date: '',
    expiry_date: ''
  });

  // Warehouse Audit state
  const [criticalGaps, setCriticalGaps] = useState<{ critical_count: number; warning_count: number; gaps: CriticalGap[] }>({ critical_count: 0, warning_count: 0, gaps: [] });
  const [packagingWatch, setPackagingWatch] = useState<MaterialWatchRow[]>([]);
  const [stationeryWatch, setStationeryWatch] = useState<MaterialWatchRow[]>([]);
  const [chemicalsWatch, setChemicalsWatch] = useState<MaterialWatchRow[]>([]);
  const [ppeWatch, setPpeWatch] = useState<PpeWatchRow[]>([]);
  const [equipmentWatch, setEquipmentWatch] = useState<EquipmentWatchRow[]>([]);
  const [testEquipmentWatch, setTestEquipmentWatch] = useState<EquipmentWatchRow[]>([]);
  const [auditLoading, setAuditLoading] = useState(false);
  const [showEquipmentForm, setShowEquipmentForm] = useState(false);
  const [editingEquipmentId, setEditingEquipmentId] = useState<string | null>(null);
  const [equipmentForm, setEquipmentForm] = useState({
    category: 'equipment' as 'equipment' | 'test_equipment', name: '', unit: 'PCS',
    qty_on_hand: '', minimum_required: '', condition: '', last_service_date: '', next_service_due: '',
    calibration_status: '', notes: '',
  });

  useEffect(() => {
    fetchData();
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
  }, []);

  useEffect(() => {
    if (activeTab === 'audit') fetchAuditData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab]);

  const fetchData = async () => {
    try {
      setLoading(true);
      const [testsResponse, batchesResponse] = await Promise.all([
        api.get('/qa/water-tests'),
        api.get('/qa/batches')
      ]);

      setWaterTests(testsResponse.data.data);
      setBatches(batchesResponse.data.data);
    } catch (error) {
      toast.error('Failed to fetch QA data');
    } finally {
      setLoading(false);
    }
  };

  const fetchAuditData = async () => {
    try {
      setAuditLoading(true);
      const [gaps, packaging, stationery, chemicals, ppe, equipment, testEquipment] = await Promise.all([
        api.get('/qa/audit/critical-gaps'),
        api.get('/qa/audit/packaging'),
        api.get('/qa/audit/stationery'),
        api.get('/qa/audit/chemicals'),
        api.get('/qa/audit/ppe'),
        api.get('/qa/audit/equipment'),
        api.get('/qa/audit/test-equipment'),
      ]);
      setCriticalGaps(gaps.data.data);
      setPackagingWatch(packaging.data.data);
      setStationeryWatch(stationery.data.data);
      setChemicalsWatch(chemicals.data.data);
      setPpeWatch(ppe.data.data);
      setEquipmentWatch(equipment.data.data);
      setTestEquipmentWatch(testEquipment.data.data);
    } catch (error) {
      toast.error('Failed to fetch warehouse audit data');
    } finally {
      setAuditLoading(false);
    }
  };

  const openNewEquipment = (category: 'equipment' | 'test_equipment') => {
    setEditingEquipmentId(null);
    setEquipmentForm({ category, name: '', unit: 'PCS', qty_on_hand: '', minimum_required: '', condition: '', last_service_date: '', next_service_due: '', calibration_status: '', notes: '' });
    setShowEquipmentForm(true);
  };

  const openEditEquipment = (item: EquipmentItem) => {
    setEditingEquipmentId(item.id);
    setEquipmentForm({
      category: item.category, name: item.name, unit: item.unit,
      qty_on_hand: item.qty_on_hand?.toString() ?? '', minimum_required: item.minimum_required?.toString() ?? '',
      condition: item.condition ?? '', last_service_date: item.last_service_date ?? '', next_service_due: item.next_service_due ?? '',
      calibration_status: item.calibration_status ?? '', notes: item.notes ?? '',
    });
    setShowEquipmentForm(true);
  };

  const handleEquipmentSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const payload = {
      category: equipmentForm.category, name: equipmentForm.name, unit: equipmentForm.unit,
      qty_on_hand: equipmentForm.qty_on_hand === '' ? null : Number(equipmentForm.qty_on_hand),
      minimum_required: equipmentForm.minimum_required === '' ? null : Number(equipmentForm.minimum_required),
      condition: equipmentForm.condition || null,
      last_service_date: equipmentForm.last_service_date || null,
      next_service_due: equipmentForm.next_service_due || null,
      calibration_status: equipmentForm.calibration_status || null,
      notes: equipmentForm.notes || null,
    };
    try {
      if (editingEquipmentId) {
        await api.put(`/qa/equipment/${editingEquipmentId}`, payload);
        toast.success('Equipment item updated');
      } else {
        await api.post('/qa/equipment', payload);
        toast.success('Equipment item added');
      }
      setShowEquipmentForm(false);
      fetchAuditData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to save equipment item');
    }
  };

  const handleDeleteEquipment = async (item: EquipmentItem) => {
    if (!window.confirm(`Remove ${item.name}?`)) return;
    try {
      await api.delete(`/qa/equipment/${item.id}`);
      fetchAuditData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to remove equipment item');
    }
  };

  const severityBadge = (severity: 'critical' | 'warning' | 'ok') => {
    switch (severity) {
      case 'critical': return 'text-red-600 bg-red-100';
      case 'warning': return 'text-yellow-600 bg-yellow-100';
      default: return 'text-green-600 bg-green-100';
    }
  };

  const handleWaterTestSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api.post('/qa/water-tests', waterTestForm);
      toast.success('Water test created successfully');
      setShowWaterTestForm(false);
      setWaterTestForm({
        test_type: 'baseline',
        ph: '',
        tds: '',
        chlorine: '',
        location_text: '',
        unit_notes: ''
      });
      fetchData();
    } catch (error) {
      toast.error('Failed to create water test');
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

  const filteredWaterTests = waterTests.filter(test => {
    const matchesSearch = test.location_text.toLowerCase().includes(searchTerm.toLowerCase()) ||
                         test.recorded_by.first_name.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'all' || test.status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'pass': return 'text-green-600 bg-green-100';
      case 'fail': return 'text-red-600 bg-red-100';
      case 'pending': return 'text-yellow-600 bg-yellow-100';
      default: return 'text-gray-600 bg-gray-100';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'pass': return <CheckCircle className="w-4 h-4" />;
      case 'fail': return <XCircle className="w-4 h-4" />;
      case 'pending': return <Clock className="w-4 h-4" />;
      default: return <Clock className="w-4 h-4" />;
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
          <h1 className="text-2xl font-bold text-gray-900">Quality Assurance</h1>
          <p className="text-gray-600">Water tests, production batches, and the warehouse audit</p>
        </div>
        <div className="flex space-x-3">
          <button
            onClick={() => setShowWaterTestForm(true)}
            className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            <Plus className="w-4 h-4 mr-2" />
            New Water Test
          </button>
          <button
            onClick={() => setShowBatchForm(true)}
            className="flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
          >
            <Plus className="w-4 h-4 mr-2" />
            New Batch
          </button>
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white rounded-lg shadow">
        <div className="border-b border-gray-200">
          <nav className="flex space-x-8 px-6">
            <button
              onClick={() => setActiveTab('tests')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'tests' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Water Tests
            </button>
            <button
              onClick={() => setActiveTab('audit')}
              className={`py-4 px-1 border-b-2 font-medium text-sm flex items-center ${
                activeTab === 'audit' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Warehouse Audit
              {criticalGaps.critical_count > 0 && (
                <span className="ml-2 px-1.5 py-0.5 text-xs rounded-full bg-red-100 text-red-600">{criticalGaps.critical_count}</span>
              )}
            </button>
          </nav>
        </div>
      </div>

      {activeTab === 'tests' && (
      <>
      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <TestTube className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Tests</p>
              <p className="text-2xl font-bold text-gray-900">{waterTests.length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <CheckCircle className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Passed Tests</p>
              <p className="text-2xl font-bold text-gray-900">
                {waterTests.filter(t => t.status === 'pass').length}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <XCircle className="w-8 h-8 text-red-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Failed Tests</p>
              <p className="text-2xl font-bold text-gray-900">
                {waterTests.filter(t => t.status === 'fail').length}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <BarChart3 className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Active Batches</p>
              <p className="text-2xl font-bold text-gray-900">
                {batches.filter(b => b.status !== 'closed').length}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Search and Filters */}
      <div className="bg-white p-6 rounded-lg shadow">
        <div className="flex flex-col md:flex-row gap-4">
          <div className="flex-1">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
              <input
                type="text"
                placeholder="Search water tests..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              />
            </div>
          </div>
          <div className="flex gap-2">
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="all">All Status</option>
              <option value="pass">Pass</option>
              <option value="fail">Fail</option>
              <option value="pending">Pending</option>
            </select>
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
      </div>

      {/* Water Tests Table */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h3 className="text-lg font-medium text-gray-900">Recent Water Tests</h3>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Test Type
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Parameters
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Location
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Recorded By
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Date
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {filteredWaterTests.map((test) => (
                <tr key={test.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                      {test.test_type}
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    <div>pH: {test.ph}</div>
                    <div>TDS: {test.tds}</div>
                    <div>Cl: {test.chlorine}</div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {test.location_text}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusColor(test.status)}`}>
                      {getStatusIcon(test.status)}
                      <span className="ml-1">{test.status}</span>
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {test.recorded_by.first_name} {test.recorded_by.last_name}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {new Date(test.recorded_at).toLocaleDateString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
      </>
      )}

      {/* Warehouse Audit Tab */}
      {activeTab === 'audit' && (
        <div className="space-y-6">
          {auditLoading ? (
            <div className="flex justify-center py-8">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
          ) : (
          <>
          {/* Critical Gaps -- ranked by what actually blocks production */}
          <div className="bg-white rounded-lg shadow">
            <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
              <h3 className="text-lg font-medium text-gray-900 flex items-center">
                <AlertTriangle className="w-5 h-5 text-red-500 mr-2" />
                Critical Gaps
              </h3>
              <div className="text-sm text-gray-500">
                <span className="text-red-600 font-medium">{criticalGaps.critical_count} critical</span>
                {criticalGaps.warning_count > 0 && <span className="ml-3 text-yellow-600 font-medium">{criticalGaps.warning_count} warning</span>}
              </div>
            </div>
            <div className="p-6">
              {criticalGaps.gaps.length === 0 ? (
                <p className="text-sm text-gray-400">No gaps found -- everything checked out.</p>
              ) : (
                <ul className="divide-y divide-gray-100">
                  {criticalGaps.gaps.map((gap, i) => (
                    <li key={i} className="py-2 flex items-center">
                      <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium mr-3 ${severityBadge(gap.severity)}`}>{gap.severity}</span>
                      <span className="text-xs text-gray-400 uppercase mr-2 w-24 flex-shrink-0">{gap.area}</span>
                      <span className="text-sm text-gray-900">{gap.message}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>

          {/* Packaging materials stock watch */}
          <div className="bg-white rounded-lg shadow overflow-x-auto">
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-medium text-gray-900">Packaging Materials Stock Watch</h3>
              <p className="text-xs text-gray-500 mt-1">Labels, seals (bottle vs. refill jerrican), stickers, bailing papers. Edit reorder levels in Production &gt; Materials.</p>
            </div>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">On Hand</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Reorder At</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {packagingWatch.map(row => (
                  <tr key={row.material.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">{row.material.name}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{row.qty_on_hand.toLocaleString()} {row.material.uom}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-500">{row.reorder_level > 0 ? row.reorder_level.toLocaleString() : 'not set'}</td>
                    <td className="px-4 py-2"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${severityBadge(row.severity)}`}>{row.status.replace('_', ' ')}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Chemicals & water testing log */}
          <div className="bg-white rounded-lg shadow overflow-x-auto">
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-medium text-gray-900">Chemicals & Water Testing Log</h3>
              <p className="text-xs text-gray-500 mt-1">Chlorine stock and expiry. Edit stock/expiry in Production &gt; Materials.</p>
            </div>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Chemical</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">On Hand</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Expiry</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {chemicalsWatch.map(row => (
                  <tr key={row.material.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">{row.material.name}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{row.qty_on_hand.toLocaleString()} {row.material.uom}</td>
                    <td className="px-4 py-2 text-sm text-gray-500">{row.expiry_date ? new Date(row.expiry_date).toLocaleDateString() : 'not set'}</td>
                    <td className="px-4 py-2"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${severityBadge(row.severity)}`}>{row.status.replace('_', ' ')}{row.expiry_status && row.expiry_status !== 'valid' && row.expiry_status !== 'not_set' ? ` / ${row.expiry_status.replace('_', ' ')}` : ''}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* PPE tracking */}
          <div className="bg-white rounded-lg shadow overflow-x-auto">
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-medium text-gray-900">PPE Tracking</h3>
              <p className="text-xs text-gray-500 mt-1">Gunboots, raincoats, hair coverings -- against active headcount from HR.</p>
            </div>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">On Hand</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Active Staff</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Shortfall</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {ppeWatch.map(row => (
                  <tr key={row.material.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">{row.material.name}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{row.qty_on_hand.toLocaleString()}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-500">{row.headcount}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{row.shortfall > 0 ? row.shortfall : '—'}</td>
                    <td className="px-4 py-2"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${severityBadge(row.severity)}`}>{row.status}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Stationery stock */}
          <div className="bg-white rounded-lg shadow overflow-x-auto">
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-medium text-gray-900">Stationery Stock</h3>
              <p className="text-xs text-gray-500 mt-1">Receipt books, delivery books, invoice books.</p>
            </div>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">On Hand</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Reorder At</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {stationeryWatch.map(row => (
                  <tr key={row.material.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">{row.material.name}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{row.qty_on_hand.toLocaleString()} {row.material.uom}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-500">{row.reorder_level > 0 ? row.reorder_level.toLocaleString() : 'not set'}</td>
                    <td className="px-4 py-2"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${severityBadge(row.severity)}`}>{row.status.replace('_', ' ')}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Equipment & machinery log */}
          <div className="bg-white rounded-lg shadow overflow-x-auto">
            <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
              <div>
                <h3 className="text-lg font-medium text-gray-900">Equipment & Machinery Log</h3>
                <p className="text-xs text-gray-500 mt-1">Batching machine, heat guns, booster pumps/valves, production basins, backwash system.</p>
              </div>
              <button onClick={() => openNewEquipment('equipment')} className="flex items-center px-3 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700">
                <Plus className="w-4 h-4 mr-1" /> Add
              </button>
            </div>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Equipment</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Count / Min</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Condition</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Last Service</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Next Due</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {equipmentWatch.map(row => (
                  <tr key={row.item.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">{row.item.name}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{row.item.qty_on_hand ?? '—'} / {row.item.minimum_required ?? '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-500">{row.item.condition ?? 'not set'}</td>
                    <td className="px-4 py-2 text-sm text-gray-500">{row.item.last_service_date ? new Date(row.item.last_service_date).toLocaleDateString() : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-500">{row.item.next_service_due ? new Date(row.item.next_service_due).toLocaleDateString() : '—'}</td>
                    <td className="px-4 py-2"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${severityBadge(row.severity)}`}>{row.service_status.replace('_', ' ')}</span></td>
                    <td className="px-4 py-2 text-sm">
                      <div className="flex items-center space-x-2">
                        <button onClick={() => openEditEquipment(row.item)} className="text-green-600 hover:text-green-900"><Edit className="w-4 h-4" /></button>
                        <button onClick={() => handleDeleteEquipment(row.item)} className="text-red-600 hover:text-red-900"><Trash2 className="w-4 h-4" /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Test equipment */}
          <div className="bg-white rounded-lg shadow overflow-x-auto">
            <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
              <div>
                <h3 className="text-lg font-medium text-gray-900">Test Equipment</h3>
                <p className="text-xs text-gray-500 mt-1">pH tester availability and calibration status.</p>
              </div>
              <button onClick={() => openNewEquipment('test_equipment')} className="flex items-center px-3 py-1.5 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700">
                <Plus className="w-4 h-4 mr-1" /> Add
              </button>
            </div>
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Item</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">On Hand</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Calibration</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Next Due</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {testEquipmentWatch.map(row => (
                  <tr key={row.item.id} className="hover:bg-gray-50">
                    <td className="px-4 py-2 text-sm text-gray-900">{row.item.name}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900">{row.item.qty_on_hand ?? '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-500">{row.item.calibration_status?.replace('_', ' ') ?? 'not set'}</td>
                    <td className="px-4 py-2 text-sm text-gray-500">{row.item.next_service_due ? new Date(row.item.next_service_due).toLocaleDateString() : '—'}</td>
                    <td className="px-4 py-2"><span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${severityBadge(row.severity)}`}>{row.service_status.replace('_', ' ')}</span></td>
                    <td className="px-4 py-2 text-sm">
                      <div className="flex items-center space-x-2">
                        <button onClick={() => openEditEquipment(row.item)} className="text-green-600 hover:text-green-900"><Edit className="w-4 h-4" /></button>
                        <button onClick={() => handleDeleteEquipment(row.item)} className="text-red-600 hover:text-red-900"><Trash2 className="w-4 h-4" /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          </>
          )}
        </div>
      )}

      {/* Water Test Form Modal */}
      {showWaterTestForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 mb-4">New Water Test</h3>
            <form onSubmit={handleWaterTestSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Test Type</label>
                <select
                  value={waterTestForm.test_type}
                  onChange={(e) => setWaterTestForm({...waterTestForm, test_type: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="baseline">Baseline</option>
                  <option value="random">Random</option>
                  <option value="retest">Retest</option>
                </select>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">pH</label>
                  <input
                    type="number"
                    step="0.1"
                    value={waterTestForm.ph}
                    onChange={(e) => setWaterTestForm({...waterTestForm, ph: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">TDS</label>
                  <input
                    type="number"
                    value={waterTestForm.tds}
                    onChange={(e) => setWaterTestForm({...waterTestForm, tds: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Chlorine</label>
                  <input
                    type="number"
                    step="0.1"
                    value={waterTestForm.chlorine}
                    onChange={(e) => setWaterTestForm({...waterTestForm, chlorine: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Location</label>
                <input
                  type="text"
                  value={waterTestForm.location_text}
                  onChange={(e) => setWaterTestForm({...waterTestForm, location_text: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Notes</label>
                <textarea
                  value={waterTestForm.unit_notes}
                  onChange={(e) => setWaterTestForm({...waterTestForm, unit_notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={3}
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowWaterTestForm(false)}
                  className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                >
                  Create Test
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Batch Form Modal */}
      {showBatchForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 mb-4">New Batch</h3>
            <form onSubmit={handleBatchSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">SKU</label>
                <select
                  required
                  value={batchForm.sku_id}
                  onChange={(e) => setBatchForm({...batchForm, sku_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select SKU</option>
                  {skus.map(s => (
                    <option key={s.id} value={s.id}>{s.brand ? `${s.brand} -- ` : ''}{s.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Planned Quantity</label>
                <input
                  type="number"
                  value={batchForm.planned_qty}
                  onChange={(e) => setBatchForm({...batchForm, planned_qty: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Manufacture Date</label>
                  <input
                    type="date"
                    value={batchForm.manufacture_date}
                    onChange={(e) => setBatchForm({...batchForm, manufacture_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Expiry Date</label>
                  <input
                    type="date"
                    value={batchForm.expiry_date}
                    onChange={(e) => setBatchForm({...batchForm, expiry_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowBatchForm(false)}
                  className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
                >
                  Create Batch
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Equipment Form Modal */}
      {showEquipmentForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg p-6 w-full max-w-md">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900">{editingEquipmentId ? 'Edit' : 'Add'} {equipmentForm.category === 'test_equipment' ? 'Test Equipment' : 'Equipment'}</h3>
              <button onClick={() => setShowEquipmentForm(false)} className="text-gray-400 hover:text-gray-600"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleEquipmentSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Name</label>
                <input required type="text" value={equipmentForm.name}
                  onChange={(e) => setEquipmentForm({...equipmentForm, name: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Unit</label>
                  <input type="text" value={equipmentForm.unit}
                    onChange={(e) => setEquipmentForm({...equipmentForm, unit: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Count on Hand</label>
                  <input type="number" min={0} value={equipmentForm.qty_on_hand}
                    onChange={(e) => setEquipmentForm({...equipmentForm, qty_on_hand: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Minimum Needed</label>
                  <input type="number" min={0} value={equipmentForm.minimum_required}
                    onChange={(e) => setEquipmentForm({...equipmentForm, minimum_required: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              {equipmentForm.category === 'equipment' ? (
                <>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Condition</label>
                    <select value={equipmentForm.condition}
                      onChange={(e) => setEquipmentForm({...equipmentForm, condition: e.target.value})}
                      className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                      <option value="">Not set</option>
                      <option value="good">Good</option>
                      <option value="fair">Fair</option>
                      <option value="poor">Poor</option>
                      <option value="broken">Broken</option>
                    </select>
                  </div>
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-sm font-medium text-gray-700">Last Serviced</label>
                      <input type="date" value={equipmentForm.last_service_date}
                        onChange={(e) => setEquipmentForm({...equipmentForm, last_service_date: e.target.value})}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700">Next Service Due</label>
                      <input type="date" value={equipmentForm.next_service_due}
                        onChange={(e) => setEquipmentForm({...equipmentForm, next_service_due: e.target.value})}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    </div>
                  </div>
                </>
              ) : (
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Calibration Status</label>
                    <select value={equipmentForm.calibration_status}
                      onChange={(e) => setEquipmentForm({...equipmentForm, calibration_status: e.target.value})}
                      className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                      <option value="">Not set</option>
                      <option value="calibrated">Calibrated</option>
                      <option value="due">Due</option>
                      <option value="not_calibrated">Not Calibrated</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Next Calibration Due</label>
                    <input type="date" value={equipmentForm.next_service_due}
                      onChange={(e) => setEquipmentForm({...equipmentForm, next_service_due: e.target.value})}
                      className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                </div>
              )}
              <div>
                <label className="block text-sm font-medium text-gray-700">Notes</label>
                <textarea value={equipmentForm.notes}
                  onChange={(e) => setEquipmentForm({...equipmentForm, notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={2} />
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowEquipmentForm(false)} className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">{editingEquipmentId ? 'Save Changes' : 'Add Item'}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default QAPage;
