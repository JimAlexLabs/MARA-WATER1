import React, { useState, useEffect, useRef } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { api } from '../services/api';
import ErrorBoundary from './ErrorBoundary';
import {
  Menu,
  X,
  Home,
  Droplets,
  Package,
  Truck,
  Users,
  BarChart3,
  Settings,
  LogOut,
  User,
  Bell,
  ChevronDown,
  Search,
  FileText,
  Factory,
  Loader2,
  Sun,
  Moon,
  TrendingUp,
  Tag,
  MessageSquare
} from 'lucide-react';

interface SearchResult {
  type: string;
  label: string;
  sublabel: string;
  route: string;
}

interface AppNotification {
  id: string;
  type: string;
  message: string;
  time: string;
  read: boolean;
}

interface LayoutProps {
  children: React.ReactNode;
}

const Layout: React.FC<LayoutProps> = ({ children }) => {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const location = useLocation();
  const navigate = useNavigate();
  const { user, logout, theme, toggleTheme } = useAuth();

  // --- Global search ---
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<SearchResult[]>([]);
  const [searchOpen, setSearchOpen] = useState(false);
  const [searchLoading, setSearchLoading] = useState(false);
  const searchBoxRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const q = searchQuery.trim();
    if (q.length < 2) {
      setSearchResults([]);
      setSearchOpen(false);
      return;
    }
    setSearchLoading(true);
    const timer = setTimeout(() => {
      api.get('/search', { params: { q } })
        .then((res) => {
          setSearchResults(res.data.data || []);
          setSearchOpen(true);
        })
        .catch(() => setSearchResults([]))
        .finally(() => setSearchLoading(false));
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (searchBoxRef.current && !searchBoxRef.current.contains(e.target as Node)) {
        setSearchOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const goToSearchResult = (result: SearchResult) => {
    navigate(result.route);
    setSearchQuery('');
    setSearchResults([]);
    setSearchOpen(false);
  };

  // --- Notifications ---
  const [notifications, setNotifications] = useState<AppNotification[]>([]);

  const loadNotifications = () => {
    api.get('/notifications').then((res) => {
      const raw = res.data?.data ?? [];
      setNotifications(raw.map((n: any) => ({
        id: n.id,
        type: n.type ?? 'info',
        message: n.message ?? n.title ?? 'Notification',
        time: n.created_at ?? '',
        read: !!n.read_at,
      })));
    }).catch(() => setNotifications([]));
  };

  useEffect(() => {
    loadNotifications();
  }, []);

  const markAllNotificationsRead = () => {
    api.post('/notifications/mark-all-read').then(() => loadNotifications()).catch(() => {});
  };

  const navigation = [
    { 
      name: 'Dashboard', 
      href: '/dashboard', 
      icon: Home,
      description: 'Overview and KPIs'
    },
    {
      name: 'Analytics',
      href: '/analytics',
      icon: TrendingUp,
      description: 'Live sales, money flow, production, debtors, fleet and payroll'
    },
    {
      name: 'QA',
      href: '/qa',
      icon: Droplets,
      description: 'Water tests and batch quality'
    },
    {
      name: 'Production',
      href: '/production',
      icon: Factory,
      description: 'Packaging runs and production batches'
    },
    {
      name: 'Pricing',
      href: '/pricing',
      icon: Tag,
      description: 'Products and current selling prices'
    },
    {
      name: 'Inventory',
      href: '/inventory',
      icon: Package,
      description: 'Stock management and materials'
    },
    { 
      name: 'Sales', 
      href: '/sales', 
      icon: Truck,
      description: 'Orders, deliveries, and customer management'
    },
    { 
      name: 'Finance', 
      href: '/finance', 
      icon: BarChart3,
      description: 'Invoices, payments, and reconciliation'
    },
    { 
      name: 'Fleet', 
      href: '/fleet', 
      icon: Truck,
      description: 'Vehicle management and maintenance'
    },
    { 
      name: 'HR', 
      href: '/hr', 
      icon: Users,
      description: 'Staff attendance and management'
    },
    {
      name: 'Reports',
      href: '/reports',
      icon: FileText,
      description: 'Analytics and reporting'
    },
    {
      name: 'Issues',
      href: '/issues',
      icon: MessageSquare,
      description: 'Issues reported by drivers/salespeople'
    },
    {
      name: 'Users',
      href: '/users',
      icon: Users,
      description: 'System users, roles and departments'
    },
    {
      name: 'Settings',
      href: '/settings',
      icon: Settings,
      description: 'System configuration'
    },
  ];

  // Round 2 Phase 11: "not just hiding UI elements client-side, a hidden
  // button is not real security" -- every one of these is also blocked
  // server-side (EnsureAccessTier). This is just so the sidebar doesn't
  // offer a link that would 403, and matches the spec's "distinct,
  // deliberately limited dashboard, not the full app with buttons
  // hidden" for Driver/Investor -- they get a single link back to their
  // own dashboard, not the full 12-item list minus a few.
  const tier = user?.role?.access_tier;
  const DIRECTOR_ONLY = ['/users', '/settings'];
  const visibleNavigation = tier === 'driver'
    ? [{ name: 'My Dashboard', href: '/driver', icon: Home, description: 'Trips, attendance' }]
    : tier === 'investor'
    ? [{ name: 'Summary', href: '/investor', icon: Home, description: 'Daily performance summary' }]
    : tier === 'director'
    ? navigation
    : navigation.filter((item) => !DIRECTOR_ONLY.includes(item.href));

  const isActive = (href: string) => {
    return location.pathname === href || location.pathname.startsWith(href + '/');
  };

  const getNotificationIcon = (type: string) => {
    switch (type) {
      case 'alert': return <Bell className="h-4 w-4 text-red-500" />;
      case 'info': return <Bell className="h-4 w-4 text-blue-500" />;
      case 'success': return <Bell className="h-4 w-4 text-green-500" />;
      default: return <Bell className="h-4 w-4 text-gray-500 dark:text-gray-400" />;
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 dark:bg-gray-900">
      {/* Mobile sidebar */}
      <div className={`fixed inset-0 z-50 lg:hidden ${sidebarOpen ? 'block' : 'hidden'}`}>
        <div className="fixed inset-0 bg-gray-600 bg-opacity-75" onClick={() => setSidebarOpen(false)} />
        <div className="fixed inset-y-0 left-0 flex w-80 flex-col bg-white dark:bg-gray-800 shadow-xl">
          <div className="flex h-16 items-center justify-between px-6 border-b border-gray-200 dark:border-gray-700">
            <div className="flex items-center">
              <div className="h-8 w-8 bg-gradient-to-r from-blue-600 to-purple-600 rounded-lg flex items-center justify-center">
                <Droplets className="h-5 w-5 text-white" />
              </div>
              <span className="ml-3 text-xl font-bold text-gray-900 dark:text-gray-100">MARA-WATER</span>
            </div>
            <button
              onClick={() => setSidebarOpen(false)}
              className="text-gray-400 dark:text-gray-500 hover:text-gray-600"
            >
              <X className="h-6 w-6" />
            </button>
          </div>
          <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
            {visibleNavigation.map((item) => {
              const Icon = item.icon;
              return (
                <Link
                  key={item.name}
                  to={item.href}
                  className={`group flex items-center px-3 py-3 text-sm font-medium rounded-lg transition-all duration-200 ${
                    isActive(item.href) 
                      ? 'bg-blue-50 text-blue-700 border-r-2 border-blue-600' 
                      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-gray-100'
                  }`}
                  onClick={() => setSidebarOpen(false)}
                >
                  <Icon className={`mr-3 h-5 w-5 ${
                    isActive(item.href) ? 'text-blue-600' : 'text-gray-400 dark:text-gray-500 group-hover:text-gray-500'
                  }`} />
                  <div className="flex-1">
                    <div className="flex items-center justify-between">
                      <span>{item.name}</span>
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{item.description}</p>
                  </div>
                </Link>
              );
            })}
          </nav>
        </div>
      </div>

      {/* Desktop sidebar */}
      <div className="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-80 lg:flex-col">
        {/* Round 3 Phase 7: this wrapper is a flex-grow child of the fixed,
            viewport-bounded container above it, but without an explicit
            overflow it still gets the flexbox default min-height:auto --
            i.e. it grows to fit the nav's full content height and pushes
            past the bottom of the screen instead of respecting the fixed
            bound, so the nav's own overflow-y-auto below never had a
            constrained box to actually scroll within. overflow-hidden here
            forces this wrapper back down to the fixed parent's height,
            which is what makes the nav scroll internally instead. */}
        <div className="flex flex-col flex-grow overflow-hidden bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 shadow-sm">
          <div className="flex h-16 items-center px-6 border-b border-gray-200 dark:border-gray-700">
            <div className="h-8 w-8 bg-gradient-to-r from-blue-600 to-purple-600 rounded-lg flex items-center justify-center">
              <Droplets className="h-5 w-5 text-white" />
            </div>
            <span className="ml-3 text-xl font-bold text-gray-900 dark:text-gray-100">MARA-WATER</span>
          </div>
          <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
            {visibleNavigation.map((item) => {
              const Icon = item.icon;
              return (
                <Link
                  key={item.name}
                  to={item.href}
                  className={`group flex items-center px-3 py-3 text-sm font-medium rounded-lg transition-all duration-200 ${
                    isActive(item.href) 
                      ? 'bg-blue-50 text-blue-700 border-r-2 border-blue-600' 
                      : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-gray-900 dark:hover:text-gray-100'
                  }`}
                >
                  <Icon className={`mr-3 h-5 w-5 ${
                    isActive(item.href) ? 'text-blue-600' : 'text-gray-400 dark:text-gray-500 group-hover:text-gray-500'
                  }`} />
                  <div className="flex-1">
                    <div className="flex items-center justify-between">
                      <span>{item.name}</span>
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{item.description}</p>
                  </div>
                </Link>
              );
            })}
          </nav>
          
          {/* Sidebar footer */}
          <div className="border-t border-gray-200 dark:border-gray-700 p-4">
            <div className="flex items-center space-x-3">
              <div className="h-8 w-8 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center">
                <span className="text-white font-bold text-sm">
                  {user?.first_name?.charAt(0)}
                </span>
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                  {user?.full_name}
                </p>
                <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
                  {user?.role?.name}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Main content */}
      <div className="lg:pl-80">
        {/* Top bar */}
        <div className="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-x-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 shadow-sm sm:gap-x-6 sm:px-6 lg:px-8">
          <button
            type="button"
            className="-m-2.5 p-2.5 text-gray-700 dark:text-gray-300 lg:hidden"
            onClick={() => setSidebarOpen(true)}
          >
            <Menu className="h-6 w-6" />
          </button>

          <div className="flex flex-1 gap-x-4 self-stretch lg:gap-x-6">
            {/* Search -- Manager/Director only (/search is server-side
                gated the same way; a driver/investor typing here would
                just get a 403, so the box doesn't even show). */}
            {tier !== 'manager' && tier !== 'director' ? (
              <div className="flex-1" />
            ) : (
            <div className="relative flex flex-1 items-center" ref={searchBoxRef}>
              <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                {searchLoading ? (
                  <Loader2 className="h-5 w-5 text-gray-400 dark:text-gray-500 animate-spin" />
                ) : (
                  <Search className="h-5 w-5 text-gray-400 dark:text-gray-500" />
                )}
              </div>
              <input
                type="text"
                className="block h-full w-full border-0 py-0 pl-10 pr-0 text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:ring-0 sm:text-sm"
                placeholder="Search customers, employees, orders, invoices, products, vehicles..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                onFocus={() => { if (searchResults.length > 0) setSearchOpen(true); }}
              />

              {searchOpen && (
                <div className="absolute left-0 right-0 top-full mt-2 max-h-96 overflow-y-auto bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-50">
                  {searchResults.length === 0 ? (
                    <div className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                      {searchLoading ? 'Searching…' : 'No matches'}
                    </div>
                  ) : (
                    searchResults.map((result, idx) => (
                      <button
                        key={`${result.type}-${idx}`}
                        onClick={() => goToSearchResult(result)}
                        className="w-full text-left px-4 py-2.5 hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center justify-between border-b border-gray-100 last:border-b-0"
                      >
                        <div>
                          <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{result.label}</p>
                          <p className="text-xs text-gray-500 dark:text-gray-400">{result.sublabel}</p>
                        </div>
                        <span className="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">{result.type}</span>
                      </button>
                    ))
                  )}
                </div>
              )}
            </div>
            )}

            {/* Right side */}
            <div className="flex items-center gap-x-4 lg:gap-x-6">
              {/* Round 2 Phase 2: dark mode toggle */}
              <button
                onClick={toggleTheme}
                className="p-2 text-gray-400 dark:text-gray-500 hover:text-gray-500"
                title={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
              >
                {theme === 'dark' ? <Sun className="h-6 w-6" /> : <Moon className="h-6 w-6" />}
              </button>

              {/* Notifications */}
              <div className="relative">
                <button
                  onClick={() => setNotificationsOpen(!notificationsOpen)}
                  className="p-2 text-gray-400 dark:text-gray-500 hover:text-gray-500 relative"
                >
                  <Bell className="h-6 w-6" />
                  {notifications.filter(n => !n.read).length > 0 && (
                    <span className="absolute -top-1 -right-1 h-4 w-4 bg-red-500 rounded-full flex items-center justify-center">
                      <span className="text-xs text-white font-medium">
                        {notifications.filter(n => !n.read).length}
                      </span>
                    </span>
                  )}
                </button>

                {notificationsOpen && (
                  <div className="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 rounded-lg shadow-lg py-1 z-50 border border-gray-200 dark:border-gray-700">
                    <div className="px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                      <h3 className="text-sm font-medium text-gray-900 dark:text-gray-100">Notifications</h3>
                    </div>
                    <div className="max-h-64 overflow-y-auto">
                      {notifications.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">No notifications</p>
                      ) : (
                        notifications.map((notification) => (
                          <div key={notification.id} className={`px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 ${!notification.read ? 'bg-blue-50' : ''}`}>
                            <div className="flex items-start">
                              {getNotificationIcon(notification.type)}
                              <div className="ml-3 flex-1">
                                <p className="text-sm text-gray-900 dark:text-gray-100">{notification.message}</p>
                                <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{notification.time}</p>
                              </div>
                            </div>
                          </div>
                        ))
                      )}
                    </div>
                    {notifications.some(n => !n.read) && (
                      <div className="px-4 py-2 border-t border-gray-200 dark:border-gray-700">
                        <button
                          onClick={markAllNotificationsRead}
                          className="text-sm text-blue-600 hover:text-blue-800 font-medium"
                        >
                          Mark all as read
                        </button>
                      </div>
                    )}
                  </div>
                )}
              </div>

              {/* User menu */}
              <div className="relative">
                <button
                  onClick={() => setUserMenuOpen(!userMenuOpen)}
                  className="flex items-center gap-x-3 text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-gray-700 dark:hover:text-gray-200"
                >
                  <div className="h-8 w-8 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center">
                    <span className="text-white font-bold text-sm">
                      {user?.first_name?.charAt(0)}
                    </span>
                  </div>
                  <span className="hidden lg:block">{user?.first_name}</span>
                  <ChevronDown className="h-4 w-4" />
                </button>

                {userMenuOpen && (
                  <div className="absolute right-0 mt-2 w-56 bg-white dark:bg-gray-800 rounded-lg shadow-lg py-1 z-50 border border-gray-200 dark:border-gray-700">
                    <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                      <div className="font-medium text-gray-900 dark:text-gray-100">{user?.full_name}</div>
                      <div className="text-sm text-gray-500 dark:text-gray-400">{user?.email}</div>
                      <div className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                        {user?.role?.name}{user?.role && user?.department ? ' • ' : ''}{user?.department?.name}
                      </div>
                    </div>
                    <div className="py-1">
                      <button
                        onClick={() => { setUserMenuOpen(false); navigate('/settings?tab=profile'); }}
                        className="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center"
                      >
                        <User className="mr-2 h-4 w-4" />
                        Profile
                      </button>
                      <button
                        onClick={() => { setUserMenuOpen(false); navigate('/settings'); }}
                        className="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center"
                      >
                        <Settings className="mr-2 h-4 w-4" />
                        Settings
                      </button>
                    </div>
                    <div className="border-t border-gray-200 dark:border-gray-700 py-1">
                      <button
                        onClick={logout}
                        className="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center"
                      >
                        <LogOut className="mr-2 h-4 w-4" />
                        Sign out
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>

        {/* Page content */}
        <main className="py-6">
          <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <ErrorBoundary>{children}</ErrorBoundary>
          </div>
        </main>
      </div>
    </div>
  );
};

export default Layout;
