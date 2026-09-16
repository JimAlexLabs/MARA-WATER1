import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Settings,
  Save,
  Shield,
  Bell,
  Globe,
  User as UserIcon,
  AlertTriangle,
  Lock,
  Unlock,
  Download,
  DatabaseBackup,
  History,
  X,
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { api } from '../services/api';
import { useAuth } from '../contexts/AuthContext';

const DEFAULT_SETTINGS = {
  company_name: 'MARA-WATER Ltd',
  company_email: 'info@marawater.com',
  company_phone: '+254700123456',
  company_address: 'Nairobi, Kenya',
  timezone: 'Africa/Nairobi',
  date_format: 'Y-m-d',
  time_format: 'H:i:s',
  language: 'en',
  notifications_enabled: true,
  email_notifications: true,
  sms_notifications: false,
  session_timeout: 30,
};

const SettingsPage: React.FC = () => {
  const [searchParams] = useSearchParams();
  const [activeTab, setActiveTab] = useState('general');
  const [settings, setSettings] = useState(DEFAULT_SETTINGS);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const { user, updateProfile } = useAuth();
  const isAdmin = user?.role?.code === 'ADMIN';

  useEffect(() => {
    const tab = searchParams.get('tab');
    if (tab) setActiveTab(tab);
  }, [searchParams]);

  useEffect(() => {
    api.get('/settings')
      .then((res) => {
        const saved = res.data?.data || {};
        setSettings((prev) => ({ ...prev, ...saved }));
      })
      .catch(() => {
        // No settings saved yet -- keep defaults.
      })
      .finally(() => setLoading(false));
  }, []);

  const handleSettingChange = (key: string, value: any) => {
    setSettings(prev => ({ ...prev, [key]: value }));
  };

  const handleSaveSettings = async () => {
    setSaving(true);
    try {
      await api.put('/settings', settings);
      toast.success('Settings saved successfully');
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to save settings');
    } finally {
      setSaving(false);
    }
  };

  // --- Profile tab ---
  const [profileForm, setProfileForm] = useState({
    first_name: user?.first_name || '',
    last_name: user?.last_name || '',
    phone: user?.phone || '',
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
  });
  const [savingProfile, setSavingProfile] = useState(false);

  const handleSaveProfile = async () => {
    setSavingProfile(true);
    try {
      const payload: any = {
        first_name: profileForm.first_name,
        last_name: profileForm.last_name,
        phone: profileForm.phone,
      };
      if (profileForm.new_password) {
        payload.current_password = profileForm.current_password;
        payload.new_password = profileForm.new_password;
        payload.new_password_confirmation = profileForm.new_password_confirmation;
      }
      const ok = await updateProfile(payload);
      if (ok) {
        setProfileForm(prev => ({ ...prev, current_password: '', new_password: '', new_password_confirmation: '' }));
      }
    } finally {
      setSavingProfile(false);
    }
  };

  // --- Danger Zone tab (backups + guarded full-data reset) ---
  const [dangerInfo, setDangerInfo] = useState<{ preserved: string[]; wiped: string[]; confirmation_phrase: string; restore_confirmation_phrase: string; retention_days: number } | null>(null);
  const [backups, setBackups] = useState<any[]>([]);
  const [resetLogs, setResetLogs] = useState<any[]>([]);
  const [dangerLoading, setDangerLoading] = useState(false);
  const [creatingBackup, setCreatingBackup] = useState(false);
  const [togglingLock, setTogglingLock] = useState(false);
  const [confirmText, setConfirmText] = useState('');
  const [resetting, setResetting] = useState(false);
  const dangerUnlocked = !!(settings as any).danger_zone_unlocked;

  const loadDangerZoneData = () => {
    setDangerLoading(true);
    Promise.all([
      api.get('/admin/danger-zone/tables'),
      api.get('/admin/backups'),
      api.get('/admin/reset-logs'),
    ]).then(([tablesRes, backupsRes, logsRes]) => {
      setDangerInfo(tablesRes.data.data);
      setBackups(backupsRes.data.data);
      setResetLogs(logsRes.data.data);
    }).catch(() => {
      toast.error('Could not load Danger Zone data');
    }).finally(() => setDangerLoading(false));
  };

  useEffect(() => {
    if (activeTab === 'danger' && isAdmin && !dangerInfo) {
      loadDangerZoneData();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab, isAdmin]);

  const toggleDangerZoneLock = async () => {
    setTogglingLock(true);
    try {
      const res = await api.put('/settings', { danger_zone_unlocked: !dangerUnlocked });
      setSettings((prev) => ({ ...prev, ...res.data.data }));
      toast.success(dangerUnlocked ? 'Danger Zone locked' : 'Danger Zone unlocked');
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update');
    } finally {
      setTogglingLock(false);
    }
  };

  const createBackupNow = async () => {
    setCreatingBackup(true);
    try {
      await api.post('/admin/backups');
      toast.success('Backup created');
      loadDangerZoneData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to create backup');
    } finally {
      setCreatingBackup(false);
    }
  };

  const downloadBackup = async (id: string) => {
    try {
      const res = await api.get(`/admin/backups/${id}/download`, { responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = `mara-water-backup-${id}.json`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Failed to download backup');
    }
  };

  const runReset = async () => {
    if (!dangerInfo || confirmText !== dangerInfo.confirmation_phrase) return;
    setResetting(true);
    try {
      const res = await api.post('/admin/reset', { confirmation: confirmText });
      toast.success(`Data cleared. Backup ${res.data.data.backup_id.slice(0, 8)}… was taken first.`);
      setConfirmText('');
      setSettings((prev) => ({ ...prev, danger_zone_unlocked: false }));
      loadDangerZoneData();
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Reset failed');
    } finally {
      setResetting(false);
    }
  };

  // --- Restore a backup ---
  const [restoringBackup, setRestoringBackup] = useState<{ id: string; created_at: string } | null>(null);
  const [restoreConfirmText, setRestoreConfirmText] = useState('');
  const [restoring, setRestoring] = useState(false);

  const runRestore = async () => {
    if (!restoringBackup || !dangerInfo || restoreConfirmText !== dangerInfo.restore_confirmation_phrase) return;
    setRestoring(true);
    try {
      const res = await api.post(`/admin/backups/${restoringBackup.id}/restore`, { confirmation: restoreConfirmText });
      toast.success(res.data.message || 'Backup restored');
      setRestoringBackup(null);
      setRestoreConfirmText('');
      setSettings((prev) => ({ ...prev, danger_zone_unlocked: false }));
      loadDangerZoneData();
    } catch (error: any) {
      if (error.response?.status === 403) {
        toast.error('Danger Zone is locked. Unlock it first, then try Restore again.');
      } else {
        toast.error(error.response?.data?.message || 'Restore failed');
      }
    } finally {
      setRestoring(false);
    }
  };

  const tabs = [
    { id: 'profile', name: 'Profile', icon: UserIcon },
    { id: 'general', name: 'General', icon: Settings },
    { id: 'notifications', name: 'Notifications', icon: Bell },
    { id: 'appearance', name: 'Locale', icon: Globe },
    { id: 'security', name: 'Security', icon: Shield },
    ...(isAdmin ? [{ id: 'danger', name: 'Danger Zone', icon: AlertTriangle }] : []),
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">System Settings</h1>
          <p className="text-gray-600">Configure system preferences and options</p>
        </div>
        {activeTab !== 'profile' && activeTab !== 'security' && activeTab !== 'danger' && (
          <div className="flex space-x-3">
            <button
              onClick={handleSaveSettings}
              disabled={saving || !isAdmin}
              className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
              title={isAdmin ? '' : 'Only an administrator can change system settings'}
            >
              <Save className="w-4 h-4 mr-2" />
              {saving ? 'Saving…' : 'Save Settings'}
            </button>
          </div>
        )}
      </div>

      {!isAdmin && activeTab !== 'profile' && (
        <div className="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3">
          You're viewing system settings as read-only. Only an administrator (Director role) can change them.
        </div>
      )}

      {/* Settings Tabs */}
      <div className="bg-white rounded-lg shadow">
        <div className="border-b border-gray-200">
          <nav className="flex space-x-8 px-6">
            {tabs.map((tab) => {
              const Icon = tab.icon;
              return (
                <button
                  key={tab.id}
                  onClick={() => setActiveTab(tab.id)}
                  className={`py-4 px-1 border-b-2 font-medium text-sm flex items-center space-x-2 ${
                    activeTab === tab.id
                      ? 'border-blue-500 text-blue-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                  }`}
                >
                  <Icon className="w-4 h-4" />
                  <span>{tab.name}</span>
                </button>
              );
            })}
          </nav>
        </div>

        <div className="p-6">
          {loading ? (
            <p className="text-sm text-gray-500">Loading…</p>
          ) : (
            <>
              {/* Profile */}
              {activeTab === 'profile' && (
                <div className="space-y-6 max-w-lg">
                  <h3 className="text-lg font-medium text-gray-900">Your Profile</h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                      <label className="block text-sm font-medium text-gray-700">First Name</label>
                      <input
                        type="text"
                        value={profileForm.first_name}
                        onChange={(e) => setProfileForm(p => ({ ...p, first_name: e.target.value }))}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium text-gray-700">Last Name</label>
                      <input
                        type="text"
                        value={profileForm.last_name}
                        onChange={(e) => setProfileForm(p => ({ ...p, last_name: e.target.value }))}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700">Phone</label>
                    <input
                      type="tel"
                      value={profileForm.phone}
                      onChange={(e) => setProfileForm(p => ({ ...p, phone: e.target.value }))}
                      className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                  <div className="border-t border-gray-200 pt-4">
                    <h4 className="text-sm font-medium text-gray-900 mb-3">Change Password (optional)</h4>
                    <div className="space-y-3">
                      <input
                        type="password"
                        placeholder="Current password"
                        value={profileForm.current_password}
                        onChange={(e) => setProfileForm(p => ({ ...p, current_password: e.target.value }))}
                        className="block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                      <input
                        type="password"
                        placeholder="New password"
                        value={profileForm.new_password}
                        onChange={(e) => setProfileForm(p => ({ ...p, new_password: e.target.value }))}
                        className="block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                      <input
                        type="password"
                        placeholder="Confirm new password"
                        value={profileForm.new_password_confirmation}
                        onChange={(e) => setProfileForm(p => ({ ...p, new_password_confirmation: e.target.value }))}
                        className="block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                  </div>
                  <button
                    onClick={handleSaveProfile}
                    disabled={savingProfile}
                    className="flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                  >
                    <Save className="w-4 h-4 mr-2" />
                    {savingProfile ? 'Saving…' : 'Save Profile'}
                  </button>
                </div>
              )}

              {/* General Settings */}
              {activeTab === 'general' && (
                <div className="space-y-6">
                  <h3 className="text-lg font-medium text-gray-900">General Settings</h3>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                      <label className="block text-sm font-medium text-gray-700">Company Name</label>
                      <input
                        type="text"
                        disabled={!isAdmin}
                        value={settings.company_name}
                        onChange={(e) => handleSettingChange('company_name', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700">Company Email</label>
                      <input
                        type="email"
                        disabled={!isAdmin}
                        value={settings.company_email}
                        onChange={(e) => handleSettingChange('company_email', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700">Company Phone</label>
                      <input
                        type="tel"
                        disabled={!isAdmin}
                        value={settings.company_phone}
                        onChange={(e) => handleSettingChange('company_phone', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                      />
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700">Session Timeout (minutes)</label>
                      <input
                        type="number"
                        disabled={!isAdmin}
                        value={settings.session_timeout}
                        onChange={(e) => handleSettingChange('session_timeout', parseInt(e.target.value) || 0)}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                      />
                      <p className="text-xs text-gray-500 mt-1">Recorded for reference only right now — not yet enforced on login sessions.</p>
                    </div>
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-gray-700">Company Address</label>
                    <textarea
                      disabled={!isAdmin}
                      value={settings.company_address}
                      onChange={(e) => handleSettingChange('company_address', e.target.value)}
                      rows={3}
                      className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                    />
                  </div>
                </div>
              )}

              {/* Notification Settings */}
              {activeTab === 'notifications' && (
                <div className="space-y-6">
                  <h3 className="text-lg font-medium text-gray-900">Notification Settings</h3>
                  <p className="text-sm text-gray-500">
                    These preferences are saved, but the app doesn't send email or SMS yet — only
                    in-app notifications (the bell icon, top right) exist today.
                  </p>

                  {[
                    { key: 'notifications_enabled', label: 'In-app Notifications', desc: 'Receive notifications about system events' },
                    { key: 'email_notifications', label: 'Email Notifications', desc: 'Receive notifications via email (not yet sent — no email delivery is wired up)' },
                    { key: 'sms_notifications', label: 'SMS Notifications', desc: 'Receive notifications via SMS (not yet sent — no SMS gateway is wired up)' },
                  ].map(({ key, label, desc }) => (
                    <div key={key} className="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                      <div>
                        <h4 className="text-sm font-medium text-gray-900">{label}</h4>
                        <p className="text-sm text-gray-600">{desc}</p>
                      </div>
                      <label className="relative inline-flex items-center cursor-pointer">
                        <input
                          type="checkbox"
                          disabled={!isAdmin}
                          checked={(settings as any)[key]}
                          onChange={(e) => handleSettingChange(key, e.target.checked)}
                          className="sr-only peer"
                        />
                        <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                      </label>
                    </div>
                  ))}
                </div>
              )}

              {/* Locale Settings */}
              {activeTab === 'appearance' && (
                <div className="space-y-6">
                  <h3 className="text-lg font-medium text-gray-900">Locale</h3>
                  <p className="text-sm text-gray-500">
                    Saved for the app to use going forward. Translations and locale-aware date/time
                    rendering across pages aren't wired up yet, so changing these doesn't visibly
                    reformat existing pages today.
                  </p>

                  <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                      <label className="block text-sm font-medium text-gray-700">Timezone</label>
                      <select
                        disabled={!isAdmin}
                        value={settings.timezone}
                        onChange={(e) => handleSettingChange('timezone', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                      >
                        <option value="Africa/Nairobi">Africa/Nairobi</option>
                        <option value="UTC">UTC</option>
                      </select>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700">Language</label>
                      <select
                        disabled={!isAdmin}
                        value={settings.language}
                        onChange={(e) => handleSettingChange('language', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                      >
                        <option value="en">English</option>
                        <option value="sw">Swahili</option>
                      </select>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700">Date Format</label>
                      <select
                        disabled={!isAdmin}
                        value={settings.date_format}
                        onChange={(e) => handleSettingChange('date_format', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                      >
                        <option value="Y-m-d">YYYY-MM-DD</option>
                        <option value="d/m/Y">DD/MM/YYYY</option>
                        <option value="m/d/Y">MM/DD/YYYY</option>
                      </select>
                    </div>

                    <div>
                      <label className="block text-sm font-medium text-gray-700">Time Format</label>
                      <select
                        disabled={!isAdmin}
                        value={settings.time_format}
                        onChange={(e) => handleSettingChange('time_format', e.target.value)}
                        className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                      >
                        <option value="H:i:s">24-hour</option>
                        <option value="h:i:s A">12-hour</option>
                      </select>
                    </div>
                  </div>
                </div>
              )}

              {/* Security */}
              {activeTab === 'security' && (
                <div className="space-y-4">
                  <h3 className="text-lg font-medium text-gray-900">Security</h3>
                  <p className="text-sm text-gray-500">
                    Two-factor authentication, a configurable password policy, and API rate limiting
                    aren't built yet — removed the buttons that used to sit here and did nothing
                    when clicked, rather than leave them decorative. Change your password from the
                    Profile tab.
                  </p>
                </div>
              )}

              {/* Danger Zone */}
              {activeTab === 'danger' && isAdmin && (
                <div className="space-y-8">
                  <div>
                    <h3 className="text-lg font-medium text-gray-900">Backups</h3>
                    <p className="text-sm text-gray-500 mt-1">
                      A full snapshot of every business table, downloadable as JSON. Take one any
                      time — this doesn't touch any data. Kept for
                      {dangerInfo ? ` ${dangerInfo.retention_days} days` : ' 400 days'} (a full financial
                      year plus margin); the most recent backup is never auto-deleted regardless of age.
                      A backup is also taken automatically before a reset and before a restore.
                    </p>
                    <button
                      onClick={createBackupNow}
                      disabled={creatingBackup}
                      className="mt-3 flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                    >
                      <DatabaseBackup className="w-4 h-4 mr-2" />
                      {creatingBackup ? 'Creating…' : 'Create Backup Now'}
                    </button>

                    {dangerLoading ? (
                      <p className="text-sm text-gray-500 mt-4">Loading…</p>
                    ) : (
                      <div className="mt-4 border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-56 overflow-y-auto">
                        {backups.length === 0 ? (
                          <p className="text-sm text-gray-500 p-4">No backups yet.</p>
                        ) : (
                          backups.map((b) => (
                            <div key={b.id} className="flex items-center justify-between px-4 py-2.5">
                              <div>
                                <p className="text-sm text-gray-900">
                                  {new Date(b.created_at).toLocaleString()}
                                  <span className="ml-2 text-xs uppercase tracking-wide text-gray-400">{b.reason.replace('_', ' ')}</span>
                                </p>
                                <p className="text-xs text-gray-500">{b.total_rows.toLocaleString()} rows · {(b.size_bytes / 1024).toFixed(0)} KB{b.created_by ? ` · by ${b.created_by}` : ''}</p>
                              </div>
                              <div className="flex items-center space-x-4">
                                <button
                                  onClick={() => setRestoringBackup({ id: b.id, created_at: b.created_at })}
                                  className="flex items-center text-sm text-amber-600 hover:text-amber-800 font-medium"
                                >
                                  <History className="w-4 h-4 mr-1" /> Restore
                                </button>
                                <button
                                  onClick={() => downloadBackup(b.id)}
                                  className="flex items-center text-sm text-blue-600 hover:text-blue-800 font-medium"
                                >
                                  <Download className="w-4 h-4 mr-1" /> Download
                                </button>
                              </div>
                            </div>
                          ))
                        )}
                      </div>
                    )}
                  </div>

                  <div className="border-t border-gray-200 pt-6">
                    <div className="flex items-center justify-between">
                      <div>
                        <h3 className="text-lg font-medium text-red-700 flex items-center">
                          <AlertTriangle className="w-5 h-5 mr-2" /> Clear All Data
                        </h3>
                        <p className="text-sm text-gray-500 mt-1 max-w-2xl">
                          Wipes operational data (orders, invoices, production, inventory, attendance,
                          fleet logs, and more) for a fresh launch. Logins, the product/customer-route
                          catalog, pricing, the vehicle registry, and settings are preserved. A backup
                          is always taken automatically first.
                        </p>
                      </div>
                      <button
                        onClick={toggleDangerZoneLock}
                        disabled={togglingLock}
                        className={`flex items-center px-4 py-2 rounded-lg font-medium flex-shrink-0 ml-4 ${
                          dangerUnlocked ? 'bg-gray-200 text-gray-700 hover:bg-gray-300' : 'bg-gray-900 text-white hover:bg-gray-800'
                        }`}
                      >
                        {dangerUnlocked ? <Unlock className="w-4 h-4 mr-2" /> : <Lock className="w-4 h-4 mr-2" />}
                        {dangerUnlocked ? 'Unlocked — click to lock' : 'Unlock Danger Zone'}
                      </button>
                    </div>

                    {dangerUnlocked && dangerInfo && (
                      <div className="mt-4 border border-red-200 bg-red-50 rounded-lg p-5 space-y-4">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                          <div>
                            <p className="font-medium text-gray-900 mb-1">Will be wiped ({dangerInfo.wiped.length} tables)</p>
                            <p className="text-gray-600 max-h-24 overflow-y-auto">{dangerInfo.wiped.join(', ')}</p>
                          </div>
                          <div>
                            <p className="font-medium text-gray-900 mb-1">Preserved ({dangerInfo.preserved.length} tables)</p>
                            <p className="text-gray-600 max-h-24 overflow-y-auto">{dangerInfo.preserved.join(', ')}</p>
                          </div>
                        </div>

                        <div>
                          <label className="block text-sm font-medium text-gray-900">
                            Type <span className="font-mono bg-white px-1.5 py-0.5 rounded border border-gray-300">{dangerInfo.confirmation_phrase}</span> to confirm
                          </label>
                          <input
                            type="text"
                            value={confirmText}
                            onChange={(e) => setConfirmText(e.target.value)}
                            className="mt-1 block w-full max-w-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500"
                            placeholder={dangerInfo.confirmation_phrase}
                          />
                        </div>

                        <button
                          onClick={runReset}
                          disabled={resetting || confirmText !== dangerInfo.confirmation_phrase}
                          className="flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                          <AlertTriangle className="w-4 h-4 mr-2" />
                          {resetting ? 'Backing up and clearing…' : 'Back Up & Clear All Data'}
                        </button>
                      </div>
                    )}
                  </div>

                  <div className="border-t border-gray-200 pt-6">
                    <h3 className="text-sm font-semibold text-gray-900">Reset history</h3>
                    <p className="text-xs text-gray-500 mb-3">Permanent — survives the reset it records.</p>
                    <div className="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-40 overflow-y-auto">
                      {resetLogs.length === 0 ? (
                        <p className="text-sm text-gray-500 p-4">No resets have been run.</p>
                      ) : (
                        resetLogs.map((l) => (
                          <div key={l.id} className="px-4 py-2.5 text-sm">
                            <span className="text-gray-900">{l.performed_by_email}</span>
                            <span className="text-gray-500"> · {new Date(l.created_at).toLocaleString()} · {l.tables_wiped.length} tables</span>
                          </div>
                        ))
                      )}
                    </div>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      </div>

      {/* Restore Backup Modal */}
      {restoringBackup && dangerInfo && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg p-6 w-full max-w-lg">
            <div className="flex justify-between items-center mb-4">
              <h3 className="text-lg font-medium text-red-700 flex items-center">
                <History className="w-5 h-5 mr-2" /> Restore Backup
              </h3>
              <button onClick={() => { setRestoringBackup(null); setRestoreConfirmText(''); }} className="text-gray-400 hover:text-gray-600">
                <X className="w-5 h-5" />
              </button>
            </div>
            <p className="text-sm text-gray-600 mb-4">
              This overwrites the current database with exactly what it looked like on{' '}
              <span className="font-medium text-gray-900">{new Date(restoringBackup.created_at).toLocaleString()}</span>.
              A safety backup of the current state is taken automatically first, so this can itself be
              undone by restoring that one if you pick the wrong backup.
            </p>
            {!dangerUnlocked ? (
              <div className="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3">
                Danger Zone is locked. Close this, unlock it below, then click Restore again.
              </div>
            ) : (
              <div className="border border-red-200 bg-red-50 rounded-lg p-4 space-y-3">
                <label className="block text-sm font-medium text-gray-900">
                  Type <span className="font-mono bg-white px-1.5 py-0.5 rounded border border-gray-300">{dangerInfo.restore_confirmation_phrase}</span> to confirm
                </label>
                <input
                  type="text"
                  value={restoreConfirmText}
                  onChange={(e) => setRestoreConfirmText(e.target.value)}
                  className="block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500"
                  placeholder={dangerInfo.restore_confirmation_phrase}
                />
                <button
                  onClick={runRestore}
                  disabled={restoring || restoreConfirmText !== dangerInfo.restore_confirmation_phrase}
                  className="flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  <AlertTriangle className="w-4 h-4 mr-2" />
                  {restoring ? 'Restoring…' : 'Overwrite Current Data With This Backup'}
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default SettingsPage;
