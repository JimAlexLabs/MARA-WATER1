import React, { useEffect, useState } from 'react';
import { Plus, CheckCircle, XCircle, MapPin, X, Lock, Download, AlertTriangle } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

// Round 4: split out of the old single-page DriverPage.tsx -- this is
// now its own sidebar-navigable page, "Trips", covering the full staged
// workflow (pending_departure -> in_transit -> completed). See
// DriverTripController for the stage-by-stage endpoints this page calls.

interface WarehouseRef { id: string; code: string; name: string; }
interface SkuRef { id: string; name: string; code: string; brand: string | null; current_price?: number | null; }
interface VehicleRef { id: string; reg_no: string; }
interface CustomerRef { id: string; name: string; code: string; phone?: string | null; type?: string; }
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
interface TripSaleItem { id: string; sku_id: string; sku?: SkuRef; qty_bales: string; unit_price: string; line_total: string; }
type SalePaymentMethod = 'cash' | 'mpesa' | 'debt' | 'pay_direct';
interface TripSale {
  id: string; customer_id: string; customer?: CustomerRef; payment_method: SalePaymentMethod;
  amount: string; mpesa_reference: string | null; debt_signatory: string | null;
  debt_expected_repayment_date: string | null; items?: TripSaleItem[];
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
const EMPTY_SALE_FORM = {
  customer_id: '', customer_name: '', payment_method: 'cash' as SalePaymentMethod,
  mpesa_reference: '', debt_signatory: '', debt_expected_repayment_date: '',
  physical_receipt_no: '', physical_delivery_note_no: '',
};
type SaleLineItem = { sku_id: string; qty_bales: string; unit_price: string };
const CUSTOMER_TYPE_OPTIONS = [
  { value: 'retail', label: 'Retail' },
  { value: 'wholesale', label: 'Distributor' },
  { value: 'corporate', label: 'Institution' },
  { value: 'walk_in', label: 'Walk-in' },
];
const EMPTY_NEW_CUSTOMER = { name: '', phone: '', type: 'retail' };

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

  // --- Stage 4: Log a Sale ---
  const [showSaleForm, setShowSaleForm] = useState(false);
  const [saleForm, setSaleForm] = useState(EMPTY_SALE_FORM);
  const [saleLineItems, setSaleLineItems] = useState<SaleLineItem[]>([]);
  const [showDebtConfirm, setShowDebtConfirm] = useState(false);
  const [showPaymentConfirm, setShowPaymentConfirm] = useState(false);
  const [savingSale, setSavingSale] = useState(false);

  // Round 3 Phase 3: customer search-as-you-type.
  const [customerQuery, setCustomerQuery] = useState('');
  const [customerResults, setCustomerResults] = useState<CustomerRef[]>([]);
  const [searchingCustomers, setSearchingCustomers] = useState(false);
  const [showNewCustomerForm, setShowNewCustomerForm] = useState(false);
  const [newCustomerForm, setNewCustomerForm] = useState(EMPTY_NEW_CUSTOMER);
  const [savingCustomer, setSavingCustomer] = useState(false);

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

  // ============ Stage 4: Log a Sale ============
  const openSaleForm = () => {
    setSaleForm(EMPTY_SALE_FORM);
    setSaleLineItems([]);
    setCustomerQuery(''); setCustomerResults([]); setShowNewCustomerForm(false);
    setShowSaleForm(true);
  };
  const addSaleLineItem = () => setSaleLineItems([...saleLineItems, { sku_id: '', qty_bales: '', unit_price: '' }]);
  const removeSaleLineItem = (i: number) => setSaleLineItems(saleLineItems.filter((_, idx) => idx !== i));
  const updateSaleLineItem = (i: number, field: keyof SaleLineItem, value: string) => {
    setSaleLineItems(saleLineItems.map((row, idx) => idx === i ? { ...row, [field]: value } : row));
  };

  // Round 3 Phase 3: "typing a name/phone searches existing customers
  // first" -- debounced, minimum 2 characters (matches the backend's own
  // validation), so a driver isn't stuck browsing a company-wide list.
  useEffect(() => {
    if (customerQuery.trim().length < 2 || saleForm.customer_id) { setCustomerResults([]); return; }
    setSearchingCustomers(true);
    const handle = setTimeout(() => {
      api.get('/sales/customers/search', { params: { query: customerQuery.trim() } })
        .then(res => setCustomerResults(res.data.data.customers))
        .catch(() => setCustomerResults([]))
        .finally(() => setSearchingCustomers(false));
    }, 350);
    return () => clearTimeout(handle);
  }, [customerQuery, saleForm.customer_id]);

  const selectCustomer = (c: CustomerRef) => {
    setSaleForm({ ...saleForm, customer_id: c.id, customer_name: c.name });
    setCustomerQuery(c.name);
    setCustomerResults([]);
  };
  const clearSelectedCustomer = () => {
    setSaleForm({ ...saleForm, customer_id: '', customer_name: '' });
    setCustomerQuery('');
  };

  const submitNewCustomer = async () => {
    if (!newCustomerForm.name.trim() || !newCustomerForm.phone.trim()) { toast.error('Name and phone are required'); return; }
    setSavingCustomer(true);
    try {
      const res = await api.post('/sales/customers', newCustomerForm);
      const created = res.data.data.customer;
      toast.success('Customer added');
      selectCustomer(created);
      setShowNewCustomerForm(false);
      setNewCustomerForm(EMPTY_NEW_CUSTOMER);
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to add customer');
    } finally {
      setSavingCustomer(false);
    }
  };

  // Round 4 Phase 0/4: the total is never typed in -- it's always the
  // computed sum of the line items.
  const validSaleLineItems = saleLineItems.filter(l => l.sku_id && parseFloat(l.qty_bales) > 0 && parseFloat(l.unit_price) >= 0);
  const saleTotal = validSaleLineItems.reduce((sum, l) => sum + (parseFloat(l.qty_bales) || 0) * (parseFloat(l.unit_price) || 0), 0);

  // How many bales of a given sku remain available on this trip --
  // dispatched minus already-logged sales minus what's entered in the
  // OTHER lines of this not-yet-saved form (so a driver can't double-
  // count the same item across two lines either).
  const availableForSku = (skuId: string, excludeLineIndex: number): number => {
    if (!activeTrip || !skuId) return 0;
    const dispatched = activeTrip.items.find(it => it.sku_id === skuId)?.qty_carried_bales ?? 0;
    const alreadySold = activeTrip.sales.reduce((sum, s) => sum + (s.items || []).filter(i => i.sku_id === skuId).reduce((s2, i) => s2 + parseFloat(i.qty_bales), 0), 0);
    const inOtherLines = saleLineItems.reduce((sum, l, idx) => idx === excludeLineIndex ? sum : sum + (l.sku_id === skuId ? (parseFloat(l.qty_bales) || 0) : 0), 0);
    return Math.max(0, dispatched - alreadySold - inOtherLines);
  };

  const handleSaleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!saleForm.customer_id) { toast.error('Select a customer'); return; }
    if (validSaleLineItems.length === 0) { toast.error('Add at least one line item (brand/size, bales, price)'); return; }
    for (const l of validSaleLineItems) {
      const idx = saleLineItems.indexOf(l);
      const available = availableForSku(l.sku_id, idx);
      if (parseFloat(l.qty_bales) > available) {
        const sku = skus.find(s => s.id === l.sku_id);
        toast.error(`Only ${available} bales of ${sku?.name ?? 'that item'} remain available on this trip`);
        return;
      }
    }
    if (saleForm.payment_method === 'debt') {
      if (!saleForm.debt_signatory.trim() || !saleForm.debt_expected_repayment_date) {
        toast.error('Enter who signed for this credit sale and an expected repayment date');
        return;
      }
      setShowDebtConfirm(true);
      return;
    }
    // Round 4 Phase 4: "a short prompt payment step for Cash/M-Pesa that
    // just confirms the amount received before the sale is saved."
    setShowPaymentConfirm(true);
  };

  const submitSale = async () => {
    if (!activeTrip) return;
    setSavingSale(true);
    try {
      const items = validSaleLineItems.map(l => ({
        sku_id: l.sku_id, qty_bales: parseFloat(l.qty_bales) || 0, unit_price: parseFloat(l.unit_price) || 0,
      }));
      await api.post(`/fleet/trips/${activeTrip.id}/sales`, {
        customer_id: saleForm.customer_id, payment_method: saleForm.payment_method,
        mpesa_reference: (saleForm.payment_method === 'mpesa' || saleForm.payment_method === 'pay_direct') ? (saleForm.mpesa_reference || undefined) : undefined,
        debt_signatory: saleForm.payment_method === 'debt' ? saleForm.debt_signatory : undefined,
        debt_expected_repayment_date: saleForm.payment_method === 'debt' ? saleForm.debt_expected_repayment_date : undefined,
        physical_receipt_no: saleForm.physical_receipt_no || undefined,
        physical_delivery_note_no: saleForm.physical_delivery_note_no || undefined,
        items,
      });
      toast.success('Sale recorded');
      setShowDebtConfirm(false);
      setShowPaymentConfirm(false);
      setShowSaleForm(false);
      const r = await api.get(`/fleet/trips/${activeTrip.id}`);
      setActiveTrip(r.data.data);
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to record sale');
      setShowDebtConfirm(false);
      setShowPaymentConfirm(false);
    } finally {
      setSavingSale(false);
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
                  <button onClick={openSaleForm} className="flex items-center text-sm px-3 py-1.5 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                    <Plus className="w-4 h-4 mr-1" /> Log a Sale
                  </button>
                </div>
                {activeTrip.sales.length > 0 && (
                  <div className="space-y-1">
                    {activeTrip.sales.map(s => (
                      <div key={s.id} className="flex justify-between text-sm py-1 border-b border-gray-100 dark:border-gray-700">
                        <span className="text-gray-700 dark:text-gray-300">{s.customer?.name || 'Customer'} · {s.payment_method}</span>
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

      {/* Log a Sale form (Stage 4) */}
      {showSaleForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[55] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Log a Sale</h3>
              <button onClick={() => setShowSaleForm(false)}><X className="w-5 h-5 text-gray-400" /></button>
            </div>
            <form onSubmit={handleSaleSubmit} className="space-y-4">
              <div className="relative">
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Customer</label>
                {saleForm.customer_id ? (
                  <div className="mt-1 flex items-center justify-between border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-3 py-2">
                    <span className="text-gray-900 dark:text-gray-100">{saleForm.customer_name}</span>
                    <button type="button" onClick={clearSelectedCustomer} className="text-xs text-blue-600 hover:text-blue-800">Change</button>
                  </div>
                ) : (
                  <>
                    <input type="text" placeholder="Type a name or phone number…" value={customerQuery}
                      onChange={e => setCustomerQuery(e.target.value)}
                      className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                    {customerQuery.trim().length >= 2 && (
                      <div className="absolute z-10 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-48 overflow-y-auto">
                        {searchingCustomers ? (
                          <p className="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">Searching…</p>
                        ) : customerResults.length > 0 ? (
                          customerResults.map(c => (
                            <button type="button" key={c.id} onClick={() => selectCustomer(c)} className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700 last:border-0">
                              <div className="text-gray-900 dark:text-gray-100">{c.name}</div>
                              <div className="text-xs text-gray-500 dark:text-gray-400">{c.phone || 'no phone'}</div>
                            </button>
                          ))
                        ) : (
                          <p className="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">No matches</p>
                        )}
                        <button type="button" onClick={() => { setNewCustomerForm({ ...EMPTY_NEW_CUSTOMER, name: customerQuery }); setShowNewCustomerForm(true); }}
                          className="w-full text-left px-3 py-2 text-sm text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20 font-medium">
                          + Add new customer{customerQuery ? ` "${customerQuery}"` : ''}
                        </button>
                      </div>
                    )}
                  </>
                )}
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Method</label>
                <select value={saleForm.payment_method} onChange={e => setSaleForm({ ...saleForm, payment_method: e.target.value as any })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                  <option value="cash">Cash</option>
                  <option value="mpesa">M-Pesa</option>
                  <option value="pay_direct">Pay-directly (QR)</option>
                  <option value="debt">Debt</option>
                </select>
              </div>
              {(saleForm.payment_method === 'mpesa' || saleForm.payment_method === 'pay_direct') && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">{saleForm.payment_method === 'mpesa' ? 'M-Pesa Reference' : 'Payment Reference / QR Transaction ID'}</label>
                  <input type="text" value={saleForm.mpesa_reference} onChange={e => setSaleForm({ ...saleForm, mpesa_reference: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
              )}
              {saleForm.payment_method === 'debt' && (
                <div className="grid grid-cols-2 gap-4 bg-amber-50 dark:bg-amber-900/20 p-3 rounded-md">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Signed For By</label>
                    <input required type="text" value={saleForm.debt_signatory} onChange={e => setSaleForm({ ...saleForm, debt_signatory: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Expected Repayment (max 7 days)</label>
                    <input required type="date" value={saleForm.debt_expected_repayment_date} onChange={e => setSaleForm({ ...saleForm, debt_expected_repayment_date: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                  </div>
                </div>
              )}

              <div>
                <div className="flex items-center justify-between mb-1">
                  {/* Round 4 Phase 0/4: line items are mandatory, not
                      optional -- this is the only place a number is
                      manually typed; the sale total below is always the
                      computed sum. */}
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Line Items (brand/size, bales, price -- required)</label>
                  <button type="button" onClick={addSaleLineItem} className="text-xs text-blue-600 hover:text-blue-800">+ Add line</button>
                </div>
                {saleLineItems.length === 0 && (
                  <p className="text-xs text-amber-600 dark:text-amber-400 mb-1">Add at least one line item -- the sale total is computed from these.</p>
                )}
                {saleLineItems.map((line, i) => {
                  const available = line.sku_id ? availableForSku(line.sku_id, i) : null;
                  const over = available !== null && parseFloat(line.qty_bales || '0') > available;
                  return (
                    <div key={i} className="mb-1">
                      <div className="grid grid-cols-12 gap-2 items-center">
                        <select value={line.sku_id} onChange={e => updateSaleLineItem(i, 'sku_id', e.target.value)} className="col-span-6 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
                          <option value="">Item…</option>
                          {(activeTrip?.items || []).filter(it => it.qty_carried_bales > 0).map(it => (
                            <option key={it.sku_id} value={it.sku_id}>{it.sku?.name}</option>
                          ))}
                        </select>
                        <input type="number" min="0" step="0.01" placeholder="Bales" value={line.qty_bales} onChange={e => updateSaleLineItem(i, 'qty_bales', e.target.value)} className={`col-span-2 border rounded-md px-2 py-1.5 text-sm dark:bg-gray-700 dark:text-gray-100 ${over ? 'border-red-400' : 'border-gray-300 dark:border-gray-600'}`} />
                        <input type="number" min="0" step="0.01" placeholder="Price" value={line.unit_price} onChange={e => updateSaleLineItem(i, 'unit_price', e.target.value)} className="col-span-3 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                        <button type="button" onClick={() => removeSaleLineItem(i)} className="col-span-1 text-red-600 hover:text-red-800"><X className="w-4 h-4" /></button>
                      </div>
                      {line.sku_id && (
                        <p className={`text-xs mt-0.5 ${over ? 'text-red-600' : 'text-gray-400 dark:text-gray-500'}`}>{available} bales available on this trip</p>
                      )}
                    </div>
                  );
                })}
                {validSaleLineItems.length > 0 && (
                  <div className="flex justify-between items-center mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                    <span className="text-sm text-gray-500 dark:text-gray-400">Sale Total (computed)</span>
                    <span className="text-lg font-bold text-gray-900 dark:text-gray-100">KES {saleTotal.toLocaleString()}</span>
                  </div>
                )}
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Physical Receipt No. (optional)</label>
                  <input type="text" value={saleForm.physical_receipt_no} onChange={e => setSaleForm({ ...saleForm, physical_receipt_no: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" placeholder="Paper receipt book no." />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Physical Delivery Note No. (optional)</label>
                  <input type="text" value={saleForm.physical_delivery_note_no} onChange={e => setSaleForm({ ...saleForm, physical_delivery_note_no: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
              </div>

              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowSaleForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={savingSale} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingSale ? 'Saving…' : 'Record Sale'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Quick "add new customer" -- name+phone required, matching the
          search fallback ("with add new customer as a fallback, to avoid
          duplicate customer records"). Code is auto-generated server-side. */}
      {showNewCustomerForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[65] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-sm">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Add New Customer</h3>
              <button type="button" onClick={() => setShowNewCustomerForm(false)}><X className="w-5 h-5 text-gray-400" /></button>
            </div>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                <input required type="text" value={newCustomerForm.name} onChange={e => setNewCustomerForm({ ...newCustomerForm, name: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                <input required type="text" value={newCustomerForm.phone} onChange={e => setNewCustomerForm({ ...newCustomerForm, phone: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Type</label>
                <select value={newCustomerForm.type} onChange={e => setNewCustomerForm({ ...newCustomerForm, type: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                  {CUSTOMER_TYPE_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
              </div>
              <div className="flex justify-end space-x-3 pt-2">
                <button type="button" onClick={() => setShowNewCustomerForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="button" onClick={submitNewCustomer} disabled={savingCustomer} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingCustomer ? 'Saving…' : 'Add Customer'}</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {showDebtConfirm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">This is a credit sale</h3>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">Confirm the signatory and repayment date before this goes on the customer's account.</p>
            <dl className="space-y-1 text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-3 mb-4">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Amount</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {saleTotal.toLocaleString()}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Debtor</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.customer_name || '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Signatory</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.debt_signatory}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Repay By</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.debt_expected_repayment_date}</dd></div>
            </dl>
            <div className="flex justify-end space-x-3">
              <button type="button" onClick={() => setShowDebtConfirm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Go Back</button>
              <button type="button" onClick={submitSale} disabled={savingSale} className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50">{savingSale ? 'Saving…' : 'Confirm Credit Sale'}</button>
            </div>
          </div>
        </div>
      )}

      {/* Round 4 Phase 4: "a short prompt payment step for Cash/M-Pesa
          that just confirms the amount received before the sale is
          saved." */}
      {showPaymentConfirm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Confirm payment received</h3>
            <dl className="space-y-1 text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-3 mb-4">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Amount</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {saleTotal.toLocaleString()}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Customer</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.customer_name || '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Payment Method</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.payment_method === 'mpesa' ? 'M-Pesa' : saleForm.payment_method === 'pay_direct' ? 'Pay-directly (QR)' : 'Cash'}</dd></div>
            </dl>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">Confirm you have received KES {saleTotal.toLocaleString()} before this sale is saved.</p>
            <div className="flex justify-end space-x-3">
              <button type="button" onClick={() => setShowPaymentConfirm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Go Back</button>
              <button type="button" onClick={submitSale} disabled={savingSale} className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50">{savingSale ? 'Saving…' : 'Confirm & Save Sale'}</button>
            </div>
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
