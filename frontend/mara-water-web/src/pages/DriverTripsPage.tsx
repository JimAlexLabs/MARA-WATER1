import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Plus, CheckCircle, XCircle, MapPin, X, Lock, Download, AlertTriangle, Camera } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

// Round 4: split out of the old single-page DriverPage.tsx -- this is
// now its own sidebar-navigable page, "Trips", covering the full staged
// workflow (pending_departure -> in_transit -> completed). See
// DriverTripController for the stage-by-stage endpoints this page calls.
// Round 4 Phase 0/4 follow-up: "Log a Sale" is its own separate page
// (DriverLogSalePage, /driver/trips/log-sale) now, not a modal here --
// this page just links to it while a trip is in_transit.

interface WarehouseRef { id: string; code: string; name: string; }
interface SkuRef { id: string; name: string; code: string; brand: string | null; current_price?: number | null; }
interface VehicleRef { id: string; reg_no: string; }
interface OfficerRef { id: string; full_name: string; }
interface LocationRef { id: string; code: string; name: string; }

interface RecentTrip {
  id: string; trip_date: string; vehicle: string | null; route: string | null;
  status: 'pending_departure' | 'in_transit' | 'completed'; has_discrepancy: boolean;
  km_covered: number | null; total_collected: number;
  reconciliation: { matches: boolean; stock_matches: boolean };
}

interface TripItem {
  // Round 4 Phase 1: bales are the sole dispatched/returned/sold
  // quantity from Production onward -- there is no bottle count here
  // anymore.
  id: string; sku_id: string; sku?: SkuRef;
  qty_carried_bales: number; qty_returned_bales: number; qty_sold: number; unit_price: string;
}
interface TripSaleItem { id: string; sku_id: string; qty_bales: string; }
interface TripSale {
  id: string; customer_id: string; customer?: { name: string }; payment_method: string;
  amount: string; items?: TripSaleItem[]; photo_url?: string | null;
}
interface ActiveTrip {
  id: string; trip_date: string; status: 'pending_departure' | 'in_transit' | 'completed';
  route: string | null; vehicle?: VehicleRef; warehouse?: WarehouseRef; authorizingOfficer?: OfficerRef;
  mileage_start: number | null; mileage_end: number | null; has_discrepancy: boolean;
  items: TripItem[]; sales: TripSale[];
  total_collected: number; reconciliation: { expected_revenue: number; collected: number; variance: number; matches: boolean; stock_matches: boolean };
}

const BRAND_ORDER = ['Premium', 'Platinum', 'Grace', 'Refill'];
const brandLabel = (brand: string | null) => brand || 'Uncategorized';
const groupSkusByBrand = (skus: SkuRef[]) => {
  const groups: Record<string, SkuRef[]> = {};
  skus.forEach(s => { const b = brandLabel(s.brand); (groups[b] = groups[b] || []).push(s); });
  return Object.entries(groups).sort(([a], [b]) => {
    const ia = BRAND_ORDER.indexOf(a), ib = BRAND_ORDER.indexOf(b);
    if (ia !== -1 || ib !== -1) return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
    return a.localeCompare(b);
  });
};

const STATUS_BADGE: Record<string, { label: string; cls: string }> = {
  pending_departure: { label: 'Pending Departure', cls: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200' },
  in_transit: { label: 'In Transit', cls: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' },
  completed: { label: 'Completed', cls: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
};

const EMPTY_NEW_TRIP = { trip_date: new Date().toISOString().slice(0, 10), vehicle_id: '', route: '', warehouse_id: '', location_id: '', mileage_start: '', authorizing_officer_id: '' };
// Round 4 Phase 1: bales only -- no bottle-count column anywhere in the
// trip dispatch/returned/sold tables or the sale line items.
type DispatchGridItem = { qty_carried_bales: string; unit_price: string };

const DriverTripsPage: React.FC = () => {
  const [vehicles, setVehicles] = useState<VehicleRef[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseRef[]>([]);
  const [skus, setSkus] = useState<SkuRef[]>([]);
  const [officers, setOfficers] = useState<OfficerRef[]>([]);
  const [locations, setLocations] = useState<LocationRef[]>([]);
  const [recentRoutes, setRecentRoutes] = useState<string[]>([]);

  const [activeTrip, setActiveTrip] = useState<ActiveTrip | null>(null);
  const [activeTripLoading, setActiveTripLoading] = useState(true);
  const [recentTrips, setRecentTrips] = useState<RecentTrip[]>([]);

  // --- Stage 1+2: New Trip ---
  const [showNewTripForm, setShowNewTripForm] = useState(false);
  const [newTripForm, setNewTripForm] = useState(EMPTY_NEW_TRIP);
  const [dispatchGrid, setDispatchGrid] = useState<Record<string, DispatchGridItem>>({});
  const [savingTrip, setSavingTrip] = useState(false);
  const [starting, setStarting] = useState(false);

  // --- Stage 5: End Trip ---
  const [showEndTripForm, setShowEndTripForm] = useState(false);
  const [endTripForm, setEndTripForm] = useState({ mileage_end: '' });
  const [reconciliationConfirmed, setReconciliationConfirmed] = useState(false);
  const [ending, setEnding] = useState(false);

  const fetchRecentTrips = () => {
    api.get('/driver/summary').then(res => setRecentTrips(res.data.data.recent_trips)).catch(() => {});
  };
  useEffect(() => { fetchRecentTrips(); }, []);

  // Round 3 Phase 2: is there a trip already in progress (not yet
  // completed)? If so, that's what this page focuses on -- only one
  // trip is worked on at a time.
  const fetchActiveTrip = () => {
    setActiveTripLoading(true);
    api.get('/fleet/trips', { params: { limit: 10 } }).then(res => {
      const open = (res.data.data as { id: string; status: string }[]).find(t => t.status !== 'completed');
      if (!open) { setActiveTrip(null); setActiveTripLoading(false); return; }
      api.get(`/fleet/trips/${open.id}`).then(r => setActiveTrip(r.data.data)).finally(() => setActiveTripLoading(false));
    }).catch(() => setActiveTripLoading(false));
  };
  useEffect(() => { fetchActiveTrip(); }, []);

  const fetchTripRefData = () => {
    // Driver-tier accessible reference lists only -- /fleet/vehicles-list
    // is a plain id/reg_no picker, not /fleet/vehicles (Manager/Director-
    // only vehicle management).
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/fleet/vehicles-list').then(res => setVehicles(res.data.data)).catch(() => {});
    api.get('/fleet/warehouses').then(res => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
    api.get('/fleet/authorizing-officers').then(res => setOfficers(res.data.data)).catch(() => setOfficers([]));
    api.get('/fleet/locations').then(res => setLocations(res.data.data)).catch(() => setLocations([]));
    api.get('/fleet/trips/recent-routes').then(res => setRecentRoutes(res.data.data)).catch(() => setRecentRoutes([]));
  };

  // ============ Stage 1+2: New Trip ============
  const openNewTrip = () => {
    fetchTripRefData();
    setNewTripForm({ ...EMPTY_NEW_TRIP, authorizing_officer_id: '' });
    setDispatchGrid({});
    setShowNewTripForm(true);
  };

  useEffect(() => {
    if (!showNewTripForm || skus.length === 0) return;
    setDispatchGrid(prev => {
      if (Object.keys(prev).length > 0) return prev;
      const grid: Record<string, DispatchGridItem> = {};
      skus.forEach(s => { grid[s.id] = { qty_carried_bales: '', unit_price: s.current_price != null ? String(s.current_price) : '' }; });
      return grid;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [skus, showNewTripForm]);

  const updateDispatchCell = (skuId: string, field: keyof DispatchGridItem, value: string) => {
    setDispatchGrid(prev => ({ ...prev, [skuId]: { ...(prev[skuId] || { qty_carried_bales: '', unit_price: '' }), [field]: value } }));
  };

  const submitNewTrip = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTripForm.vehicle_id || !newTripForm.warehouse_id || !newTripForm.location_id || !newTripForm.route.trim() || !newTripForm.authorizing_officer_id || !newTripForm.mileage_start) {
      toast.error('Vehicle, route, warehouse, location, mileage start, and authorizing officer are all required');
      return;
    }
    const items = Object.entries(dispatchGrid)
      .filter(([, r]) => (parseInt(r.qty_carried_bales, 10) || 0) > 0)
      .map(([sku_id, r]) => ({
        sku_id, qty_carried_bales: parseInt(r.qty_carried_bales, 10) || 0,
        unit_price: parseFloat(r.unit_price) || 0,
      }));
    if (items.length === 0) { toast.error('Add at least one dispatched item'); return; }

    setSavingTrip(true);
    try {
      const res = await api.post('/fleet/trips', { ...newTripForm, items });
      toast.success('Trip created -- pending departure. Start it once loaded.');
      setShowNewTripForm(false);
      setActiveTrip(res.data.data);
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to create trip');
    } finally {
      setSavingTrip(false);
    }
  };

  // ============ Stage 3: Start Trip ============
  const startTrip = async () => {
    if (!activeTrip) return;
    setStarting(true);
    try {
      const res = await api.post(`/fleet/trips/${activeTrip.id}/start`);
      toast.success('Trip started -- dispatch is now locked');
      const warnings: { message: string }[] = res.data?.stock_warnings || [];
      warnings.forEach(w => toast.error(w.message, { duration: 8000 }));
      setActiveTrip(res.data.data);
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to start trip');
    } finally {
      setStarting(false);
    }
  };

  // ============ Stage 5: End Trip ============
  const openEndTrip = () => {
    if (!activeTrip) return;
    setEndTripForm({ mileage_end: '' });
    setReconciliationConfirmed(false);
    setShowEndTripForm(true);
  };

  // Round 4 Phase 2: a client-side preview of what Returned will be --
  // the real computation happens server-side at submit time (this is
  // display only, non-editable, matching the trip's own live Sold tally
  // from the Sales entity).
  const soldBalesForSku = (skuId: string): number =>
    (activeTrip?.sales || []).reduce((sum, s) => sum + (s.items || []).filter(i => i.sku_id === skuId).reduce((s2, i) => s2 + parseFloat(i.qty_bales), 0), 0);

  const submitEndTrip = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeTrip || !endTripForm.mileage_end) { toast.error('Mileage end is required'); return; }
    if (parseInt(endTripForm.mileage_end, 10) < (activeTrip.mileage_start ?? 0)) { toast.error('Mileage End cannot be less than Mileage Start'); return; }
    if (!reconciliationConfirmed) { toast.error('Confirm you have reconciled all paper sales into the app before ending the trip'); return; }
    setEnding(true);
    try {
      const res = await api.post(`/fleet/trips/${activeTrip.id}/end`, {
        mileage_end: parseInt(endTripForm.mileage_end, 10),
        reconciliation_confirmed: true,
      });
      const recon = res.data.data.reconciliation;
      if (res.data.data.has_discrepancy) {
        toast.error(`Trip closed with a discrepancy -- off by KES ${Math.abs(recon.variance).toLocaleString()}. This has been flagged for Manager/Director.`, { duration: 8000 });
      } else {
        toast.success('Trip closed -- stock and cash reconcile');
      }
      const warnings: { message: string }[] = res.data?.stock_warnings || [];
      warnings.forEach(w => toast.error(w.message, { duration: 8000 }));
      setShowEndTripForm(false);
      setActiveTrip(null);
      fetchRecentTrips();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to end trip');
    } finally {
      setEnding(false);
    }
  };

  const downloadSheet = (kind: 'dispatch' | 'return' | 'sales') => {
    if (!activeTrip) return;
    const endpoint = kind === 'sales' ? 'sales-sheet' : `${kind}-sheet`;
    api.get(`/fleet/trips/${activeTrip.id}/${endpoint}`, { responseType: 'blob' }).then((res) => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = `${kind}-sheet-${activeTrip.trip_date}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    }).catch(() => toast.error(`Failed to download ${kind} sheet`));
  };

  const salesTotal = (activeTrip?.sales || []).reduce((sum, s) => sum + parseFloat(s.amount), 0);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Trips</h1>
          <p className="text-gray-600 dark:text-gray-400">Dispatch, sales, and closing out your trip</p>
        </div>
        {!activeTripLoading && !activeTrip && (
          <button onClick={openNewTrip} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <Plus className="w-4 h-4 mr-2" /> New Trip
          </button>
        )}
      </div>

      {/* Round 3 Phase 2: the staged active-trip workflow */}
      {activeTrip && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6 border-2 border-blue-200 dark:border-blue-900">
          <div className="flex items-center justify-between flex-wrap gap-2 mb-4">
            <div className="flex items-center gap-3">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Active Trip</h3>
              <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${STATUS_BADGE[activeTrip.status].cls}`}>
                {activeTrip.status === 'in_transit' && <Lock className="w-3 h-3 mr-1" />}
                {STATUS_BADGE[activeTrip.status].label}
              </span>
            </div>
            <div className="flex gap-2">
              {activeTrip.status !== 'pending_departure' && (
                <>
                  <button onClick={() => downloadSheet('dispatch')} className="flex items-center text-xs px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                    <Download className="w-3.5 h-3.5 mr-1" /> Dispatch Sheet
                  </button>
                  {activeTrip.sales.length > 0 && (
                    <button onClick={() => downloadSheet('sales')} className="flex items-center text-xs px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                      <Download className="w-3.5 h-3.5 mr-1" /> Sales
                    </button>
                  )}
                </>
              )}
            </div>
          </div>

          <dl className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm mb-4">
            <div><dt className="text-gray-500 dark:text-gray-400">Vehicle</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{activeTrip.vehicle?.reg_no || '—'}</dd></div>
            <div><dt className="text-gray-500 dark:text-gray-400">Route</dt><dd className="font-medium text-gray-900 dark:text-gray-100 flex items-center"><MapPin className="w-3 h-3 mr-1" />{activeTrip.route || '—'}</dd></div>
            <div><dt className="text-gray-500 dark:text-gray-400">Warehouse</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{activeTrip.warehouse?.name || '—'}</dd></div>
            <div><dt className="text-gray-500 dark:text-gray-400">Authorizing Officer</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{activeTrip.authorizingOfficer?.full_name || '—'}</dd></div>
            <div><dt className="text-gray-500 dark:text-gray-400">Mileage Start</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{activeTrip.mileage_start ?? '—'}</dd></div>
          </dl>

          <div className="overflow-x-auto mb-4">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
              <thead>
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Item</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Dispatched (bales)</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Price</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                {activeTrip.items.filter(it => it.qty_carried_bales > 0).map(it => (
                  <tr key={it.id}>
                    <td className="px-3 py-2 text-gray-900 dark:text-gray-100">{it.sku?.name}</td>
                    <td className="px-3 py-2 text-gray-900 dark:text-gray-100">{it.qty_carried_bales}</td>
                    <td className="px-3 py-2 text-gray-900 dark:text-gray-100">KES {parseFloat(it.unit_price).toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {activeTrip.status === 'pending_departure' && (
            <div className="flex flex-col items-end">
              {/* Round 4 Phase 3: disabled (not just validated on
                  submit) while Authorizing Officer is empty. */}
              <button onClick={startTrip} disabled={starting || !activeTrip.authorizingOfficer} className="flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed">
                <Lock className="w-4 h-4 mr-2" /> {starting ? 'Starting…' : 'Start Trip'}
              </button>
              {!activeTrip.authorizingOfficer && (
                <p className="text-xs text-red-600 mt-1">Required before you can start the trip</p>
              )}
            </div>
          )}

          {activeTrip.status === 'in_transit' && (
            <>
              <div className="border-t border-gray-200 dark:border-gray-700 pt-4 mb-4">
                <div className="flex items-center justify-between mb-2">
                  <h4 className="font-medium text-gray-900 dark:text-gray-100">Sales so far: {activeTrip.sales.length} · KES {salesTotal.toLocaleString()}</h4>
                  {/* Round 4 Phase 0/4: "New standalone Log a Sale
                      entity, connected to but separate from the trip
                      page" -- a real page, not a modal. */}
                  <Link to="/driver/trips/log-sale" className="flex items-center text-sm px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <Plus className="w-4 h-4 mr-1" /> Log a Sale
                  </Link>
                </div>
                {activeTrip.sales.length > 0 && (
                  <div className="space-y-1">
                    {activeTrip.sales.map(s => (
                      <div key={s.id} className="flex justify-between items-center text-sm py-1 border-b border-gray-100 dark:border-gray-700">
                        <span className="text-gray-700 dark:text-gray-300 flex items-center gap-2">
                          {s.customer?.name || 'Customer'} · {s.payment_method}
                          {/* Round 5A Phase 3: viewable, not just stored. */}
                          {s.photo_url && (
                            <a href={s.photo_url} target="_blank" rel="noopener noreferrer" title="View attached photo" className="text-gray-400 hover:text-blue-600">
                              <Camera className="w-3.5 h-3.5" />
                            </a>
                          )}
                        </span>
                        <span className="font-medium text-gray-900 dark:text-gray-100">KES {parseFloat(s.amount).toLocaleString()}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
              <div className="flex justify-end">
                <button onClick={openEndTrip} className="flex items-center px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700">
                  End Trip
                </button>
              </div>
            </>
          )}
        </div>
      )}

      {/* My trip history */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700"><h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">My Recent Trips</h3></div>
        <div className="p-6">
          {recentTrips.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">No trips logged yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Vehicle / Route</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">KM</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Collected</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Reconciliation</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {recentTrips.map(t => (
                    <tr key={t.id}>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{new Date(t.trip_date).toLocaleDateString()}</td>
                      <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
                        <div className="text-gray-900 dark:text-gray-100">{t.vehicle || '—'}</div>
                        <div className="flex items-center"><MapPin className="w-3 h-3 mr-1" />{t.route || '—'}</div>
                      </td>
                      <td className="px-4 py-2 text-sm">
                        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_BADGE[t.status].cls}`}>{STATUS_BADGE[t.status].label}</span>
                        {t.has_discrepancy && (
                          <span className="ml-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
                            <AlertTriangle className="w-3 h-3 mr-1" />Discrepancy
                          </span>
                        )}
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

      {/* New Trip form (Stage 1+2) */}
      {showNewTripForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">New Trip -- Pre-departure</h3>
              <button onClick={() => setShowNewTripForm(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={submitNewTrip} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                  <input required type="date" value={newTripForm.trip_date} onChange={e => setNewTripForm({ ...newTripForm, trip_date: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Vehicle</label>
                  <select required value={newTripForm.vehicle_id} onChange={e => setNewTripForm({ ...newTripForm, vehicle_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="">Select vehicle</option>
                    {vehicles.map(v => <option key={v.id} value={v.id}>{v.reg_no}</option>)}
                  </select>
                </div>
              </div>
              {/* Round 4 Phase 10: Route is its own clearly labeled
                  section -- free-text, not buried inside another field
                  group. */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Route</label>
                  <input required type="text" list="recent-routes-list" placeholder="e.g. Kilimani -> Lavington loop"
                    value={newTripForm.route} onChange={e => setNewTripForm({ ...newTripForm, route: e.target.value })}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                  <datalist id="recent-routes-list">
                    {recentRoutes.map(r => <option key={r} value={r} />)}
                  </datalist>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Warehouse (loaded from)</label>
                  <select required value={newTripForm.warehouse_id} onChange={e => setNewTripForm({ ...newTripForm, warehouse_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="">Select warehouse</option>
                    {warehouses.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Location (KDN/KDQ/Warehouse)</label>
                  <select required value={newTripForm.location_id} onChange={e => setNewTripForm({ ...newTripForm, location_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="">Select location</option>
                    {locations.map(l => <option key={l.id} value={l.id}>{l.name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Mileage Start</label>
                  <input required type="number" value={newTripForm.mileage_start} onChange={e => setNewTripForm({ ...newTripForm, mileage_start: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Authorizing Officer</label>
                  <select required value={newTripForm.authorizing_officer_id} onChange={e => setNewTripForm({ ...newTripForm, authorizing_officer_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="">Select officer</option>
                    {officers.map(o => <option key={o.id} value={o.id}>{o.full_name}</option>)}
                  </select>
                </div>
              </div>

              <div>
                <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dispatched Quantities</h4>
                <div className="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-md">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-900">
                      <tr>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Item</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Bales</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Unit Price</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {groupSkusByBrand(skus).map(([brand, brandSkus]) => (
                        <React.Fragment key={brand}>
                          <tr><td colSpan={3} className="px-3 py-1 bg-gray-50 dark:bg-gray-900 text-xs font-semibold text-gray-500 dark:text-gray-400">{brand}</td></tr>
                          {brandSkus.map(s => (
                            <tr key={s.id}>
                              <td className="px-3 py-1.5 text-gray-900 dark:text-gray-100">{s.name}</td>
                              <td className="px-3 py-1.5"><input type="number" min="0" value={dispatchGrid[s.id]?.qty_carried_bales ?? ''} onChange={e => updateDispatchCell(s.id, 'qty_carried_bales', e.target.value)} className="w-20 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1" /></td>
                              <td className="px-3 py-1.5"><input type="number" min="0" step="0.01" value={dispatchGrid[s.id]?.unit_price ?? ''} onChange={e => updateDispatchCell(s.id, 'unit_price', e.target.value)} className="w-24 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1" /></td>
                            </tr>
                          ))}
                        </React.Fragment>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowNewTripForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={savingTrip} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingTrip ? 'Saving…' : 'Save Trip'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* End Trip form (Stage 5) */}
      {showEndTripForm && activeTrip && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">End Trip -- Return & Close</h3>
              <button onClick={() => setShowEndTripForm(false)}><X className="w-5 h-5 text-gray-400" /></button>
            </div>
            <form onSubmit={submitEndTrip} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Mileage End</label>
                <input required type="number" min={activeTrip.mileage_start ?? 0} value={endTripForm.mileage_end} onChange={e => setEndTripForm({ ...endTripForm, mileage_end: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                {activeTrip.mileage_start != null && parseInt(endTripForm.mileage_end || '0', 10) > 0 && parseInt(endTripForm.mileage_end, 10) < activeTrip.mileage_start && (
                  <p className="text-xs text-red-600 mt-1">Mileage End cannot be less than Mileage Start ({activeTrip.mileage_start}).</p>
                )}
              </div>

              <div>
                {/* Round 4 Phase 2: Dispatched is the real locked-in
                    number from Start Trip; Sold is the live tally from
                    the Sales entity; Returned = Dispatched − Sold,
                    computed here for preview and again authoritatively
                    server-side on submit -- none of these three are
                    manually typed. Only items with quantity > 0 show. */}
                <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Dispatched / Sold / Returned (bales)</h4>
                <div className="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-md">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-900">
                      <tr>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Item</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Dispatched</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Sold</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Returned (computed)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {activeTrip.items.filter(it => it.qty_carried_bales > 0).map(it => {
                        const sold = soldBalesForSku(it.sku_id);
                        const returned = Math.max(0, it.qty_carried_bales - sold);
                        return (
                          <tr key={it.id}>
                            <td className="px-3 py-1.5 text-gray-900 dark:text-gray-100">{it.sku?.name}</td>
                            <td className="px-3 py-1.5 text-gray-900 dark:text-gray-100">{it.qty_carried_bales}</td>
                            <td className="px-3 py-1.5 text-gray-900 dark:text-gray-100">{sold}</td>
                            <td className="px-3 py-1.5 text-gray-900 dark:text-gray-100 font-medium">{returned}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Returned is computed, never typed in. If money collected doesn't match, the trip still closes but gets flagged for Manager/Director as a discrepancy -- never shown here on your dashboard.</p>
              </div>

              {/* Round 4 Phase 5: reconciliation gate. */}
              <div className="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-md p-3">
                <p className="text-sm text-amber-900 dark:text-amber-200 font-medium mb-2">Before ending this trip, reconcile any sales you recorded on paper into the app. Confirm your app totals match your paperwork.</p>
                <label className="flex items-start text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                  <input type="checkbox" checked={reconciliationConfirmed} onChange={e => setReconciliationConfirmed(e.target.checked)} className="mt-0.5 mr-2" />
                  I have reconciled all manual/paper sales into the app and they match my paperwork.
                </label>
              </div>

              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowEndTripForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={ending || !reconciliationConfirmed} className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50" title={!reconciliationConfirmed ? 'Confirm reconciliation above first' : undefined}>{ending ? 'Closing…' : 'Close Trip'}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default DriverTripsPage;
