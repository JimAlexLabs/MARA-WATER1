import React, { useState, useEffect } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import {
  Plus,
  Search,
  Filter,
  Download,
  Users,
  ShoppingCart,
  DollarSign,
  TrendingUp,
  Eye,
  Edit,
  Trash2,
  X,
  PackageMinus,
  AlertTriangle
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { usePermissions } from '../contexts/AuthContext';
import { PageShell, PageHero, HeroButton } from '../components/PageShell';
import {
  CONTACT_ADMIN_MSG,
  DEFAULT_MANAGER_LOCKS,
  isLocked,
  isWithinPastWeek,
  locksFromSettingsPayload,
  pickMainWarehouse,
} from '../utils/managerLocks';
import MpesaStkDialog, { StkPayment } from '../components/MpesaStkDialog';

interface Customer {
  id: string;
  code: string;
  name: string;
  contact_person?: string | null;
  type: 'retail' | 'wholesale' | 'corporate' | 'hotel_restaurant' | 'walk_in';
  phone: string;
  email: string;
  address: string;
  price_tier: string;
  preferred_products?: string | null;
  typical_order_size?: string | null;
  payment_terms?: string | null;
  notes?: string | null;
  status?: 'active' | 'inactive';
  debtor_balance?: number;
  route: {
    id?: string;
    name: string;
  } | null;
}

const CUSTOMER_TYPE_LABELS: Record<string, string> = {
  retail: 'Retail Shop',
  wholesale: 'Distributor / Reseller',
  corporate: 'Institution',
  hotel_restaurant: 'Hotel / Restaurant',
  walk_in: 'Walk-in',
};

const EMPTY_CUSTOMER_FORM = {
  name: '', type: 'retail', phone: '', address: '', price_tier: 'standard', notes: '', status: 'active',
};

interface Sku {
  id: string;
  code: string;
  name: string;
  size_liters: string;
  unit: string;
}

interface Warehouse {
  id: string;
  code: string;
  name: string;
}

interface PriceList {
  id: string;
  name: string;
  is_default: boolean;
}

interface PriceListItem {
  sku_id: string;
  unit_price: string;
}

interface OrderItemRow {
  id: string;
  sku_id: string;
  qty: number;
  qty_returned: number;
  unit_price: string;
  unit_price_overridden: boolean;
  override_reason: string | null;
  net_qty: number;
  line_total: number;
  sku: Sku;
}

interface Order {
  id: string;
  order_no: string;
  customer: Customer | null;
  status: 'draft' | 'confirmed' | 'dispatched' | 'delivered' | 'partially_returned' | 'cancelled' | 'awaiting_payment';
  order_date: string;
  source?: 'outlet' | 'field';
  // Laravel serializes decimal-cast columns as JSON strings, not numbers.
  total_amount: string;
  payment_method?: 'cash' | 'mpesa' | 'credit' | null;
  payment_reference?: string | null;
  warehouse?: Warehouse | null;
  items?: OrderItemRow[] | { sku_name?: string; qty?: number }[];
  invoice?: { invoice_no: string; due_date: string } | null;
  sales_officer?: {
    first_name: string;
    last_name: string;
  } | null;
}

const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash: 'Cash',
  mpesa: 'M-Pesa',
  credit: 'Credit',
};

// New empty line item for the Log a Sale form.
const newSaleItem = () => ({
  id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
  sku_id: '',
  qty: 1,
  qty_returned: 0,
  unit_price: '',
  override_reason: '',
});

const EMPTY_SALE_FORM = {
  warehouse_id: '',
  customer_id: '',
  price_list_id: '',
  payment_method: 'cash' as 'cash' | 'mpesa' | 'credit',
  payment_reference: '',
  stk_phone: '',
  order_date: '',
  notes: '',
  items: [newSaleItem()],
  signatory: '',
  expected_repayment_date: '',
  sale_channel: 'walk_in' as 'walk_in' | 'field' | 'outlet',
  buyer_name: '',
  buyer_phone: '',
};

// Kenya-time "today" as YYYY-MM-DD, and the max repayment date (7 days out).
const todayStr = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' });
const maxRepaymentDate = (fromDate: string) => {
  const d = new Date((fromDate || todayStr()) + 'T00:00:00');
  d.setDate(d.getDate() + 7);
  return d.toISOString().slice(0, 10);
};

const SalesPage: React.FC = () => {
  const { isDirector, isManagerTier, accessTier } = usePermissions();
  const director = isDirector() || accessTier() === 'director';
  const [managerLocks, setManagerLocks] = useState(DEFAULT_MANAGER_LOCKS);
  const cashLocked = !director && isLocked(managerLocks, 'manager_cash_payment');
  const canManagerEditSale = (order: Order) =>
    !director
    && !isLocked(managerLocks, 'manager_edit_sales_7d')
    && isWithinPastWeek(order.order_date);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [orders, setOrders] = useState<Order[]>([]);
  const [deletingSaleId, setDeletingSaleId] = useState<string | null>(null);
  const [editingSale, setEditingSale] = useState(false);
  const [editForm, setEditForm] = useState({
    payment_method: 'cash' as 'cash' | 'mpesa' | 'credit',
    payment_reference: '',
    order_date: '',
    customer_id: '',
    amount: '',
  });
  const [savingEdit, setSavingEdit] = useState(false);
  const [loading, setLoading] = useState(true);
  const [initialLoaded, setInitialLoaded] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [searchParams] = useSearchParams();

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
    const tab = searchParams.get('tab');
    if (tab) setActiveTab(tab);
  }, [searchParams]);
  const [filterStatus, setFilterStatus] = useState('all');
  const [activeTab, setActiveTab] = useState('orders');
  const [showCustomerForm, setShowCustomerForm] = useState(false);
  const [showSaleForm, setShowSaleForm] = useState(false);
  const [viewingOrder, setViewingOrder] = useState<Order | null>(null);
  const [openingOrderId, setOpeningOrderId] = useState<string | null>(null);
  const [showDeletedSales, setShowDeletedSales] = useState(false);
  const [trashedOrders, setTrashedOrders] = useState<Order[]>([]);
  const [trashedFieldSales, setTrashedFieldSales] = useState<Order[]>([]);
  const [loadingTrashedSales, setLoadingTrashedSales] = useState(false);
  const [showDeletedCustomers, setShowDeletedCustomers] = useState(false);
  const [trashedCustomers, setTrashedCustomers] = useState<Customer[]>([]);
  const [loadingTrashedCustomers, setLoadingTrashedCustomers] = useState(false);
  const [editingCustomerId, setEditingCustomerId] = useState<string | null>(null);
  const [routes, setRoutes] = useState<{ id: string; name: string }[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [skus, setSkus] = useState<Sku[]>([]);
  const [priceLists, setPriceLists] = useState<PriceList[]>([]);
  const [priceListPrices, setPriceListPrices] = useState<Record<string, string>>({});
  const [saleSubmitting, setSaleSubmitting] = useState(false);
  const [stkPayment, setStkPayment] = useState<StkPayment | null>(null);

  // Customer Form State
  const [customerForm, setCustomerForm] = useState(EMPTY_CUSTOMER_FORM);

  // Log a Sale Form State
  const [saleForm, setSaleForm] = useState(EMPTY_SALE_FORM);

  useEffect(() => {
    const endpoint = director ? '/settings' : '/settings/ui-locks';
    api.get(endpoint)
      .then((res) => {
        const locks = locksFromSettingsPayload(res.data?.data);
        setManagerLocks(locks);
        if (!director && isLocked(locks, 'manager_cash_payment')) {
          setSaleForm((f) => (f.payment_method === 'cash' ? { ...f, payment_method: 'mpesa' } : f));
        }
      })
      .catch(() => {});
  }, [director]);

  useEffect(() => {
    fetchData();
    api.get('/fleet/routes').then(res => setRoutes(res.data.data)).catch(() => {});
    api.get('/inventory/warehouses').then(res => {
      const list: Warehouse[] = res.data.data || [];
      setWarehouses(list);
      const main = pickMainWarehouse(list);
      if (main) {
        setSaleForm(f => (f.warehouse_id ? f : { ...f, warehouse_id: main.id }));
      }
    }).catch(() => {});
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/sales/price-lists').then(res => {
      const lists: PriceList[] = res.data.data;
      setPriceLists(lists);
      const defaultList = lists.find(l => l.is_default) || lists[0];
      if (defaultList) {
        setSaleForm(f => ({ ...f, price_list_id: defaultList.id }));
      }
    }).catch(() => {});

    // Round 3 Phase 3: "a customer or sale created from the Driver/
    // salesperson dashboard must appear immediately ... not on refresh-
    // only" -- reuses the same polling approach Analytics already uses
    // for this (Round 2 Phase 9), not a second mechanism, just applied
    // here too so a driver's trip sale/new customer shows up without a
    // manual refresh.
    const interval = setInterval(fetchData, 20000);
    return () => clearInterval(interval);
  }, []);

  // Keep the price-list lookup map in sync with whichever list is selected
  // on the sale form, so line items can auto-fill and flag overrides.
  useEffect(() => {
    if (!saleForm.price_list_id) {
      setPriceListPrices({});
      return;
    }
    api.get(`/sales/price-lists/${saleForm.price_list_id}/items`).then(res => {
      const map: Record<string, string> = {};
      (res.data.data as PriceListItem[]).forEach(item => { map[item.sku_id] = item.unit_price; });
      setPriceListPrices(map);
    }).catch(() => {});
  }, [saleForm.price_list_id]);

  const fetchData = async () => {
    try {
      setLoading(true);
      const [customersResponse, ordersResponse, fieldResponse] = await Promise.all([
        api.get('/sales/customers'),
        api.get('/sales/orders', { params: { limit: 100 } }),
        api.get('/sales/field-sales', { params: { limit: 100 } }).catch(() => ({ data: { data: [] } })),
      ]);

      setCustomers(customersResponse.data.data);
      const outlet = ordersResponse.data.data || [];
      const field = (fieldResponse.data?.data || []).map((row: Order) => ({ ...row, source: 'field' as const }));
      const outletTagged = outlet.map((row: Order) => ({ ...row, source: row.source || 'outlet' as const }));
      setOrders([...field, ...outletTagged].sort((a, b) => String(b.order_date || '').localeCompare(String(a.order_date || ''))));
    } catch (error) {
      toast.error('Failed to fetch sales data');
    } finally {
      setLoading(false);
      setInitialLoaded(true);
    }
  };

  const openNewCustomer = () => {
    setEditingCustomerId(null);
    setCustomerForm(EMPTY_CUSTOMER_FORM);
    setShowCustomerForm(true);
  };

  const openEditCustomer = (c: Customer) => {
    setEditingCustomerId(c.id);
    setCustomerForm({
      name: c.name, type: c.type, phone: c.phone || '', address: c.address || '',
      price_tier: director ? (c.price_tier || 'standard') : 'standard',
      notes: c.notes || '', status: c.status || 'active',
    });
    setShowCustomerForm(true);
  };

  const handleCustomerSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const payload = {
      name: customerForm.name,
      type: customerForm.type,
      phone: customerForm.phone,
      address: customerForm.address || null,
      price_tier: director ? customerForm.price_tier : 'standard',
      notes: customerForm.notes || null,
      status: customerForm.status,
    };
    try {
      if (editingCustomerId) {
        await api.put(`/sales/customers/${editingCustomerId}`, payload);
        toast.success('Customer updated successfully');
      } else {
        await api.post('/sales/customers', payload);
        toast.success('Customer created successfully');
      }
      setShowCustomerForm(false);
      fetchData();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to save customer');
    }
  };

  const handleDeleteCustomer = async (c: Customer) => {
    if (!window.confirm(`Remove customer ${c.name}?`)) return;
    try {
      await api.delete(`/sales/customers/${c.id}`);
      toast.success(
        (t) => (
          <span className="flex items-center gap-3">
            Customer removed
            <button
              type="button"
              className="underline font-medium"
              onClick={() => {
                toast.dismiss(t.id);
                restoreCustomer(c.id);
              }}
            >
              Undo
            </button>
          </span>
        ),
        { duration: 8000 },
      );
      fetchData();
      if (showDeletedCustomers) loadTrashedCustomers();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to remove customer');
    }
  };

  const outletLabel = (order: Order) =>
    order.warehouse?.name
    || (order.source === 'field' ? 'Field' : 'Main Warehouse');

  const openViewOrder = async (order: Order) => {
    setEditingSale(false);
    const needsFetch = !(order.items && order.items.length) && order.source !== 'field';
    if (!needsFetch) {
      setViewingOrder(order);
      return;
    }
    setOpeningOrderId(order.id);
    setViewingOrder(order);
    try {
      const res = await api.get(`/sales/orders/${order.id}`);
      const full = res.data?.data?.order || res.data?.data;
      if (full?.id) setViewingOrder(full);
    } catch {
      toast.error('Could not load line items');
    } finally {
      setOpeningOrderId(null);
    }
  };

  const loadTrashedSales = async () => {
    setLoadingTrashedSales(true);
    try {
      const res = await api.get('/sales/orders/trashed');
      const data = res.data?.data || {};
      setTrashedOrders(data.orders || []);
      setTrashedFieldSales(data.field_sales || []);
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to load deleted sales');
    } finally {
      setLoadingTrashedSales(false);
    }
  };

  const loadTrashedCustomers = async () => {
    setLoadingTrashedCustomers(true);
    try {
      const res = await api.get('/sales/customers/trashed');
      setTrashedCustomers(res.data?.data || []);
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to load deleted customers');
    } finally {
      setLoadingTrashedCustomers(false);
    }
  };

  const restoreSale = async (order: Order) => {
    try {
      if (order.source === 'field') {
        await api.post(`/sales/field-sales/${order.id}/restore`);
      } else {
        await api.post(`/sales/orders/${order.id}/restore`);
      }
      toast.success('Sale restored');
      fetchData();
      loadTrashedSales();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to restore sale');
    }
  };

  const restoreCustomer = async (id: string) => {
    try {
      await api.post(`/sales/customers/${id}/restore`);
      toast.success('Customer restored');
      fetchData();
      if (showDeletedCustomers) loadTrashedCustomers();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to restore customer');
    }
  };

  const openLogSale = () => {
    const defaultList = priceLists.find(l => l.is_default) || priceLists[0];
    const main = pickMainWarehouse(warehouses);
    setSaleForm({
      ...EMPTY_SALE_FORM,
      warehouse_id: main?.id || '',
      price_list_id: defaultList?.id || '',
      items: [newSaleItem()],
    });
    setShowCreditConfirm(false);
    setShowSaleForm(true);
  };

  const addSaleItem = () => {
    setSaleForm(f => ({ ...f, items: [...f.items, newSaleItem()] }));
  };

  const removeSaleItem = (id: string) => {
    setSaleForm(f => ({ ...f, items: f.items.length > 1 ? f.items.filter(i => i.id !== id) : f.items }));
  };

  const updateSaleItem = (id: string, patch: Partial<ReturnType<typeof newSaleItem>>) => {
    setSaleForm(f => ({
      ...f,
      items: f.items.map(i => i.id === id ? { ...i, ...patch } : i)
    }));
  };

  // What each line's price would be if left at the price list default --
  // used both to pre-fill the input and to tell whether it's been overridden.
  const listPriceFor = (skuId: string) => priceListPrices[skuId] || '';

  const saleLineTotal = (item: ReturnType<typeof newSaleItem>) => {
    const price = parseFloat(item.unit_price || listPriceFor(item.sku_id) || '0');
    const netQty = Math.max(0, (item.qty || 0) - (item.qty_returned || 0));
    return netQty * price;
  };

  const saleTotal = saleForm.items.reduce((sum, i) => sum + saleLineTotal(i), 0);

  // Round 2 Phase 6: "debt sales are discouraged, not banned... show a
  // visible warning/confirmation step... so it's a deliberate action, not
  // an easy default." Submitting the main form with payment_method
  // 'credit' opens this instead of logging the sale immediately;
  // confirmSale() below does the actual submit.
  const [showCreditConfirm, setShowCreditConfirm] = useState(false);

  const handleSaleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (saleForm.payment_method === 'credit') {
      if (!director) {
        toast.error('Only a Director can log a credit sale');
        return;
      }
      if (!saleForm.customer_id && !saleForm.buyer_name.trim()) {
        toast.error('Select a customer or enter the buyer name for credit');
        return;
      }
      if (!saleForm.signatory.trim()) {
        toast.error('Enter who is signing for this credit sale');
        return;
      }
      setShowCreditConfirm(true);
      return;
    }
    submitSale();
  };

  const openEditSale = (order: Order) => {
    setViewingOrder(order);
    setEditForm({
      payment_method: (order.payment_method || 'cash') as 'cash' | 'mpesa' | 'credit',
      payment_reference: order.payment_reference || '',
      order_date: order.order_date ? String(order.order_date).slice(0, 10) : '',
      customer_id: order.customer?.id || '',
      amount: String(order.total_amount ?? ''),
    });
    setEditingSale(true);
  };

  const saveEditSale = async () => {
    if (!viewingOrder || !director) return;
    setSavingEdit(true);
    try {
      if (viewingOrder.source === 'field') {
        await api.put(`/sales/field-sales/${viewingOrder.id}`, {
          payment_method: editForm.payment_method === 'credit' ? 'debt' : editForm.payment_method,
          payment_reference: editForm.payment_reference || null,
          customer_id: editForm.customer_id || null,
          amount: editForm.amount !== '' ? Number(editForm.amount) : undefined,
        });
      } else {
        await api.put(`/sales/orders/${viewingOrder.id}`, {
          payment_method: editForm.payment_method,
          payment_reference: editForm.payment_reference || null,
          order_date: editForm.order_date || null,
          customer_id: editForm.customer_id || null,
        });
      }
      toast.success('Sale updated');
      setEditingSale(false);
      setViewingOrder(null);
      fetchData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update sale');
    } finally {
      setSavingEdit(false);
    }
  };

  const deleteSale = async (order: Order) => {
    if (!director) {
      toast.error('Only a Director can delete a posted sale');
      return;
    }
    const label = order.source === 'field'
      ? `field sale for ${order.customer?.name || 'walk-in'} (KES ${Number(order.total_amount).toLocaleString()})`
      : `sale ${order.order_no}`;
    if (!window.confirm(`Delete ${label}? You can restore it from Deleted sales.`)) return;
    setDeletingSaleId(order.id);
    try {
      if (order.source === 'field') {
        await api.delete(`/sales/field-sales/${order.id}`);
      } else {
        await api.delete(`/sales/orders/${order.id}`);
      }
      toast.success(
        (t) => (
          <span className="flex items-center gap-3">
            Sale deleted
            <button
              type="button"
              className="underline font-medium"
              onClick={() => {
                toast.dismiss(t.id);
                restoreSale(order);
              }}
            >
              Undo
            </button>
          </span>
        ),
        { duration: 8000 },
      );
      if (viewingOrder?.id === order.id) {
        setViewingOrder(null);
        setEditingSale(false);
      }
      fetchData();
      if (showDeletedSales) loadTrashedSales();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to delete sale');
    } finally {
      setDeletingSaleId(null);
    }
  };

  const submitSale = async () => {
    setSaleSubmitting(true);
    try {
      const channel = isManagerTier() && !director ? 'walk_in' : saleForm.sale_channel;
      const phone = (saleForm.stk_phone || saleForm.buyer_phone || '').trim();
      const payload = {
        warehouse_id: saleForm.warehouse_id,
        customer_id: saleForm.customer_id || null,
        price_list_id: saleForm.price_list_id || null,
        payment_method: saleForm.payment_method,
        payment_reference: null,
        stk_phone: saleForm.payment_method === 'mpesa' ? (phone || undefined) : undefined,
        stk_channel: 'warehouse',
        order_date: saleForm.order_date || todayStr(),
        notes: saleForm.notes || null,
        sale_channel: channel,
        buyer_name: saleForm.buyer_name.trim() || undefined,
        buyer_phone: phone || undefined,
        signatory: saleForm.payment_method === 'credit' ? saleForm.signatory : undefined,
        expected_repayment_date: saleForm.payment_method === 'credit' && saleForm.expected_repayment_date
          ? saleForm.expected_repayment_date
          : undefined,
        items: saleForm.items.map(i => ({
          sku_id: i.sku_id,
          qty: i.qty,
          qty_returned: i.qty_returned || 0,
          unit_price: i.unit_price ? parseFloat(i.unit_price) : undefined,
          override_reason: director ? (i.override_reason || undefined) : undefined,
        })),
      };
      const res = await api.post('/sales/orders/log-sale', payload);
      const payment = res.data?.data?.payment as StkPayment | undefined;
      const stkError = res.data?.data?.stk_error as string | undefined;
      if (payment) {
        setStkPayment(payment);
        if (stkError) toast.error(stkError);
        return;
      }
      const orderNo = res.data?.data?.order?.order_no;
      const catchUp = Boolean(res.data?.data?.catch_up);
      toast.success(
        catchUp
          ? `Catch-up sale logged${orderNo ? ` (${orderNo})` : ''}`
          : `Sale logged${orderNo ? ` (${orderNo})` : ''}`
      );
      const stockWarnings = res.data?.data?.stock_warnings || [];
      if (!catchUp) {
        stockWarnings.forEach((w: { message: string }) => toast.error(w.message, { duration: 8000 }));
      }
      setShowCreditConfirm(false);
      setShowSaleForm(false);
      fetchData();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error(
        (Array.isArray(firstError) ? firstError[0] : firstError)
        || error.response?.data?.error
        || error.response?.data?.message
        || 'Failed to log sale'
      );
      setShowCreditConfirm(false);
    } finally {
      setSaleSubmitting(false);
    }
  };

  const filteredOrders = orders.filter(order => {
    const matchesSearch = (order.customer?.name || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                         order.order_no.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'all' || order.status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  const filteredCustomers = customers.filter(customer => {
    return customer.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
           customer.code.toLowerCase().includes(searchTerm.toLowerCase());
  });

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'draft': return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700';
      case 'confirmed': return 'text-blue-600 bg-blue-100';
      case 'dispatched': return 'text-yellow-600 bg-yellow-100';
      case 'delivered': return 'text-green-600 bg-green-100';
      case 'cancelled': return 'text-red-600 bg-red-100';
      default: return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700';
    }
  };

  const getTypeColor = (type: string) => {
    switch (type) {
      case 'retail': return 'text-blue-600 bg-blue-100';
      case 'wholesale': return 'text-green-600 bg-green-100';
      case 'corporate': return 'text-purple-600 bg-purple-100';
      case 'hotel_restaurant': return 'text-orange-600 bg-orange-100';
      case 'walk_in': return 'text-gray-600 bg-gray-200';
      default: return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700';
    }
  };

  if (loading && !initialLoaded) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <PageShell>
      <PageHero
        eyebrow="Customers · orders · field sales"
        title="Sales"
        description="Manage customers and orders. Field sales from drivers appear here too."
        actions={(
          <>
            <HeroButton onClick={openNewCustomer}><Plus className="w-4 h-4 mr-2" /> New customer</HeroButton>
            <HeroButton variant="solid" onClick={openLogSale}><Plus className="w-4 h-4 mr-2" /> Log a sale</HeroButton>
          </>
        )}
      />

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="mara-stat">
          <div className="flex items-center">
            <ShoppingCart className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Orders</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{orders.length}</p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <DollarSign className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Revenue</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                KES {orders.reduce((sum, order) => sum + Number(order.total_amount), 0).toLocaleString()}
              </p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <Users className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Customers</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{customers.length}</p>
            </div>
          </div>
        </div>
        <div className="mara-stat">
          <div className="flex items-center">
            <TrendingUp className="w-8 h-8 text-orange-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Avg Order Value</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                KES {orders.length > 0 ? (orders.reduce((sum, order) => sum + Number(order.total_amount), 0) / orders.length).toFixed(0) : 0}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="mara-panel">
        <div className="border-b border-gray-200 dark:border-gray-700">
          <nav className="flex space-x-8 px-6">
            <button
              onClick={() => setActiveTab('orders')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'orders'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Orders ({orders.length})
            </button>
            <button
              onClick={() => setActiveTab('customers')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'customers'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
              }`}
            >
              Customers ({customers.length})
            </button>
            {director && activeTab === 'orders' && (
              <button
                type="button"
                onClick={() => {
                  const next = !showDeletedSales;
                  setShowDeletedSales(next);
                  if (next) loadTrashedSales();
                }}
                className={`py-4 px-1 border-b-2 font-medium text-sm ml-auto ${
                  showDeletedSales
                    ? 'border-amber-500 text-amber-700'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700'
                }`}
              >
                Deleted sales
              </button>
            )}
            {director && activeTab === 'customers' && (
              <button
                type="button"
                onClick={() => {
                  const next = !showDeletedCustomers;
                  setShowDeletedCustomers(next);
                  if (next) loadTrashedCustomers();
                }}
                className={`py-4 px-1 border-b-2 font-medium text-sm ml-auto ${
                  showDeletedCustomers
                    ? 'border-amber-500 text-amber-700'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700'
                }`}
              >
                Deleted customers
              </button>
            )}
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
              {activeTab === 'orders' && (
                <select
                  value={filterStatus}
                  onChange={(e) => setFilterStatus(e.target.value)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                  <option value="all">All Status</option>
                  <option value="draft">Draft</option>
                  <option value="confirmed">Confirmed</option>
                  <option value="dispatched">Dispatched</option>
                  <option value="delivered">Delivered</option>
                  <option value="partially_returned">Partially Returned</option>
                  <option value="cancelled">Cancelled</option>
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

          {/* Orders Table */}
          {activeTab === 'orders' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Order Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Outlet
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Customer
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Amount (KES)
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Payment
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Sales Officer
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                  {filteredOrders.map((order) => (
                    <tr key={order.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{order.order_no}</div>
                          <div className="text-sm text-gray-500 dark:text-gray-400">
                            {new Date(order.order_date).toLocaleDateString()}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                        {outletLabel(order)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <button
                            type="button"
                            onClick={() => openViewOrder(order)}
                            className="text-sm font-medium text-blue-600 hover:text-blue-800 hover:underline text-left"
                          >
                            {order.customer?.name || 'Walk-in'}
                          </button>
                          <div className="text-sm text-gray-500 dark:text-gray-400">{order.customer?.code || ''}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                        KES {Number(order.total_amount).toLocaleString()}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        {order.payment_method ? (
                          <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${
                            order.payment_method === 'credit' ? 'text-red-600 bg-red-100' : 'text-green-600 bg-green-100'
                          }`}>
                            {PAYMENT_METHOD_LABELS[order.payment_method] || order.payment_method}
                          </span>
                        ) : <span className="text-gray-400 dark:text-gray-500 text-sm">—</span>}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusColor(order.status)}`}>
                          {order.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                        {order.sales_officer
                          ? `${order.sales_officer.first_name} ${order.sales_officer.last_name}`
                          : '—'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center space-x-2">
                          <button onClick={() => openViewOrder(order)} className="text-blue-600 hover:text-blue-900" title="View" disabled={openingOrderId === order.id}>
                            <Eye className="w-4 h-4" />
                          </button>
                          {(director || canManagerEditSale(order)) && (
                            <button
                              onClick={() => openEditSale(order)}
                              className="text-amber-600 hover:text-amber-800"
                              title={director ? 'Alter sale (director)' : 'Alter sale (Admin-approved, ≤7 days)'}
                            >
                              <Edit className="w-4 h-4" />
                            </button>
                          )}
                          {director && (
                            <button
                              onClick={() => deleteSale(order)}
                              disabled={deletingSaleId === order.id}
                              className="text-red-600 hover:text-red-900 disabled:opacity-40"
                              title="Delete sale (director)"
                            >
                              <Trash2 className="w-4 h-4" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {director && showDeletedSales && (
                <div className="border-t border-gray-200 dark:border-gray-700 mt-4 p-4 bg-amber-50/50 dark:bg-amber-900/10">
                  <div className="flex items-center justify-between mb-3">
                    <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Deleted sales</h4>
                    <button type="button" onClick={loadTrashedSales} className="text-xs text-blue-600 hover:underline">Refresh</button>
                  </div>
                  {loadingTrashedSales ? (
                    <p className="text-sm text-gray-500">Loading…</p>
                  ) : (trashedOrders.length + trashedFieldSales.length) === 0 ? (
                    <p className="text-sm text-gray-500">No deleted sales.</p>
                  ) : (
                    <div className="space-y-2 max-h-56 overflow-y-auto">
                      {[
                        ...trashedOrders.map((o) => ({ ...o, source: (o.source || 'outlet') as Order['source'] })),
                        ...trashedFieldSales.map((o) => ({ ...o, source: 'field' as const })),
                      ].map((order) => (
                        <div key={`${order.source}-${order.id}`} className="flex items-center justify-between gap-3 text-sm bg-white dark:bg-gray-800 rounded-md px-3 py-2 border border-gray-200 dark:border-gray-700">
                          <div>
                            <span className="font-medium text-gray-900 dark:text-gray-100">{order.order_no || 'Field sale'}</span>
                            <span className="text-gray-500 dark:text-gray-400 ml-2">
                              {order.customer?.name || 'Walk-in'} · KES {Number(order.total_amount).toLocaleString()}
                            </span>
                          </div>
                          <button
                            type="button"
                            onClick={() => restoreSale(order)}
                            className="text-xs font-medium text-blue-600 hover:text-blue-800 whitespace-nowrap"
                          >
                            Undo
                          </button>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Customers Table */}
          {activeTab === 'customers' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-900">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Customer Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Contact
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Type
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Route
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                      Debtor Balance
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
                  {filteredCustomers.map((customer) => (
                    <tr key={customer.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <Link to={`/customers/${customer.id}`} className="text-sm font-medium text-blue-600 hover:text-blue-800 hover:underline">{customer.name}</Link>
                        <div className="text-sm text-gray-500 dark:text-gray-400">{customer.code}{customer.contact_person ? ` · ${customer.contact_person}` : ''}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900 dark:text-gray-100">{customer.phone}</div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">{customer.email}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${getTypeColor(customer.type)}`}>
                          {CUSTOMER_TYPE_LABELS[customer.type] || customer.type}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                        {customer.route?.name || 'No Route'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">
                        <span className={(customer.debtor_balance || 0) > 0 ? 'text-red-600 font-medium' : 'text-gray-500 dark:text-gray-400'}>
                          KES {(customer.debtor_balance || 0).toLocaleString()}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${customer.status === 'inactive' ? 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700' : 'text-green-600 bg-green-100'}`}>
                          {customer.status || 'active'}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center space-x-3">
                          <Link to={`/customers/${customer.id}`} className="text-blue-600 hover:text-blue-900">
                            <Eye className="w-4 h-4" />
                          </Link>
                          <button onClick={() => openEditCustomer(customer)} className="text-green-600 hover:text-green-900">
                            <Edit className="w-4 h-4" />
                          </button>
                          <button onClick={() => handleDeleteCustomer(customer)} className="text-red-600 hover:text-red-900">
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {director && showDeletedCustomers && (
                <div className="border-t border-gray-200 dark:border-gray-700 mt-4 p-4 bg-amber-50/50 dark:bg-amber-900/10">
                  <div className="flex items-center justify-between mb-3">
                    <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Deleted customers</h4>
                    <button type="button" onClick={loadTrashedCustomers} className="text-xs text-blue-600 hover:underline">Refresh</button>
                  </div>
                  {loadingTrashedCustomers ? (
                    <p className="text-sm text-gray-500">Loading…</p>
                  ) : trashedCustomers.length === 0 ? (
                    <p className="text-sm text-gray-500">No deleted customers.</p>
                  ) : (
                    <div className="space-y-2 max-h-56 overflow-y-auto">
                      {trashedCustomers.map((c) => (
                        <div key={c.id} className="flex items-center justify-between gap-3 text-sm bg-white dark:bg-gray-800 rounded-md px-3 py-2 border border-gray-200 dark:border-gray-700">
                          <div>
                            <span className="font-medium text-gray-900 dark:text-gray-100">{c.name}</span>
                            <span className="text-gray-500 dark:text-gray-400 ml-2">{c.code} · {c.phone}</span>
                          </div>
                          <button
                            type="button"
                            onClick={() => restoreCustomer(c.id)}
                            className="text-xs font-medium text-blue-600 hover:text-blue-800 whitespace-nowrap"
                          >
                            Undo
                          </button>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Customer Form Modal — short: name, type, phone, location */}
      {showCustomerForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md max-h-[90vh] overflow-y-auto">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">{editingCustomerId ? 'Edit Customer' : 'New Customer'}</h3>
            <p className="text-xs text-gray-500 dark:text-gray-400 mb-4">Name, type, phone and location. Price stays standard unless a Director changes it.</p>
            <form onSubmit={handleCustomerSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Organization / Shop / Customer name</label>
                <input
                  required
                  type="text"
                  value={customerForm.name}
                  onChange={(e) => setCustomerForm({...customerForm, name: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Type</label>
                  <select
                    value={customerForm.type}
                    onChange={(e) => setCustomerForm({...customerForm, type: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  >
                    {Object.entries(CUSTOMER_TYPE_LABELS).map(([value, label]) => (
                      <option key={value} value={value}>{label}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                  <input
                    required
                    type="tel"
                    value={customerForm.phone}
                    onChange={(e) => setCustomerForm({...customerForm, phone: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Location</label>
                <input
                  type="text"
                  value={customerForm.address}
                  onChange={(e) => setCustomerForm({...customerForm, address: e.target.value})}
                  placeholder="Area / shop location"
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                />
              </div>
              {director && (
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Price tier (Director only)</label>
                  <select
                    value={customerForm.price_tier}
                    onChange={(e) => setCustomerForm({...customerForm, price_tier: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  >
                    <option value="standard">Standard</option>
                    <option value="premium">Premium</option>
                    <option value="wholesale">Wholesale</option>
                  </select>
                </div>
              )}
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowCustomerForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md">Cancel</button>
                <button type="submit" className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                  {editingCustomerId ? 'Save' : 'Add customer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Log a Sale Modal */}
      {showSaleForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Log a Sale</h3>
              <button onClick={() => setShowSaleForm(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600">
                <X className="w-5 h-5" />
              </button>
            </div>
            <form onSubmit={handleSaleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Outlet / Branch</label>
                  <input
                    readOnly
                    value={warehouses.find(w => w.id === saleForm.warehouse_id)?.name
                      || pickMainWarehouse(warehouses)?.name
                      || 'Main warehouse'}
                    className="mt-1 block w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-800 rounded-md px-3 py-2 bg-gray-50 text-gray-700 dark:text-gray-300 cursor-not-allowed"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Sale date{director ? ' (catch-up OK)' : ''}
                  </label>
                  <input
                    type="date"
                    value={saleForm.order_date || todayStr()}
                    max={todayStr()}
                    min={director ? undefined : todayStr()}
                    onChange={(e) => setSaleForm({...saleForm, order_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                  {director && (
                    <p className="text-xs text-gray-500 mt-1">
                      Pick a past date to log sales from days without the app — no stock discrepancy popups.
                    </p>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Method</label>
                  <select
                    value={saleForm.payment_method}
                    onChange={(e) => setSaleForm({...saleForm, payment_method: e.target.value as any})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  >
                    {!cashLocked && <option value="cash">Cash</option>}
                    <option value="mpesa">M-Pesa</option>
                    {director && <option value="credit">Credit (Director only)</option>}
                  </select>
                  {cashLocked && (
                    <p className="text-xs text-amber-700 mt-1">{CONTACT_ADMIN_MSG}</p>
                  )}
                </div>
                {director ? (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Sale type</label>
                    <select
                      value={saleForm.sale_channel}
                      onChange={(e) => setSaleForm({...saleForm, sale_channel: e.target.value as any})}
                      className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                    >
                      <option value="walk_in">Walk-in</option>
                      <option value="field">Field</option>
                      <option value="outlet">Outlet / counter</option>
                    </select>
                  </div>
                ) : (
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Sale type</label>
                    <input readOnly value="Walk-in" className="mt-1 block w-full border border-gray-200 dark:border-gray-600 dark:bg-gray-700 rounded-md px-3 py-2 text-gray-600" />
                  </div>
                )}
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Existing customer (optional)</label>
                  <select
                    value={saleForm.customer_id}
                    onChange={(e) => setSaleForm({...saleForm, customer_id: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  >
                    <option value="">New / not listed</option>
                    {customers.map(customer => (
                      <option key={customer.id} value={customer.id}>{customer.name} ({customer.code})</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Buyer name (saved to customers)</label>
                  <input
                    type="text"
                    value={saleForm.buyer_name}
                    onChange={(e) => setSaleForm({...saleForm, buyer_name: e.target.value})}
                    placeholder="Person / shop name"
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                  {saleForm.payment_method === 'mpesa' ? 'Phone for M-Pesa prompt' : 'Buyer phone'}
                </label>
                <input
                  type="tel"
                  value={saleForm.stk_phone || saleForm.buyer_phone}
                  onChange={(e) => {
                    const phone = e.target.value;
                    setSaleForm({ ...saleForm, stk_phone: phone, buyer_phone: phone });
                  }}
                  placeholder="07…"
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2"
                />
                {saleForm.payment_method === 'mpesa' && (
                  <p className="text-xs text-gray-500 mt-1">STK prompt is sent automatically — same number is saved as buyer phone.</p>
                )}
              </div>

              {saleForm.payment_method === 'credit' && director && (
                <div className="grid grid-cols-2 gap-4 bg-amber-50 border border-amber-200 rounded-lg p-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Signatory</label>
                    <input
                      required
                      type="text"
                      value={saleForm.signatory}
                      onChange={(e) => setSaleForm({...saleForm, signatory: e.target.value})}
                      placeholder="Who is accountable"
                      className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Repayment date (optional — leave blank for indefinite)</label>
                    <input
                      type="date"
                      value={saleForm.expected_repayment_date}
                      min={saleForm.order_date || todayStr()}
                      onChange={(e) => setSaleForm({...saleForm, expected_repayment_date: e.target.value})}
                      className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                    />
                  </div>
                </div>
              )}

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Price List</label>
                <select
                  value={saleForm.price_list_id}
                  onChange={(e) => setSaleForm({...saleForm, price_list_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  {priceLists.map(pl => <option key={pl.id} value={pl.id}>{pl.name}{pl.is_default ? ' (default)' : ''}</option>)}
                </select>
              </div>

              {/* Line items */}
              <div className="border border-gray-200 dark:border-gray-700 rounded-md">
                <div className="grid grid-cols-12 gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-900 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                  <div className="col-span-4">Product</div>
                  <div className="col-span-2">Qty Dispatched</div>
                  <div className="col-span-2">Qty Returned</div>
                  <div className="col-span-2">Unit Price</div>
                  <div className="col-span-1">Total</div>
                  <div className="col-span-1"></div>
                </div>
                {saleForm.items.map(item => {
                  const listPrice = listPriceFor(item.sku_id);
                  const overridden = !!item.sku_id && !!item.unit_price && !!listPrice &&
                    parseFloat(item.unit_price) !== parseFloat(listPrice);
                  return (
                    <div key={item.id} className="px-3 py-2 border-t border-gray-100">
                      <div className="grid grid-cols-12 gap-2 items-start">
                        <div className="col-span-4">
                          <select
                            required
                            value={item.sku_id}
                            onChange={(e) => {
                              const sku_id = e.target.value;
                              updateSaleItem(item.id, { sku_id, unit_price: listPriceFor(sku_id) || item.unit_price });
                            }}
                            className="block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          >
                            <option value="">Select product</option>
                            {skus.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                          </select>
                        </div>
                        <div className="col-span-2">
                          <input
                            type="number" min={1} required
                            value={item.qty}
                            onChange={(e) => updateSaleItem(item.id, { qty: parseInt(e.target.value) || 0 })}
                            className="block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                        </div>
                        <div className="col-span-2">
                          <input
                            type="number" min={0} max={item.qty}
                            value={item.qty_returned}
                            onChange={(e) => updateSaleItem(item.id, { qty_returned: parseInt(e.target.value) || 0 })}
                            className="block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                        </div>
                        <div className="col-span-2">
                          <input
                            type="number" min={0} step="0.01"
                            value={item.unit_price}
                            onChange={(e) => director && updateSaleItem(item.id, { unit_price: e.target.value })}
                            disabled={!director}
                            readOnly={!director}
                            placeholder={listPrice || '0.00'}
                            className="block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:cursor-not-allowed"
                          />
                        </div>
                        <div className="col-span-1 text-sm text-gray-700 dark:text-gray-300 pt-2">
                          {saleLineTotal(item).toLocaleString()}
                        </div>
                        <div className="col-span-1 pt-1">
                          <button type="button" onClick={() => removeSaleItem(item.id)} className="text-red-500 hover:text-red-700">
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </div>
                      {director && overridden && (
                        <input
                          type="text" required
                          value={item.override_reason}
                          onChange={(e) => updateSaleItem(item.id, { override_reason: e.target.value })}
                          placeholder="Reason for overriding the price list price (required)"
                          className="mt-2 block w-full border border-amber-300 bg-amber-50 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                        />
                      )}
                      {item.qty_returned > 0 && (
                        <p className="mt-1 text-xs text-blue-600 flex items-center">
                          <PackageMinus className="w-3 h-3 mr-1" />
                          {item.qty_returned} will be logged back into {warehouses.find(w => w.id === saleForm.warehouse_id)?.name || 'the outlet'}'s stock
                        </p>
                      )}
                    </div>
                  );
                })}
                <div className="px-3 py-2 border-t border-gray-100">
                  <button type="button" onClick={addSaleItem} className="text-sm text-blue-600 hover:text-blue-800 flex items-center">
                    <Plus className="w-4 h-4 mr-1" /> Add product
                  </button>
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea
                  value={saleForm.notes}
                  onChange={(e) => setSaleForm({...saleForm, notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={2}
                />
              </div>

              <div className="flex justify-between items-center pt-2 border-t border-gray-200 dark:border-gray-700">
                <div className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                  Total: KES {saleTotal.toLocaleString()}
                </div>
                <div className="flex space-x-3">
                  <button
                    type="button"
                    onClick={() => setShowSaleForm(false)}
                    className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={saleSubmitting}
                    className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50"
                  >
                    {saleSubmitting ? 'Logging...' : 'Log Sale'}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Round 2 Phase 6: credit sales are discouraged, not banned -- this
          is the deliberate confirmation step, not an easy default. */}
      {showCreditConfirm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <div className="flex items-center mb-4">
              <AlertTriangle className="w-6 h-6 text-amber-500 mr-2" />
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">This is a credit sale</h3>
            </div>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
              Confirm the signatory and repayment date before this goes on {customers.find(c => c.id === saleForm.customer_id)?.name || 'the customer'}'s account.
            </p>
            <dl className="space-y-2 text-sm bg-gray-50 dark:bg-gray-900 rounded-lg p-4 mb-4">
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Amount</dt><dd className="font-medium text-gray-900 dark:text-gray-100">KES {saleTotal.toLocaleString()}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Debtor</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{customers.find(c => c.id === saleForm.customer_id)?.name || '—'}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Signatory</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.signatory}</dd></div>
              <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Expected Repayment</dt><dd className="font-medium text-gray-900 dark:text-gray-100">{saleForm.expected_repayment_date}</dd></div>
            </dl>
            <div className="flex justify-end space-x-3">
              <button type="button" onClick={() => setShowCreditConfirm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Go Back
              </button>
              <button type="button" onClick={submitSale} disabled={saleSubmitting} className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50">
                {saleSubmitting ? 'Logging...' : 'Confirm Credit Sale'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Order Detail / Director alter modal */}
      {viewingOrder && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                {editingSale ? 'Alter sale' : viewingOrder.order_no}
              </h3>
              <button onClick={() => { setViewingOrder(null); setEditingSale(false); }} className="text-gray-400 dark:text-gray-500 hover:text-gray-600">
                <X className="w-5 h-5" />
              </button>
            </div>
            {editingSale && director ? (
              <div className="space-y-3 text-sm mb-4">
                <p className="text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/30 rounded px-3 py-2">
                  Director correction for test or mis-keyed entries. Wrong products: delete this sale and log again.
                </p>
                <div>
                  <label className="block text-gray-600 dark:text-gray-400 mb-1">Customer</label>
                  <select
                    value={editForm.customer_id}
                    onChange={(e) => setEditForm(f => ({ ...f, customer_id: e.target.value }))}
                    className="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-3 py-2"
                  >
                    <option value="">Walk-in / none</option>
                    {customers.map(c => (
                      <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-gray-600 dark:text-gray-400 mb-1">Payment</label>
                  <select
                    value={editForm.payment_method}
                    onChange={(e) => setEditForm(f => ({ ...f, payment_method: e.target.value as 'cash' | 'mpesa' | 'credit' }))}
                    className="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-3 py-2"
                  >
                    {(director || !cashLocked) && <option value="cash">Cash</option>}
                    <option value="mpesa">M-Pesa</option>
                    {director && <option value="credit">Credit</option>}
                  </select>
                  {cashLocked && !director && (
                    <p className="text-xs text-amber-700 mt-1">{CONTACT_ADMIN_MSG}</p>
                  )}
                </div>
                <div>
                  <label className="block text-gray-600 dark:text-gray-400 mb-1">Reference</label>
                  <input
                    type="text"
                    value={editForm.payment_reference}
                    onChange={(e) => setEditForm(f => ({ ...f, payment_reference: e.target.value }))}
                    className="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-3 py-2"
                    placeholder="M-Pesa code / receipt"
                  />
                </div>
                {viewingOrder.source !== 'field' && (
                  <div>
                    <label className="block text-gray-600 dark:text-gray-400 mb-1">Date</label>
                    <input
                      type="date"
                      value={editForm.order_date}
                      onChange={(e) => setEditForm(f => ({ ...f, order_date: e.target.value }))}
                      className="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-3 py-2"
                    />
                  </div>
                )}
                {viewingOrder.source === 'field' && (
                  <div>
                    <label className="block text-gray-600 dark:text-gray-400 mb-1">Amount (KES)</label>
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={editForm.amount}
                      onChange={(e) => setEditForm(f => ({ ...f, amount: e.target.value }))}
                      className="w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md px-3 py-2"
                    />
                  </div>
                )}
              </div>
            ) : (
              <>
                <div className="space-y-1 text-sm text-gray-700 dark:text-gray-300 mb-4">
                  <p><span className="text-gray-500 dark:text-gray-400">Outlet:</span> {outletLabel(viewingOrder)}</p>
                  <p><span className="text-gray-500 dark:text-gray-400">Customer:</span> {viewingOrder.customer?.name || 'Walk-in'}</p>
                  <p><span className="text-gray-500 dark:text-gray-400">Payment:</span> {viewingOrder.payment_method ? (PAYMENT_METHOD_LABELS[viewingOrder.payment_method] || viewingOrder.payment_method) : '—'}
                    {viewingOrder.payment_reference ? ` (${viewingOrder.payment_reference})` : ''}</p>
                  <p><span className="text-gray-500 dark:text-gray-400">Date:</span> {new Date(viewingOrder.order_date).toLocaleDateString()}</p>
                  {viewingOrder.invoice && (
                    <p><span className="text-gray-500 dark:text-gray-400">Invoice:</span> {viewingOrder.invoice.invoice_no} (due {new Date(viewingOrder.invoice.due_date).toLocaleDateString()})</p>
                  )}
                </div>
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                      <th className="pb-2">Product</th>
                      <th className="pb-2">Qty</th>
                      <th className="pb-2">Returned</th>
                      <th className="pb-2">Unit Price</th>
                      <th className="pb-2 text-right">Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {!(viewingOrder.items && viewingOrder.items.length) ? (
                      <tr>
                        <td colSpan={5} className="py-3 text-sm text-gray-400 dark:text-gray-500">
                          {openingOrderId === viewingOrder.id ? 'Loading line items…' : 'No line items'}
                        </td>
                      </tr>
                    ) : (
                      (viewingOrder.items || []).map((item: any) => (
                        <tr key={item.id || item.sku_name || item.sku?.id}>
                          <td className="py-1.5">{item.sku?.name || item.sku_name || '—'}</td>
                          <td className="py-1.5">{item.qty ?? '—'}</td>
                          <td className="py-1.5">{item.qty_returned ?? '—'}</td>
                          <td className="py-1.5">{item.unit_price != null ? Number(item.unit_price).toLocaleString() : '—'}</td>
                          <td className="py-1.5 text-right">{item.line_total != null ? Number(item.line_total).toLocaleString() : '—'}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
                <div className="flex justify-end mt-4 pt-3 border-t border-gray-200 dark:border-gray-700 text-lg font-semibold text-gray-900 dark:text-gray-100">
                  Total: KES {Number(viewingOrder.total_amount).toLocaleString()}
                </div>
              </>
            )}
            <div className="flex justify-between items-center mt-5 pt-3 border-t border-gray-200 dark:border-gray-700">
              {director ? (
                <button
                  type="button"
                  onClick={() => deleteSale(viewingOrder)}
                  disabled={deletingSaleId === viewingOrder.id}
                  className="px-3 py-2 text-sm text-red-700 border border-red-300 rounded-md hover:bg-red-50 disabled:opacity-40"
                >
                  Delete sale
                </button>
              ) : <span />}
              <div className="flex gap-2">
                {editingSale ? (
                  <>
                    <button type="button" onClick={() => setEditingSale(false)} className="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-md">Cancel</button>
                    <button type="button" onClick={saveEditSale} disabled={savingEdit} className="px-3 py-2 text-sm bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50">
                      {savingEdit ? 'Saving…' : 'Save changes'}
                    </button>
                  </>
                ) : (
                  <>
                    {director && (
                      <button type="button" onClick={() => openEditSale(viewingOrder)} className="px-3 py-2 text-sm border border-amber-300 text-amber-800 rounded-md hover:bg-amber-50">
                        Alter
                      </button>
                    )}
                    <button type="button" onClick={() => { setViewingOrder(null); setEditingSale(false); }} className="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-md">Close</button>
                  </>
                )}
              </div>
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
            setShowSaleForm(false);
            fetchData();
          }}
        />
      )}
    </PageShell>
  );
};

export default SalesPage;
