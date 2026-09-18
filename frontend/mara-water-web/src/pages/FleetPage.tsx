import React, { useState, useEffect } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import {
  ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip,
} from 'recharts';
import {
  Plus,
  Search,
  Truck,
  Wrench,
  AlertTriangle,
  CheckCircle,
  XCircle,
  Edit,
  Trash2,
  MapPin,
  X,
  Download,
  Eye,
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { usePermissions } from '../contexts/AuthContext';

interface Vehicle {
  id: string;
  reg_no: string;
  make: string;
  model: string;
  year: number;
  capacity: number;
  active: boolean;
  fuel_type?: string | null;
  notes?: string | null;
  driver?: { id: string; first_name: string; last_name: string } | null;
  insurance_expiry?: string;
  inspection_expiry?: string;
  speed_gov_status?: string;
}

interface Person { id: string; first_name: string; last_name: string; role?: { code: string; name: string }; }
interface WarehouseRef { id: string; code: string; name: string; }
interface SkuRef { id: string; name: string; code: string; brand: string | null; size_liters: string; unit?: string; current_price?: number | null; }
type PaymentMethod = 'cash' | 'mpesa' | 'debt';

interface TripSale {
  id: string;
  customer: { id: string; name: string; phone?: string | null } | null;
  payment_method: PaymentMethod;
  amount: string;
  mpesa_reference: string | null;
  debt_signatory: string | null;
  debt_expected_repayment_date: string | null;
}

interface StockMismatch {
  sku_id: string;
  sku_name: string;
  dispatched: number;
  returned: number;
  implied_sold: number;
  reported_sold: number;
  difference: number;
}

interface Trip {
  id: string;
  trip_date: string;
  driver: Person;
  vehicle: Vehicle;
  route?: string | null;
  warehouse?: WarehouseRef | null;
  authorizingOfficer?: Person | null;
  status: 'pending_departure' | 'in_transit' | 'completed';
  has_discrepancy: boolean;
  mileage_start: number | null;
  mileage_end: number | null;
  km_covered: number | null;
  fuel_liters: string | null;
  fuel_cost: string | null;
  time_out: string | null;
  time_in: string | null;
  notes: string | null;
  cash_collected: number;
  mpesa_collected: number;
  debt_collected: number;
  total_collected: number;
  reconciliation: {
    units_sold: number; expected_revenue: number; collected: number;
    cash_collected: number; mpesa_collected: number; debt_collected: number;
    variance: number; matches: boolean;
    stock_mismatches: StockMismatch[]; stock_matches: boolean;
  };
  items: { sku_id: string; sku: SkuRef; qty_carried: number; qty_carried_bales: number; qty_returned: number; qty_returned_bales: number; qty_sold: number; unit_price: string }[];
  sales: TripSale[];
}

const TRIP_STATUS_BADGE: Record<string, { label: string; cls: string }> = {
  pending_departure: { label: 'Pending Departure', cls: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' },
  in_transit: { label: 'In Transit', cls: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' },
  completed: { label: 'Completed', cls: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
};

const EMPTY_VEHICLE_FORM = {
  reg_no: '', make: '', model: '', year: '', capacity: '', fuel_type: '',
  insurance_expiry: '', inspection_expiry: '', speed_gov_status: 'active', notes: '',
};


const FleetPage: React.FC = () => {
  const [searchParams] = useSearchParams();
  const [activeTab, setActiveTab] = useState('vehicles');
  const [searchTerm, setSearchTerm] = useState('');

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
    const tab = searchParams.get('tab');
    if (tab) setActiveTab(tab);
  }, [searchParams]);

  // --- Vehicles ---
  const [vehicles, setVehicles] = useState<Vehicle[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatus, setFilterStatus] = useState('all');
  const [showVehicleForm, setShowVehicleForm] = useState(false);
  const [editingVehicleId, setEditingVehicleId] = useState<string | null>(null);
  const [vehicleForm, setVehicleForm] = useState(EMPTY_VEHICLE_FORM);

  const fetchVehicles = async () => {
    try {
      setLoading(true);
      const response = await api.get('/fleet/vehicles');
      setVehicles(response.data.data);
    } catch (error) {
      toast.error('Failed to fetch fleet data');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { fetchVehicles(); }, []);

  const openNewVehicle = () => { setEditingVehicleId(null); setVehicleForm(EMPTY_VEHICLE_FORM); setShowVehicleForm(true); };
  const openEditVehicle = (v: Vehicle) => {
    setEditingVehicleId(v.id);
    setVehicleForm({
      reg_no: v.reg_no, make: v.make, model: v.model, year: String(v.year), capacity: String(v.capacity),
      fuel_type: v.fuel_type || '', insurance_expiry: (v.insurance_expiry || '').slice(0, 10),
      inspection_expiry: (v.inspection_expiry || '').slice(0, 10), speed_gov_status: v.speed_gov_status || 'active',
      notes: v.notes || '',
    });
    setShowVehicleForm(true);
  };

  const handleVehicleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      if (editingVehicleId) {
        await api.put(`/fleet/vehicles/${editingVehicleId}`, vehicleForm);
        toast.success('Vehicle updated');
      } else {
        await api.post('/fleet/vehicles', vehicleForm);
        toast.success('Vehicle created');
      }
      setShowVehicleForm(false);
      fetchVehicles();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to save vehicle');
    }
  };

  const handleDeleteVehicle = async (v: Vehicle) => {
    if (!window.confirm(`Remove vehicle ${v.reg_no}?`)) return;
    try {
      await api.delete(`/fleet/vehicles/${v.id}`);
      toast.success('Vehicle removed');
      fetchVehicles();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to remove vehicle');
    }
  };

  const filteredVehicles = vehicles.filter(vehicle => {
    const matchesSearch = (vehicle.reg_no || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                         (vehicle.make || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                         (vehicle.model || '').toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'all' || (filterStatus === 'active' ? vehicle.active : !vehicle.active);
    return matchesSearch && matchesStatus;
  });

  const getVehicleStatus = (vehicle: Vehicle) => {
    if (vehicle.speed_gov_status === 'maintenance') {
      return { status: 'maintenance', color: 'text-yellow-600 bg-yellow-100', icon: <Wrench className="w-4 h-4" /> };
    }
    if (vehicle.active) {
      return { status: 'active', color: 'text-green-600 bg-green-100', icon: <CheckCircle className="w-4 h-4" /> };
    }
    return { status: 'inactive', color: 'text-red-600 bg-red-100', icon: <AlertTriangle className="w-4 h-4" /> };
  };

  const getExpiryStatus = (expiryDate?: string) => {
    if (!expiryDate) return { color: 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700', text: 'No Date' };
    const today = new Date();
    const expiry = new Date(expiryDate);
    const daysUntilExpiry = Math.ceil((expiry.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    if (daysUntilExpiry < 0) return { color: 'text-red-600 bg-red-100', text: 'Expired' };
    if (daysUntilExpiry <= 30) return { color: 'text-yellow-600 bg-yellow-100', text: `${daysUntilExpiry} days` };
    return { color: 'text-green-600 bg-green-100', text: `${daysUntilExpiry} days` };
  };

  // --- Trips ---
  // Round 3 Phase 2: trip logging itself moved to a staged workflow
  // (pending_departure -> in_transit -> completed) that only DriverPage
  // builds a form for -- Manager/Director's Fleet tab is view + Director-
  // only Unlock from here on, not a second copy of the staged form.
  const { isDirector } = usePermissions();
  const [trips, setTrips] = useState<Trip[]>([]);
  const [tripsLoading, setTripsLoading] = useState(false);
  const [mileageTrend, setMileageTrend] = useState<any[]>([]);
  const [viewingTrip, setViewingTrip] = useState<Trip | null>(null);
  const [exportingTrips, setExportingTrips] = useState(false);
  const [showUnlockPrompt, setShowUnlockPrompt] = useState(false);
  const [unlockReason, setUnlockReason] = useState('');
  const [unlocking, setUnlocking] = useState(false);

  const fetchTrips = async () => {
    setTripsLoading(true);
    try {
      const res = await api.get('/fleet/trips');
      setTrips(res.data.data);
    } catch {
      toast.error('Failed to fetch trips');
    } finally {
      setTripsLoading(false);
    }
  };

  const fetchTripRefData = () => {
    api.get('/fleet/trips/mileage-trend').then(res => setMileageTrend(res.data.data)).catch(() => {});
  };

  useEffect(() => {
    if (activeTab === 'trips') {
      fetchTrips();
      fetchTripRefData();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab]);

  const handleDeleteTrip = async (t: Trip) => {
    if (t.status !== 'pending_departure') {
      toast.error('A locked trip must be unlocked by a Director before it can be deleted');
      return;
    }
    if (!window.confirm(`Delete this trip (${t.trip_date})? This can't be undone.`)) return;
    try {
      await api.delete(`/fleet/trips/${t.id}`);
      toast.success('Trip deleted');
      fetchTrips();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to delete trip');
    }
  };

  const openTripDetail = (id: string) => {
    api.get(`/fleet/trips/${id}`).then(res => setViewingTrip(res.data.data)).catch(() => toast.error('Failed to load trip'));
  };

  const downloadSheet = (trip: Trip, kind: 'dispatch' | 'return') => {
    api.get(`/fleet/trips/${trip.id}/${kind}-sheet`, { responseType: 'blob' }).then((res) => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = `${kind}-sheet-${trip.trip_date}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    }).catch(() => toast.error(`Failed to download ${kind} sheet`));
  };

  // Round 3 Phase 2: reverses exactly one stage transition (the most
  // recent lock) -- Director-only, both here (UX) and server-side (the
  // actual gate). Reason required, every unlock audit-logged.
  const submitUnlock = async () => {
    if (!viewingTrip || !unlockReason.trim()) { toast.error('Enter a reason for the unlock'); return; }
    setUnlocking(true);
    try {
      const res = await api.post(`/fleet/trips/${viewingTrip.id}/unlock`, { reason: unlockReason });
      toast.success('Trip unlocked');
      setViewingTrip(res.data.data);
      setShowUnlockPrompt(false);
      setUnlockReason('');
      fetchTrips();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to unlock trip');
    } finally {
      setUnlocking(false);
    }
  };

  // Round 2 Phase 7: "one click export ... with every column above" --
  // auth header is added by the shared axios instance's interceptor, but
  // a plain <a href> download can't carry it, so fetch as a blob instead
  // (same pattern as HRPage's bank transfer file download).
  const downloadTripLog = () => {
    setExportingTrips(true);
    api.get('/fleet/trips/export', { responseType: 'blob' }).then((res) => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = `driver-trip-log-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    }).catch(() => toast.error('Failed to export trip log'))
      .finally(() => setExportingTrips(false));
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
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Fleet Management</h1>
          <p className="text-gray-600 dark:text-gray-400">Vehicles, driver trips, and reconciliation</p>
        </div>
        <div className="flex space-x-3">
          {activeTab === 'vehicles' ? (
            <button onClick={openNewVehicle} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
              <Plus className="w-4 h-4 mr-2" /> New Vehicle
            </button>
          ) : (
            <button onClick={downloadTripLog} disabled={exportingTrips} className="flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50">
              <Download className="w-4 h-4 mr-2" /> {exportingTrips ? 'Exporting…' : 'Export'}
            </button>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex space-x-1 bg-gray-100 dark:bg-gray-700 rounded-lg p-1 w-fit">
        {[{ id: 'vehicles', name: 'Vehicles' }, { id: 'trips', name: 'Driver Trips' }].map(tab => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`px-4 py-1.5 rounded-md text-sm font-medium ${activeTab === tab.id ? 'bg-white dark:bg-gray-800 shadow text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100'}`}
          >
            {tab.name}
          </button>
        ))}
      </div>

      {activeTab === 'vehicles' && (
        <>
          {/* Stats Cards */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <div className="flex items-center">
                <Truck className="w-8 h-8 text-blue-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Vehicles</p>
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{vehicles.length}</p>
                </div>
              </div>
            </div>
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <div className="flex items-center">
                <CheckCircle className="w-8 h-8 text-green-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Active Vehicles</p>
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{vehicles.filter(v => v.active).length}</p>
                </div>
              </div>
            </div>
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <div className="flex items-center">
                <Wrench className="w-8 h-8 text-yellow-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400">In Maintenance</p>
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{vehicles.filter(v => v.speed_gov_status === 'maintenance').length}</p>
                </div>
              </div>
            </div>
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <div className="flex items-center">
                <AlertTriangle className="w-8 h-8 text-red-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Expiring Soon</p>
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {vehicles.filter(v => {
                      const d = getExpiryStatus(v.insurance_expiry);
                      const i = getExpiryStatus(v.inspection_expiry);
                      return (d.text.includes('day') && !d.text.includes('No')) || (i.text.includes('day') && !i.text.includes('No'));
                    }).length}
                  </p>
                </div>
              </div>
            </div>
          </div>

          {/* Vehicles Table */}
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700"><h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Vehicles</h3></div>
            <div className="p-6">
              <div className="flex flex-col md:flex-row gap-4 mb-6">
                <div className="flex-1 relative">
                  <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500 w-4 h-4" />
                  <input type="text" placeholder="Search vehicles..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)}
                    className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                </div>
                <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg">
                  <option value="all">All Status</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>

              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                  <thead className="bg-gray-50 dark:bg-gray-900">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Vehicle Details</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Driver</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Capacity</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Insurance</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Inspection</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    {filteredVehicles.map((vehicle) => {
                      const insuranceStatus = getExpiryStatus(vehicle.insurance_expiry);
                      const inspectionStatus = getExpiryStatus(vehicle.inspection_expiry);
                      return (
                        <tr key={vehicle.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                          <td className="px-6 py-4 whitespace-nowrap">
                            <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{vehicle.reg_no}</div>
                            <div className="text-sm text-gray-500 dark:text-gray-400">{vehicle.make} {vehicle.model} ({vehicle.year})</div>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                            {vehicle.driver ? `${vehicle.driver.first_name} ${vehicle.driver.last_name}` : 'No Driver Assigned'}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{(vehicle.capacity || 0).toLocaleString()}L</td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <div className="text-sm text-gray-900 dark:text-gray-100">{vehicle.insurance_expiry ? new Date(vehicle.insurance_expiry).toLocaleDateString() : 'N/A'}</div>
                            <span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${insuranceStatus.color}`}>{insuranceStatus.text}</span>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <div className="text-sm text-gray-900 dark:text-gray-100">{vehicle.inspection_expiry ? new Date(vehicle.inspection_expiry).toLocaleDateString() : 'N/A'}</div>
                            <span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${inspectionStatus.color}`}>{inspectionStatus.text}</span>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getVehicleStatus(vehicle).color}`}>
                              {getVehicleStatus(vehicle).icon}<span className="ml-1">{getVehicleStatus(vehicle).status}</span>
                            </span>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div className="flex items-center space-x-3">
                              <button onClick={() => openEditVehicle(vehicle)} className="text-green-600 hover:text-green-900"><Edit className="w-4 h-4" /></button>
                              <button onClick={() => handleDeleteVehicle(vehicle)} className="text-red-600 hover:text-red-900"><Trash2 className="w-4 h-4" /></button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </>
      )}

      {activeTab === 'trips' && (
        <>
          {mileageTrend.length > 0 && (
            <div>
              <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-3">Mileage &amp; Fuel Efficiency (last 30 days)</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {mileageTrend.map((v: any) => (
                  <div key={v.vehicle_id} className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                    <div className="flex items-center justify-between mb-2">
                      <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100">{v.reg_no}</h4>
                      <span className="text-xs text-gray-500 dark:text-gray-400">{v.trips} trip{v.trips === 1 ? '' : 's'}</span>
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
                      {v.total_km.toLocaleString()} km · {v.total_fuel_liters.toLocaleString()} L
                      {v.km_per_liter ? ` · ${v.km_per_liter} km/L` : ''}
                    </p>
                    <ResponsiveContainer width="100%" height={100}>
                      <LineChart data={v.daily}>
                        <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#eee" />
                        <XAxis dataKey="date" tick={{ fontSize: 10 }} axisLine={false} tickLine={false} />
                        <YAxis hide />
                        <Tooltip formatter={(val: number) => `${val} km`} />
                        <Line type="monotone" dataKey="km" stroke="#4f46e5" strokeWidth={2} dot={false} />
                      </LineChart>
                    </ResponsiveContainer>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700"><h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Driver Trips</h3></div>
            <div className="p-6">
              {tripsLoading ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
              ) : trips.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">No trips logged yet.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead className="bg-gray-50 dark:bg-gray-900">
                      <tr>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date / Driver</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Vehicle / Route</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">KM</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Buyers</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Collected (Cash / M-Pesa / Debt)</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Reconciliation</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                      {trips.map((t) => (
                        <tr key={t.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                          <td className="px-4 py-3 whitespace-nowrap text-sm">
                            <div className="text-gray-900 dark:text-gray-100">{new Date(t.trip_date).toLocaleDateString()}</div>
                            <div className="text-gray-500 dark:text-gray-400">{t.driver?.first_name} {t.driver?.last_name}</div>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm">
                            <div className="text-gray-900 dark:text-gray-100">{t.vehicle?.reg_no}</div>
                            <div className="text-gray-500 dark:text-gray-400 flex items-center"><MapPin className="w-3 h-3 mr-1" />{t.route || '—'}</div>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap">
                            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${TRIP_STATUS_BADGE[t.status].cls}`}>{TRIP_STATUS_BADGE[t.status].label}</span>
                            {t.has_discrepancy && (
                              <span className="ml-1 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
                                <AlertTriangle className="w-3.5 h-3.5 mr-1" />Discrepancy
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{t.km_covered ?? '—'}</td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 max-w-[10rem] truncate" title={t.sales.map(s => s.customer?.name).filter(Boolean).join(', ')}>
                            {t.sales.length === 0 ? '—' : `${t.sales.length} sale${t.sales.length === 1 ? '' : 's'}`}
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                            KES {t.total_collected.toLocaleString()}
                            <div className="text-xs text-gray-500 dark:text-gray-400">
                              {t.cash_collected.toLocaleString()} / {t.mpesa_collected.toLocaleString()} / {t.debt_collected.toLocaleString()}
                            </div>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap space-y-1">
                            {t.status !== 'completed' ? (
                              <span className="text-xs text-gray-400 dark:text-gray-500">Available once closed</span>
                            ) : (
                              <>
                                {t.reconciliation.matches ? (
                                  <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <CheckCircle className="w-3.5 h-3.5 mr-1" /> Cash Matches
                                  </span>
                                ) : (
                                  <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800" title={`Expected KES ${t.reconciliation.expected_revenue}, collected KES ${t.reconciliation.collected}`}>
                                    <XCircle className="w-3.5 h-3.5 mr-1" /> Off by KES {Math.abs(t.reconciliation.variance).toLocaleString()}
                                  </span>
                                )}
                              </>
                            )}
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm">
                            <div className="flex items-center space-x-3">
                              <button onClick={() => openTripDetail(t.id)} className="text-blue-600 hover:text-blue-900"><Eye className="w-4 h-4" /></button>
                              <button onClick={() => handleDeleteTrip(t)} className="text-red-600 hover:text-red-900"><Trash2 className="w-4 h-4" /></button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </>
      )}

      {/* Vehicle Form Modal */}
      {showVehicleForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md max-h-[90vh] overflow-y-auto">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{editingVehicleId ? 'Edit Vehicle' : 'New Vehicle'}</h3>
            <form onSubmit={handleVehicleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Registration Number</label>
                <input required type="text" value={vehicleForm.reg_no} onChange={(e) => setVehicleForm({ ...vehicleForm, reg_no: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Make</label>
                  <input required type="text" value={vehicleForm.make} onChange={(e) => setVehicleForm({ ...vehicleForm, make: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Model</label>
                  <input required type="text" value={vehicleForm.model} onChange={(e) => setVehicleForm({ ...vehicleForm, model: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Year</label>
                  <input required type="number" value={vehicleForm.year} onChange={(e) => setVehicleForm({ ...vehicleForm, year: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Capacity (L)</label>
                  <input required type="number" value={vehicleForm.capacity} onChange={(e) => setVehicleForm({ ...vehicleForm, capacity: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Fuel Type</label>
                  <select value={vehicleForm.fuel_type} onChange={(e) => setVehicleForm({ ...vehicleForm, fuel_type: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="">—</option>
                    <option value="diesel">Diesel</option>
                    <option value="petrol">Petrol</option>
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Insurance Expiry</label>
                  <input required type="date" value={vehicleForm.insurance_expiry} onChange={(e) => setVehicleForm({ ...vehicleForm, insurance_expiry: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Inspection Expiry</label>
                  <input required type="date" value={vehicleForm.inspection_expiry} onChange={(e) => setVehicleForm({ ...vehicleForm, inspection_expiry: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select value={vehicleForm.speed_gov_status} onChange={(e) => setVehicleForm({ ...vehicleForm, speed_gov_status: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                  <option value="active">Active</option>
                  <option value="maintenance">In Maintenance</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea value={vehicleForm.notes} onChange={(e) => setVehicleForm({ ...vehicleForm, notes: e.target.value })} rows={2} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowVehicleForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">{editingVehicleId ? 'Save Changes' : 'Create Vehicle'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Round 2 Phase 7: trip detail view -- full stock breakdown per
          product/size (with mismatches highlighted) and the per-sale
          buyer list, since the list row itself can only show totals. */}
      {viewingTrip && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <div className="flex items-center gap-3">
                <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                  Trip — {new Date(viewingTrip.trip_date).toLocaleDateString()}
                </h3>
                <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${TRIP_STATUS_BADGE[viewingTrip.status].cls}`}>{TRIP_STATUS_BADGE[viewingTrip.status].label}</span>
              </div>
              <button onClick={() => setViewingTrip(null)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"><X className="w-5 h-5" /></button>
            </div>

            <div className="flex flex-wrap gap-2 mb-4">
              {viewingTrip.status !== 'pending_departure' && (
                <button onClick={() => downloadSheet(viewingTrip, 'dispatch')} className="flex items-center text-xs px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                  <Download className="w-3.5 h-3.5 mr-1" /> Dispatch Sheet
                </button>
              )}
              {viewingTrip.status === 'completed' && (
                <button onClick={() => downloadSheet(viewingTrip, 'return')} className="flex items-center text-xs px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                  <Download className="w-3.5 h-3.5 mr-1" /> Return Sheet
                </button>
              )}
              {isDirector() && viewingTrip.status !== 'pending_departure' && (
                <button onClick={() => setShowUnlockPrompt(true)} className="flex items-center text-xs px-3 py-1.5 rounded-md border border-amber-300 dark:border-amber-700 text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-900/30">
                  Unlock (Director)
                </button>
              )}
            </div>

            <dl className="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm mb-6">
              <div><dt className="text-gray-500 dark:text-gray-400">Driver</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.driver?.first_name} {viewingTrip.driver?.last_name}</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Vehicle</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.vehicle?.reg_no}</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Route</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.route || '—'}</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Warehouse</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.warehouse?.name || '—'}</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Mileage</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.mileage_start ?? '—'} → {viewingTrip.mileage_end ?? '—'} ({viewingTrip.km_covered ?? '—'} km)</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Fuel</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.fuel_liters ?? '—'} L (KES {viewingTrip.fuel_cost ?? '0'})</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Departure / Return</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.time_out ?? '—'} → {viewingTrip.time_in ?? '—'}</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Authorizing Officer</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.authorizingOfficer ? `${viewingTrip.authorizingOfficer.first_name} ${viewingTrip.authorizingOfficer.last_name}` : '—'}</dd></div>
              {viewingTrip.notes && <div className="col-span-2 md:col-span-3"><dt className="text-gray-500 dark:text-gray-400">Notes</dt><dd className="text-gray-900 dark:text-gray-100">{viewingTrip.notes}</dd></div>}
            </dl>

            <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Stock</h4>
            <div className="overflow-x-auto mb-6">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                    <th className="pr-4 py-1">Product</th>
                    <th className="pr-4 py-1">Dispatched</th>
                    <th className="pr-4 py-1">Dispatched (bales)</th>
                    <th className="pr-4 py-1">Returned</th>
                    <th className="pr-4 py-1">Returned (bales)</th>
                    <th className="pr-4 py-1">Sold</th>
                    <th className="pr-4 py-1">Unit Price</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {viewingTrip.items.map((item, i) => {
                    const mismatch = viewingTrip.reconciliation.stock_mismatches.find(m => m.sku_id === item.sku_id);
                    return (
                      <tr key={i} className={mismatch ? 'bg-red-50 dark:bg-red-900/20' : ''}>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">{item.sku?.name || 'Product'}</td>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">{item.qty_carried}</td>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">{item.qty_carried_bales}</td>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">{item.qty_returned}</td>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">{item.qty_returned_bales}</td>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">
                          {item.qty_sold}
                          {mismatch && <span className="ml-1 text-xs text-red-600" title={`Implied ${mismatch.implied_sold} from dispatched-returned`}>(implied {mismatch.implied_sold})</span>}
                        </td>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">KES {item.unit_price}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Sales</h4>
            <div className="overflow-x-auto mb-6">
              {viewingTrip.sales.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">No sales logged for this trip.</p>
              ) : (
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                      <th className="pr-4 py-1">Buyer</th>
                      <th className="pr-4 py-1">Method</th>
                      <th className="pr-4 py-1">Amount</th>
                      <th className="pr-4 py-1">Details</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {viewingTrip.sales.map((sale) => (
                      <tr key={sale.id}>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">
                          {sale.customer ? <Link to={`/customers/${sale.customer.id}`} className="text-blue-600 hover:text-blue-800 hover:underline">{sale.customer.name}</Link> : '—'}
                        </td>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100 capitalize">{sale.payment_method}</td>
                        <td className="pr-4 py-1 text-gray-900 dark:text-gray-100">KES {parseFloat(sale.amount).toLocaleString()}</td>
                        <td className="pr-4 py-1 text-gray-500 dark:text-gray-400">
                          {sale.payment_method === 'mpesa' && (sale.mpesa_reference || '—')}
                          {sale.payment_method === 'debt' && `Signed: ${sale.debt_signatory || '—'} · Repay by ${sale.debt_expected_repayment_date || '—'}`}
                          {sale.payment_method === 'cash' && '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>

            <dl className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <div><dt className="text-gray-500 dark:text-gray-400">Expected Revenue</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {viewingTrip.reconciliation.expected_revenue.toLocaleString()}</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Collected</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {viewingTrip.total_collected.toLocaleString()}</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Variance</dt><dd className={`font-medium ${viewingTrip.reconciliation.matches ? 'text-green-600' : 'text-red-600'}`}>KES {viewingTrip.reconciliation.variance.toLocaleString()}</dd></div>
              <div><dt className="text-gray-500 dark:text-gray-400">Stock</dt><dd className={`font-medium ${viewingTrip.reconciliation.stock_matches ? 'text-green-600' : 'text-red-600'}`}>{viewingTrip.reconciliation.stock_matches ? 'Matches' : `${viewingTrip.reconciliation.stock_mismatches.length} mismatch(es)`}</dd></div>
            </dl>

            <div className="flex justify-end mt-4">
              <button type="button" onClick={() => setViewingTrip(null)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Close</button>
            </div>
          </div>
        </div>
      )}

      {/* Round 3 Phase 2: Director-only unlock -- reverses exactly one
          stage transition (undoing Start Trip or End Trip, including the
          matching stock reversal). Reason required, every unlock
          audit-logged server-side. */}
      {showUnlockPrompt && viewingTrip && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Unlock Trip</h3>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
              {viewingTrip.status === 'completed'
                ? 'This undoes End Trip -- reverses the return stock moves and reopens it as In Transit so returns/mileage end can be corrected.'
                : 'This undoes Start Trip -- reverses the dispatch stock deduction and reopens it as Pending Departure so items/mileage start can be corrected.'}
            </p>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason (required)</label>
            <textarea required rows={3} value={unlockReason} onChange={(e) => setUnlockReason(e.target.value)} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
            <div className="flex justify-end space-x-3 mt-4">
              <button type="button" onClick={() => { setShowUnlockPrompt(false); setUnlockReason(''); }} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
              <button type="button" onClick={submitUnlock} disabled={unlocking || !unlockReason.trim()} className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50">{unlocking ? 'Unlocking…' : 'Unlock'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default FleetPage;
