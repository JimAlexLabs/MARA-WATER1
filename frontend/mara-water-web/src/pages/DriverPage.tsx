import React, { useCallback, useEffect, useState } from 'react';
import { Clock, LogIn, LogOut, Plus, CheckCircle, XCircle, MapPin, X } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 2 Phase 11: a driver's own dashboard/login -- "Log Driver Trip,
// view their own trip history, check-in/check-out. No visibility into
// other drivers, finance, or HR." A deliberately separate, simpler page
// from FleetPage (which stays Manager/Director's fleet-wide tool) --
// every trip/attendance call here is already scoped server-side to the
// logged-in driver, but this page also just never shows anyone else's
// data or the vehicle-management/finance chrome a driver has no access
// to anyway.

interface RouteRef { id: string; name: string; }
interface WarehouseRef { id: string; code: string; name: string; }
interface SkuRef { id: string; name: string; code: string; brand: string | null; current_price?: number | null; }
interface VehicleRef { id: string; reg_no: string; }
interface CustomerRef { id: string; name: string; code: string; }
interface GridItem { qty_carried: string; qty_returned: string; qty_sold: string; unit_price: string; }
type PaymentMethod = 'cash' | 'mpesa' | 'debt';
interface SaleRow {
  customer_id: string; payment_method: PaymentMethod; amount: string;
  mpesa_reference: string; debt_signatory: string; debt_expected_repayment_date: string;
}

interface RecentTrip {
  id: string; trip_date: string; vehicle: string | null; route: string | null;
  km_covered: number | null; total_collected: number;
  reconciliation: { matches: boolean; stock_matches: boolean };
}
interface Summary {
  today: { clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null };
  this_month: { trips: number; km_covered: number; total_collected: number };
  recent_trips: RecentTrip[];
}

const EMPTY_TRIP_FORM = {
  trip_date: new Date().toISOString().slice(0, 10),
  vehicle_id: '', route_id: '', warehouse_id: '',
  mileage_start: '', mileage_end: '', fuel_liters: '', fuel_cost: '',
  time_out: '', time_in: '', notes: '',
};
const EMPTY_SALE_ROW: SaleRow = { customer_id: '', payment_method: 'cash', amount: '', mpesa_reference: '', debt_signatory: '', debt_expected_repayment_date: '' };
const EMPTY_GRID_ITEM: GridItem = { qty_carried: '', qty_returned: '', qty_sold: '', unit_price: '' };

const BRAND_ORDER = ['Premium', 'Platinum', 'Grace', 'Refill'];
const brandLabel = (brand: string | null) => brand || 'Mara Water';
const groupSkusByBrand = (skus: SkuRef[]) => {
  const groups = new Map<string, SkuRef[]>();
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
const tripMaxRepaymentDate = (fromDate: string) => {
  const d = new Date((fromDate || new Date().toISOString().slice(0, 10)) + 'T00:00:00');
  d.setDate(d.getDate() + 7);
  return d.toISOString().slice(0, 10);
};

const DriverPage: React.FC = () => {
  const { user } = useAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [clockLoading, setClockLoading] = useState(false);

  const [vehicles, setVehicles] = useState<VehicleRef[]>([]);
  const [routes, setRoutes] = useState<RouteRef[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseRef[]>([]);
  const [skus, setSkus] = useState<SkuRef[]>([]);
  const [customers, setCustomers] = useState<CustomerRef[]>([]);

  const [showTripForm, setShowTripForm] = useState(false);
  const [tripForm, setTripForm] = useState(EMPTY_TRIP_FORM);
  const [tripGrid, setTripGrid] = useState<Record<string, GridItem>>({});
  const [tripSales, setTripSales] = useState<SaleRow[]>([{ ...EMPTY_SALE_ROW }]);
  const [savingTrip, setSavingTrip] = useState(false);
  const [showDebtConfirm, setShowDebtConfirm] = useState(false);

  const fetchSummary = useCallback(() => {
    api.get('/driver/summary').then(res => setSummary(res.data.data)).catch(() => toast.error('Failed to load your summary')).finally(() => setLoading(false));
  }, []);

  useEffect(() => { fetchSummary(); }, [fetchSummary]);

  const fetchTripRefData = () => {
    // Driver-tier accessible reference lists only -- /fleet/vehicles-list
    // is a plain id/reg_no picker, not /fleet/vehicles (Manager/Director-
    // only vehicle management).
    api.get('/fleet/routes').then(res => setRoutes(res.data.data)).catch(() => {});
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/fleet/vehicles-list').then(res => setVehicles(res.data.data)).catch(() => {});
    api.get('/fleet/warehouses').then(res => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
    api.get('/sales/customers', { params: { limit: 500 } }).then(res => setCustomers(res.data.data)).catch(() => setCustomers([]));
  };

  const handleClockIn = async () => {
    setClockLoading(true);
    try {
      await api.post('/hr/attendance/clock-in', { user_id: user?.id });
      toast.success('Clocked in');
      fetchSummary();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to clock in');
    } finally {
      setClockLoading(false);
    }
  };

  const handleClockOut = async () => {
    setClockLoading(true);
    try {
      const res = await api.post('/hr/attendance/clock-out', { user_id: user?.id });
      toast.success(`Clocked out -- ${res.data.data?.total_hours ?? ''}h worked`);
      fetchSummary();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to clock out');
    } finally {
      setClockLoading(false);
    }
  };

  const openNewTrip = () => {
    fetchTripRefData();
    setTripForm(EMPTY_TRIP_FORM);
    setTripGrid({});
    setTripSales([{ ...EMPTY_SALE_ROW }]);
    setShowDebtConfirm(false);
    setShowTripForm(true);
  };

  // Pre-fill each row's price once SKUs (with current_price) load.
  useEffect(() => {
    if (!showTripForm || skus.length === 0) return;
    setTripGrid(prev => {
      if (Object.keys(prev).length > 0) return prev;
      const grid: Record<string, GridItem> = {};
      skus.forEach(s => {
        if (s.current_price != null) grid[s.id] = { ...EMPTY_GRID_ITEM, unit_price: String(s.current_price) };
      });
      return grid;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [skus, showTripForm]);

  const updateGridCell = (skuId: string, field: keyof GridItem, value: string) => {
    setTripGrid(prev => ({ ...prev, [skuId]: { ...(prev[skuId] || EMPTY_GRID_ITEM), [field]: value } }));
  };
  const addSaleRow = () => setTripSales([...tripSales, { ...EMPTY_SALE_ROW }]);
  const removeSaleRow = (i: number) => setTripSales(tripSales.filter((_, idx) => idx !== i));
  const updateSaleRow = (i: number, field: keyof SaleRow, value: string) => {
    setTripSales(tripSales.map((row, idx) => idx === i ? { ...row, [field]: value } : row));
  };

  const usedSales = () => tripSales.filter(s => s.customer_id && parseFloat(s.amount) > 0);
  const debtSales = () => usedSales().filter(s => s.payment_method === 'debt');

  const buildItemsPayload = () => Object.entries(tripGrid)
    .filter(([, r]) => (parseInt(r.qty_carried, 10) || 0) > 0 || (parseInt(r.qty_returned, 10) || 0) > 0 || (parseInt(r.qty_sold, 10) || 0) > 0)
    .map(([sku_id, r]) => ({
      sku_id,
      qty_carried: parseInt(r.qty_carried, 10) || 0,
      qty_returned: parseInt(r.qty_returned, 10) || 0,
      qty_sold: parseInt(r.qty_sold, 10) || 0,
      unit_price: parseFloat(r.unit_price) || skus.find(s => s.id === sku_id)?.current_price || 0,
    }));

  const buildSalesPayload = () => usedSales().map(s => ({
    customer_id: s.customer_id,
    payment_method: s.payment_method,
    amount: parseFloat(s.amount) || 0,
    mpesa_reference: s.payment_method === 'mpesa' ? (s.mpesa_reference || undefined) : undefined,
    debt_signatory: s.payment_method === 'debt' ? s.debt_signatory : undefined,
    debt_expected_repayment_date: s.payment_method === 'debt' ? s.debt_expected_repayment_date : undefined,
  }));

  const handleTripSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    for (const sale of debtSales()) {
      if (!sale.debt_signatory.trim()) { toast.error('Enter who signed for each credit sale'); return; }
      if (!sale.debt_expected_repayment_date) { toast.error('Enter an expected repayment date for each credit sale'); return; }
    }
    if (debtSales().length > 0) { setShowDebtConfirm(true); return; }
    submitTrip();
  };

  const submitTrip = async () => {
    if (!tripForm.vehicle_id || !tripForm.warehouse_id) {
      toast.error('Vehicle and warehouse are required');
      return;
    }
    setSavingTrip(true);
    try {
      const payload = {
        ...tripForm,
        route_id: tripForm.route_id || undefined,
        items: buildItemsPayload(),
        sales: buildSalesPayload(),
      };
      const res = await api.post('/fleet/trips', payload);
      const recon = res.data.data.reconciliation;
      if (recon.matches && recon.stock_matches) {
        toast.success('Trip logged — stock and cash reconcile');
      } else if (!recon.stock_matches) {
        toast.error("Trip logged, but reported sold quantities don't match dispatched minus returned for some products", { duration: 6000 });
      } else {
        toast.error(`Trip logged, but off by KES ${Math.abs(recon.variance).toLocaleString()} — check stock/cash entries`, { duration: 6000 });
      }
      const stockWarnings: { message: string }[] = res.data?.stock_warnings || [];
      stockWarnings.forEach(w => toast.error(w.message, { duration: 8000 }));
      setShowDebtConfirm(false);
      setShowTripForm(false);
      fetchSummary();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to log trip');
      setShowDebtConfirm(false);
    } finally {
      setSavingTrip(false);
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
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Welcome, {user?.first_name}</h1>
          <p className="text-gray-600 dark:text-gray-400">Your trips and attendance</p>
        </div>
        <button onClick={openNewTrip} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
          <Plus className="w-4 h-4 mr-2" /> Log Trip
        </button>
      </div>

      {/* Clock in/out */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div className="flex items-center justify-between flex-wrap gap-4">
          <div className="flex items-center">
            <Clock className="w-8 h-8 text-blue-600 mr-3" />
            <div>
              <p className="text-sm text-gray-500 dark:text-gray-400">Today</p>
              <p className="text-gray-900 dark:text-gray-100 font-medium">
                {summary?.today.clocked_in ? `Clocked in at ${summary.today.clock_in_time}` : 'Not clocked in yet'}
                {summary?.today.clocked_out ? ` · Clocked out at ${summary.today.clock_out_time}` : ''}
              </p>
            </div>
          </div>
          <div className="flex gap-3">
            <button onClick={handleClockIn} disabled={clockLoading || !!summary?.today.clocked_in}
              className="flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">
              <LogIn className="w-4 h-4 mr-2" /> Clock In
            </button>
            <button onClick={handleClockOut} disabled={clockLoading || !summary?.today.clocked_in || !!summary?.today.clocked_out}
              className="flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50">
              <LogOut className="w-4 h-4 mr-2" /> Clock Out
            </button>
          </div>
        </div>
      </div>

      {/* This month */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Trips This Month</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{summary?.this_month.trips ?? 0}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">KM Covered</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{(summary?.this_month.km_covered ?? 0).toLocaleString()}</p>
        </div>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
          <p className="text-sm text-gray-500 dark:text-gray-400">Collected</p>
          <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">KES {(summary?.this_month.total_collected ?? 0).toLocaleString()}</p>
        </div>
      </div>

      {/* My trip history */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700"><h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">My Recent Trips</h3></div>
        <div className="p-6">
          {!summary || summary.recent_trips.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">No trips logged yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Vehicle / Route</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">KM</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Collected</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Reconciliation</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {summary.recent_trips.map(t => (
                    <tr key={t.id}>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{new Date(t.trip_date).toLocaleDateString()}</td>
                      <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
                        <div className="text-gray-900 dark:text-gray-100">{t.vehicle || '—'}</div>
                        <div className="flex items-center"><MapPin className="w-3 h-3 mr-1" />{t.route || '—'}</div>
                      </td>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{t.km_covered ?? '—'}</td>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">KES {t.total_collected.toLocaleString()}</td>
                      <td className="px-4 py-2 text-sm">
                        {t.reconciliation.matches && t.reconciliation.stock_matches ? (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"><CheckCircle className="w-3 h-3 mr-1" />Matches</span>
                        ) : (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"><XCircle className="w-3 h-3 mr-1" />Check</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Trip form */}
      {showTripForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Log Trip</h3>
              <button onClick={() => setShowTripForm(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleTripSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                  <input required type="date" value={tripForm.trip_date} onChange={e => setTripForm({ ...tripForm, trip_date: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Vehicle</label>
                  <select required value={tripForm.vehicle_id} onChange={e => setTripForm({ ...tripForm, vehicle_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="">Select vehicle</option>
                    {vehicles.map(v => <option key={v.id} value={v.id}>{v.reg_no}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Route</label>
                  <select value={tripForm.route_id} onChange={e => setTripForm({ ...tripForm, route_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="">Select route</option>
                    {routes.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Warehouse (loaded from)</label>
                  <select required value={tripForm.warehouse_id} onChange={e => setTripForm({ ...tripForm, warehouse_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="">Select warehouse</option>
                    {warehouses.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Mileage Start</label>
                  <input type="number" value={tripForm.mileage_start} onChange={e => setTripForm({ ...tripForm, mileage_start: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Mileage End</label>
                  <input type="number" value={tripForm.mileage_end} onChange={e => setTripForm({ ...tripForm, mileage_end: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
              </div>

              <div className="border-t border-gray-200 dark:border-gray-700 pt-4">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Stock: Dispatched / Returned / Sold</label>
                {skus.length === 0 ? (
                  <p className="text-sm text-gray-500 dark:text-gray-400">Loading products…</p>
                ) : (
                  <div className="space-y-4">
                    {groupSkusByBrand(skus).map(([brand, brandSkus]) => (
                      <div key={brand}>
                        <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{brand}</h4>
                        <div className="grid grid-cols-12 gap-2 text-xs text-gray-500 dark:text-gray-400 px-1 mb-1">
                          <div className="col-span-4">Size</div>
                          <div className="col-span-2">Dispatched</div>
                          <div className="col-span-2">Returned</div>
                          <div className="col-span-2">Sold</div>
                          <div className="col-span-2">Price</div>
                        </div>
                        <div className="space-y-1">
                          {brandSkus.map(s => {
                            const row = tripGrid[s.id] || EMPTY_GRID_ITEM;
                            return (
                              <div key={s.id} className="grid grid-cols-12 gap-2 items-center">
                                <div className="col-span-4 text-sm text-gray-900 dark:text-gray-100">{s.name.replace(brand === 'Mara Water' ? '' : brand, '').trim()}</div>
                                <input type="number" min="0" value={row.qty_carried} onChange={e => updateGridCell(s.id, 'qty_carried', e.target.value)} className="col-span-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                                <input type="number" min="0" value={row.qty_returned} onChange={e => updateGridCell(s.id, 'qty_returned', e.target.value)} className="col-span-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                                <input type="number" min="0" value={row.qty_sold} onChange={e => updateGridCell(s.id, 'qty_sold', e.target.value)} className="col-span-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                                <input type="number" min="0" step="0.01" value={row.unit_price} onChange={e => updateGridCell(s.id, 'unit_price', e.target.value)} className="col-span-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="border-t border-gray-200 dark:border-gray-700 pt-4">
                <div className="flex items-center justify-between mb-2">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Sales Made This Trip</label>
                  <button type="button" onClick={addSaleRow} className="text-sm text-blue-600 hover:text-blue-800">+ Add sale</button>
                </div>
                <div className="space-y-3">
                  {tripSales.map((sale, i) => (
                    <div key={i} className={`rounded-lg border p-3 ${sale.payment_method === 'debt' && sale.customer_id ? 'bg-amber-50 border-amber-200' : 'border-gray-200 dark:border-gray-700'}`}>
                      <div className="grid grid-cols-12 gap-2 items-center">
                        <select value={sale.customer_id} onChange={e => updateSaleRow(i, 'customer_id', e.target.value)} className="col-span-4 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
                          <option value="">Customer / buyer</option>
                          {customers.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                        <select value={sale.payment_method} onChange={e => updateSaleRow(i, 'payment_method', e.target.value)} className="col-span-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
                          <option value="cash">Cash</option>
                          <option value="mpesa">M-Pesa</option>
                          <option value="debt">Debt</option>
                        </select>
                        <input type="number" min="0" step="0.01" placeholder="Amount (KES)" value={sale.amount} onChange={e => updateSaleRow(i, 'amount', e.target.value)} className="col-span-3 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                        {sale.payment_method === 'mpesa' && (
                          <input type="text" placeholder="M-Pesa reference" value={sale.mpesa_reference} onChange={e => updateSaleRow(i, 'mpesa_reference', e.target.value)} className="col-span-3 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                        )}
                        <button type="button" onClick={() => removeSaleRow(i)} className="col-span-1 text-gray-400 dark:text-gray-500 hover:text-red-600 justify-self-end"><X className="w-4 h-4" /></button>
                      </div>
                      {sale.payment_method === 'debt' && (
                        <div className="grid grid-cols-2 gap-3 mt-2">
                          <div>
                            <label className="block text-xs font-medium text-gray-700">Signatory (who received it)</label>
                            <input required={!!sale.customer_id} type="text" value={sale.debt_signatory} onChange={e => updateSaleRow(i, 'debt_signatory', e.target.value)} className="mt-1 block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm" />
                          </div>
                          <div>
                            <label className="block text-xs font-medium text-gray-700">Repay By (max 7 days)</label>
                            <input required={!!sale.customer_id} type="date" value={sale.debt_expected_repayment_date} min={tripForm.trip_date} max={tripMaxRepaymentDate(tripForm.trip_date)} onChange={e => updateSaleRow(i, 'debt_expected_repayment_date', e.target.value)} className="mt-1 block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm" />
                          </div>
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea value={tripForm.notes} onChange={e => setTripForm({ ...tripForm, notes: e.target.value })} rows={2} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>

              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowTripForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={savingTrip} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingTrip ? 'Logging…' : 'Log Trip'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {showDebtConfirm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">This trip includes {debtSales().length > 1 ? 'credit sales' : 'a credit sale'}</h3>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">Confirm the signatory and repayment date before this goes on the customer's account.</p>
            <div className="space-y-3 mb-4">
              {debtSales().map((sale, i) => (
                <dl key={i} className="space-y-1 text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
                  <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Amount</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {parseFloat(sale.amount || '0').toLocaleString()}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Debtor</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{customers.find(c => c.id === sale.customer_id)?.name || '—'}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Signatory</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{sale.debt_signatory}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Repay By</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{sale.debt_expected_repayment_date}</dd></div>
                </dl>
              ))}
            </div>
            <div className="flex justify-end space-x-3">
              <button type="button" onClick={() => setShowDebtConfirm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Go Back</button>
              <button type="button" onClick={submitTrip} disabled={savingTrip} className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50">{savingTrip ? 'Logging…' : 'Confirm Credit Sale'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default DriverPage;
