import React, { useState, useEffect } from 'react';
import { useSearchParams, Link } from 'react-router-dom';
import {
  Plus,
  Search,
  Download,
  Users,
  Clock,
  CheckCircle,
  AlertTriangle,
  Eye,
  Edit,
  Trash2,
  DollarSign,
  Lock,
  Printer,
  X
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { usePermissions } from '../contexts/AuthContext';

interface Employee {
  id: string;
  first_name: string;
  last_name: string;
  email: string;
}

interface Attendance {
  id: string;
  // The backend eager-loads this relation as `user`, not `employee` -- and
  // it can genuinely be null (a deleted user, a row created before a
  // relation existed), so every read of it has to be defensive. This
  // exact field-name mismatch plus the missing null-check is what crashed
  // this whole page to a blank white screen the moment any real
  // attendance record existed.
  user: {
    first_name: string;
    last_name: string;
    email?: string;
  } | null;
  date: string;
  clock_in_time: string;
  clock_out_time: string | null;
  total_hours: number | null;
  overtime_hours: number | null;
  status: 'present' | 'absent' | 'late' | 'half_day';
}

interface PayrollRun {
  id: string;
  month: string;
  status: 'draft' | 'finalized';
  payslips_count?: number;
  finalized_at: string | null;
  payslips?: Payslip[];
}

interface Payslip {
  id: string;
  payroll_run_id: string;
  user_id: string;
  user?: { id: string; first_name: string; last_name: string; staff_number?: string | null; department?: { name: string } | null };
  basic_pay: number; house_allowance: number;
  absentism_hours: number; absentism_deduction: number;
  overtime_hours_1_5x: number; overtime_hours_2x: number; overtime_pay: number;
  commission: number; leave_hours: number; leave_pay: number;
  telephone_allowance: number; other_allowance: number; bonus: number;
  gross_pay: number;
  pensionable_pay: number; employee_nssf: number; company_nssf: number; avc: number;
  total_pension_contribution: number; shif: number; housing_levy: number;
  taxable_pay: number; tax_payable: number; insurance_relief: number; tax_relief: number; paye: number;
  bus_fare: number; insurance_deduction: number; loan_deduction: number;
  sacco_loan_deduction: number; sacco_contribution: number; sacco_advance_deduction: number;
  other_deduction: number; staff_advance_deduction: number; penalties: number;
  total_deductions: number; net_salary: number;
}

interface StaffLoan {
  id: string;
  user_id: string;
  user?: { first_name: string; last_name: string };
  type: 'advance' | 'loan' | 'sacco_loan' | 'sacco_advance';
  principal: number;
  monthly_deduction: number;
  balance: number;
  issued_date: string;
  status: 'active' | 'settled';
  notes: string | null;
}

interface SalaryTemplate {
  id: string;
  name: string;
  basic_salary: number;
  house_allowance: number;
  telephone_allowance: number;
  other_allowance: number;
  terms_of_employment: string | null;
  notes: string | null;
}

const ALL_TABS = [
  { id: 'attendance', name: 'Attendance', icon: Clock },
  { id: 'payroll', name: 'Payroll', icon: DollarSign },
  { id: 'loans', name: 'Loans & Advances', icon: Users },
  { id: 'templates', name: 'Salary Templates', icon: Edit },
];

// Round 3 Phase 8: Manager only gets Attendance -- Payroll/Loans/Salary
// Templates are Director-only. This is UX only (matches what Manager can
// actually reach, so they're not shown a tab that just 404s); the real
// enforcement is the `tier:director` middleware on those routes now, not
// this list.
const DIRECTOR_ONLY_TAB_IDS = ['payroll', 'loans', 'templates'];

const LOAN_TYPE_LABELS: Record<string, string> = {
  advance: 'Staff Advance', loan: 'Loan', sacco_loan: 'Sacco Loan', sacco_advance: 'Sacco Advance',
};

const PAYSLIP_EDIT_FIELDS: { key: string; label: string; suffix?: string }[] = [
  { key: 'absentism_hours', label: 'Absenteeism Hours' },
  { key: 'overtime_hours_1_5x', label: 'Overtime Hours (1.5x)' },
  { key: 'overtime_hours_2x', label: 'Overtime Hours (2x)' },
  { key: 'commission', label: 'Commission', suffix: 'KES' },
  { key: 'leave_hours', label: 'Leave Hours Sold' },
  { key: 'telephone_allowance', label: 'Telephone Allowance', suffix: 'KES' },
  { key: 'other_allowance', label: 'Other Allowance', suffix: 'KES' },
  { key: 'bonus', label: 'Bonus', suffix: 'KES' },
  { key: 'avc', label: 'Additional Voluntary Contribution (AVC)', suffix: 'KES' },
  { key: 'bus_fare', label: 'Bus Fare', suffix: 'KES' },
  { key: 'insurance_deduction', label: 'Insurance Deduction', suffix: 'KES' },
  { key: 'sacco_contribution', label: 'Sacco Contribution', suffix: 'KES' },
  { key: 'other_deduction', label: 'Other Deduction', suffix: 'KES' },
  { key: 'penalties', label: 'Penalties', suffix: 'KES' },
];

const HRPage: React.FC = () => {
  const { isDirector } = usePermissions();
  const TABS = isDirector() ? ALL_TABS : ALL_TABS.filter(t => !DIRECTOR_ONLY_TAB_IDS.includes(t.id));

  const [activeTabState, setActiveTabState] = useState('attendance');
  const [searchParams] = useSearchParams();
  useEffect(() => {
    const tab = searchParams.get('tab');
    // Guard against a typed/bookmarked ?tab=payroll URL for a Manager --
    // fall back to Attendance rather than rendering a tab whose data
    // fetch will just 403 against the server-side tier:director gate.
    if (tab && TABS.some(t => t.id === tab)) setActiveTabState(tab);
  }, [searchParams, TABS]);

  // ============ ATTENDANCE (unchanged from Phase 1) ============
  const [attendances, setAttendances] = useState<Attendance[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
  }, [searchParams]);
  const [filterStatus, setFilterStatus] = useState('all');
  // Round 2 Phase 5: "give managers a daily/weekly attendance view per
  // department" -- period + department filters, applied server-side
  // (AttendanceController::index() already supported date_from/date_to/
  // department_id, just nothing in the UI used them yet).
  const [periodFilter, setPeriodFilter] = useState<'today' | 'week' | 'all'>('today');
  const [departmentFilter, setDepartmentFilter] = useState('');
  const [departments, setDepartments] = useState<{ id: string; name: string }[]>([]);
  const [showAttendanceForm, setShowAttendanceForm] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const [attendanceForm, setAttendanceForm] = useState({
    user_id: '',
    date: new Date().toISOString().split('T')[0],
    clock_in_time: '',
    clock_out_time: '',
    status: 'present',
    notes: ''
  });

  useEffect(() => {
    fetchEmployees();
    fetchPayrollRuns();
    fetchLoans();
    fetchTemplates();
    api.get('/users/departments').then(res => setDepartments(res.data.data || [])).catch(() => {});
  }, []);

  const periodDates = (): { date_from?: string; date_to?: string } => {
    const now = new Date();
    if (periodFilter === 'today') {
      const d = now.toISOString().slice(0, 10);
      return { date_from: d, date_to: d };
    }
    if (periodFilter === 'week') {
      const day = now.getDay(); // 0 = Sunday
      const monday = new Date(now);
      monday.setDate(now.getDate() - ((day + 6) % 7));
      const sunday = new Date(monday);
      sunday.setDate(monday.getDate() + 6);
      return { date_from: monday.toISOString().slice(0, 10), date_to: sunday.toISOString().slice(0, 10) };
    }
    return {};
  };

  useEffect(() => {
    fetchData();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [periodFilter, departmentFilter]);

  const fetchData = async () => {
    try {
      setLoading(true);
      const response = await api.get('/hr/attendance', {
        params: {
          ...periodDates(),
          department_id: departmentFilter || undefined,
          limit: 100,
        },
      });
      setAttendances(response.data.data || []);
    } catch (error) {
      toast.error('Failed to fetch HR data');
    } finally {
      setLoading(false);
    }
  };

  const fetchEmployees = async () => {
    try {
      const response = await api.get('/users', { params: { per_page: 200 } });
      setEmployees(response.data.data || []);
    } catch (error) {
      // Non-fatal -- the attendance list itself still works, only the
      // "Record Attendance" form's employee picker would be empty.
    }
  };

  const handleAttendanceSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!attendanceForm.user_id || !attendanceForm.date || !attendanceForm.clock_in_time) {
      toast.error('Employee, date, and clock-in time are required');
      return;
    }
    setSubmitting(true);
    try {
      await api.post('/hr/attendance', {
        ...attendanceForm,
        clock_in_time: `${attendanceForm.clock_in_time}:00`,
        clock_out_time: attendanceForm.clock_out_time ? `${attendanceForm.clock_out_time}:00` : null,
      });
      toast.success('Attendance recorded successfully');
      setShowAttendanceForm(false);
      setAttendanceForm({
        user_id: '',
        date: new Date().toISOString().split('T')[0],
        clock_in_time: '',
        clock_out_time: '',
        status: 'present',
        notes: ''
      });
      fetchData();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to record attendance');
    } finally {
      setSubmitting(false);
    }
  };

  const filteredAttendances = attendances.filter(attendance => {
    const name = `${attendance.user?.first_name ?? ''} ${attendance.user?.last_name ?? ''}`.toLowerCase();
    const matchesSearch = name.includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'all' || attendance.status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'present': return 'text-green-600 bg-green-100';
      case 'absent': return 'text-red-600 bg-red-100';
      case 'late': return 'text-yellow-600 bg-yellow-100';
      case 'half_day': return 'text-orange-600 bg-orange-100';
      default: return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'present': return <CheckCircle className="w-4 h-4" />;
      case 'absent': return <AlertTriangle className="w-4 h-4" />;
      case 'late': return <Clock className="w-4 h-4" />;
      case 'half_day': return <Clock className="w-4 h-4" />;
      default: return <Clock className="w-4 h-4" />;
    }
  };

  // ============ PAYROLL ============
  const [payrollRuns, setPayrollRuns] = useState<PayrollRun[]>([]);
  const [selectedRun, setSelectedRun] = useState<PayrollRun | null>(null);
  const [showNewRunForm, setShowNewRunForm] = useState(false);
  const [newRunMonth, setNewRunMonth] = useState(new Date().toISOString().slice(0, 7));
  const [creatingRun, setCreatingRun] = useState(false);
  const [finalizing, setFinalizing] = useState(false);
  const [editingPayslip, setEditingPayslip] = useState<Payslip | null>(null);
  const [payslipForm, setPayslipForm] = useState<Record<string, string>>({});
  const [savingPayslip, setSavingPayslip] = useState(false);
  const [viewingPayslip, setViewingPayslip] = useState<Payslip | null>(null);

  const fetchPayrollRuns = async () => {
    try {
      const res = await api.get('/hr/payroll/runs');
      setPayrollRuns(res.data.data || []);
    } catch (error) {
      // Non-fatal
    }
  };

  const openRun = async (id: string) => {
    try {
      const res = await api.get(`/hr/payroll/runs/${id}`);
      setSelectedRun(res.data.data);
    } catch (error) {
      toast.error('Failed to load payroll run');
    }
  };

  const handleCreateRun = async (e: React.FormEvent) => {
    e.preventDefault();
    setCreatingRun(true);
    try {
      const res = await api.post('/hr/payroll/runs', { month: `${newRunMonth}-01` });
      toast.success(res.data.message || 'Payroll run created');
      setShowNewRunForm(false);
      await fetchPayrollRuns();
      openRun(res.data.data.id);
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to create payroll run');
    } finally {
      setCreatingRun(false);
    }
  };

  const handleFinalize = async () => {
    if (!selectedRun) return;
    if (!window.confirm('Finalize this payroll run? Loan/advance balances will be permanently reduced and payslips can no longer be edited.')) return;
    setFinalizing(true);
    try {
      await api.post(`/hr/payroll/runs/${selectedRun.id}/finalize`);
      toast.success('Payroll run finalized');
      openRun(selectedRun.id);
      fetchPayrollRuns();
      fetchLoans();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to finalize');
    } finally {
      setFinalizing(false);
    }
  };

  const handleDeleteRun = async (run: PayrollRun) => {
    if (!window.confirm(`Delete the draft payroll run for ${run.month}? This cannot be undone.`)) return;
    try {
      await api.delete(`/hr/payroll/runs/${run.id}`);
      toast.success('Draft deleted');
      setSelectedRun(null);
      fetchPayrollRuns();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to delete');
    }
  };

  const openEditPayslip = (p: Payslip) => {
    setEditingPayslip(p);
    setPayslipForm(Object.fromEntries(PAYSLIP_EDIT_FIELDS.map(f => [f.key, String((p as any)[f.key] ?? 0)])));
  };

  const handleSavePayslip = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingPayslip || !selectedRun) return;
    setSavingPayslip(true);
    try {
      await api.put(`/hr/payroll/runs/${selectedRun.id}/payslips/${editingPayslip.id}`, payslipForm);
      toast.success('Payslip updated');
      setEditingPayslip(null);
      openRun(selectedRun.id);
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to update payslip');
    } finally {
      setSavingPayslip(false);
    }
  };

  const downloadBankFile = (run: PayrollRun) => {
    // Auth header is added by the shared axios instance's interceptor,
    // but a plain <a href> download can't carry it -- fetch as a blob instead.
    api.get(`/hr/payroll/runs/${run.id}/bank-transfer-file`, { responseType: 'blob' }).then((res) => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = `bank-transfer-${run.month.slice(0, 7)}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    }).catch(() => toast.error('Failed to download bank transfer file'));
  };

  // ============ LOANS & ADVANCES ============
  const [loans, setLoans] = useState<StaffLoan[]>([]);
  const [showLoanForm, setShowLoanForm] = useState(false);
  const [savingLoan, setSavingLoan] = useState(false);
  const [loanForm, setLoanForm] = useState({
    user_id: '', type: 'loan', principal: '', monthly_deduction: '',
    issued_date: new Date().toISOString().slice(0, 10), notes: '',
  });

  const fetchLoans = async () => {
    try {
      const res = await api.get('/hr/loans');
      setLoans(res.data.data || []);
    } catch (error) {
      // Non-fatal
    }
  };

  const handleCreateLoan = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!loanForm.user_id || !loanForm.principal || !loanForm.monthly_deduction) {
      toast.error('Employee, principal, and monthly deduction are required');
      return;
    }
    setSavingLoan(true);
    try {
      await api.post('/hr/loans', loanForm);
      toast.success('Recorded');
      setShowLoanForm(false);
      setLoanForm({ user_id: '', type: 'loan', principal: '', monthly_deduction: '', issued_date: new Date().toISOString().slice(0, 10), notes: '' });
      fetchLoans();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to record');
    } finally {
      setSavingLoan(false);
    }
  };

  const handleDeleteLoan = async (loan: StaffLoan) => {
    if (!window.confirm('Delete this loan/advance record?')) return;
    try {
      await api.delete(`/hr/loans/${loan.id}`);
      toast.success('Deleted');
      fetchLoans();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to delete');
    }
  };

  // ============ SALARY TEMPLATES ============
  const [templates, setTemplates] = useState<SalaryTemplate[]>([]);
  const [showTemplateForm, setShowTemplateForm] = useState(false);
  const [savingTemplate, setSavingTemplate] = useState(false);
  const [templateForm, setTemplateForm] = useState({
    name: '', basic_salary: '', house_allowance: '', telephone_allowance: '', other_allowance: '',
    terms_of_employment: '', notes: '',
  });

  const fetchTemplates = async () => {
    try {
      const res = await api.get('/hr/salary-templates');
      setTemplates(res.data.data || []);
    } catch (error) {
      // Non-fatal
    }
  };

  const handleCreateTemplate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!templateForm.name) {
      toast.error('Template name is required');
      return;
    }
    setSavingTemplate(true);
    try {
      await api.post('/hr/salary-templates', templateForm);
      toast.success('Template created');
      setShowTemplateForm(false);
      setTemplateForm({ name: '', basic_salary: '', house_allowance: '', telephone_allowance: '', other_allowance: '', terms_of_employment: '', notes: '' });
      fetchTemplates();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to create template');
    } finally {
      setSavingTemplate(false);
    }
  };

  const handleDeleteTemplate = async (t: SalaryTemplate) => {
    if (!window.confirm(`Delete the "${t.name}" template?`)) return;
    try {
      await api.delete(`/hr/salary-templates/${t.id}`);
      toast.success('Deleted');
      fetchTemplates();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to delete');
    }
  };

  const money = (n: number | null | undefined) => `KES ${(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">HR &amp; Payroll</h1>
          <p className="text-gray-600 dark:text-gray-400">
            Attendance, payroll runs, loans/advances, and salary templates. Adding or bulk-importing
            employee records (name, phone, ID number, department, role, employment date, salary,
            bank details) is in{' '}
            <Link to="/users" className="text-blue-600 hover:text-blue-800 font-medium">Employees</Link>.
          </p>
        </div>
      </div>

      {/* Tabs */}
      <div className="border-b border-gray-200 dark:border-gray-700">
        <nav className="flex space-x-8">
          {TABS.map((tab) => {
            const Icon = tab.icon;
            return (
              <button
                key={tab.id}
                onClick={() => setActiveTabState(tab.id)}
                className={`py-3 px-1 border-b-2 font-medium text-sm flex items-center space-x-2 ${
                  activeTabState === tab.id
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100'
                }`}
              >
                <Icon className="w-4 h-4" />
                <span>{tab.name}</span>
              </button>
            );
          })}
        </nav>
      </div>

      {/* ============ ATTENDANCE TAB ============ */}
      {activeTabState === 'attendance' && (
        <div className="space-y-6">
          <div className="flex justify-end">
            <button
              onClick={() => setShowAttendanceForm(true)}
              className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
            >
              <Plus className="w-4 h-4 mr-2" />
              Record Attendance
            </button>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <div className="flex items-center">
                <Users className="w-8 h-8 text-blue-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Records</p>
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{attendances.length}</p>
                </div>
              </div>
            </div>
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <div className="flex items-center">
                <CheckCircle className="w-8 h-8 text-green-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Present Today</p>
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {attendances.filter(a => a.status === 'present' && a.date === new Date().toISOString().split('T')[0]).length}
                  </p>
                </div>
              </div>
            </div>
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <div className="flex items-center">
                <Clock className="w-8 h-8 text-yellow-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Hours</p>
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {attendances.reduce((sum, a) => sum + (a.total_hours ?? 0), 0).toFixed(1)}
                  </p>
                </div>
              </div>
            </div>
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
              <div className="flex items-center">
                <AlertTriangle className="w-8 h-8 text-red-600" />
                <div className="ml-4">
                  <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Overtime Hours</p>
                  <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {attendances.reduce((sum, a) => sum + (a.overtime_hours ?? 0), 0).toFixed(1)}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Attendance Records</h3>
            </div>
            <div className="p-6">
              <div className="flex flex-col md:flex-row gap-4 mb-6">
                <div className="flex-1">
                  <div className="relative">
                    <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500 w-4 h-4" />
                    <input
                      type="text"
                      placeholder="Search attendance..."
                      value={searchTerm}
                      onChange={(e) => setSearchTerm(e.target.value)}
                      className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    />
                  </div>
                </div>
                <div className="flex gap-2">
                  <select
                    value={periodFilter}
                    onChange={(e) => setPeriodFilter(e.target.value as 'today' | 'week' | 'all')}
                    className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  >
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="all">All Time</option>
                  </select>
                  <select
                    value={departmentFilter}
                    onChange={(e) => setDepartmentFilter(e.target.value)}
                    className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  >
                    <option value="">All Departments</option>
                    {departments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                  </select>
                  <select
                    value={filterStatus}
                    onChange={(e) => setFilterStatus(e.target.value)}
                    className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  >
                    <option value="all">All Status</option>
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                    <option value="late">Late</option>
                    <option value="half_day">Half Day</option>
                  </select>
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                  <thead className="bg-gray-50 dark:bg-gray-900">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Employee</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Clock In/Out</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Hours</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    {filteredAttendances.map((attendance) => (
                      <tr key={attendance.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {attendance.user ? `${attendance.user.first_name} ${attendance.user.last_name}` : 'Unknown employee'}
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                          {new Date(attendance.date).toLocaleDateString()}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                          <div>In: {attendance.clock_in_time}</div>
                          <div>Out: {attendance.clock_out_time || 'Not clocked out'}</div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                          <div>Worked: {attendance.total_hours ?? 0}h</div>
                          <div>Overtime: {attendance.overtime_hours ?? 0}h</div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusColor(attendance.status)}`}>
                            {getStatusIcon(attendance.status)}
                            <span className="ml-1">{attendance.status.replace('_', ' ')}</span>
                          </span>
                        </td>
                      </tr>
                    ))}
                    {filteredAttendances.length === 0 && (
                      <tr><td colSpan={5} className="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No attendance records match.</td></tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ============ PAYROLL TAB ============ */}
      {activeTabState === 'payroll' && (
        <div className="space-y-6">
          {!selectedRun ? (
            <>
              <div className="flex justify-end">
                <button onClick={() => setShowNewRunForm(true)} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                  <Plus className="w-4 h-4 mr-2" /> New Payroll Run
                </button>
              </div>
              <div className="bg-white dark:bg-gray-800 rounded-lg shadow divide-y divide-gray-200 dark:divide-gray-700">
                {payrollRuns.length === 0 && <p className="p-6 text-sm text-gray-500 dark:text-gray-400">No payroll runs yet.</p>}
                {payrollRuns.map(run => (
                  <button key={run.id} onClick={() => openRun(run.id)} className="w-full text-left px-6 py-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700">
                    <div>
                      <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {new Date(run.month + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'long' })}
                      </p>
                      <p className="text-xs text-gray-500 dark:text-gray-400">{run.payslips_count ?? 0} payslip(s)</p>
                    </div>
                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${run.status === 'finalized' ? 'text-green-700 bg-green-100' : 'text-amber-700 bg-amber-100'}`}>
                      {run.status === 'finalized' && <Lock className="w-3 h-3 mr-1" />}
                      {run.status}
                    </span>
                  </button>
                ))}
              </div>
            </>
          ) : (
            <>
              <div className="flex items-center justify-between">
                <button onClick={() => setSelectedRun(null)} className="text-sm text-blue-600 hover:text-blue-800 font-medium">&larr; Back to all runs</button>
                <div className="flex items-center space-x-3">
                  <button onClick={() => downloadBankFile(selectedRun)} className="flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                    <Download className="w-4 h-4 mr-2" /> Bank Transfer File
                  </button>
                  {selectedRun.status === 'draft' && (
                    <>
                      <button onClick={() => handleDeleteRun(selectedRun)} className="flex items-center px-3 py-2 border border-red-300 text-red-600 rounded-lg text-sm hover:bg-red-50">
                        <Trash2 className="w-4 h-4 mr-2" /> Delete Draft
                      </button>
                      <button onClick={handleFinalize} disabled={finalizing} className="flex items-center px-3 py-2 bg-green-600 text-white rounded-lg text-sm hover:bg-green-700 disabled:opacity-50">
                        <Lock className="w-4 h-4 mr-2" /> {finalizing ? 'Finalizing…' : 'Finalize Run'}
                      </button>
                    </>
                  )}
                </div>
              </div>

              <div className="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                <div className="flex items-center justify-between mb-4">
                  <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {new Date(selectedRun.month + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'long' })}
                  </h3>
                  <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${selectedRun.status === 'finalized' ? 'text-green-700 bg-green-100' : 'text-amber-700 bg-amber-100'}`}>
                    {selectedRun.status === 'finalized' && <Lock className="w-3 h-3 mr-1" />}
                    {selectedRun.status}
                  </span>
                </div>
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead className="bg-gray-50 dark:bg-gray-900">
                      <tr>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Employee</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Gross Pay</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">PAYE</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">NSSF</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">SHIF</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Deductions</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Net Salary</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                      {(selectedRun.payslips || []).map(p => (
                        <tr key={p.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                          <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{p.user ? `${p.user.first_name} ${p.user.last_name}` : '—'}</td>
                          <td className="px-4 py-2 text-sm text-right text-gray-900 dark:text-gray-100">{money(p.gross_pay)}</td>
                          <td className="px-4 py-2 text-sm text-right text-gray-900 dark:text-gray-100">{money(p.paye)}</td>
                          <td className="px-4 py-2 text-sm text-right text-gray-900 dark:text-gray-100">{money(p.employee_nssf)}</td>
                          <td className="px-4 py-2 text-sm text-right text-gray-900 dark:text-gray-100">{money(p.shif)}</td>
                          <td className="px-4 py-2 text-sm text-right text-gray-900 dark:text-gray-100">{money(p.total_deductions)}</td>
                          <td className="px-4 py-2 text-sm text-right font-medium text-gray-900 dark:text-gray-100">{money(p.net_salary)}</td>
                          <td className="px-4 py-2 text-right whitespace-nowrap">
                            <button onClick={() => setViewingPayslip(p)} className="text-blue-600 hover:text-blue-900 mr-3" title="View payslip">
                              <Eye className="w-4 h-4 inline" />
                            </button>
                            {selectedRun.status === 'draft' && (
                              <button onClick={() => openEditPayslip(p)} className="text-green-600 hover:text-green-900" title="Edit">
                                <Edit className="w-4 h-4 inline" />
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                      {(selectedRun.payslips || []).length === 0 && (
                        <tr><td colSpan={8} className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No payslips in this run.</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>
              </div>
            </>
          )}
        </div>
      )}

      {/* ============ LOANS & ADVANCES TAB ============ */}
      {activeTabState === 'loans' && (
        <div className="space-y-6">
          <div className="flex justify-end">
            <button onClick={() => setShowLoanForm(true)} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
              <Plus className="w-4 h-4 mr-2" /> Record Advance / Loan
            </button>
          </div>
          <div className="bg-white dark:bg-gray-800 rounded-lg shadow overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Employee</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Type</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Principal</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Monthly Deduction</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Balance</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {loans.map(loan => (
                  <tr key={loan.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{loan.user ? `${loan.user.first_name} ${loan.user.last_name}` : '—'}</td>
                    <td className="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">{LOAN_TYPE_LABELS[loan.type] || loan.type}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900 dark:text-gray-100">{money(loan.principal)}</td>
                    <td className="px-4 py-2 text-sm text-right text-gray-900 dark:text-gray-100">{money(loan.monthly_deduction)}</td>
                    <td className="px-4 py-2 text-sm text-right font-medium text-gray-900 dark:text-gray-100">{money(loan.balance)}</td>
                    <td className="px-4 py-2">
                      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${loan.status === 'settled' ? 'text-gray-700 bg-gray-100' : 'text-blue-700 bg-blue-100'}`}>
                        {loan.status}
                      </span>
                    </td>
                    <td className="px-4 py-2 text-right">
                      <button onClick={() => handleDeleteLoan(loan)} className="text-red-600 hover:text-red-900">
                        <Trash2 className="w-4 h-4 inline" />
                      </button>
                    </td>
                  </tr>
                ))}
                {loans.length === 0 && (
                  <tr><td colSpan={7} className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No advances or loans recorded.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* ============ SALARY TEMPLATES TAB ============ */}
      {activeTabState === 'templates' && (
        <div className="space-y-6">
          <div className="flex justify-end">
            <button onClick={() => setShowTemplateForm(true)} className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
              <Plus className="w-4 h-4 mr-2" /> New Template
            </button>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {templates.map(t => (
              <div key={t.id} className="bg-white dark:bg-gray-800 rounded-lg shadow p-5">
                <div className="flex items-start justify-between">
                  <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">{t.name}</h3>
                  <button onClick={() => handleDeleteTemplate(t)} className="text-red-600 hover:text-red-900">
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
                {t.terms_of_employment && <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{t.terms_of_employment}</p>}
                <dl className="mt-3 space-y-1 text-sm">
                  <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Basic Salary</dt><dd className="text-gray-900 dark:text-gray-100">{money(t.basic_salary)}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">House Allowance</dt><dd className="text-gray-900 dark:text-gray-100">{money(t.house_allowance)}</dd></div>
                  {t.telephone_allowance > 0 && <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Telephone Allowance</dt><dd className="text-gray-900 dark:text-gray-100">{money(t.telephone_allowance)}</dd></div>}
                  {t.other_allowance > 0 && <div className="flex justify-between"><dt className="text-gray-500 dark:text-gray-400">Other Allowance</dt><dd className="text-gray-900 dark:text-gray-100">{money(t.other_allowance)}</dd></div>}
                </dl>
              </div>
            ))}
            {templates.length === 0 && (
              <p className="text-sm text-gray-500 dark:text-gray-400 col-span-3">
                No templates yet. Create one per role (e.g. "Driver", "Production", "Management") so new hires start from real numbers, not a blank form.
              </p>
            )}
          </div>
        </div>
      )}

      {/* ============ MODALS ============ */}

      {/* Attendance Form Modal */}
      {showAttendanceForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Record Attendance</h3>
            <form onSubmit={handleAttendanceSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Employee</label>
                <select
                  value={attendanceForm.user_id}
                  onChange={(e) => setAttendanceForm({...attendanceForm, user_id: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="">Select Employee</option>
                  {employees.map((emp) => (
                    <option key={emp.id} value={emp.id}>{emp.first_name} {emp.last_name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                <input
                  type="date"
                  value={attendanceForm.date}
                  onChange={(e) => setAttendanceForm({...attendanceForm, date: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select
                  value={attendanceForm.status}
                  onChange={(e) => setAttendanceForm({...attendanceForm, status: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                  <option value="present">Present</option>
                  <option value="absent">Absent</option>
                  <option value="late">Late</option>
                  <option value="half_day">Half Day</option>
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Clock In</label>
                  <input
                    type="time"
                    value={attendanceForm.clock_in_time}
                    onChange={(e) => setAttendanceForm({...attendanceForm, clock_in_time: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Clock Out</label>
                  <input
                    type="time"
                    value={attendanceForm.clock_out_time}
                    onChange={(e) => setAttendanceForm({...attendanceForm, clock_out_time: e.target.value})}
                    className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea
                  value={attendanceForm.notes}
                  onChange={(e) => setAttendanceForm({...attendanceForm, notes: e.target.value})}
                  className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                  rows={3}
                />
              </div>
              <div className="flex justify-end space-x-3">
                <button
                  type="button"
                  onClick={() => setShowAttendanceForm(false)}
                  className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                >
                  {submitting ? 'Saving…' : 'Record Attendance'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* New Payroll Run Modal */}
      {showNewRunForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-sm">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">New Payroll Run</h3>
            <form onSubmit={handleCreateRun} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Month</label>
                <input type="month" required value={newRunMonth} onChange={(e) => setNewRunMonth(e.target.value)} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Generates one payslip per active employee, from their current basic salary, house allowance, and any active loan deductions. Every line item can be adjusted afterward.</p>
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowNewRunForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={creatingRun} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{creatingRun ? 'Creating…' : 'Create Run'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Edit Payslip Modal */}
      {editingPayslip && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                Edit Payslip — {editingPayslip.user ? `${editingPayslip.user.first_name} ${editingPayslip.user.last_name}` : ''}
              </h3>
              <button onClick={() => setEditingPayslip(null)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600"><X className="w-5 h-5" /></button>
            </div>
            <form onSubmit={handleSavePayslip} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                {PAYSLIP_EDIT_FIELDS.map(f => (
                  <div key={f.key}>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">{f.label}</label>
                    <input
                      type="number" min="0" step="0.01"
                      value={payslipForm[f.key] ?? ''}
                      onChange={(e) => setPayslipForm({ ...payslipForm, [f.key]: e.target.value })}
                      className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                ))}
              </div>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                Loan/advance deductions were pulled in automatically from Loans &amp; Advances when this run was created and aren't editable here.
              </p>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setEditingPayslip(null)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={savingPayslip} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingPayslip ? 'Saving…' : 'Save & Recalculate'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Payslip Print View */}
      {viewingPayslip && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4 print:bg-white print:relative print:inset-auto">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-8 w-full max-w-2xl max-h-[90vh] overflow-y-auto print:max-h-none print:shadow-none">
            <div className="flex items-center justify-between mb-6 print:hidden">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Payslip</h3>
              <div className="flex items-center space-x-3">
                <button onClick={() => window.print()} className="flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                  <Printer className="w-4 h-4 mr-2" /> Print / Save as PDF
                </button>
                <button onClick={() => setViewingPayslip(null)} className="text-gray-400 dark:text-gray-500 hover:text-gray-600"><X className="w-5 h-5" /></button>
              </div>
            </div>
            <div className="text-center mb-6">
              <h2 className="text-xl font-bold text-gray-900 dark:text-gray-100">MARA-WATER Ltd</h2>
              <p className="text-sm text-gray-500 dark:text-gray-400">Payslip — {selectedRun ? new Date(selectedRun.month + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'long' }) : ''}</p>
            </div>
            <div className="grid grid-cols-2 gap-4 mb-6 text-sm">
              <div><span className="text-gray-500 dark:text-gray-400">Employee:</span> <span className="text-gray-900 dark:text-gray-100 font-medium">{viewingPayslip.user ? `${viewingPayslip.user.first_name} ${viewingPayslip.user.last_name}` : ''}</span></div>
              <div><span className="text-gray-500 dark:text-gray-400">Staff No:</span> <span className="text-gray-900 dark:text-gray-100">{viewingPayslip.user?.staff_number || '—'}</span></div>
              <div><span className="text-gray-500 dark:text-gray-400">Department:</span> <span className="text-gray-900 dark:text-gray-100">{viewingPayslip.user?.department?.name || '—'}</span></div>
            </div>
            <div className="grid grid-cols-2 gap-6">
              <div>
                <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-1 mb-2">Earnings</h4>
                <dl className="space-y-1 text-sm">
                  <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Basic Pay</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.basic_pay)}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">House Allowance</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.house_allowance)}</dd></div>
                  {viewingPayslip.overtime_pay > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Overtime</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.overtime_pay)}</dd></div>}
                  {viewingPayslip.commission > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Commission</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.commission)}</dd></div>}
                  {viewingPayslip.leave_pay > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Leave Pay</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.leave_pay)}</dd></div>}
                  {viewingPayslip.telephone_allowance > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Telephone Allowance</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.telephone_allowance)}</dd></div>}
                  {viewingPayslip.other_allowance > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Other Allowance</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.other_allowance)}</dd></div>}
                  {viewingPayslip.bonus > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Bonus</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.bonus)}</dd></div>}
                  {viewingPayslip.absentism_deduction > 0 && <div className="flex justify-between text-red-600"><dt>Absenteeism Deduction</dt><dd>-{money(viewingPayslip.absentism_deduction)}</dd></div>}
                  <div className="flex justify-between font-semibold border-t border-gray-200 dark:border-gray-700 pt-1 mt-1"><dt className="text-gray-900 dark:text-gray-100">Gross Pay</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.gross_pay)}</dd></div>
                </dl>
              </div>
              <div>
                <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100 border-b border-gray-200 dark:border-gray-700 pb-1 mb-2">Deductions</h4>
                <dl className="space-y-1 text-sm">
                  <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">PAYE</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.paye)}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">NSSF</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.employee_nssf)}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">SHIF</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.shif)}</dd></div>
                  <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Housing Levy</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.housing_levy)}</dd></div>
                  {viewingPayslip.loan_deduction > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Loan</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.loan_deduction)}</dd></div>}
                  {viewingPayslip.staff_advance_deduction > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Staff Advance</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.staff_advance_deduction)}</dd></div>}
                  {viewingPayslip.sacco_loan_deduction > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Sacco Loan</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.sacco_loan_deduction)}</dd></div>}
                  {viewingPayslip.sacco_advance_deduction > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Sacco Advance</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.sacco_advance_deduction)}</dd></div>}
                  {viewingPayslip.sacco_contribution > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Sacco Contribution</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.sacco_contribution)}</dd></div>}
                  {viewingPayslip.bus_fare > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Bus Fare</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.bus_fare)}</dd></div>}
                  {viewingPayslip.insurance_deduction > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Insurance</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.insurance_deduction)}</dd></div>}
                  {viewingPayslip.other_deduction > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Other</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.other_deduction)}</dd></div>}
                  {viewingPayslip.penalties > 0 && <div className="flex justify-between"><dt className="text-gray-600 dark:text-gray-400">Penalties</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.penalties)}</dd></div>}
                  <div className="flex justify-between font-semibold border-t border-gray-200 dark:border-gray-700 pt-1 mt-1"><dt className="text-gray-900 dark:text-gray-100">Total Deductions</dt><dd className="text-gray-900 dark:text-gray-100">{money(viewingPayslip.total_deductions)}</dd></div>
                </dl>
              </div>
            </div>
            <div className="mt-6 pt-4 border-t-2 border-gray-900 dark:border-gray-100 flex justify-between items-center">
              <span className="text-lg font-bold text-gray-900 dark:text-gray-100">Net Salary Payable</span>
              <span className="text-lg font-bold text-gray-900 dark:text-gray-100">{money(viewingPayslip.net_salary)}</span>
            </div>
          </div>
        </div>
      )}

      {/* New Loan/Advance Modal */}
      {showLoanForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Record Advance / Loan</h3>
            <form onSubmit={handleCreateLoan} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Employee</label>
                <select required value={loanForm.user_id} onChange={(e) => setLoanForm({ ...loanForm, user_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="">Select Employee</option>
                  {employees.map((emp) => (<option key={emp.id} value={emp.id}>{emp.first_name} {emp.last_name}</option>))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Type</label>
                <select value={loanForm.type} onChange={(e) => setLoanForm({ ...loanForm, type: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="loan">Loan</option>
                  <option value="advance">Staff Advance</option>
                  <option value="sacco_loan">Sacco Loan</option>
                  <option value="sacco_advance">Sacco Advance</option>
                </select>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Principal (KES)</label>
                  <input required type="number" min="0.01" step="0.01" value={loanForm.principal} onChange={(e) => setLoanForm({ ...loanForm, principal: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Monthly Deduction (KES)</label>
                  <input required type="number" min="0.01" step="0.01" value={loanForm.monthly_deduction} onChange={(e) => setLoanForm({ ...loanForm, monthly_deduction: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Issued Date</label>
                <input required type="date" value={loanForm.issued_date} onChange={(e) => setLoanForm({ ...loanForm, issued_date: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                <textarea rows={2} value={loanForm.notes} onChange={(e) => setLoanForm({ ...loanForm, notes: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowLoanForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={savingLoan} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingLoan ? 'Saving…' : 'Record'}</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* New Salary Template Modal */}
      {showTemplateForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">New Salary Template</h3>
            <form onSubmit={handleCreateTemplate} className="space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Role Name</label>
                <input required type="text" placeholder="e.g. Driver, Production, Management" value={templateForm.name} onChange={(e) => setTemplateForm({ ...templateForm, name: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Basic Salary (KES)</label>
                  <input type="number" min="0" value={templateForm.basic_salary} onChange={(e) => setTemplateForm({ ...templateForm, basic_salary: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">House Allowance (KES)</label>
                  <input type="number" min="0" value={templateForm.house_allowance} onChange={(e) => setTemplateForm({ ...templateForm, house_allowance: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Telephone Allowance (KES)</label>
                  <input type="number" min="0" value={templateForm.telephone_allowance} onChange={(e) => setTemplateForm({ ...templateForm, telephone_allowance: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Other Allowance (KES)</label>
                  <input type="number" min="0" value={templateForm.other_allowance} onChange={(e) => setTemplateForm({ ...templateForm, other_allowance: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Terms of Employment</label>
                <input type="text" placeholder="e.g. Contract, Permanent" value={templateForm.terms_of_employment} onChange={(e) => setTemplateForm({ ...templateForm, terms_of_employment: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowTemplateForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                <button type="submit" disabled={savingTemplate} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">{savingTemplate ? 'Saving…' : 'Create Template'}</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default HRPage;
