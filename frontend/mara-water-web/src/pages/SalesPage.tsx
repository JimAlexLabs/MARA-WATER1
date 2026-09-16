import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
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
  PackageMinus
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

interface Customer {
  id: string;
  code: string;
  name: string;
  contact_person?: string | null;
  type: 'retail' | 'wholesale' | 'corporate' | 'hotel_restaurant';
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
};

const EMPTY_CUSTOMER_FORM = {
  code: '', name: '', contact_person: '', type: 'retail', phone: '', email: '',
  address: '', route_id: '', price_tier: 'standard', preferred_products: '',
  typical_order_size: '', payment_terms: '', notes: '', status: 'active',
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
  status: 'draft' | 'confirmed' | 'dispatched' | 'delivered' | 'partially_returned' | 'cancelled';
  order_date: string;
  // Laravel serializes decimal-cast columns as JSON strings, not numbers.
  total_amount: string;
  payment_method?: 'cash' | 'mpesa' | 'credit' | null;
  payment_reference?: string | null;
  warehouse?: Warehouse | null;
  items?: OrderItemRow[];
  invoice?: { invoice_no: string; due_date: string } | null;
  sales_officer: {
    first_name: string;
    last_name: string;
  };
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
  order_date: '',
  notes: '',
  items: [newSaleItem()],
};

const SalesPage: React.FC = () => {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [orders, setOrders] = useState<Order[]>([]);
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
  const [activeTab, setActiveTab] = useState('orders');
  const [showCustomerForm, setShowCustomerForm] = useState(false);
  const [showSaleForm, setShowSaleForm] = useState(false);
  const [viewingOrder, setViewingOrder] = useState<Order | null>(null);
  const [editingCustomerId, setEditingCustomerId] = useState<string | null>(null);
  const [routes, setRoutes] = useState<{ id: string; name: string }[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [skus, setSkus] = useState<Sku[]>([]);
  const [priceLists, setPriceLists] = useState<PriceList[]>([]);
  const [priceListPrices, setPriceListPrices] = useState<Record<string, string>>({});
  const [saleSubmitting, setSaleSubmitting] = useState(false);

  // Customer Form State
  const [customerForm, setCustomerForm] = useState(EMPTY_CUSTOMER_FORM);

  // Log a Sale Form State
  const [saleForm, setSaleForm] = useState(EMPTY_SALE_FORM);

  useEffect(() => {
    fetchData();
    api.get('/fleet/routes').then(res => setRoutes(res.data.data)).catch(() => {});
    api.get('/inventory/warehouses').then(res => setWarehouses(res.data.data)).catch(() => {});
    api.get('/fleet/skus').then(res => setSkus(res.data.data)).catch(() => {});
    api.get('/sales/price-lists').then(res => {
      const lists: PriceList[] = res.data.data;
      setPriceLists(lists);
      const defaultList = lists.find(l => l.is_default) || lists[0];
      if (defaultList) {
        setSaleForm(f => ({ ...f, price_list_id: defaultList.id }));
      }
    }).catch(() => {});
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
      const [customersResponse, ordersResponse] = await Promise.all([
        api.get('/sales/customers'),
        api.get('/sales/orders')
      ]);

      setCustomers(customersResponse.data.data);
      setOrders(ordersResponse.data.data);
    } catch (error) {
      toast.error('Failed to fetch sales data');
    } finally {
      setLoading(false);
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
      code: c.code, name: c.name, contact_person: c.contact_person || '', type: c.type,
      phone: c.phone || '', email: c.email || '', address: c.address || '',
      route_id: c.route?.id || '', price_tier: c.price_tier || 'standard',
      preferred_products: c.preferred_products || '', typical_order_size: c.typical_order_size || '',
      payment_terms: c.payment_terms || '', notes: c.notes || '', status: c.status || 'active',
    });
    setShowCustomerForm(true);
  };

  const handleCustomerSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      if (editingCustomerId) {
        await api.put(`/sales/customers/${editingCustomerId}`, customerForm);
        toast.success('Customer updated successfully');
      } else {
        await api.post('/sales/customers', customerForm);
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
      toast.success('Customer removed');
      fetchData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to remove customer');
    }
  };

  const openLogSale = () => {
    const defaultList = priceLists.find(l => l.is_default) || priceLists[0];
    setSaleForm({ ...EMPTY_SALE_FORM, price_list_id: defaultList?.id || '', items: [newSaleItem()] });
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

  const handleSaleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (saleForm.payment_method === 'credit' && !saleForm.customer_id) {
      toast.error('Select a customer for a credit sale');
      return;
    }
    setSaleSubmitting(true);
    try {
      const payload = {
        warehouse_id: saleForm.warehouse_id,
        customer_id: saleForm.customer_id || null,
        price_list_id: saleForm.price_list_id || null,
        payment_method: saleForm.payment_method,
        payment_reference: saleForm.payment_reference || null,
        order_date: saleForm.order_date || null,
        notes: saleForm.notes || null,
        items: saleForm.items.map(i => ({
          sku_id: i.sku_id,
          qty: i.qty,
          qty_returned: i.qty_returned || 0,
          unit_price: i.unit_price ? parseFloat(i.unit_price) : undefined,
          override_reason: i.override_reason || undefined,
        })),
      };
      const res = await api.post('/sales/orders/log-sale', payload);
      const orderNo = res.data?.data?.order?.order_no;
      toast.success(`Sale logged${orderNo ? ` (${orderNo})` : ''}`);
      setShowSaleForm(false);
      fetchData();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to log sale');
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
      case 'draft': return 'text-gray-600 bg-gray-100';
      case 'confirmed': return 'text-blue-600 bg-blue-100';
      case 'dispatched': return 'text-yellow-600 bg-yellow-100';
      case 'delivered': return 'text-green-600 bg-green-100';
      case 'cancelled': return 'text-red-600 bg-red-100';
      default: return 'text-gray-600 bg-gray-100';
    }
  };

  const getTypeColor = (type: string) => {
    switch (type) {
      case 'retail': return 'text-blue-600 bg-blue-100';
      case 'wholesale': return 'text-green-600 bg-green-100';
      case 'corporate': return 'text-purple-600 bg-purple-100';
      case 'hotel_restaurant': return 'text-orange-600 bg-orange-100';
      default: return 'text-gray-600 bg-gray-100';
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
          <h1 className="text-2xl font-bold text-gray-900">Sales Management</h1>
          <p className="text-gray-600">Manage customers and orders</p>
        </div>
        <div className="flex space-x-3">
          <button
            onClick={openNewCustomer}
            className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            <Plus className="w-4 h-4 mr-2" />
            New Customer
          </button>
          <button
            onClick={openLogSale}
            className="flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
          >
            <Plus className="w-4 h-4 mr-2" />
            Log a Sale
          </button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <ShoppingCart className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Orders</p>
              <p className="text-2xl font-bold text-gray-900">{orders.length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <DollarSign className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Revenue</p>
              <p className="text-2xl font-bold text-gray-900">
                KES {orders.reduce((sum, order) => sum + Number(order.total_amount), 0).toLocaleString()}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Users className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Total Customers</p>
              <p className="text-2xl font-bold text-gray-900">{customers.length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white p-6 rounded-lg shadow">
          <div className="flex items-center">
            <TrendingUp className="w-8 h-8 text-orange-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600">Avg Order Value</p>
              <p className="text-2xl font-bold text-gray-900">
                KES {orders.length > 0 ? (orders.reduce((sum, order) => sum + Number(order.total_amount), 0) / orders.length).toFixed(0) : 0}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white rounded-lg shadow">
        <div className="border-b border-gray-200">
          <nav className="flex space-x-8 px-6">
            <button
              onClick={() => setActiveTab('orders')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'orders'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Orders ({orders.length})
            </button>
            <button
              onClick={() => setActiveTab('customers')}
              className={`py-4 px-1 border-b-2 font-medium text-sm ${
                activeTab === 'customers'
                  ? 'border-blue-500 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              Customers ({customers.length})
            </button>
          </nav>
        </div>

        <div className="p-6">
          {/* Search and Filters */}
          <div className="flex flex-col md:flex-row gap-4 mb-6">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4" />
                <input
                  type="text"
                  placeholder={`Search ${activeTab}...`}
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>
            <div className="flex gap-2">
              {activeTab === 'orders' && (
                <select
                  value={filterStatus}
                  onChange={(e) => setFilterStatus(e.target.value)}
                  className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
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

          {/* Orders Table */}
          {activeTab === 'orders' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Order Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Outlet
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Customer
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Amount (KES)
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Payment
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Sales Officer
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {filteredOrders.map((order) => (
                    <tr key={order.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900">{order.order_no}</div>
                          <div className="text-sm text-gray-500">
                            {new Date(order.order_date).toLocaleDateString()}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {order.warehouse?.name || '—'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900">{order.customer?.name || 'Walk-in'}</div>
                          <div className="text-sm text-gray-500">{order.customer?.code || ''}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                        KES {Number(order.total_amount).toLocaleString()}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        {order.payment_method ? (
                          <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${
                            order.payment_method === 'credit' ? 'text-red-600 bg-red-100' : 'text-green-600 bg-green-100'
                          }`}>
                            {PAYMENT_METHOD_LABELS[order.payment_method] || order.payment_method}
                          </span>
                        ) : <span className="text-gray-400 text-sm">—</span>}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusColor(order.status)}`}>
                          {order.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {order.sales_officer.first_name} {order.sales_officer.last_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center space-x-2">
                          <button onClick={() => setViewingOrder(order)} className="text-blue-600 hover:text-blue-900">
                            <Eye className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Customers Table */}
          {activeTab === 'customers' && (
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Customer Details
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Contact
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Type
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Route
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Debtor Balance
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {filteredCustomers.map((customer) => (
                    <tr key={customer.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">{customer.name}</div>
                        <div className="text-sm text-gray-500">{customer.code}{customer.contact_person ? ` · ${customer.contact_person}` : ''}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900">{customer.phone}</div>
                        <div className="text-sm text-gray-500">{customer.email}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${getTypeColor(customer.type)}`}>
                          {CUSTOMER_TYPE_LABELS[customer.type] || customer.type}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {customer.route?.name || 'No Route'}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm">
                        <span className={(customer.debtor_balance || 0) > 0 ? 'text-red-600 font-medium' : 'text-gray-500'}>
                          KES {(customer.debtor_balance || 0).toLocaleString()}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${customer.status === 'inactive' ? 'text-gray-600 bg-gray-100' : 'text-green-600 bg-green-100'}`}>
                          {customer.status || 'active'}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <div className="flex items-center space-x-3">
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
            </div>
          )}
        </div>
      </div>

      {/* Customer Form Modal */}
      {showCustomerForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <h3 className="text-lg font-medium text-gray-900 mb-4">{editingCustomerId ? 'Edit Customer' : 'New Customer'}</h3>
            <form onSubmit={handleCustomerSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Customer Code</label>
                  <input
                    required
                    type="text"
                    value={customerForm.code}
                    onChange={(e) => setCustomerForm({...customerForm, code: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Type</label>
                  <select
                    value={customerForm.type}
                    onChange={(e) => setCustomerForm({...customerForm, type: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    {Object.entries(CUSTOMER_TYPE_LABELS).map(([value, label]) => (
                      <option key={value} value={value}>{label}</option>
                    ))}
                  </select>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Organization / Shop Name</label>
                <input
                  required
                  type="text"
                  value={customerForm.name}
                  onChange={(e) => setCustomerForm({...customerForm, name: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Contact Person</label>
                <input
                  type="text"
                  value={customerForm.contact_person}
                  onChange={(e) => setCustomerForm({...customerForm, contact_person: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Phone</label>
                  <input
                    type="tel"
                    value={customerForm.phone}
                    onChange={(e) => setCustomerForm({...customerForm, phone: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Email</label>
                  <input
                    type="email"
                    value={customerForm.email}
                    onChange={(e) => setCustomerForm({...customerForm, email: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Delivery Address</label>
                <textarea
                  value={customerForm.address}
                  onChange={(e) => setCustomerForm({...customerForm, address: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={2}
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Route / Zone</label>
                  <select
                    value={customerForm.route_id}
                    onChange={(e) => setCustomerForm({...customerForm, route_id: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">No route</option>
                    {routes.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Price Tier</label>
                  <select
                    value={customerForm.price_tier}
                    onChange={(e) => setCustomerForm({...customerForm, price_tier: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="standard">Standard</option>
                    <option value="premium">Premium</option>
                    <option value="wholesale">Wholesale</option>
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Preferred Products</label>
                  <input
                    type="text"
                    placeholder="e.g. Premium 1L, 5L"
                    value={customerForm.preferred_products}
                    onChange={(e) => setCustomerForm({...customerForm, preferred_products: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Typical Order Size</label>
                  <input
                    type="text"
                    placeholder="e.g. 50 crates/week"
                    value={customerForm.typical_order_size}
                    onChange={(e) => setCustomerForm({...customerForm, typical_order_size: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Payment Terms</label>
                  <select
                    value={customerForm.payment_terms}
                    onChange={(e) => setCustomerForm({...customerForm, payment_terms: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">Not set</option>
                    <option value="cash">Cash</option>
                    <option value="mpesa">M-Pesa</option>
                    <option value="credit">Credit / Debtor Account</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Status</label>
                  <select
                    value={customerForm.status}
                    onChange={(e) => setCustomerForm({...customerForm, status: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700">Notes</label>
                <textarea
                  value={customerForm.notes}
                  onChange={(e) => setCustomerForm({...customerForm, notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={2}
                  placeholder="Interaction history, preferences, anything worth remembering..."
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowCustomerForm(false)}
                  className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                >
                  {editingCustomerId ? 'Save Changes' : 'Create Customer'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Log a Sale Modal */}
      {showSaleForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900">Log a Sale</h3>
              <button onClick={() => setShowSaleForm(false)} className="text-gray-400 hover:text-gray-600">
                <X className="w-5 h-5" />
              </button>
            </div>
            <form onSubmit={handleSaleSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Outlet / Branch</label>
                  <select
                    required
                    value={saleForm.warehouse_id}
                    onChange={(e) => setSaleForm({...saleForm, warehouse_id: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">Select outlet</option>
                    {warehouses.map(w => <option key={w.id} value={w.id}>{w.name} ({w.code})</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Date</label>
                  <input
                    type="date"
                    value={saleForm.order_date}
                    onChange={(e) => setSaleForm({...saleForm, order_date: e.target.value})}
                    placeholder="Today"
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700">Payment Method</label>
                  <select
                    value={saleForm.payment_method}
                    onChange={(e) => setSaleForm({...saleForm, payment_method: e.target.value as any})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="cash">Cash</option>
                    <option value="mpesa">M-Pesa</option>
                    <option value="credit">Credit (to customer account)</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">
                    Customer {saleForm.payment_method === 'credit' ? '(required)' : '(optional)'}
                  </label>
                  <select
                    required={saleForm.payment_method === 'credit'}
                    value={saleForm.customer_id}
                    onChange={(e) => setSaleForm({...saleForm, customer_id: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="">{saleForm.payment_method === 'credit' ? 'Select customer' : 'Walk-in / none'}</option>
                    {customers.map(customer => (
                      <option key={customer.id} value={customer.id}>{customer.name} ({customer.code})</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700">Reference / Receipt No.</label>
                  <input
                    type="text"
                    value={saleForm.payment_reference}
                    onChange={(e) => setSaleForm({...saleForm, payment_reference: e.target.value})}
                    placeholder="e.g. M-Pesa code"
                    className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700">Price List</label>
                <select
                  value={saleForm.price_list_id}
                  onChange={(e) => setSaleForm({...saleForm, price_list_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  {priceLists.map(pl => <option key={pl.id} value={pl.id}>{pl.name}{pl.is_default ? ' (default)' : ''}</option>)}
                </select>
              </div>

              {/* Line items */}
              <div className="border border-gray-200 rounded-md">
                <div className="grid grid-cols-12 gap-2 px-3 py-2 bg-gray-50 text-xs font-medium text-gray-500 uppercase">
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
                            className="block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
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
                            className="block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                        </div>
                        <div className="col-span-2">
                          <input
                            type="number" min={0} max={item.qty}
                            value={item.qty_returned}
                            onChange={(e) => updateSaleItem(item.id, { qty_returned: parseInt(e.target.value) || 0 })}
                            className="block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                        </div>
                        <div className="col-span-2">
                          <input
                            type="number" min={0} step="0.01"
                            value={item.unit_price}
                            onChange={(e) => updateSaleItem(item.id, { unit_price: e.target.value })}
                            placeholder={listPrice || '0.00'}
                            className="block w-full border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                          />
                        </div>
                        <div className="col-span-1 text-sm text-gray-700 pt-2">
                          {saleLineTotal(item).toLocaleString()}
                        </div>
                        <div className="col-span-1 pt-1">
                          <button type="button" onClick={() => removeSaleItem(item.id)} className="text-red-500 hover:text-red-700">
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </div>
                      {overridden && (
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
                <label className="block text-sm font-medium text-gray-700">Notes</label>
                <textarea
                  value={saleForm.notes}
                  onChange={(e) => setSaleForm({...saleForm, notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={2}
                />
              </div>

              <div className="flex justify-between items-center pt-2 border-t border-gray-200">
                <div className="text-lg font-semibold text-gray-900">
                  Total: KES {saleTotal.toLocaleString()}
                </div>
                <div className="flex space-x-3">
                  <button
                    type="button"
                    onClick={() => setShowSaleForm(false)}
                    className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
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

      {/* Order Detail Modal */}
      {viewingOrder && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900">{viewingOrder.order_no}</h3>
              <button onClick={() => setViewingOrder(null)} className="text-gray-400 hover:text-gray-600">
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="space-y-1 text-sm text-gray-700 mb-4">
              <p><span className="text-gray-500">Outlet:</span> {viewingOrder.warehouse?.name || '—'}</p>
              <p><span className="text-gray-500">Customer:</span> {viewingOrder.customer?.name || 'Walk-in'}</p>
              <p><span className="text-gray-500">Payment:</span> {viewingOrder.payment_method ? (PAYMENT_METHOD_LABELS[viewingOrder.payment_method] || viewingOrder.payment_method) : '—'}
                {viewingOrder.payment_reference ? ` (${viewingOrder.payment_reference})` : ''}</p>
              <p><span className="text-gray-500">Date:</span> {new Date(viewingOrder.order_date).toLocaleDateString()}</p>
              {viewingOrder.invoice && (
                <p><span className="text-gray-500">Invoice:</span> {viewingOrder.invoice.invoice_no} (due {new Date(viewingOrder.invoice.due_date).toLocaleDateString()})</p>
              )}
            </div>
            <table className="min-w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-gray-500 uppercase">
                  <th className="pb-2">Product</th>
                  <th className="pb-2">Dispatched</th>
                  <th className="pb-2">Returned</th>
                  <th className="pb-2">Unit Price</th>
                  <th className="pb-2 text-right">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {(viewingOrder.items || []).map(item => (
                  <tr key={item.id}>
                    <td className="py-1.5">{item.sku?.name}</td>
                    <td className="py-1.5">{item.qty}</td>
                    <td className="py-1.5">{item.qty_returned}</td>
                    <td className="py-1.5">{Number(item.unit_price).toLocaleString()}</td>
                    <td className="py-1.5 text-right">{Number(item.line_total).toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="flex justify-end mt-4 pt-3 border-t border-gray-200 text-lg font-semibold text-gray-900">
              Total: KES {Number(viewingOrder.total_amount).toLocaleString()}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default SalesPage;
