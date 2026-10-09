import React, { useEffect, useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { X, Camera } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import MpesaStkDialog, { StkPayment } from '../components/MpesaStkDialog';

// Round 4 Phase 0/4: "New standalone Log a Sale entity, connected to
// but separate from the trip page" -- a real page (its own route), not
// a modal on top of Trips. Reachable from the Trips page's "Log a Sale"
// button while a trip is in_transit; if there's no active trip in that
// state, this page says so and points back rather than rendering a
// form with nothing to attach the sale to.

interface SkuRef { id: string; name: string; code: string; brand: string | null; }
interface CustomerRef { id: string; name: string; code: string; phone?: string | null; type?: string; }
type SalePaymentMethod = 'cash' | 'mpesa';
interface TripSaleItem { id: string; sku_id: string; qty_bales: string; }
interface TripSale { id: string; items?: TripSaleItem[]; }
interface TripItem { id: string; sku_id: string; sku?: SkuRef; qty_carried_bales: number; }
interface ActiveTrip {
  id: string; status: 'pending_departure' | 'in_transit' | 'completed';
  items: TripItem[]; sales: TripSale[];
}

const EMPTY_SALE_FORM = {
  customer_id: '', customer_name: '', payment_method: 'mpesa' as SalePaymentMethod,
  mpesa_phone: '',
  physical_receipt_no: '', physical_delivery_note_no: '',
};
type SaleLineItem = { sku_id: string; qty_bales: string; unit_price: string };
const BLANK_LINE: SaleLineItem = { sku_id: '', qty_bales: '', unit_price: '' };
const CUSTOMER_TYPE_OPTIONS = [
  { value: 'retail', label: 'Retail' },
  { value: 'wholesale', label: 'Distributor' },
  { value: 'corporate', label: 'Institution' },
  { value: 'walk_in', label: 'Walk-in' },
];
const EMPTY_NEW_CUSTOMER = { name: '', phone: '', type: 'retail' };

const DriverLogSalePage: React.FC = () => {
  const navigate = useNavigate();
  const [activeTrip, setActiveTrip] = useState<ActiveTrip | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    api.get('/fleet/trips', { params: { limit: 10 } }).then(res => {
      const open = (res.data.data as { id: string; status: string }[]).find(t => t.status !== 'completed');
      if (!open) { setActiveTrip(null); setLoading(false); return; }
      api.get(`/fleet/trips/${open.id}`).then(r => setActiveTrip(r.data.data)).finally(() => setLoading(false));
    }).catch(() => setLoading(false));
  }, []);

  // Round 5A Phase 5: "sale line-item pricing should read from the
  // Pricing page's price lists" -- one lookup per dispatched item,
  // against the Director's default retail list (Round 5B Phase 4's
  // PriceListController::currentPrice). Pre-fills the line's price when
  // an item is picked; the field stays editable for a genuine
  // negotiated exception, it just no longer starts blank.
  const [skuPrices, setSkuPrices] = useState<Record<string, number | null>>({});
  useEffect(() => {
    const dispatched = (activeTrip?.items || []).filter(it => it.qty_carried_bales > 0);
    if (dispatched.length === 0) return;
    Promise.all(dispatched.map(it =>
      api.get('/sales/prices/current', { params: { sku_id: it.sku_id } })
        .then(res => [it.sku_id, res.data?.data?.unit_price ?? null] as const)
        .catch(() => [it.sku_id, null] as const)
    )).then(pairs => setSkuPrices(Object.fromEntries(pairs)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTrip?.id]);

  const [saleForm, setSaleForm] = useState(EMPTY_SALE_FORM);
  const [saleLineItems, setSaleLineItems] = useState<SaleLineItem[]>([{ ...BLANK_LINE }]);
  const [showPaymentConfirm, setShowPaymentConfirm] = useState(false);
  const [stkPayment, setStkPayment] = useState<StkPayment | null>(null);
  const [savingSale, setSavingSale] = useState(false);
  // Round 5A Phase 3: optional photo -- proof of delivery, the
  // customer's shop/stock, or a scanned paper receipt. Never required;
  // high-volume field days shouldn't be slowed down by it.
  const [salePhoto, setSalePhoto] = useState<File | null>(null);
  const [salePhotoPreview, setSalePhotoPreview] = useState<string | null>(null);

  const [customerQuery, setCustomerQuery] = useState('');
  const [customerResults, setCustomerResults] = useState<CustomerRef[]>([]);
  const [searchingCustomers, setSearchingCustomers] = useState(false);
  const [showNewCustomerForm, setShowNewCustomerForm] = useState(false);
  const [newCustomerForm, setNewCustomerForm] = useState(EMPTY_NEW_CUSTOMER);
  const [savingCustomer, setSavingCustomer] = useState(false);

  const addSaleLineItem = () => setSaleLineItems([...saleLineItems, { ...BLANK_LINE }]);
  const removeSaleLineItem = (i: number) => setSaleLineItems(saleLineItems.filter((_, idx) => idx !== i));
  const updateSaleLineItem = (i: number, field: keyof SaleLineItem, value: string) => {
    setSaleLineItems(saleLineItems.map((row, idx) => {
      if (idx !== i) return row;
      const next = { ...row, [field]: value };
      // Round 5A Phase 5: pre-fill from the Pricing list the moment an
      // item is picked, but only if the price hasn't already been typed
      // -- never silently overwrite a price the driver already entered.
      if (field === 'sku_id' && !row.unit_price) {
        const looked = skuPrices[value];
        if (looked != null) next.unit_price = String(looked);
      }
      return next;
    }));
  };

  // Round 3 Phase 3: "typing a name/phone searches existing customers
  // first" -- debounced, minimum 2 characters.
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
    setSaleForm({ ...saleForm, customer_id: c.id, customer_name: c.name, mpesa_phone: c.phone || saleForm.mpesa_phone });
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
  // OTHER lines of this not-yet-saved form.
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
        const sku = activeTrip?.items.find(it => it.sku_id === l.sku_id)?.sku;
        toast.error(`Only ${available} bales of ${sku?.name ?? 'that item'} remain available on this trip`);
        return;
      }
    }
    if (saleForm.payment_method === 'mpesa' && !saleForm.mpesa_phone.trim()) {
      toast.error('Enter the customer phone that should receive the M-Pesa prompt');
      return;
    }
    setShowPaymentConfirm(true);
  };

  // Round 5A Phase 3: same upload-then-attach-the-URL pattern as the
  // Issues page's photo attachment -- entity_id is 'pending' since the
  // sale doesn't exist yet at upload time.
  const uploadSalePhoto = async (): Promise<string | null> => {
    if (!salePhoto) return null;
    const form = new FormData();
    form.append('file', salePhoto);
    form.append('type', 'image');
    form.append('entity_type', 'driver_trip_sale');
    form.append('entity_id', 'pending');
    const res = await api.post('/files/upload', form, { headers: { 'Content-Type': 'multipart/form-data' } });
    return res.data?.data?.url ?? null;
  };

  const submitSale = async () => {
    if (!activeTrip) return;
    setSavingSale(true);
    try {
      const items = validSaleLineItems.map(l => ({
        sku_id: l.sku_id, qty_bales: parseFloat(l.qty_bales) || 0, unit_price: parseFloat(l.unit_price) || 0,
      }));
      const photo_url = await uploadSalePhoto().catch(() => null);
      const res = await api.post(`/fleet/trips/${activeTrip.id}/sales`, {
        customer_id: saleForm.customer_id, payment_method: saleForm.payment_method,
        stk_phone: saleForm.payment_method === 'mpesa' ? saleForm.mpesa_phone.trim() : undefined,
        physical_receipt_no: saleForm.physical_receipt_no || undefined,
        physical_delivery_note_no: saleForm.physical_delivery_note_no || undefined,
        photo_url: photo_url || undefined,
        items,
      });
      const payment = res.data?.data?.payment as StkPayment | undefined;
      setShowPaymentConfirm(false);
      if (payment) {
        setStkPayment(payment);
        return;
      }
      toast.success('Cash sale recorded');
      navigate('/driver/trips');
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to record sale');
      setShowPaymentConfirm(false);
    } finally {
      setSavingSale(false);
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  if (!activeTrip || activeTrip.status !== 'in_transit') {
    return (
      <div className="space-y-6">
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Log a Sale</h1>
        <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
          <p className="text-sm text-gray-600 dark:text-gray-400">
            {!activeTrip
              ? 'No trip in progress -- start one under Trips before you can log a sale.'
              : activeTrip.status === 'pending_departure'
              ? 'Your trip hasn’t started yet -- click Start Trip before logging sales.'
              : 'This trip is already closed.'}
          </p>
          <Link to="/driver/trips" className="inline-block mt-4 text-sm text-blue-600 hover:underline">Go to Trips</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Log a Sale</h1>
        <Link to="/driver/trips" className="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 flex items-center"><X className="w-4 h-4 mr-1" /> Cancel</Link>
      </div>

      <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6 max-w-2xl">
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
          <div className="rounded-xl border-2 border-green-600 bg-green-50 dark:bg-green-950/30 p-4 space-y-3">
            <p className="text-base font-semibold text-gray-900 dark:text-gray-100">How is the customer paying?</p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <button type="button" onClick={() => setSaleForm({ ...saleForm, payment_method: 'mpesa' })} className={`min-h-14 rounded-xl px-4 py-3 text-left text-base font-semibold border-2 ${saleForm.payment_method === 'mpesa' ? 'border-green-700 bg-green-600 text-white' : 'border-gray-300 bg-white text-gray-800 dark:bg-gray-800 dark:text-gray-100'}`}>
                M-Pesa prompt
                <span className={`block text-sm font-normal ${saleForm.payment_method === 'mpesa' ? 'text-green-100' : 'text-gray-500'}`}>Sends a PIN request to the customer’s phone</span>
              </button>
              <button type="button" onClick={() => setSaleForm({ ...saleForm, payment_method: 'cash' })} className={`min-h-14 rounded-xl px-4 py-3 text-left text-base font-semibold border-2 ${saleForm.payment_method === 'cash' ? 'border-amber-700 bg-amber-500 text-white' : 'border-gray-300 bg-white text-gray-800 dark:bg-gray-800 dark:text-gray-100'}`}>
                Cash
                <span className={`block text-sm font-normal ${saleForm.payment_method === 'cash' ? 'text-amber-100' : 'text-gray-500'}`}>Only when the customer has paid cash</span>
              </button>
            </div>
            {saleForm.payment_method === 'mpesa' && (
              <div>
                <label className="block text-base font-semibold text-gray-900 dark:text-gray-100">Phone for the M-Pesa prompt</label>
                <input type="tel" inputMode="tel" value={saleForm.mpesa_phone} onChange={e => setSaleForm({ ...saleForm, mpesa_phone: e.target.value })} placeholder="07…" className="mt-2 block w-full border-2 border-green-700 dark:bg-gray-800 dark:text-gray-100 rounded-xl px-4 py-4 text-2xl tracking-wide" />
                <p className="text-sm text-gray-600 dark:text-gray-300 mt-2">The PIN request goes to this number. The sale is counted only after the customer approves it.</p>
              </div>
            )}
          </div>

          <div className="rounded-xl border-2 border-blue-600 p-4 space-y-3">
            <div className="flex items-center justify-between gap-3">
              <h2 className="text-lg font-bold text-gray-900 dark:text-gray-100">What was sold</h2>
              <button type="button" onClick={addSaleLineItem} className="shrink-0 min-h-12 px-4 py-2 rounded-xl bg-blue-600 text-white text-base font-semibold">+ Add item</button>
            </div>
            {saleLineItems.map((line, i) => {
              const available = line.sku_id ? availableForSku(line.sku_id, i) : null;
              const over = available !== null && parseFloat(line.qty_bales || '0') > available;
              return (
                <div key={i} className="rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 p-3 space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold text-gray-500">Item {i + 1}</span>
                    {saleLineItems.length > 1 && (
                      <button type="button" onClick={() => removeSaleLineItem(i)} className="text-red-600 text-sm font-semibold px-2 py-1">Remove</button>
                    )}
                  </div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Brand / size</label>
                  <select value={line.sku_id} onChange={e => updateSaleLineItem(i, 'sku_id', e.target.value)} className="block w-full border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-xl px-3 py-3 text-base">
                    <option value="">Choose item</option>
                    {(activeTrip.items || []).filter(it => it.qty_carried_bales > 0).map(it => (
                      <option key={it.sku_id} value={it.sku_id}>{it.sku?.name}</option>
                    ))}
                  </select>
                  <div className="grid grid-cols-2 gap-3">
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Bales</label>
                      <input type="number" min="0" step="0.01" inputMode="decimal" placeholder="0" value={line.qty_bales} onChange={e => updateSaleLineItem(i, 'qty_bales', e.target.value)} className={`mt-1 block w-full border-2 rounded-xl px-3 py-3 text-xl dark:bg-gray-700 dark:text-gray-100 ${over ? 'border-red-500' : 'border-gray-300 dark:border-gray-600'}`} />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Price each</label>
                      <input type="number" min="0" step="0.01" inputMode="decimal" placeholder="0" value={line.unit_price} onChange={e => updateSaleLineItem(i, 'unit_price', e.target.value)} className="mt-1 block w-full border-2 border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-xl px-3 py-3 text-xl" />
                    </div>
                  </div>
                  {line.sku_id && (
                    <p className={`text-sm font-medium ${over ? 'text-red-600' : 'text-gray-500'}`}>{available} bales still on this trip</p>
                  )}
                </div>
              );
            })}
            <div className="flex justify-between items-center rounded-xl bg-gray-900 text-white px-4 py-4">
              <span className="text-base">Total</span>
              <span className="text-3xl font-bold">KES {saleTotal.toLocaleString()}</span>
            </div>
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

          {/* Round 5A Phase 3: optional photo -- proof of delivery, the
              customer's shop/stock, or a scanned paper receipt. Never
              required. Uploaded at submit time, not on selection, so
              nothing is stored if the sale form gets cancelled. */}
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Photo (optional)</label>
            <div className="mt-1 flex items-center gap-3">
              <label className="flex items-center text-sm px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                <Camera className="w-4 h-4 mr-2" /> {salePhoto ? 'Change photo' : 'Add photo'}
                <input type="file" accept="image/*" capture="environment" className="hidden" onChange={e => {
                  const file = e.target.files?.[0] ?? null;
                  setSalePhoto(file);
                  setSalePhotoPreview(file ? URL.createObjectURL(file) : null);
                }} />
              </label>
              {salePhotoPreview && (
                <div className="flex items-center gap-2">
                  <img src={salePhotoPreview} alt="Sale attachment preview" className="h-12 w-12 object-cover rounded-md border border-gray-200 dark:border-gray-700" />
                  <button type="button" onClick={() => { setSalePhoto(null); setSalePhotoPreview(null); }} className="text-gray-400 hover:text-red-600"><X className="w-4 h-4" /></button>
                </div>
              )}
            </div>
          </div>

          <button type="submit" disabled={savingSale} className={`w-full min-h-14 rounded-xl text-white text-lg font-bold disabled:opacity-50 ${saleForm.payment_method === 'mpesa' ? 'bg-green-600' : 'bg-amber-600'}`}>
            {savingSale ? 'Sending…' : saleForm.payment_method === 'mpesa' ? 'Send M-Pesa prompt' : 'Save cash sale'}
          </button>
        </form>
      </div>

      {/* Quick "add new customer" */}
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

      {showPaymentConfirm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{saleForm.payment_method === 'mpesa' ? 'Send the M-Pesa prompt?' : 'Save this cash sale?'}</h3>
            <dl className="space-y-1 text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-3 mb-4">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Amount</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {saleTotal.toLocaleString()}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Customer</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.customer_name || '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Payment</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.payment_method === 'mpesa' ? `M-Pesa prompt to ${saleForm.mpesa_phone}` : 'Cash'}</dd></div>
            </dl>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
              {saleForm.payment_method === 'mpesa'
                ? `This sends an M-Pesa prompt for KES ${saleTotal.toLocaleString()} to ${saleForm.mpesa_phone}. It is not a sale until the customer enters the PIN.`
                : `Save this only because the customer paid KES ${saleTotal.toLocaleString()} in cash.`}
            </p>
            <div className="flex justify-end space-x-3">
              <button type="button" onClick={() => setShowPaymentConfirm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Go Back</button>
              <button type="button" onClick={submitSale} disabled={savingSale} className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50">
                {savingSale ? 'Sending…' : (saleForm.payment_method === 'mpesa' ? 'Send prompt' : 'Save cash sale')}
              </button>
            </div>
          </div>
        </div>
      )}
      {stkPayment && (
        <MpesaStkDialog
          payment={stkPayment}
          onFinished={(p) => setStkPayment(p)}
          onClose={() => {
            setStkPayment(null);
            navigate('/driver/trips');
          }}
        />
      )}
    </div>
  );
};

export default DriverLogSalePage;
