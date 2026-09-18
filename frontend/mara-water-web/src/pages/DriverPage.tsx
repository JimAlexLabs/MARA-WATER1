import React, { useCallback, useEffect, useState } from 'react';
import { Clock, LogIn, LogOut, Plus, CheckCircle, XCircle, MapPin, X, Send, Paperclip, Lock, Download, AlertTriangle } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

// Round 2 Phase 11 / Round 3 Phase 2: a driver's own dashboard/login --
// "Log Driver Trip, view their own trip history, check-in/check-out. No
// visibility into other drivers, finance, or HR." Round 3 rebuilds the
// trip-logging section as a staged workflow (pending_departure ->
// in_transit -> completed) instead of the old single-shot form -- see
// DriverTripController for the stage-by-stage endpoints this page calls.

interface WarehouseRef { id: string; code: string; name: string; }
interface SkuRef { id: string; name: string; code: string; brand: string | null; current_price?: number | null; }
interface VehicleRef { id: string; reg_no: string; }
interface CustomerRef { id: string; name: string; code: string; phone?: string | null; type?: string; }
interface OfficerRef { id: string; full_name: string; }

interface RecentTrip {
  id: string; trip_date: string; vehicle: string | null; route: string | null;
  status: 'pending_departure' | 'in_transit' | 'completed'; has_discrepancy: boolean;
  km_covered: number | null; total_collected: number;
  reconciliation: { matches: boolean; stock_matches: boolean };
}
interface Summary {
  today: { clocked_in: boolean; clocked_out: boolean; clock_in_time: string | null; clock_out_time: string | null };
  this_month: { trips: number; km_covered: number; total_collected: number };
  recent_trips: RecentTrip[];
}

interface TripItem {
  id: string; sku_id: string; sku?: SkuRef;
  qty_carried: number; qty_carried_bales: number;
  qty_returned: number; qty_returned_bales: number; qty_sold: number; unit_price: string;
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

// Round 3 Phase 4: driver/salesperson's own analytics.
interface DriverAnalytics {
  sales: { this_week: { kes: number; bales: number }; this_month: { kes: number; bales: number } };
  new_customers: { count: number };
  qty_by_brand_size: {
    current_trip: { sku_id: string; name: string; brand: string | null; qty_carried: number }[];
    cumulative: { sku_id: string; name: string; brand: string | null; qty_sold: string }[];
  };
  debt: { outstanding: number; overdue: number; overdue_count: number };
}
interface DriverSaleRow {
  id: string; trip_date: string | null; customer: { id: string; name: string } | null;
  payment_method: SalePaymentMethod; amount: number; physical_receipt_no: string | null;
  debt: { balance: number; expected_repayment_date: string | null; signatory: string | null; days_overdue: number } | null;
}

const BRAND_ORDER = ['Premium', 'Platinum', 'Grace', 'Refill'];
const brandLabel = (brand: string | null) => brand || 'Mara Water';
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

const EMPTY_NEW_TRIP = { trip_date: new Date().toISOString().slice(0, 10), vehicle_id: '', route: '', warehouse_id: '', mileage_start: '', authorizing_officer_id: '' };
type DispatchGridItem = { qty_carried: string; qty_carried_bales: string; unit_price: string };
type ReturnGridItem = { qty_returned: string; qty_returned_bales: string };
const EMPTY_SALE_FORM = {
  customer_id: '', customer_name: '', payment_method: 'cash' as SalePaymentMethod, amount: '',
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

// Round 3 Phase 5: in-app issue reporting.
interface IssueMessageRow { id: string; sender_id: string; sender?: { full_name?: string }; body: string; photo_path: string | null; created_at: string; }
interface IssueRow {
  id: string; subject: string; status: 'open' | 'acknowledged' | 'resolved';
  messages?: IssueMessageRow[]; messages_count?: number;
  latest_message?: IssueMessageRow | null; created_at: string;
}
const ISSUE_STATUS_STYLE: Record<string, string> = {
  open: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
  acknowledged: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
  resolved: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
};

const DriverPage: React.FC = () => {
  const { user } = useAuth();
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(true);
  const [clockLoading, setClockLoading] = useState(false);

  const [vehicles, setVehicles] = useState<VehicleRef[]>([]);
  const [warehouses, setWarehouses] = useState<WarehouseRef[]>([]);
  const [skus, setSkus] = useState<SkuRef[]>([]);
  const [officers, setOfficers] = useState<OfficerRef[]>([]);
  const [recentRoutes, setRecentRoutes] = useState<string[]>([]);

  const [activeTrip, setActiveTrip] = useState<ActiveTrip | null>(null);
  const [activeTripLoading, setActiveTripLoading] = useState(true);

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
  const [savingSale, setSavingSale] = useState(false);

  // Round 3 Phase 3: customer search-as-you-type ("typing a name/phone
  // searches existing customers first, with add new customer as a
  // fallback") -- also fixes a real pre-existing bug where this form's
  // customer picker called a Manager/Director-only endpoint and silently
  // showed an empty list for every driver.
  const [customerQuery, setCustomerQuery] = useState('');
  const [customerResults, setCustomerResults] = useState<CustomerRef[]>([]);
  const [searchingCustomers, setSearchingCustomers] = useState(false);
  const [showNewCustomerForm, setShowNewCustomerForm] = useState(false);
  const [newCustomerForm, setNewCustomerForm] = useState(EMPTY_NEW_CUSTOMER);
  const [savingCustomer, setSavingCustomer] = useState(false);

  // --- Stage 5: End Trip ---
  const [showEndTripForm, setShowEndTripForm] = useState(false);
  const [endTripForm, setEndTripForm] = useState({ mileage_end: '', fuel_liters: '', fuel_cost: '' });
  const [returnGrid, setReturnGrid] = useState<Record<string, ReturnGridItem>>({});
  const [ending, setEnding] = useState(false);

  // Round 3 Phase 5: in-app issue reporting.
  const [issues, setIssues] = useState<IssueRow[]>([]);
  const [showIssueForm, setShowIssueForm] = useState(false);
  const [issueSubject, setIssueSubject] = useState('');
  const [issueMessage, setIssueMessage] = useState('');
  const [issuePhoto, setIssuePhoto] = useState<File | null>(null);
  const [submittingIssue, setSubmittingIssue] = useState(false);
  const [openIssueId, setOpenIssueId] = useState<string | null>(null);
  const [issueThread, setIssueThread] = useState<IssueRow | null>(null);
  const [replyText, setReplyText] = useState('');
  const [sendingReply, setSendingReply] = useState(false);

  const fetchIssues = useCallback(() => {
    api.get('/issues').then(res => setIssues(res.data.data)).catch(() => {});
  }, []);
  useEffect(() => { fetchIssues(); }, [fetchIssues]);

  const uploadIssuePhoto = async (): Promise<string | null> => {
    if (!issuePhoto) return null;
    const form = new FormData();
    form.append('file', issuePhoto);
    form.append('type', 'image');
    form.append('entity_type', 'issue');
    form.append('entity_id', 'pending');
    const res = await api.post('/files/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } });
    return res.data?.data?.url ?? null;
  };

  const submitIssue = async () => {
    if (!issueSubject.trim() || !issueMessage.trim()) { toast.error('Subject and message are required'); return; }
    setSubmittingIssue(true);
    try {
      const photo_path = await uploadIssuePhoto().catch(() => null);
      await api.post('/issues', { subject: issueSubject, message: issueMessage, photo_path });
      toast.success('Issue reported -- your Manager/Director has been notified');
      setShowIssueForm(false);
      setIssueSubject(''); setIssueMessage(''); setIssuePhoto(null);
      fetchIssues();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to report issue');
    } finally {
      setSubmittingIssue(false);
    }
  };

  const openThread = (id: string) => {
    setOpenIssueId(id);
    api.get(`/issues/${id}`).then(res => setIssueThread(res.data.data.issue)).catch(() => toast.error('Failed to load issue'));
  };

  const submitReply = async () => {
    if (!openIssueId || !replyText.trim()) return;
    setSendingReply(true);
    try {
      await api.post(`/issues/${openIssueId}/reply`, { body: replyText });
      setReplyText('');
      openThread(openIssueId);
      fetchIssues();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to send reply');
    } finally {
      setSendingReply(false);
    }
  };

  const fetchSummary = useCallback(() => {
    api.get('/driver/summary').then(res => setSummary(res.data.data)).catch(() => toast.error('Failed to load your summary')).finally(() => setLoading(false));
  }, []);
  useEffect(() => { fetchSummary(); }, [fetchSummary]);

  // Round 3 Phase 4: a driver/salesperson's own analytics -- separate
  // fetch from summary() above (different data, different refresh cost),
  // refetched whenever the active trip changes too (e.g. after End Trip,
  // "cumulative" and "this week/month" should reflect the closed trip).
  const [analytics, setAnalytics] = useState<DriverAnalytics | null>(null);
  const fetchAnalytics = useCallback(() => {
    api.get('/driver/analytics').then(res => setAnalytics(res.data.data)).catch(() => {});
  }, []);
  useEffect(() => { fetchAnalytics(); }, [fetchAnalytics, activeTrip?.status]);

  // Round 3 Phase 12: "My Sales" (Invoices) / "My Debtors" -- one
  // dataset, toggled, both scoped to only this driver's own sales.
  // Petty Cash and Costing & P&L are deliberately not built here at all
  // (spec default for a driver-tier user).
  const [mySales, setMySales] = useState<DriverSaleRow[]>([]);
  const [mySalesFilter, setMySalesFilter] = useState<'all' | 'debts'>('all');
  const fetchMySales = useCallback((filter: 'all' | 'debts') => {
    api.get('/driver/sales', { params: filter === 'debts' ? { debt_only: 1 } : {} })
      .then(res => setMySales(res.data.data)).catch(() => {});
  }, []);
  useEffect(() => { fetchMySales(mySalesFilter); }, [fetchMySales, mySalesFilter, activeTrip?.status]);

  // Round 3 Phase 2: is there a trip already in progress (not yet
  // completed)? If so, that's what the page focuses on -- only one trip
  // is worked on at a time.
  const fetchActiveTrip = useCallback(() => {
    setActiveTripLoading(true);
    api.get('/fleet/trips', { params: { limit: 10 } }).then(res => {
      const open = (res.data.data as { id: string; status: string }[]).find(t => t.status !== 'completed');
      if (!open) { setActiveTrip(null); setActiveTripLoading(false); return; }
      api.get(`/fleet/trips/${open.id}`).then(r => setActiveTrip(r.data.data)).finally(() => setActiveTripLoading(false));
    }).catch(() => setActiveTripLoading(false));
  }, []);
  useEffect(() => { fetchActiveTrip(); }, [fetchActiveTrip]);

  const fetchTripRefData = () => {
    // Driver-tier accessible reference lists only -- /fleet/vehicles-list
    // is a plain id/reg_no picker, not /fleet/vehicles (Manager/Director-
    // only vehicle management).
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/fleet/vehicles-list').then(res => setVehicles(res.data.data)).catch(() => {});
    api.get('/fleet/warehouses').then(res => setWarehouses(res.data.data)).catch(() => setWarehouses([]));
    api.get('/fleet/authorizing-officers').then(res => setOfficers(res.data.data)).catch(() => setOfficers([]));
    api.get('/fleet/trips/recent-routes').then(res => setRecentRoutes(res.data.data)).catch(() => setRecentRoutes([]));
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
      skus.forEach(s => { grid[s.id] = { qty_carried: '', qty_carried_bales: '', unit_price: s.current_price != null ? String(s.current_price) : '' }; });
      return grid;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [skus, showNewTripForm]);

  const updateDispatchCell = (skuId: string, field: keyof DispatchGridItem, value: string) => {
    setDispatchGrid(prev => ({ ...prev, [skuId]: { ...(prev[skuId] || { qty_carried: '', qty_carried_bales: '', unit_price: '' }), [field]: value } }));
  };

  const submitNewTrip = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTripForm.vehicle_id || !newTripForm.warehouse_id || !newTripForm.route.trim() || !newTripForm.authorizing_officer_id || !newTripForm.mileage_start) {
      toast.error('Vehicle, route, warehouse, mileage start, and authorizing officer are all required');
      return;
    }
    const items = Object.entries(dispatchGrid)
      .filter(([, r]) => (parseInt(r.qty_carried, 10) || 0) > 0 || (parseInt(r.qty_carried_bales, 10) || 0) > 0)
      .map(([sku_id, r]) => ({
        sku_id, qty_carried: parseInt(r.qty_carried, 10) || 0, qty_carried_bales: parseInt(r.qty_carried_bales, 10) || 0,
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

  const handleSaleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!saleForm.customer_id || !(parseFloat(saleForm.amount) > 0)) { toast.error('Customer and amount are required'); return; }
    if (saleForm.payment_method === 'debt') {
      if (!saleForm.debt_signatory.trim() || !saleForm.debt_expected_repayment_date) {
        toast.error('Enter who signed for this credit sale and an expected repayment date');
        return;
      }
      setShowDebtConfirm(true);
      return;
    }
    submitSale();
  };

  const submitSale = async () => {
    if (!activeTrip) return;
    setSavingSale(true);
    try {
      const items = saleLineItems.filter(l => l.sku_id && parseFloat(l.qty_bales) > 0).map(l => ({
        sku_id: l.sku_id, qty_bales: parseFloat(l.qty_bales) || 0, unit_price: parseFloat(l.unit_price) || 0,
      }));
      await api.post(`/fleet/trips/${activeTrip.id}/sales`, {
        customer_id: saleForm.customer_id, payment_method: saleForm.payment_method, amount: parseFloat(saleForm.amount) || 0,
        mpesa_reference: (saleForm.payment_method === 'mpesa' || saleForm.payment_method === 'pay_direct') ? (saleForm.mpesa_reference || undefined) : undefined,
        debt_signatory: saleForm.payment_method === 'debt' ? saleForm.debt_signatory : undefined,
        debt_expected_repayment_date: saleForm.payment_method === 'debt' ? saleForm.debt_expected_repayment_date : undefined,
        physical_receipt_no: saleForm.physical_receipt_no || undefined,
        physical_delivery_note_no: saleForm.physical_delivery_note_no || undefined,
        items: items.length > 0 ? items : undefined,
      });
      toast.success('Sale recorded');
      setShowDebtConfirm(false);
      setShowSaleForm(false);
      const r = await api.get(`/fleet/trips/${activeTrip.id}`);
      setActiveTrip(r.data.data);
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to record sale');
      setShowDebtConfirm(false);
    } finally {
      setSavingSale(false);
    }
  };

  // ============ Stage 5: End Trip ============
  const openEndTrip = () => {
    if (!activeTrip) return;
    setEndTripForm({ mileage_end: '', fuel_liters: '', fuel_cost: '' });
    const grid: Record<string, ReturnGridItem> = {};
    activeTrip.items.forEach(it => { grid[it.sku_id] = { qty_returned: '', qty_returned_bales: '' }; });
    setReturnGrid(grid);
    setShowEndTripForm(true);
  };

  const submitEndTrip = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeTrip || !endTripForm.mileage_end) { toast.error('Mileage end is required'); return; }
    const items = activeTrip.items.map(it => ({
      sku_id: it.sku_id,
      qty_returned: parseInt(returnGrid[it.sku_id]?.qty_returned || '0', 10) || 0,
      qty_returned_bales: parseInt(returnGrid[it.sku_id]?.qty_returned_bales || '0', 10) || 0,
    }));
    setEnding(true);
    try {
      const res = await api.post(`/fleet/trips/${activeTrip.id}/end`, {
        mileage_end: parseInt(endTripForm.mileage_end, 10),
        fuel_liters: endTripForm.fuel_liters || undefined,
        fuel_cost: endTripForm.fuel_cost || undefined,
        items,
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
      fetchSummary();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to end trip');
    } finally {
      setEnding(false);
    }
  };

  const downloadSheet = (kind: 'dispatch' | 'return') => {
    if (!activeTrip) return;
    api.get(`/fleet/trips/${activeTrip.id}/${kind}-sheet`, { responseType: 'blob' }).then((res) => {
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

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  const salesTotal = (activeTrip?.sales || []).reduce((sum, s) => sum + parseFloat(s.amount), 0);

  return (
    <div className="space-y-6">
      <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Welcome, {user?.first_name}</h1>
          <p className="text-gray-600 dark:text-gray-400">Your trips and attendance</p>
        </div>
        {!activeTripLoading && !activeTrip && (
          <button onClick={openNewTrip} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <Plus className="w-4 h-4 mr-2" /> New Trip
          </button>
        )}
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

      {/* Round 3 Phase 4: driver's own analytics -- read-only, scoped
          only to their own records (enforced server-side, same rule as
          everywhere else on this page). */}
      {analytics && (
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">My Analytics</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Sales This Week</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">KES {analytics.sales.this_week.kes.toLocaleString()}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">{analytics.sales.this_week.bales} bales net dispatched</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Sales This Month</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">KES {analytics.sales.this_month.kes.toLocaleString()}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">{analytics.sales.this_month.bales} bales net dispatched</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">New Customers Registered</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{analytics.new_customers.count}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">all time</p>
            </div>
            <div className="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
              <p className="text-sm text-gray-500 dark:text-gray-400">Debt Sales Outstanding</p>
              <p className="text-xl font-bold text-gray-900 dark:text-gray-100">KES {analytics.debt.outstanding.toLocaleString()}</p>
              {analytics.debt.overdue_count > 0 && (
                <p className="text-xs text-red-600 flex items-center"><AlertTriangle className="w-3 h-3 mr-1" />{analytics.debt.overdue_count} overdue · KES {analytics.debt.overdue.toLocaleString()}</p>
              )}
            </div>
          </div>

          {analytics.qty_by_brand_size.current_trip.length > 0 && (
            <div className="mb-4">
              <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">This Trip -- Dispatched by Item</h4>
              <div className="flex flex-wrap gap-2">
                {analytics.qty_by_brand_size.current_trip.map(i => (
                  <span key={i.sku_id} className="text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 rounded-full px-3 py-1">
                    {i.brand ? `${i.brand} ` : ''}{i.name}: {i.qty_carried}
                  </span>
                ))}
              </div>
            </div>
          )}

          {analytics.qty_by_brand_size.cumulative.length > 0 && (
            <div>
              <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Cumulative Quantity Sold, by Item</h4>
              <div className="flex flex-wrap gap-2">
                {analytics.qty_by_brand_size.cumulative.map(i => (
                  <span key={i.sku_id} className="text-xs bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 rounded-full px-3 py-1">
                    {i.brand ? `${i.brand} ` : ''}{i.name}: {i.qty_sold}
                  </span>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

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
                <button onClick={() => downloadSheet('dispatch')} className="flex items-center text-xs px-3 py-1.5 rounded-md border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                  <Download className="w-3.5 h-3.5 mr-1" /> Dispatch Sheet
                </button>
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
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Dispatched</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Dispatched (bales)</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Price</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                {activeTrip.items.map(it => (
                  <tr key={it.id}>
                    <td className="px-3 py-2 text-gray-900 dark:text-gray-100">{brandLabel(it.sku?.brand ?? null)} {it.sku?.name}</td>
                    <td className="px-3 py-2 text-gray-900 dark:text-gray-100">{it.qty_carried}</td>
                    <td className="px-3 py-2 text-gray-900 dark:text-gray-100">{it.qty_carried_bales}</td>
                    <td className="px-3 py-2 text-gray-900 dark:text-gray-100">KES {parseFloat(it.unit_price).toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {activeTrip.status === 'pending_departure' && (
            <div className="flex justify-end">
              <button onClick={startTrip} disabled={starting} className="flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">
                <Lock className="w-4 h-4 mr-2" /> {starting ? 'Starting…' : 'Start Trip'}
              </button>
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
          {!summary || summary.recent_trips.length === 0 ? (
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
                  {summary.recent_trips.map(t => (
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

      {/* Round 3 Phase 12: "Invoices"/Debtors Ledger, scoped to only this
          driver's own sales -- there's no formal Invoice document for a
          trip sale (Round 2 Phase 7: informal shop credit, no invoice
          generated), so this is genuinely "my sales", labeled as such
          rather than implying documents that don't exist. Petty Cash and
          Costing & P&L are deliberately not added anywhere on this page. */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">My Sales{mySalesFilter === 'debts' ? ' -- Debtors Ledger' : ''}</h3>
          <div className="flex bg-gray-100 dark:bg-gray-700 rounded-lg p-1 text-sm">
            <button onClick={() => setMySalesFilter('all')} className={`px-3 py-1 rounded-md ${mySalesFilter === 'all' ? 'bg-white dark:bg-gray-800 shadow text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400'}`}>All Sales</button>
            <button onClick={() => setMySalesFilter('debts')} className={`px-3 py-1 rounded-md ${mySalesFilter === 'debts' ? 'bg-white dark:bg-gray-800 shadow text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-400'}`}>My Debtors</button>
          </div>
        </div>
        <div className="p-6">
          {mySales.length === 0 ? (
            <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-8">{mySalesFilter === 'debts' ? 'No credit sales on your account.' : 'No sales logged yet.'}</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Customer</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Payment</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Amount</th>
                    {mySalesFilter === 'debts' && <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Debt Status</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                  {mySales.map(s => (
                    <tr key={s.id}>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{s.trip_date ? new Date(s.trip_date).toLocaleDateString() : '—'}</td>
                      <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{s.customer?.name || '—'}</td>
                      <td className="px-4 py-2 text-sm text-gray-500 dark:text-gray-400 capitalize">{s.payment_method.replace('_', ' ')}</td>
                      <td className="px-4 py-2 text-sm font-medium text-gray-900 dark:text-gray-100">KES {s.amount.toLocaleString()}</td>
                      {mySalesFilter === 'debts' && (
                        <td className="px-4 py-2 text-sm">
                          {s.debt ? (
                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${s.debt.days_overdue > 0 ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'}`}>
                              KES {s.debt.balance.toLocaleString()}{s.debt.days_overdue > 0 ? ` · ${s.debt.days_overdue}d overdue` : ' outstanding'}
                            </span>
                          ) : '—'}
                        </td>
                      )}
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
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Bottles</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Bales</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Unit Price</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {groupSkusByBrand(skus).map(([brand, brandSkus]) => (
                        <React.Fragment key={brand}>
                          <tr><td colSpan={4} className="px-3 py-1 bg-gray-50 dark:bg-gray-900 text-xs font-semibold text-gray-500 dark:text-gray-400">{brand}</td></tr>
                          {brandSkus.map(s => (
                            <tr key={s.id}>
                              <td className="px-3 py-1.5 text-gray-900 dark:text-gray-100">{s.name}</td>
                              <td className="px-3 py-1.5"><input type="number" min="0" value={dispatchGrid[s.id]?.qty_carried ?? ''} onChange={e => updateDispatchCell(s.id, 'qty_carried', e.target.value)} className="w-20 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1" /></td>
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
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Method</label>
                  <select value={saleForm.payment_method} onChange={e => setSaleForm({ ...saleForm, payment_method: e.target.value as any })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                    <option value="cash">Cash</option>
                    <option value="mpesa">M-Pesa</option>
                    <option value="pay_direct">Pay-directly (QR)</option>
                    <option value="debt">Debt</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount (KES)</label>
                  <input required type="number" min="0.01" step="0.01" value={saleForm.amount} onChange={e => setSaleForm({ ...saleForm, amount: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
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
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Line Items (optional, by brand/size)</label>
                  <button type="button" onClick={addSaleLineItem} className="text-xs text-blue-600 hover:text-blue-800">+ Add line</button>
                </div>
                {saleLineItems.map((line, i) => (
                  <div key={i} className="grid grid-cols-12 gap-2 mb-1 items-center">
                    <select value={line.sku_id} onChange={e => updateSaleLineItem(i, 'sku_id', e.target.value)} className="col-span-6 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm">
                      <option value="">Item…</option>
                      {skus.map(s => <option key={s.id} value={s.id}>{brandLabel(s.brand)} {s.name}</option>)}
                    </select>
                    <input type="number" min="0" step="0.01" placeholder="Bales" value={line.qty_bales} onChange={e => updateSaleLineItem(i, 'qty_bales', e.target.value)} className="col-span-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                    <input type="number" min="0" step="0.01" placeholder="Price" value={line.unit_price} onChange={e => updateSaleLineItem(i, 'unit_price', e.target.value)} className="col-span-3 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm" />
                    <button type="button" onClick={() => removeSaleLineItem(i)} className="col-span-1 text-red-600 hover:text-red-800"><X className="w-4 h-4" /></button>
                  </div>
                ))}
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
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Amount</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {parseFloat(saleForm.amount || '0').toLocaleString()}</dd></div>
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

      {/* End Trip form (Stage 5) */}
      {showEndTripForm && activeTrip && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">End Trip -- Return & Close</h3>
              <button onClick={() => setShowEndTripForm(false)}><X className="w-5 h-5 text-gray-400" /></button>
            </div>
            <form onSubmit={submitEndTrip} className="space-y-4">
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Mileage End</label>
                  <input required type="number" min={activeTrip.mileage_start ?? 0} value={endTripForm.mileage_end} onChange={e => setEndTripForm({ ...endTripForm, mileage_end: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Fuel (Litres)</label>
                  <input type="number" min="0" step="0.01" value={endTripForm.fuel_liters} onChange={e => setEndTripForm({ ...endTripForm, fuel_liters: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Fuel Cost</label>
                  <input type="number" min="0" step="0.01" value={endTripForm.fuel_cost} onChange={e => setEndTripForm({ ...endTripForm, fuel_cost: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
                </div>
              </div>

              <div>
                <h4 className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Returned Quantities</h4>
                <div className="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-md">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead className="bg-gray-50 dark:bg-gray-900">
                      <tr>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Item</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Dispatched</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Returned</th>
                        <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Returned (bales)</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                      {activeTrip.items.map(it => (
                        <tr key={it.id}>
                          <td className="px-3 py-1.5 text-gray-900 dark:text-gray-100">{brandLabel(it.sku?.brand ?? null)} {it.sku?.name}</td>
                          <td className="px-3 py-1.5 text-gray-900 dark:text-gray-100">{it.qty_carried}</td>
                          <td className="px-3 py-1.5">
                            <input type="number" min="0" max={it.qty_carried} value={returnGrid[it.sku_id]?.qty_returned ?? ''}
                              onChange={e => setReturnGrid(prev => ({ ...prev, [it.sku_id]: { ...(prev[it.sku_id] || { qty_returned: '', qty_returned_bales: '' }), qty_returned: e.target.value } }))}
                              className="w-20 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1" />
                          </td>
                          <td className="px-3 py-1.5">
                            <input type="number" min="0" value={returnGrid[it.sku_id]?.qty_returned_bales ?? ''}
                              onChange={e => setReturnGrid(prev => ({ ...prev, [it.sku_id]: { ...(prev[it.sku_id] || { qty_returned: '', qty_returned_bales: '' }), qty_returned_bales: e.target.value } }))}
                              className="w-20 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1" />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Sold = Dispatched − Returned. If money collected doesn't match, the trip still closes but gets flagged for Manager/Director as a discrepancy.</p>
              </div>

              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowEndTripForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={ending} className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50">{ending ? 'Closing…' : 'Close Trip'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Round 3 Phase 5: in-app issue reporting -- a lightweight ticket
          thread, not real-time chat. Visible only here on the Driver's
          own dashboard; Manager/Director see the same issues in their
          "Issues" nav item. */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div className="flex justify-between items-center mb-4">
          <div>
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Report an Issue</h3>
            <p className="text-sm text-gray-500 dark:text-gray-400">Something wrong with a vehicle, a delivery, stock, or anything else -- your Manager/Director will see it and can reply here.</p>
          </div>
          <button onClick={() => setShowIssueForm(true)} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            <Plus className="h-4 w-4 mr-1" /> New Issue
          </button>
        </div>

        {issues.length === 0 ? (
          <p className="text-sm text-gray-500 dark:text-gray-400">No issues reported yet.</p>
        ) : (
          <div className="space-y-2">
            {issues.map(issue => (
              <button key={issue.id} onClick={() => openThread(issue.id)} className="w-full text-left flex items-center justify-between p-3 rounded-md border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{issue.subject}</p>
                  <p className="text-xs text-gray-500 dark:text-gray-400 truncate">{issue.latest_message?.body ?? ''}</p>
                </div>
                <span className={`ml-3 shrink-0 inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${ISSUE_STATUS_STYLE[issue.status]}`}>{issue.status}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      {showIssueForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Report an Issue</h3>
              <button onClick={() => setShowIssueForm(false)}><X className="h-5 w-5 text-gray-400" /></button>
            </div>
            <div className="space-y-3">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Subject</label>
                <input type="text" value={issueSubject} onChange={e => setIssueSubject(e.target.value)} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" placeholder="e.g. Vehicle brake issue" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Message</label>
                <textarea value={issueMessage} onChange={e => setIssueMessage(e.target.value)} rows={4} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" placeholder="Describe what's wrong..." />
              </div>
              <div>
                <label className="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                  <Paperclip className="h-4 w-4 mr-1" /> {issuePhoto ? issuePhoto.name : 'Attach a photo (optional)'}
                  <input type="file" accept="image/*" className="hidden" onChange={e => setIssuePhoto(e.target.files?.[0] ?? null)} />
                </label>
              </div>
              <div className="flex justify-end space-x-3 pt-2">
                <button type="button" onClick={() => setShowIssueForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="button" onClick={submitIssue} disabled={submittingIssue} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{submittingIssue ? 'Sending…' : 'Send'}</button>
              </div>
            </div>
          </div>
        </div>
      )}

      {openIssueId && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[80vh] flex flex-col">
            <div className="flex justify-between items-center mb-4">
              <div>
                <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">{issueThread?.subject ?? 'Loading…'}</h3>
                {issueThread && <span className={`inline-flex mt-1 px-2 py-0.5 rounded-full text-xs font-medium ${ISSUE_STATUS_STYLE[issueThread.status]}`}>{issueThread.status}</span>}
              </div>
              <button onClick={() => { setOpenIssueId(null); setIssueThread(null); }}><X className="h-5 w-5 text-gray-400" /></button>
            </div>
            <div className="flex-1 overflow-y-auto space-y-3 mb-4">
              {(issueThread?.messages ?? []).map(m => (
                <div key={m.id} className={`p-3 rounded-lg text-sm ${m.sender_id === user?.id ? 'bg-blue-50 dark:bg-blue-900/30 ml-6' : 'bg-gray-100 dark:bg-gray-700 mr-6'}`}>
                  <p className="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{m.sender?.full_name ?? '—'} · {new Date(m.created_at).toLocaleString()}</p>
                  <p className="text-gray-900 dark:text-gray-100">{m.body}</p>
                  {m.photo_path && <img src={m.photo_path} alt="attachment" className="mt-2 rounded-md max-h-48" />}
                </div>
              ))}
            </div>
            <div className="flex space-x-2">
              <input type="text" value={replyText} onChange={e => setReplyText(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') submitReply(); }} placeholder="Reply..." className="flex-1 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              <button onClick={submitReply} disabled={sendingReply || !replyText.trim()} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"><Send className="h-4 w-4" /></button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default DriverPage;
