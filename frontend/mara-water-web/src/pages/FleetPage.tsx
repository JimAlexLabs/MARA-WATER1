import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
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
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

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
interface RouteRef { id: string; name: string; }
interface SkuRef { id: string; name: string; code: string; size_liters: string; }
interface TripItem { sku_id: string; qty_carried: string; qty_returned: string; unit_price: string; }

interface Trip {
  id: string;
  trip_date: string;
  driver: Person;
  vehicle: Vehicle;
  route?: RouteRef | null;
  mileage_start: number | null;
  mileage_end: number | null;
  km_covered: number | null;
  fuel_liters: string | null;
  fuel_cost: string | null;
  cash_collected: string;
  mpesa_collected: string;
  mpesa_reference: string | null;
  total_collected: number;
  reconciliation: { units_sold: number; expected_revenue: number; collected: number; variance: number; matches: boolean };
  items: { sku: SkuRef; qty_carried: number; qty_returned: number; unit_price: string }[];
}

const EMPTY_VEHICLE_FORM = {
  reg_no: '', make: '', model: '', year: '', capacity: '', fuel_type: '',
  insurance_expiry: '', inspection_expiry: '', speed_gov_status: 'active', notes: '',
};

const EMPTY_TRIP_FORM = {
  trip_date: new Date().toISOString().slice(0, 10),
  driver_id: '', vehicle_id: '', route_id: '',
  mileage_start: '', mileage_end: '', fuel_liters: '', fuel_cost: '', oil_liters: '',
  authorizing_officer_id: '', time_out: '', time_in: '',
  cash_collected: '', mpesa_collected: '', mpesa_reference: '', notes: '',
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
    if (!expiryDate) return { color: 'text-gray-600 bg-gray-100', text: 'No Date' };
    const today = new Date();
    const expiry = new Date(expiryDate);
    const daysUntilExpiry = Math.ceil((expiry.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    if (daysUntilExpiry < 0) return { color: 'text-red-600 bg-red-100', text: 'Expired' };
    if (daysUntilExpiry <= 30) return { color: 'text-yellow-600 bg-yellow-100', text: `${daysUntilExpiry} days` };
    return { color: 'text-green-600 bg-green-100', text: `${daysUntilExpiry} days` };
  };

  // --- Trips ---
  const [trips, setTrips] = useState<Trip[]>([]);
  const [tripsLoading, setTripsLoading] = useState(false);
  const [people, setPeople] = useState<Person[]>([]);
  const [routes, setRoutes] = useState<RouteRef[]>([]);
  const [skus, setSkus] = useState<SkuRef[]>([]);
  const [mileageTrend, setMileageTrend] = useState<any[]>([]);
  const [showTripForm, setShowTripForm] = useState(false);
  const [tripForm, setTripForm] = useState(EMPTY_TRIP_FORM);
  const [tripItems, setTripItems] = useState<TripItem[]>([{ sku_id: '', qty_carried: '', qty_returned: '', unit_price: '' }]);
  const [savingTrip, setSavingTrip] = useState(false);
  const [newRouteName, setNewRouteName] = useState('');
  const [addingRoute, setAddingRoute] = useState(false);

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
    api.get('/users?per_page=200').then(res => setPeople(res.data.data)).catch(() => {});
    api.get('/fleet/routes').then(res => setRoutes(res.data.data)).catch(() => {});
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/fleet/trips/mileage-trend').then(res => setMileageTrend(res.data.data)).catch(() => {});
  };

  useEffect(() => {
    if (activeTab === 'trips') {
      fetchTrips();
      fetchTripRefData();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab]);

  const kmCovered = (() => {
    const s = parseInt(tripForm.mileage_start, 10);
    const e = parseInt(tripForm.mileage_end, 10);
    return !isNaN(s) && !isNaN(e) && e >= s ? e - s : null;
  })();

  const addItemRow = () => setTripItems([...tripItems, { sku_id: '', qty_carried: '', qty_returned: '', unit_price: '' }]);
  const removeItemRow = (i: number) => setTripItems(tripItems.filter((_, idx) => idx !== i));
  const updateItemRow = (i: number, field: keyof TripItem, value: string) => {
    setTripItems(tripItems.map((row, idx) => idx === i ? { ...row, [field]: value } : row));
  };

  const openNewTrip = () => {
    setTripForm(EMPTY_TRIP_FORM);
    setTripItems([{ sku_id: '', qty_carried: '', qty_returned: '', unit_price: '' }]);
    setShowTripForm(true);
  };

  const handleAddRoute = async () => {
    if (!newRouteName.trim()) return;
    setAddingRoute(true);
    try {
      const res = await api.post('/fleet/routes', { name: newRouteName.trim() });
      setRoutes([...routes, res.data.data]);
      setTripForm({ ...tripForm, route_id: res.data.data.id });
      setNewRouteName('');
      toast.success('Route added');
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to add route');
    } finally {
      setAddingRoute(false);
    }
  };

  const handleTripSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingTrip(true);
    try {
      const payload = {
        ...tripForm,
        items: tripItems
          .filter(r => r.sku_id && (r.qty_carried || r.qty_returned))
          .map(r => ({
            sku_id: r.sku_id,
            qty_carried: parseInt(r.qty_carried, 10) || 0,
            qty_returned: parseInt(r.qty_returned, 10) || 0,
            unit_price: parseFloat(r.unit_price) || 0,
          })),
      };
      const res = await api.post('/fleet/trips', payload);
      const recon = res.data.data.reconciliation;
      if (recon.matches) {
        toast.success('Trip logged — stock and cash reconcile');
      } else {
        toast.error(`Trip logged, but off by KES ${Math.abs(recon.variance).toLocaleString()} — check stock/cash entries`, { duration: 6000 });
      }
      setShowTripForm(false);
      fetchTrips();
      fetchTripRefData();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to log trip');
    } finally {
      setSavingTrip(false);
    }
  };

  const handleDeleteTrip = async (t: Trip) => {
    if (!window.confirm(`Delete this trip (${t.trip_date})? This can't be undone.`)) return;
    try {
      await api.delete(`/fleet/trips/${t.id}`);
      toast.success('Trip deleted');
      fetchTrips();
    } catch {
      toast.error('Failed to delete trip');
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
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Fleet Management</h1>
          <p className="text-gray-600">Vehicles, driver trips, and reconciliation</p>
        </div>
        <div className="flex space-x-3">
          {activeTab === 'vehicles' ? (
            <button onClick={openNewVehicle} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
              <Plus className="w-4 h-4 mr-2" /> New Vehicle
            </button>
          ) : (
            <button onClick={openNewTrip} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
              <Plus className="w-4 h-4 mr-2" /> Log Trip
            </button>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex space-x-1 bg-gray-100 rounded-lg p-1 w-fit">
        {[{ id: 'vehicles', name: 'Vehicles' }, { id: 'trips', name: 'Driver Trips' }].map(tab => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`px-4 py-1.5 rounded-md text-sm font-medium ${activeTab === tab.id ? 'bg-white shadow text-gray-900' : 'text-gray-600 hover:text-gray-900'}`}
          >
            {tab.name}
          </button>
        ))}
      </div>

      {activeTab === 'vehicles' && (
        <>
          {/* Stats Cards */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div className="bg-white p-6 rounded-lg shadow">
              <div className="flex items-center">
                <Truck className="w-8 h-8 text-blue-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600">Total Vehicles</p>
                  <p className="text-2xl font-bold text-gray-900">{vehicles.length}</p>
                </div>
              </div>
            </div>
            <div className="bg-white p-6 rounded-lg shadow">
              <div className="flex items-center">
                <CheckCircle className="w-8 h-8 text-green-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600">Active Vehicles</p>
                  <p className="text-2xl font-bold text-gray-900">{vehicles.filter(v => v.active).length}</p>
                </div>
              </div>
            </div>
            <div className="bg-white p-6 rounded-lg shadow">
              <div className="flex items-center">
                <Wrench className="w-8 h-8 text-yellow-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600">In Maintenance</p>
                  <p className="text-2xl font-bold text-gray-900">{vehicles.filter(v => v.speed_gov_status === 'maintenance').length}</p>
                </div>
              </div>
            </div>
            <div className="bg-white p-6 rounded-lg shadow">
              <div className="flex items-center">
                <AlertTriangle className="w-8 h-8 text-red-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600">Expiring Soon</p>
                  <p className="text-2xl font-bold text-gray-900">
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
          <div className="bg-white rounded-lg shadow">
            <div className="px-6 py-4 border-b border-gray-200"><h3 className="text-lg font-medium text-gray-900">Vehicles</h3></div>
            <div className="p-6">
              <div className="flex flex-col md:flex-row gap-4 mb-6">
                <div className="flex-1 relative">
                  <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
                  <input type="text" placeholder="Search vehicles..." value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)}
                    className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent" />
                </div>
                <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} className="px-4 py-2 border border-gray-300 rounded-lg">
                  <option value="all">All Status</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>

              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle Details</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Capacity</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Insurance</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Inspection</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {filteredVehicles.map((vehicle) => {
                      const insuranceStatus = getExpiryStatus(vehicle.insurance_expiry);
                      const inspectionStatus = getExpiryStatus(vehicle.inspection_expiry);
                      return (
                        <tr key={vehicle.id} className="hover:bg-gray-50">
                          <td className="px-6 py-4 whitespace-nowrap">
                            <div className="text-sm font-medium text-gray-900">{vehicle.reg_no}</div>
                            <div className="text-sm text-gray-500">{vehicle.make} {vehicle.model} ({vehicle.year})</div>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {vehicle.driver ? `${vehicle.driver.first_name} ${vehicle.driver.last_name}` : 'No Driver Assigned'}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{(vehicle.capacity || 0).toLocaleString()}L</td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <div className="text-sm text-gray-900">{vehicle.insurance_expiry ? new Date(vehicle.insurance_expiry).toLocaleDateString() : 'N/A'}</div>
                            <span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${insuranceStatus.color}`}>{insuranceStatus.text}</span>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <div className="text-sm text-gray-900">{vehicle.inspection_expiry ? new Date(vehicle.inspection_expiry).toLocaleDateString() : 'N/A'}</div>
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
              <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Mileage &amp; Fuel Efficiency (last 30 days)</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {mileageTrend.map((v: any) => (
                  <div key={v.vehicle_id} className="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                    <div className="flex items-center justify-between mb-2">
                      <h4 className="text-sm font-semibold text-gray-900">{v.reg_no}</h4>
                      <span className="text-xs text-gray-500">{v.trips} trip{v.trips === 1 ? '' : 's'}</span>
                    </div>
                    <p className="text-xs text-gray-500 mb-2">
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

          <div className="bg-white rounded-lg shadow">
            <div className="px-6 py-4 border-b border-gray-200"><h3 className="text-lg font-medium text-gray-900">Driver Trips</h3></div>
            <div className="p-6">
              {tripsLoading ? (
                <p className="text-sm text-gray-500">Loading…</p>
              ) : trips.length === 0 ? (
                <p className="text-sm text-gray-500 text-center py-8">No trips logged yet.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date / Driver</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle / Route</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">KM</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Collected</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reconciliation</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                      {trips.map((t) => (
                        <tr key={t.id} className="hover:bg-gray-50">
                          <td className="px-4 py-3 whitespace-nowrap text-sm">
                            <div className="text-gray-900">{new Date(t.trip_date).toLocaleDateString()}</div>
                            <div className="text-gray-500">{t.driver?.first_name} {t.driver?.last_name}</div>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm">
                            <div className="text-gray-900">{t.vehicle?.reg_no}</div>
                            <div className="text-gray-500 flex items-center"><MapPin className="w-3 h-3 mr-1" />{t.route?.name || '—'}</div>
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{t.km_covered ?? '—'}</td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900">KES {t.total_collected.toLocaleString()}</td>
                          <td className="px-4 py-3 whitespace-nowrap">
                            {t.reconciliation.matches ? (
                              <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <CheckCircle className="w-3.5 h-3.5 mr-1" /> Matches
                              </span>
                            ) : (
                              <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800" title={`Expected KES ${t.reconciliation.expected_revenue}, collected KES ${t.reconciliation.collected}`}>
                                <XCircle className="w-3.5 h-3.5 mr-1" /> Off by KES {Math.abs(t.reconciliation.variance).toLocaleString()}
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-3 whitespace-nowrap text-sm">
                            <button onClick={() => handleDeleteTrip(t)} className="text-red-600 hover:text-red-900"><Trash2 className="w-4 h-4" /></button>
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
          <div className="bg-white rounded-lg p-6 w-full max-w-md max-h-[90vh] overflow-y-auto">
            <h3 className="text-lg font-medium text-gray-900 mb-4">{editingVehicleId ? 'Edit Vehicle' : 'New Vehicle'}</h3>
            <form onSubmit={handleVehicleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700">Registration Number</label>
                <input required type="text" value={vehicleForm.reg_no} onChange={(e) => setVehicleForm({ ...vehicleForm, reg_no: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Make</label>
                  <input required type="text" value={vehicleForm.make} onChange={(e) => setVehicleForm({ ...vehicleForm, make: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Model</label>
                  <input required type="text" value={vehicleForm.model} onChange={(e) => setVehicleForm({ ...vehicleForm, model: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
              </div>
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Year</label>
                  <input required type="number" value={vehicleForm.year} onChange={(e) => setVehicleForm({ ...vehicleForm, year: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Capacity (L)</label>
                  <input required type="number" value={vehicleForm.capacity} onChange={(e) => setVehicleForm({ ...vehicleForm, capacity: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Fuel Type</label>
                  <select value={vehicleForm.fuel_type} onChange={(e) => setVehicleForm({ ...vehicleForm, fuel_type: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">—</option>
                    <option value="diesel">Diesel</option>
                    <option value="petrol">Petrol</option>
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Insurance Expiry</label>
                  <input required type="date" value={vehicleForm.insurance_expiry} onChange={(e) => setVehicleForm({ ...vehicleForm, insurance_expiry: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Inspection Expiry</label>
                  <input required type="date" value={vehicleForm.inspection_expiry} onChange={(e) => setVehicleForm({ ...vehicleForm, inspection_expiry: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Status</label>
                <select value={vehicleForm.speed_gov_status} onChange={(e) => setVehicleForm({ ...vehicleForm, speed_gov_status: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                  <option value="active">Active</option>
                  <option value="maintenance">In Maintenance</option>
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Notes</label>
                <textarea value={vehicleForm.notes} onChange={(e) => setVehicleForm({ ...vehicleForm, notes: e.target.value })} rows={2} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowVehicleForm(false)} className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">{editingVehicleId ? 'Save Changes' : 'Create Vehicle'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Trip Form Modal */}
      {showTripForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <h3 className="text-lg font-medium text-gray-900 mb-4">Log Driver Trip</h3>
            <form onSubmit={handleTripSubmit} className="space-y-4">
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Date</label>
                  <input required type="date" value={tripForm.trip_date} onChange={(e) => setTripForm({ ...tripForm, trip_date: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Driver</label>
                  <select required value={tripForm.driver_id} onChange={(e) => setTripForm({ ...tripForm, driver_id: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">Select driver</option>
                    {people.map(p => <option key={p.id} value={p.id}>{p.first_name} {p.last_name}{p.role ? ` (${p.role.name})` : ''}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Vehicle</label>
                  <select required value={tripForm.vehicle_id} onChange={(e) => setTripForm({ ...tripForm, vehicle_id: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">Select vehicle</option>
                    {vehicles.map(v => <option key={v.id} value={v.id}>{v.reg_no}</option>)}
                  </select>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Route</label>
                <div className="flex gap-2 mt-1">
                  <select value={tripForm.route_id} onChange={(e) => setTripForm({ ...tripForm, route_id: e.target.value })} className="flex-1 border border-gray-300 rounded-md px-3 py-2">
                    <option value="">Select route</option>
                    {routes.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                  </select>
                  <input type="text" placeholder="Add new route…" value={newRouteName} onChange={(e) => setNewRouteName(e.target.value)} className="flex-1 border border-gray-300 rounded-md px-3 py-2" />
                  <button type="button" onClick={handleAddRoute} disabled={addingRoute || !newRouteName.trim()} className="px-3 py-2 border border-gray-300 rounded-md text-sm hover:bg-gray-50 disabled:opacity-50">Add</button>
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Mileage Start</label>
                  <input type="number" value={tripForm.mileage_start} onChange={(e) => setTripForm({ ...tripForm, mileage_start: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Mileage End</label>
                  <input type="number" value={tripForm.mileage_end} onChange={(e) => setTripForm({ ...tripForm, mileage_end: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div className="flex flex-col justify-end">
                  <label className="block text-sm font-medium text-gray-700">KM Covered</label>
                  <div className="mt-1 px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-700">{kmCovered ?? '—'}</div>
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Fuel (Litres)</label>
                  <input type="number" step="0.1" value={tripForm.fuel_liters} onChange={(e) => setTripForm({ ...tripForm, fuel_liters: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Fuel Cost (KES)</label>
                  <input type="number" value={tripForm.fuel_cost} onChange={(e) => setTripForm({ ...tripForm, fuel_cost: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Oil (Litres)</label>
                  <input type="number" step="0.1" value={tripForm.oil_liters} onChange={(e) => setTripForm({ ...tripForm, oil_liters: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Time Out</label>
                  <input type="time" value={tripForm.time_out} onChange={(e) => setTripForm({ ...tripForm, time_out: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Time In</label>
                  <input type="time" value={tripForm.time_in} onChange={(e) => setTripForm({ ...tripForm, time_in: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Authorizing Officer</label>
                  <select value={tripForm.authorizing_officer_id} onChange={(e) => setTripForm({ ...tripForm, authorizing_officer_id: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">—</option>
                    {people.map(p => <option key={p.id} value={p.id}>{p.first_name} {p.last_name}</option>)}
                  </select>
                </div>
              </div>

              <div className="border-t border-gray-200 pt-4">
                <div className="flex items-center justify-between mb-2">
                  <label className="block text-sm font-medium text-gray-700">Stock Carried / Returned</label>
                  <button type="button" onClick={addItemRow} className="text-sm text-blue-600 hover:text-blue-800">+ Add product</button>
                </div>
                <div className="space-y-2">
                  {tripItems.map((row, i) => (
                    <div key={i} className="grid grid-cols-12 gap-2 items-center">
                      <select value={row.sku_id} onChange={(e) => updateItemRow(i, 'sku_id', e.target.value)} className="col-span-4 border border-gray-300 rounded-md px-2 py-1.5 text-sm">
                        <option value="">Product</option>
                        {skus.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                      </select>
                      <input type="number" placeholder="Carried" value={row.qty_carried} onChange={(e) => updateItemRow(i, 'qty_carried', e.target.value)} className="col-span-2 border border-gray-300 rounded-md px-2 py-1.5 text-sm" />
                      <input type="number" placeholder="Returned" value={row.qty_returned} onChange={(e) => updateItemRow(i, 'qty_returned', e.target.value)} className="col-span-2 border border-gray-300 rounded-md px-2 py-1.5 text-sm" />
                      <input type="number" placeholder="Unit price" value={row.unit_price} onChange={(e) => updateItemRow(i, 'unit_price', e.target.value)} className="col-span-3 border border-gray-300 rounded-md px-2 py-1.5 text-sm" />
                      <button type="button" onClick={() => removeItemRow(i)} className="col-span-1 text-gray-400 hover:text-red-600"><X className="w-4 h-4" /></button>
                    </div>
                  ))}
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4 border-t border-gray-200 pt-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Cash Collected</label>
                  <input type="number" value={tripForm.cash_collected} onChange={(e) => setTripForm({ ...tripForm, cash_collected: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">M-Pesa Collected</label>
                  <input type="number" value={tripForm.mpesa_collected} onChange={(e) => setTripForm({ ...tripForm, mpesa_collected: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">M-Pesa Reference</label>
                  <input type="text" value={tripForm.mpesa_reference} onChange={(e) => setTripForm({ ...tripForm, mpesa_reference: e.target.value })} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Notes</label>
                <textarea value={tripForm.notes} onChange={(e) => setTripForm({ ...tripForm, notes: e.target.value })} rows={2} className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2" />
              </div>

              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowTripForm(false)} className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" disabled={savingTrip} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingTrip ? 'Logging…' : 'Log Trip'}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default FleetPage;
