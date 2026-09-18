import React, { useState, useEffect } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import {
  Plus,
  Search,
  Filter,
  Download,
  DollarSign,
  TrendingUp,
  AlertTriangle,
  CheckCircle,
  Clock,
  Eye,
  Edit,
  Trash2,
  X,
  BookOpen
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

interface Invoice {
  id: string;
  invoice_no: string;
  customer: {
    name: string;
    code: string;
  };
  invoice_date: string;
  due_date: string;
  subtotal: number | string;
  tax_amount: number | string;
  total_amount: number | string;
  payment_status: 'pending' | 'paid' | 'overdue';
  payment_method: string;
  created_by: {
    first_name: string;
    last_name: string;
  };
}

interface Account {
  id: string;
  code: string;
  description: string;
  category: string;
  is_active?: boolean;
}

interface PettyCashEntryRow {
  id: string;
  entry_date: string;
  mpesa_reference?: string | null;
  account: Account;
  description: string;
  requestor?: { first_name: string; last_name: string } | null;
  requestor_name?: string | null;
  amount_in: string;
  amount_out: string;
  running_balance: number;
}

interface CustomerRow {
  id: string;
  code: string;
  name: string;
  debtor_balance?: number;
}

interface DebtorLedgerRow {
  id: string;
  entry_date: string;
  details: string;
  reference_no?: string | null;
  voucher_no?: string | null;
  debt_id?: string | null;
  debit: string;
  credit: string;
  running_balance: number;
}

interface OpenDebt {
  id: string;
  principal: string;
  balance: string;
  days_overdue: number;
  invoice?: { invoice_no: string; due_date: string } | null;
}

interface PerBottleCostRow {
  sku_id: string;
  sku: { id: string; code: string; name: string; brand: string | null };
  material_cost: number;
  has_bom: boolean;
  bom_lines: { material: string; qty_per_unit: number; unit_cost: number; line_cost: number }[];
}

interface ProfitLoss {
  period: { from: string; to: string };
  revenue: number;
  cogs: number;
  gross_margin: number;
  gross_margin_pct: number | null;
  monthly_payroll: number;
  overhead_expenses: number;
  net_margin: number;
}

// Round 3 Phase 9: shared blob-download helper -- exact-format .xlsx
// exports, same pattern used everywhere else this round (auth header
// can't ride a plain <a href>, so fetch as a blob instead).
const downloadBlob = (path: string, params: Record<string, string>, filename: string, onError: () => void) => {
  api.get(path, { params, responseType: 'blob' }).then((res) => {
    const url = window.URL.createObjectURL(new Blob([res.data]));
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
  }).catch(onError);
};

const FinancePage: React.FC = () => {
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [searchParams] = useSearchParams();

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
  }, [searchParams]);
  const [filterStatus, setFilterStatus] = useState('all');
  const [showInvoiceForm, setShowInvoiceForm] = useState(false);
  const [invoiceForm, setInvoiceForm] = useState({
    customer_id: '',
    invoice_date: '',
    due_date: '',
    subtotal: '',
    tax_amount: '',
    notes: ''
  });
  const [activeTab, setActiveTab] = useState<'invoices' | 'pettycash' | 'debtors' | 'costing'>('invoices');
  const [customers, setCustomers] = useState<CustomerRow[]>([]);
  const [accounts, setAccounts] = useState<Account[]>([]);

  // Round 2 Phase 12: Chart of Accounts management -- only a starter set
  // was seeded (the real ~200-code list wasn't available), and full
  // account management existed on the backend but was never actually
  // reachable from the UI. This is what makes "enter the real list
  // later" actually possible.
  const [showAccountsManager, setShowAccountsManager] = useState(false);
  const [showAccountForm, setShowAccountForm] = useState(false);
  const [editingAccountId, setEditingAccountId] = useState<string | null>(null);
  const [accountForm, setAccountForm] = useState({ code: '', description: '', category: 'expense' });
  const [savingAccount, setSavingAccount] = useState(false);

  // Petty Cash state
  const [pettyCashEntries, setPettyCashEntries] = useState<PettyCashEntryRow[]>([]);
  const [pettyCashOpening, setPettyCashOpening] = useState(0);
  const [pettyCashClosing, setPettyCashClosing] = useState(0);
  const [showPettyCashForm, setShowPettyCashForm] = useState(false);
  const [pettyCashForm, setPettyCashForm] = useState({
    entry_date: new Date().toISOString().slice(0, 10), mpesa_reference: '', account_id: '',
    description: '', requestor_name: '', direction: 'out' as 'in' | 'out', amount: '',
  });

  // Debtors state
  const [debtorCustomerId, setDebtorCustomerId] = useState('');
  const [debtorLedger, setDebtorLedger] = useState<DebtorLedgerRow[]>([]);
  const [debtorBalance, setDebtorBalance] = useState(0);
  const [openDebts, setOpenDebts] = useState<OpenDebt[]>([]);
  const [showPaymentForm, setShowPaymentForm] = useState(false);
  const [paymentForm, setPaymentForm] = useState({ debt_id: '', entry_date: new Date().toISOString().slice(0, 10), amount: '', reference_no: '', voucher_no: '' });
  const [showManualLedgerForm, setShowManualLedgerForm] = useState(false);
  const [manualLedgerForm, setManualLedgerForm] = useState({ entry_date: new Date().toISOString().slice(0, 10), details: '', reference_no: '', voucher_no: '', direction: 'debit' as 'debit' | 'credit', amount: '' });

  // Costing state
  const [perBottleCosts, setPerBottleCosts] = useState<PerBottleCostRow[]>([]);
  const [profitLoss, setProfitLoss] = useState<ProfitLoss | null>(null);
  const [plDateFrom, setPlDateFrom] = useState(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10));
  const [plDateTo, setPlDateTo] = useState(new Date().toISOString().slice(0, 10));

  useEffect(() => {
    fetchData();
    api.get('/sales/customers?limit=500').then(res => setCustomers(res.data.data)).catch(() => {});
    api.get('/finance/accounts').then(res => setAccounts(res.data.data)).catch(() => {});
  }, []);

  useEffect(() => {
    if (activeTab === 'pettycash') fetchPettyCash();
    if (activeTab === 'costing') fetchCosting();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab]);

  useEffect(() => {
    if (!debtorCustomerId) return;
    fetchDebtorLedger(debtorCustomerId);
    // Round 3 Phase 3: a debt sale logged from a driver's trip should
    // show up here without a manual refresh -- same polling approach
    // Analytics/Sales already use for this, not a second mechanism.
    const interval = setInterval(() => fetchDebtorLedger(debtorCustomerId), 20000);
    return () => clearInterval(interval);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debtorCustomerId]);

  const fetchData = async () => {
    try {
      setLoading(true);
      const response = await api.get('/finance/invoices');
      setInvoices(response.data.data);
    } catch (error) {
      toast.error('Failed to fetch finance data');
    } finally {
      setLoading(false);
    }
  };

  const fetchPettyCash = async () => {
    try {
      const res = await api.get('/finance/petty-cash');
      setPettyCashEntries(res.data.data.entries);
      setPettyCashOpening(res.data.data.opening_balance);
      setPettyCashClosing(res.data.data.closing_balance);
    } catch (error) {
      toast.error('Failed to fetch petty cash');
    }
  };

  const handlePettyCashSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api.post('/finance/petty-cash', {
        entry_date: pettyCashForm.entry_date,
        mpesa_reference: pettyCashForm.mpesa_reference || null,
        account_id: pettyCashForm.account_id,
        description: pettyCashForm.description,
        requestor_name: pettyCashForm.requestor_name || null,
        amount_in: pettyCashForm.direction === 'in' ? pettyCashForm.amount : 0,
        amount_out: pettyCashForm.direction === 'out' ? pettyCashForm.amount : 0,
      });
      toast.success('Entry recorded');
      setShowPettyCashForm(false);
      setPettyCashForm({ entry_date: new Date().toISOString().slice(0, 10), mpesa_reference: '', account_id: '', description: '', requestor_name: '', direction: 'out', amount: '' });
      fetchPettyCash();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to record entry');
    }
  };

  const handleDeletePettyCash = async (id: string) => {
    if (!window.confirm('Remove this petty cash entry?')) return;
    try {
      await api.delete(`/finance/petty-cash/${id}`);
      fetchPettyCash();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to remove entry');
    }
  };

  const fetchAccounts = () => {
    api.get('/finance/accounts', { params: { include_inactive: true } }).then(res => setAccounts(res.data.data)).catch(() => {});
  };

  const openNewAccount = () => {
    setEditingAccountId(null);
    setAccountForm({ code: '', description: '', category: 'expense' });
    setShowAccountForm(true);
  };

  const openEditAccount = (a: Account) => {
    setEditingAccountId(a.id);
    setAccountForm({ code: a.code, description: a.description, category: a.category });
    setShowAccountForm(true);
  };

  const handleAccountSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingAccount(true);
    try {
      if (editingAccountId) {
        await api.put(`/finance/accounts/${editingAccountId}`, accountForm);
        toast.success('Account updated');
      } else {
        await api.post('/finance/accounts', accountForm);
        toast.success('Account added');
      }
      setShowAccountForm(false);
      fetchAccounts();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to save account');
    } finally {
      setSavingAccount(false);
    }
  };

  const toggleAccountActive = async (a: Account) => {
    try {
      await api.put(`/finance/accounts/${a.id}`, { is_active: !a.is_active });
      fetchAccounts();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update account');
    }
  };

  const fetchDebtorLedger = async (customerId: string) => {
    try {
      const [ledgerRes, debtsRes] = await Promise.all([
        api.get(`/finance/debtors/${customerId}/ledger`),
        api.get(`/finance/debtors/${customerId}/open-debts`),
      ]);
      setDebtorLedger(ledgerRes.data.data.entries);
      setDebtorBalance(ledgerRes.data.data.balance);
      setOpenDebts(debtsRes.data.data);
    } catch (error) {
      toast.error('Failed to fetch debtor ledger');
    }
  };

  const handleRecordPayment = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!paymentForm.debt_id) { toast.error('Select which invoice this payment is for'); return; }
    try {
      await api.post(`/finance/debtors/debts/${paymentForm.debt_id}/pay`, {
        entry_date: paymentForm.entry_date, amount: paymentForm.amount,
        reference_no: paymentForm.reference_no || null, voucher_no: paymentForm.voucher_no || null,
      });
      toast.success('Payment recorded');
      setShowPaymentForm(false);
      setPaymentForm({ debt_id: '', entry_date: new Date().toISOString().slice(0, 10), amount: '', reference_no: '', voucher_no: '' });
      fetchDebtorLedger(debtorCustomerId);
      api.get('/sales/customers?limit=500').then(res => setCustomers(res.data.data)).catch(() => {});
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to record payment');
    }
  };

  const handleManualLedgerSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api.post('/finance/debtors/ledger', {
        customer_id: debtorCustomerId, entry_date: manualLedgerForm.entry_date, details: manualLedgerForm.details,
        reference_no: manualLedgerForm.reference_no || null, voucher_no: manualLedgerForm.voucher_no || null,
        debit: manualLedgerForm.direction === 'debit' ? manualLedgerForm.amount : 0,
        credit: manualLedgerForm.direction === 'credit' ? manualLedgerForm.amount : 0,
      });
      toast.success('Ledger entry recorded');
      setShowManualLedgerForm(false);
      setManualLedgerForm({ entry_date: new Date().toISOString().slice(0, 10), details: '', reference_no: '', voucher_no: '', direction: 'debit', amount: '' });
      fetchDebtorLedger(debtorCustomerId);
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to record entry');
    }
  };

  const fetchCosting = async () => {
    try {
      const [costRes, plRes] = await Promise.all([
        api.get('/finance/costing/per-bottle'),
        api.get(`/finance/costing/profit-loss?date_from=${plDateFrom}&date_to=${plDateTo}`),
      ]);
      setPerBottleCosts(costRes.data.data);
      setProfitLoss(plRes.data.data);
    } catch (error) {
      toast.error('Failed to fetch costing data');
    }
  };

  const handleInvoiceSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await api.post('/finance/invoices', invoiceForm);
      toast.success('Invoice created successfully');
      setShowInvoiceForm(false);
      setInvoiceForm({
        customer_id: '',
        invoice_date: '',
        due_date: '',
        subtotal: '',
        tax_amount: '',
        notes: ''
      });
      fetchData();
    } catch (error) {
      toast.error('Failed to create invoice');
    }
  };

  const filteredInvoices = invoices.filter(invoice => {
    const matchesSearch = (invoice.customer?.name ?? '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                         invoice.invoice_no.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'all' || invoice.payment_status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'pending': return 'text-yellow-600 bg-yellow-100';
      case 'paid': return 'text-green-600 bg-green-100';
      case 'overdue': return 'text-red-600 bg-red-100';
      default: return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'pending': return <Clock className="w-4 h-4" />;
      case 'paid': return <CheckCircle className="w-4 h-4" />;
      case 'overdue': return <AlertTriangle className="w-4 h-4" />;
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
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Finance Management</h1>
          <p className="text-gray-600 dark:text-gray-400">Invoices, petty cash, debtors ledger, and costing</p>
        </div>
        <div className="flex space-x-3">
          {activeTab === 'invoices' && (
            <button
              onClick={() => setShowInvoiceForm(true)}
              className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
            >
              <Plus className="w-4 h-4 mr-2" />
              New Invoice
            </button>
          )}
          {activeTab === 'pettycash' && (
            <>
              <button
                onClick={() => { fetchAccounts(); setShowAccountsManager(true); }}
                className="flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
              >
                <BookOpen className="w-4 h-4 mr-2" />
                Manage Accounts
              </button>
              <button
                onClick={() => setShowPettyCashForm(true)}
                className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
              >
                <Plus className="w-4 h-4 mr-2" />
                New Entry
              </button>
            </>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="border-b border-gray-200 dark:border-gray-700">
          <nav className="flex space-x-8 px-6">
            {[
              { key: 'invoices', label: 'Invoices' },
              { key: 'pettycash', label: 'Petty Cash' },
              { key: 'debtors', label: 'Debtors Ledger' },
              { key: 'costing', label: 'Costing & P&L' },
            ].map(tab => (
              <button
                key={tab.key}
                onClick={() => setActiveTab(tab.key as typeof activeTab)}
                className={`py-4 px-1 border-b-2 font-medium text-sm ${
                  activeTab === tab.key
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </nav>
        </div>
      </div>

      {/* Stats Cards */}
      {activeTab === 'invoices' && (
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <DollarSign className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Revenue</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                KES {invoices.reduce((sum, inv) => sum + Number(inv.total_amount), 0).toLocaleString()}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <CheckCircle className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Paid Invoices</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {invoices.filter(inv => inv.payment_status === 'paid').length}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <AlertTriangle className="w-8 h-8 text-red-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Overdue</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {invoices.filter(inv => inv.payment_status === 'overdue').length}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <TrendingUp className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Pending</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {invoices.filter(inv => inv.payment_status === 'pending').length}
              </p>
            </div>
          </div>
        </div>
      </div>
      )}

      {/* Invoices Table */}
      {activeTab === 'invoices' && (
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Invoices</h3>
        </div>
        <div className="p-6">
          {/* Search and Filters */}
          <div className="flex flex-col md:flex-row gap-4 mb-6">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500 w-4 h-4" />
                <input
                  type="text"
                  placeholder="Search invoices..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>
            <div className="flex gap-2">
              <select
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value)}
                className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
              >
                <option value="all">All Status</option>
                <option value="pending">Pending</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
              </select>
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

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Invoice Details
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Customer
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Amount (KES)
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Dates
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
                {filteredInvoices.map((invoice) => (
                  <tr key={invoice.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div>
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{invoice.invoice_no}</div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                          By: {invoice.created_by ? `${invoice.created_by.first_name} ${invoice.created_by.last_name}` : '—'}
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div>
                        <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{invoice.customer?.name ?? '—'}</div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">{invoice.customer?.code ?? ''}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900 dark:text-gray-100">
                        <div>Subtotal: KES {invoice.subtotal.toLocaleString()}</div>
                        <div>Tax: KES {invoice.tax_amount.toLocaleString()}</div>
                        <div className="font-medium">Total: KES {invoice.total_amount.toLocaleString()}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900 dark:text-gray-100">
                        <div>Date: {new Date(invoice.invoice_date).toLocaleDateString()}</div>
                        <div>Due: {new Date(invoice.due_date).toLocaleDateString()}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusColor(invoice.payment_status)}`}>
                        {getStatusIcon(invoice.payment_status)}
                        <span className="ml-1">{invoice.payment_status}</span>
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                      <div className="flex items-center space-x-2">
                        <button className="text-blue-600 hover:text-blue-900">
                          <Eye className="w-4 h-4" />
                        </button>
                        <button className="text-green-600 hover:text-green-900">
                          <Edit className="w-4 h-4" />
                        </button>
                        <button className="text-red-600 hover:text-red-900">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
      )}

      {/* Invoice Form Modal */}
      {showInvoiceForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">New Invoice</h3>
            <form onSubmit={handleInvoiceSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Customer</label>
                <select
                  value={invoiceForm.customer_id}
                  onChange={(e) => setInvoiceForm({...invoiceForm, customer_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select Customer</option>
                  {customers.map(c => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Invoice Date</label>
                  <input
                    type="date"
                    value={invoiceForm.invoice_date}
                    onChange={(e) => setInvoiceForm({...invoiceForm, invoice_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Due Date</label>
                  <input
                    type="date"
                    value={invoiceForm.due_date}
                    onChange={(e) => setInvoiceForm({...invoiceForm, due_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Subtotal (KES)</label>
                  <input
                    type="number"
                    step="0.01"
                    value={invoiceForm.subtotal}
                    onChange={(e) => setInvoiceForm({...invoiceForm, subtotal: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Tax Amount (KES)</label>
                  <input
                    type="number"
                    step="0.01"
                    value={invoiceForm.tax_amount}
                    onChange={(e) => setInvoiceForm({...invoiceForm, tax_amount: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea
                  value={invoiceForm.notes}
                  onChange={(e) => setInvoiceForm({...invoiceForm, notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={3}
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowInvoiceForm(false)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700"
                >
                  Create Invoice
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Petty Cash Tab */}
      {activeTab === 'pettycash' && (
        <div className="space-y-6">
          <div className="flex justify-end">
            <button
              onClick={() => {
                const from = new Date(); from.setDate(1);
                const to = new Date();
                downloadBlob('/finance/petty-cash/export',
                  { date_from: from.toISOString().slice(0, 10), date_to: to.toISOString().slice(0, 10) },
                  `petty-cash-${from.toISOString().slice(0, 7)}.xlsx`,
                  () => toast.error('Failed to export petty cash'));
              }}
              className="flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
              <Download className="w-4 h-4 mr-2" /> Export (this month)
            </button>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Opening Balance</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">KES {pettyCashOpening.toLocaleString()}</p>
            </div>
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Closing Balance</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">KES {pettyCashClosing.toLocaleString()}</p>
            </div>
          </div>

          <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Description</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Account</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">M-Pesa Ref</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Requestor</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">In</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Out</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Balance</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {pettyCashEntries.length === 0 && (
                  <tr><td colSpan={9} className="px-4 py-4 text-sm text-gray-400 dark:text-gray-500">No petty cash entries yet</td></tr>
                )}
                {pettyCashEntries.map(entry => (
                  <tr key={entry.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">{new Date(entry.entry_date).toLocaleDateString()}</td>
                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{entry.description}</td>
                    <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 whitespace-nowrap">{entry.account?.code} · {entry.account?.description}</td>
                    <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{entry.mpesa_reference || '—'}</td>
                    <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{entry.requestor_name || (entry.requestor ? `${entry.requestor.first_name} ${entry.requestor.last_name}` : '—')}</td>
                    <td className="px-4 py-3 text-sm text-green-600 text-right">{Number(entry.amount_in) > 0 ? Number(entry.amount_in).toLocaleString() : ''}</td>
                    <td className="px-4 py-3 text-sm text-red-600 text-right">{Number(entry.amount_out) > 0 ? Number(entry.amount_out).toLocaleString() : ''}</td>
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 text-right">{entry.running_balance.toLocaleString()}</td>
                    <td className="px-4 py-3 text-sm">
                      <button onClick={() => handleDeletePettyCash(entry.id)} className="text-red-600 hover:text-red-900">
                        <Trash2 className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Petty Cash Entry Modal */}
      {showPettyCashForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">New Petty Cash Entry</h3>
              <button onClick={() => setShowPettyCashForm(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handlePettyCashSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                  <input required type="date" value={pettyCashForm.entry_date}
                    onChange={(e) => setPettyCashForm({...pettyCashForm, entry_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">M-Pesa Reference</label>
                  <input type="text" value={pettyCashForm.mpesa_reference}
                    onChange={(e) => setPettyCashForm({...pettyCashForm, mpesa_reference: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Account</label>
                <select required value={pettyCashForm.account_id}
                  onChange={(e) => setPettyCashForm({...pettyCashForm, account_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="">Select account</option>
                  {accounts.map(a => <option key={a.id} value={a.id}>{a.code} — {a.description}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Transaction Description</label>
                <input required type="text" value={pettyCashForm.description}
                  onChange={(e) => setPettyCashForm({...pettyCashForm, description: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Requestor</label>
                <input type="text" placeholder="Name" value={pettyCashForm.requestor_name}
                  onChange={(e) => setPettyCashForm({...pettyCashForm, requestor_name: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Direction</label>
                  <select value={pettyCashForm.direction}
                    onChange={(e) => setPettyCashForm({...pettyCashForm, direction: e.target.value as 'in' | 'out'})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="out">Money Out</option>
                    <option value="in">Money In</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount (KES)</label>
                  <input required type="number" min={0.01} step="0.01" value={pettyCashForm.amount}
                    onChange={(e) => setPettyCashForm({...pettyCashForm, amount: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowPettyCashForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Record Entry</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Round 2 Phase 12: Chart of Accounts manager -- only a starter
          set was ever seeded (the real ~200-code list wasn't available);
          this is what makes entering the real list actually possible,
          instead of the backend CRUD existing with no way to reach it. */}
      {showAccountsManager && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-2xl max-h-[85vh] overflow-y-auto">
            <div className="flex justify-between items-center mb-4">
              <div>
                <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Chart of Accounts</h3>
                <p className="text-xs text-gray-500 dark:text-gray-400">Petty cash entries are coded against these accounts.</p>
              </div>
              <div className="flex items-center gap-2">
                <button onClick={openNewAccount} className="flex items-center px-3 py-1.5 bg-blue-600 text-white rounded-md text-sm hover:bg-blue-700">
                  <Plus className="w-4 h-4 mr-1" /> Add Account
                </button>
                <button onClick={() => setShowAccountsManager(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600"><X className="w-5 h-5" /></button>
              </div>
            </div>
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead>
                <tr>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Code</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Description</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Category</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                  <th className="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                {accounts.map(a => (
                  <tr key={a.id} className={a.is_active === false ? 'opacity-50' : ''}>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">{a.code}</td>
                    <td className="px-3 py-2 text-sm text-gray-900 dark:text-gray-100">{a.description}</td>
                    <td className="px-3 py-2 text-sm text-gray-500 dark:text-gray-400 capitalize">{a.category}</td>
                    <td className="px-3 py-2 text-sm">
                      {a.is_active === false ? (
                        <span className="text-xs text-gray-600 bg-gray-100 px-2 py-0.5 rounded-full">Inactive</span>
                      ) : (
                        <span className="text-xs text-green-700 bg-green-100 px-2 py-0.5 rounded-full">Active</span>
                      )}
                    </td>
                    <td className="px-3 py-2 text-sm whitespace-nowrap">
                      <button onClick={() => openEditAccount(a)} className="text-blue-600 hover:text-blue-900 mr-3"><Edit className="w-4 h-4" /></button>
                      <button onClick={() => toggleAccountActive(a)} className="text-xs text-gray-500 dark:text-gray-400 hover:underline">
                        {a.is_active === false ? 'Activate' : 'Deactivate'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {showAccountForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[60] p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">{editingAccountId ? 'Edit Account' : 'New Account'}</h3>
              <button onClick={() => setShowAccountForm(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleAccountSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Code</label>
                <input required type="text" placeholder="e.g. 5460" value={accountForm.code}
                  onChange={(e) => setAccountForm({ ...accountForm, code: e.target.value })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                <input required type="text" placeholder="e.g. Advertising and publicity" value={accountForm.description}
                  onChange={(e) => setAccountForm({ ...accountForm, description: e.target.value })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                <select value={accountForm.category} onChange={(e) => setAccountForm({ ...accountForm, category: e.target.value })}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2">
                  <option value="asset">Asset</option>
                  <option value="liability">Liability</option>
                  <option value="equity">Equity</option>
                  <option value="income">Income</option>
                  <option value="expense">Expense</option>
                </select>
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowAccountForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={savingAccount} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingAccount ? 'Saving…' : (editingAccountId ? 'Save Changes' : 'Add Account')}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Debtors Ledger Tab */}
      {activeTab === 'debtors' && (
        <div className="space-y-6">
          <div className="flex justify-end">
            <button
              onClick={() => downloadBlob('/finance/debtors/export', {}, `debtors-ledger-${new Date().toISOString().slice(0, 10)}.xlsx`, () => toast.error('Failed to export debtors ledger'))}
              className="flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
              <Download className="w-4 h-4 mr-2" /> Export Full Ledger
            </button>
          </div>
          <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Customer</label>
            <select value={debtorCustomerId} onChange={(e) => setDebtorCustomerId(e.target.value)}
              className="mt-1 block w-full max-w-md border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option value="">Select a customer to view their ledger</option>
              {customers.map(c => (
                <option key={c.id} value={c.id}>{c.name} ({c.code}){(c.debtor_balance || 0) > 0 ? ` — owes KES ${Number(c.debtor_balance).toLocaleString()}` : ''}</option>
              ))}
            </select>
          </div>

          {debtorCustomerId && (
            <>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Outstanding Balance</p>
                    <p className={`text-2xl font-bold ${debtorBalance > 0 ? 'text-red-600' : 'text-gray-900 dark:text-gray-100'}`}>KES {debtorBalance.toLocaleString()}</p>
                    <Link to={`/customers/${debtorCustomerId}`} className="text-xs text-blue-600 hover:text-blue-800 hover:underline">View customer profile</Link>
                  </div>
                  <div className="flex space-x-2">
                    <button onClick={() => setShowManualLedgerForm(true)} className="px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-sm hover:bg-gray-50 dark:hover:bg-gray-700">Manual Entry</button>
                    <button onClick={() => setShowPaymentForm(true)} disabled={openDebts.length === 0}
                      className="px-3 py-2 bg-green-600 text-white rounded-md text-sm hover:bg-green-700 disabled:opacity-50">Record Payment</button>
                  </div>
                </div>
                <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400 mb-2">Open Invoices</p>
                  {openDebts.length === 0 ? (
                    <p className="text-sm text-gray-400 dark:text-gray-500">No outstanding invoices</p>
                  ) : (
                    <ul className="text-sm space-y-1">
                      {openDebts.map(d => (
                        <li key={d.id} className="flex justify-between">
                          <span>{d.invoice?.invoice_no || 'Invoice'} {d.days_overdue > 0 ? <span className="text-red-500">({d.days_overdue}d overdue)</span> : ''}</span>
                          <span className="font-medium">KES {Number(d.balance).toLocaleString()}</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </div>

              <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                  <thead className="bg-gray-50 dark:bg-gray-900">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Date</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Details</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Ref (Cheque/Invoice)</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Voucher No.</th>
                      <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Debit</th>
                      <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Credit</th>
                      <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Balance</th>
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    {debtorLedger.length === 0 && (
                      <tr><td colSpan={7} className="px-4 py-4 text-sm text-gray-400 dark:text-gray-500">No ledger entries for this customer</td></tr>
                    )}
                    {debtorLedger.map(entry => (
                      <tr key={entry.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100 whitespace-nowrap">{new Date(entry.entry_date).toLocaleDateString()}</td>
                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{entry.details}</td>
                        <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{entry.reference_no || '—'}</td>
                        <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{entry.voucher_no || '—'}</td>
                        <td className="px-4 py-3 text-sm text-red-600 text-right">{Number(entry.debit) > 0 ? Number(entry.debit).toLocaleString() : ''}</td>
                        <td className="px-4 py-3 text-sm text-green-600 text-right">{Number(entry.credit) > 0 ? Number(entry.credit).toLocaleString() : ''}</td>
                        <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 text-right">{entry.running_balance.toLocaleString()}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      )}

      {/* Record Payment Modal */}
      {showPaymentForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Record Payment</h3>
              <button onClick={() => setShowPaymentForm(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleRecordPayment} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Invoice</label>
                <select required value={paymentForm.debt_id}
                  onChange={(e) => setPaymentForm({...paymentForm, debt_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="">Select invoice</option>
                  {openDebts.map(d => (
                    <option key={d.id} value={d.id}>{d.invoice?.invoice_no || 'Invoice'} — owes KES {Number(d.balance).toLocaleString()}</option>
                  ))}
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                  <input required type="date" value={paymentForm.entry_date}
                    onChange={(e) => setPaymentForm({...paymentForm, entry_date: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount (KES)</label>
                  <input required type="number" min={0.01} step="0.01" value={paymentForm.amount}
                    onChange={(e) => setPaymentForm({...paymentForm, amount: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Reference No.</label>
                  <input type="text" placeholder="M-Pesa code, cheque no." value={paymentForm.reference_no}
                    onChange={(e) => setPaymentForm({...paymentForm, reference_no: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Voucher No.</label>
                  <input type="text" value={paymentForm.voucher_no}
                    onChange={(e) => setPaymentForm({...paymentForm, voucher_no: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowPaymentForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">Record Payment</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Manual Ledger Entry Modal */}
      {showManualLedgerForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Manual Ledger Entry</h3>
              <button onClick={() => setShowManualLedgerForm(false)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600"><X className="w-5 h-5" /></button>
            </div>
            <p className="text-sm text-gray-500 dark:text-gray-400 mb-4">For adjustments or opening balances -- not tied to a specific invoice.</p>
            <form onSubmit={handleManualLedgerSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                <input required type="date" value={manualLedgerForm.entry_date}
                  onChange={(e) => setManualLedgerForm({...manualLedgerForm, entry_date: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Details</label>
                <input required type="text" value={manualLedgerForm.details}
                  onChange={(e) => setManualLedgerForm({...manualLedgerForm, details: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Reference No.</label>
                  <input type="text" value={manualLedgerForm.reference_no}
                    onChange={(e) => setManualLedgerForm({...manualLedgerForm, reference_no: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Voucher No.</label>
                  <input type="text" value={manualLedgerForm.voucher_no}
                    onChange={(e) => setManualLedgerForm({...manualLedgerForm, voucher_no: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Type</label>
                  <select value={manualLedgerForm.direction}
                    onChange={(e) => setManualLedgerForm({...manualLedgerForm, direction: e.target.value as 'debit' | 'credit'})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="debit">Debit (increases balance owed)</option>
                    <option value="credit">Credit (decreases balance owed)</option>
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount (KES)</label>
                  <input required type="number" min={0.01} step="0.01" value={manualLedgerForm.amount}
                    onChange={(e) => setManualLedgerForm({...manualLedgerForm, amount: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowManualLedgerForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Record Entry</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Costing & P&L Tab */}
      {activeTab === 'costing' && (
        <div className="space-y-6">
          <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow flex flex-wrap items-end gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">From</label>
              <input type="date" value={plDateFrom} onChange={(e) => setPlDateFrom(e.target.value)}
                className="mt-1 block border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">To</label>
              <input type="date" value={plDateTo} onChange={(e) => setPlDateTo(e.target.value)}
                className="mt-1 block border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <button onClick={fetchCosting} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Update</button>
          </div>

          {profitLoss && (
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <p className="text-xs text-gray-500 dark:text-gray-400">Revenue</p>
                <p className="text-xl font-bold text-gray-900 dark:text-gray-100">KES {profitLoss.revenue.toLocaleString()}</p>
              </div>
              <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <p className="text-xs text-gray-500 dark:text-gray-400">COGS</p>
                <p className="text-xl font-bold text-gray-900 dark:text-gray-100">KES {profitLoss.cogs.toLocaleString()}</p>
              </div>
              <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <p className="text-xs text-gray-500 dark:text-gray-400">Gross Margin</p>
                <p className="text-xl font-bold text-green-600">KES {profitLoss.gross_margin.toLocaleString()}</p>
                <p className="text-xs text-gray-400 dark:text-gray-500">{profitLoss.gross_margin_pct != null ? `${profitLoss.gross_margin_pct}%` : '—'}</p>
              </div>
              <div className="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <p className="text-xs text-gray-500 dark:text-gray-400">Net Margin</p>
                <p className={`text-xl font-bold ${profitLoss.net_margin >= 0 ? 'text-green-600' : 'text-red-600'}`}>KES {profitLoss.net_margin.toLocaleString()}</p>
                <p className="text-xs text-gray-400 dark:text-gray-500">after payroll (KES {profitLoss.monthly_payroll.toLocaleString()}/mo) & overhead (KES {profitLoss.overhead_expenses.toLocaleString()})</p>
              </div>
            </div>
          )}

          <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Per-Bottle Material Cost</h3>
            </div>
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Product</th>
                  <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Material Cost (KES)</th>
                  <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Bill of Materials</th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {perBottleCosts.map(row => (
                  <tr key={row.sku_id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{row.sku.brand ? `${row.sku.brand} — ` : ''}{row.sku.name}</td>
                    <td className="px-4 py-3 text-sm text-right font-medium text-gray-900 dark:text-gray-100">{row.material_cost.toLocaleString()}</td>
                    <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                      {row.has_bom
                        ? row.bom_lines.map(l => `${l.material} (${l.qty_per_unit}×${l.unit_cost})`).join(', ')
                        : <span className="text-amber-600">No recipe set</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
};

export default FinancePage;
