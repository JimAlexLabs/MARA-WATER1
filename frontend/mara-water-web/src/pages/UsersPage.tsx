import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Plus,
  Search,
  Users,
  Shield,
  Phone,
  Edit,
  Trash2,
  CheckCircle,
  XCircle,
  Upload,
  X,
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';

interface Role { id: string; code: string; name: string; }
interface Department { id: string; code: string; name: string; }

interface User {
  id: string;
  first_name: string;
  last_name: string;
  email: string | null;
  phone: string;
  id_number: string | null;
  employment_date: string | null;
  salary?: number | null;
  role: Role;
  department: Department;
  status: 'active' | 'inactive' | 'suspended';
  created_at: string;
}

const EMPTY_FORM = {
  first_name: '', last_name: '', phone: '', email: '',
  id_number: '', role_id: '', department_id: '',
  employment_date: '', salary: '', status: 'active',
  password: '',
};

// What a pasted/CSV row needs, in this exact order, for the bulk importer.
const BULK_COLUMNS = ['first_name', 'last_name', 'phone', 'id_number', 'department', 'role', 'employment_date', 'salary', 'status'];

const UsersPage: React.FC = () => {
  const [users, setUsers] = useState<User[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [departments, setDepartments] = useState<Department[]>([]);
  const [loading, setLoading] = useState(true);
  const [searchTerm, setSearchTerm] = useState('');
  const [searchParams] = useSearchParams();

  useEffect(() => {
    const q = searchParams.get('q');
    if (q) setSearchTerm(q);
  }, [searchParams]);

  const [filterStatus, setFilterStatus] = useState('all');
  const [showUserForm, setShowUserForm] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [userForm, setUserForm] = useState(EMPTY_FORM);
  const [saving, setSaving] = useState(false);

  const [showBulkImport, setShowBulkImport] = useState(false);
  const [bulkText, setBulkText] = useState('');
  const [bulkPreview, setBulkPreview] = useState<Record<string, string>[]>([]);
  const [bulkResult, setBulkResult] = useState<{ created: any[]; errors: any[] } | null>(null);
  const [bulkSubmitting, setBulkSubmitting] = useState(false);

  useEffect(() => {
    fetchData();
    api.get('/users/roles').then(res => setRoles(res.data.data)).catch(() => {});
    api.get('/users/departments').then(res => setDepartments(res.data.data)).catch(() => {});
  }, []);

  const fetchData = async () => {
    try {
      setLoading(true);
      const response = await api.get('/users');
      setUsers(response.data.data);
    } catch (error) {
      toast.error('Failed to fetch users data');
    } finally {
      setLoading(false);
    }
  };

  const openNewForm = () => {
    setEditingId(null);
    setUserForm(EMPTY_FORM);
    setShowUserForm(true);
  };

  const openEditForm = (u: User) => {
    setEditingId(u.id);
    setUserForm({
      first_name: u.first_name, last_name: u.last_name, phone: u.phone, email: u.email || '',
      id_number: u.id_number || '', role_id: u.role?.id || '', department_id: u.department?.id || '',
      employment_date: u.employment_date ? u.employment_date.slice(0, 10) : '',
      salary: u.salary != null ? String(u.salary) : '', status: u.status, password: '',
    });
    setShowUserForm(true);
  };

  const handleUserSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    const payload: any = { ...userForm };
    if (!payload.password) delete payload.password;
    try {
      if (editingId) {
        await api.put(`/users/${editingId}`, payload);
        toast.success('Employee updated');
      } else {
        await api.post('/users', payload);
        toast.success('Employee created');
      }
      setShowUserForm(false);
      fetchData();
    } catch (error: any) {
      const errors = error.response?.data?.errors;
      const firstError = errors ? Object.values(errors)[0] : null;
      toast.error((Array.isArray(firstError) ? firstError[0] : firstError) || error.response?.data?.message || 'Failed to save employee');
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (u: User) => {
    if (!window.confirm(`Remove ${u.first_name} ${u.last_name}? This can't be undone.`)) return;
    try {
      await api.delete(`/users/${u.id}`);
      toast.success('Employee removed');
      fetchData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to remove employee');
    }
  };

  // --- Bulk import: parse pasted spreadsheet rows or an uploaded CSV into
  // { first_name, last_name, phone, id_number, department, role,
  //   employment_date, salary, status } rows, previewed before submitting. ---
  const parseBulkText = (text: string) => {
    const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    const rows = lines.map((line) => {
      const cells = line.includes('\t') ? line.split('\t') : line.split(',');
      const row: Record<string, string> = {};
      BULK_COLUMNS.forEach((col, i) => { row[col] = (cells[i] || '').trim(); });
      return row;
    });
    setBulkPreview(rows);
  };

  const handleBulkFile = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result || '');
      setBulkText(text);
      parseBulkText(text);
    };
    reader.readAsText(file);
  };

  const submitBulkImport = async () => {
    if (bulkPreview.length === 0) return;
    setBulkSubmitting(true);
    try {
      const res = await api.post('/users/bulk', { employees: bulkPreview });
      setBulkResult(res.data.data);
      toast.success(res.data.message);
      fetchData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Bulk import failed');
    } finally {
      setBulkSubmitting(false);
    }
  };

  const closeBulkImport = () => {
    setShowBulkImport(false);
    setBulkText('');
    setBulkPreview([]);
    setBulkResult(null);
  };

  const filteredUsers = users.filter(user => {
    const matchesSearch = user.first_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                         user.last_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                         (user.email || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                         (user.id_number || '').toLowerCase().includes(searchTerm.toLowerCase());
    const matchesStatus = filterStatus === 'all' || user.status === filterStatus;
    return matchesSearch && matchesStatus;
  });

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'active': return 'text-green-600 bg-green-100';
      case 'inactive': return 'text-red-600 bg-red-100';
      default: return 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-gray-700';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'active': return <CheckCircle className="w-4 h-4" />;
      case 'inactive': return <XCircle className="w-4 h-4" />;
      default: return <CheckCircle className="w-4 h-4" />;
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
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Employees</h1>
          <p className="text-gray-600 dark:text-gray-400">Staff records used across Fleet, Sales, and HR — not just system logins</p>
        </div>
        <div className="flex space-x-3">
          <button
            onClick={() => setShowBulkImport(true)}
            className="flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
          >
            <Upload className="w-4 h-4 mr-2" />
            Bulk Import
          </button>
          <button
            onClick={openNewForm}
            className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            <Plus className="w-4 h-4 mr-2" />
            New Employee
          </button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Users className="w-8 h-8 text-blue-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Total Employees</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{users.length}</p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <CheckCircle className="w-8 h-8 text-green-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Active</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {users.filter(u => u.status === 'active').length}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <Shield className="w-8 h-8 text-purple-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Roles</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {new Set(users.map(u => u.role?.name)).size}
              </p>
            </div>
          </div>
        </div>
        <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
          <div className="flex items-center">
            <XCircle className="w-8 h-8 text-red-600" />
            <div className="ml-4">
              <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Inactive</p>
              <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">
                {users.filter(u => u.status === 'inactive').length}
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Users Table */}
      <div className="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Employees</h3>
        </div>
        <div className="p-6">
          <div className="flex flex-col md:flex-row gap-4 mb-6">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 dark:text-gray-500 w-4 h-4" />
                <input
                  type="text"
                  placeholder="Search by name, email, ID number..."
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  className="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            >
              <option value="all">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>

          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
              <thead className="bg-gray-50 dark:bg-gray-900">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Employee</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Contact</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Department</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Role</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                {filteredUsers.map((user) => (
                  <tr key={user.id} className="hover:bg-gray-50 dark:hover:bg-gray-700">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm font-medium text-gray-900 dark:text-gray-100">{user.first_name} {user.last_name}</div>
                      <div className="text-sm text-gray-500 dark:text-gray-400">{user.id_number ? `ID: ${user.id_number}` : 'No ID number on file'}</div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900 dark:text-gray-100">
                        <div className="flex items-center">
                          <Phone className="w-4 h-4 mr-2 text-gray-400 dark:text-gray-500" />
                          {user.phone}
                        </div>
                        {!user.email && <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">No app login</div>}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">{user.department?.name || '—'}</td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="flex items-center">
                        <Shield className="w-4 h-4 mr-2 text-purple-600" />
                        <span className="text-sm font-medium text-gray-900 dark:text-gray-100">{user.role?.name || '—'}</span>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusColor(user.status)}`}>
                        {getStatusIcon(user.status)}
                        <span className="ml-1">{user.status}</span>
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                      <div className="flex items-center space-x-3">
                        <button onClick={() => openEditForm(user)} className="text-green-600 hover:text-green-900">
                          <Edit className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(user)} className="text-red-600 hover:text-red-900">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
                {filteredUsers.length === 0 && (
                  <tr><td colSpan={6} className="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No employees match.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Single Employee Form Modal */}
      {showUserForm && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">{editingId ? 'Edit Employee' : 'New Employee'}</h3>
            <form onSubmit={handleUserSubmit} className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">First Name</label>
                  <input required type="text" value={userForm.first_name} onChange={(e) => setUserForm({ ...userForm, first_name: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Last Name</label>
                  <input required type="text" value={userForm.last_name} onChange={(e) => setUserForm({ ...userForm, last_name: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                  <input required type="tel" value={userForm.phone} onChange={(e) => setUserForm({ ...userForm, phone: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">ID Number</label>
                  <input type="text" value={userForm.id_number} onChange={(e) => setUserForm({ ...userForm, id_number: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Department</label>
                  <select required value={userForm.department_id} onChange={(e) => setUserForm({ ...userForm, department_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Select department</option>
                    {departments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Role</label>
                  <select required value={userForm.role_id} onChange={(e) => setUserForm({ ...userForm, role_id: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Select role</option>
                    {roles.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Employment Date</label>
                  <input type="date" value={userForm.employment_date} onChange={(e) => setUserForm({ ...userForm, employment_date: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Salary (KES)</label>
                  <input type="number" min="0" value={userForm.salary} onChange={(e) => setUserForm({ ...userForm, salary: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                <select value={userForm.status} onChange={(e) => setUserForm({ ...userForm, status: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                  <option value="suspended">Suspended</option>
                </select>
              </div>

              <div className="border-t border-gray-200 dark:border-gray-700 pt-4">
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">Only needed if this person should be able to log into the app.</p>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Email (optional)</label>
                    <input type="email" value={userForm.email} onChange={(e) => setUserForm({ ...userForm, email: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">{editingId ? 'Reset password (optional)' : 'Password (optional)'}</label>
                    <input type="password" value={userForm.password} onChange={(e) => setUserForm({ ...userForm, password: e.target.value })} className="mt-1 block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                  </div>
                </div>
              </div>

              <div className="flex justify-end space-x-3">
                <button type="button" onClick={() => setShowUserForm(false)} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                  Cancel
                </button>
                <button type="submit" disabled={saving} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50">
                  {saving ? 'Saving…' : editingId ? 'Save Changes' : 'Create Employee'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Bulk Import Modal */}
      {showBulkImport && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">Bulk Import Employees</h3>
              <button onClick={closeBulkImport} className="text-gray-400 dark:text-gray-500 hover:text-gray-600"><X className="w-5 h-5" /></button>
            </div>

            {!bulkResult ? (
              <>
                <p className="text-sm text-gray-600 dark:text-gray-400 mb-2">
                  Paste rows copied from Excel/Google Sheets, or upload a CSV. One employee per line, columns in this order:
                </p>
                <p className="text-xs font-mono bg-gray-100 dark:bg-gray-700 rounded px-2 py-1.5 mb-3 overflow-x-auto whitespace-nowrap">
                  First Name, Last Name, Phone, ID Number, Department, Role, Employment Date (YYYY-MM-DD), Salary, Status
                </p>
                <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                  Department and Role are matched by name or code (e.g. "Fleet Management" or "FLEET"). ID Number, Employment
                  Date, Salary, and Status are optional — leave the cell blank. No email or password needed unless this
                  person should log into the app (add those individually afterward).
                </p>

                <div className="flex items-center gap-3 mb-3">
                  <label className="flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg text-sm cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700">
                    <Upload className="w-4 h-4 mr-2" /> Upload CSV
                    <input type="file" accept=".csv,text/csv,text/plain" className="hidden" onChange={handleBulkFile} />
                  </label>
                  <span className="text-xs text-gray-400 dark:text-gray-500">or paste below</span>
                </div>

                <textarea
                  value={bulkText}
                  onChange={(e) => { setBulkText(e.target.value); parseBulkText(e.target.value); }}
                  rows={6}
                  placeholder={'Ishmael\tOchieng\t+254712345678\t12345678\tFleet Management\tDriver\t2023-01-15\t35000\tactive'}
                  className="block w-full border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-3 py-2 font-mono text-xs focus:outline-none focus:ring-2 focus:ring-blue-500"
                />

                {bulkPreview.length > 0 && (
                  <div className="mt-4">
                    <p className="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">Preview ({bulkPreview.length} row{bulkPreview.length === 1 ? '' : 's'})</p>
                    <div className="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg max-h-48 overflow-y-auto">
                      <table className="min-w-full text-xs">
                        <thead className="bg-gray-50 dark:bg-gray-900">
                          <tr>{BULK_COLUMNS.map(c => <th key={c} className="px-3 py-1.5 text-left font-medium text-gray-500 dark:text-gray-400">{c.replace('_', ' ')}</th>)}</tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                          {bulkPreview.map((row, i) => (
                            <tr key={i}>{BULK_COLUMNS.map(c => <td key={c} className="px-3 py-1.5 text-gray-700 dark:text-gray-300 whitespace-nowrap">{row[c] || '—'}</td>)}</tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}

                <div className="flex justify-end space-x-3 mt-4">
                  <button onClick={closeBulkImport} className="px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                  <button
                    onClick={submitBulkImport}
                    disabled={bulkPreview.length === 0 || bulkSubmitting}
                    className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                  >
                    {bulkSubmitting ? 'Importing…' : `Import ${bulkPreview.length} Employee${bulkPreview.length === 1 ? '' : 's'}`}
                  </button>
                </div>
              </>
            ) : (
              <div className="space-y-4">
                <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                  <p className="text-sm font-medium text-green-800">{bulkResult.created.length} employee(s) created</p>
                </div>
                {bulkResult.errors.length > 0 && (
                  <div className="bg-amber-50 border border-amber-200 rounded-lg p-4">
                    <p className="text-sm font-medium text-amber-800 mb-2">{bulkResult.errors.length} row(s) skipped</p>
                    <ul className="text-xs text-amber-700 space-y-1 max-h-40 overflow-y-auto">
                      {bulkResult.errors.map((err: any, i: number) => (
                        <li key={i}>Row {err.row}: {err.error}</li>
                      ))}
                    </ul>
                  </div>
                )}
                <div className="flex justify-end">
                  <button onClick={closeBulkImport} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Done</button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default UsersPage;
