import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Settings,
  Save,
  Shield,
  Bell,
  Globe,
  User as UserIcon,
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

  const tabs = [
    { id: 'profile', name: 'Profile', icon: UserIcon },
    { id: 'general', name: 'General', icon: Settings },
    { id: 'notifications', name: 'Notifications', icon: Bell },
    { id: 'appearance', name: 'Locale', icon: Globe },
    { id: 'security', name: 'Security', icon: Shield },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">System Settings</h1>
          <p className="text-gray-600">Configure system preferences and options</p>
        </div>
        {activeTab !== 'profile' && activeTab !== 'security' && (
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
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default SettingsPage;
