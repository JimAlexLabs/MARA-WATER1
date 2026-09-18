import React, { Suspense, lazy } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import LoginPage from './pages/LoginPage';
import DashboardPage from './pages/DashboardPage';
import Layout from './components/Layout';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import './index.css';

// Round 2 Phase 0: these used to be static imports, so every login/page
// load shipped all 10 pages' code in one ~930KB bundle regardless of
// which page the user actually wanted. Lazy-loading means a page's code
// only downloads the first time it's visited. Login and Dashboard stay
// eager -- they're what everyone hits first, so there's nothing to save
// by deferring them, and it avoids a loading flash on the most common path.
const AnalyticsPage = lazy(() => import('./pages/AnalyticsPage'));
const PricingPage = lazy(() => import('./pages/PricingPage'));
const DriverPage = lazy(() => import('./pages/DriverPage'));
const InvestorPage = lazy(() => import('./pages/InvestorPage'));
const QAPage = lazy(() => import('./pages/QAPage'));
const ProductionPage = lazy(() => import('./pages/ProductionPage'));
const InventoryPage = lazy(() => import('./pages/InventoryPage'));
const SalesPage = lazy(() => import('./pages/SalesPage'));
const FinancePage = lazy(() => import('./pages/FinancePage'));
const FleetPage = lazy(() => import('./pages/FleetPage'));
const HRPage = lazy(() => import('./pages/HRPage'));
const ReportsPage = lazy(() => import('./pages/ReportsPage'));
const IssuesPage = lazy(() => import('./pages/IssuesPage'));
const SettingsPage = lazy(() => import('./pages/SettingsPage'));
const UsersPage = lazy(() => import('./pages/UsersPage'));

const PageLoading: React.FC = () => (
  <div className="min-h-[60vh] flex items-center justify-center">
    <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600"></div>
  </div>
);

// Round 2 Phase 11: "each login should land the user on the dashboard
// appropriate to their role" -- also used to bounce a tier away from a
// route it can't use, and as the catch-all/root redirect target.
const homeRouteFor = (tier?: string | null): string => {
  if (tier === 'driver') return '/driver';
  if (tier === 'investor') return '/investor';
  return '/dashboard';
};

// Protected Route Component. `tiers`, when given, restricts the route to
// those access tiers -- this is a UX convenience (redirect to the user's
// own home instead of a page that will just 403 every request); the
// real enforcement is server-side (EnsureAccessTier), not this.
const ProtectedRoute: React.FC<{ children: React.ReactNode; tiers?: string[] }> = ({ children, tiers }) => {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
          <p className="mt-4 text-gray-600">Loading...</p>
        </div>
      </div>
    );
  }

  if (!user) return <Navigate to="/login" replace />;

  if (tiers && !tiers.includes(user.role?.access_tier || '')) {
    return <Navigate to={homeRouteFor(user.role?.access_tier)} replace />;
  }

  return <>{children}</>;
};

// Public Route Component (redirects to dashboard if already logged in)
const PublicRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, loading } = useAuth();
  
  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
          <p className="mt-4 text-gray-600">Loading...</p>
        </div>
      </div>
    );
  }
  
  return user ? <Navigate to={homeRouteFor(user.role?.access_tier)} replace /> : <>{children}</>;
};

// "/" and any unmatched path -- send a logged-in user to their own tier's
// home, or an anonymous visitor to login.
const HomeRedirect: React.FC = () => {
  const { user, loading } = useAuth();
  if (loading) return null;
  return <Navigate to={user ? homeRouteFor(user.role?.access_tier) : '/login'} replace />;
};

function App() {
  return (
    <AuthProvider>
      <Router>
        <div className="App">
          <Toaster 
            position="top-right"
            toastOptions={{
              duration: 4000,
              style: {
                background: '#363636',
                color: '#fff',
              },
            }}
          />
          
          <Suspense fallback={<PageLoading />}>
          <Routes>
            {/* Public Routes */}
            <Route path="/login" element={
              <PublicRoute>
                <LoginPage />
              </PublicRoute>
            } />
            
            {/* Protected Routes -- Round 2 Phase 11: each gated to the
                access tiers the spec grants it. The `tiers` prop is a UX
                convenience (redirect to the user's own home instead of a
                page that will just 403); the real enforcement is
                server-side (EnsureAccessTier). */}
            <Route path="/dashboard" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <DashboardPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* A driver's own dashboard/login. */}
            <Route path="/driver" element={
              <ProtectedRoute tiers={['driver']}>
                <Layout>
                  <DriverPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Investor's own deliberately limited, read-only summary. */}
            <Route path="/investor" element={
              <ProtectedRoute tiers={['investor']}>
                <Layout>
                  <InvestorPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Analytics Routes */}
            <Route path="/analytics" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <AnalyticsPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Products & Prices Routes */}
            <Route path="/pricing" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <PricingPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* QA & Production Routes */}
            <Route path="/qa" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <QAPage />
                </Layout>
              </ProtectedRoute>
            } />

            <Route path="/production" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <ProductionPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Inventory Routes */}
            <Route path="/inventory" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <InventoryPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Sales Routes */}
            <Route path="/sales" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <SalesPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Finance Routes */}
            <Route path="/finance" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <FinancePage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Fleet Routes -- Manager/Director's fleet-wide tool; a
                driver's own trip logging lives at /driver instead. */}
            <Route path="/fleet" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <FleetPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* HR Routes */}
            <Route path="/hr" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <HRPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Reports Routes */}
            <Route path="/reports" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <ReportsPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Round 3 Phase 5: Issues inbox (Manager/Director side --
                Driver's own report/reply UI lives inline on /driver) */}
            <Route path="/issues" element={
              <ProtectedRoute tiers={['manager', 'director']}>
                <Layout>
                  <IssuesPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Settings Routes -- Director only, per the spec's own
                suggested default. */}
            <Route path="/settings" element={
              <ProtectedRoute tiers={['director']}>
                <Layout>
                  <SettingsPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Users Routes -- user management/role assignment, Director only. */}
            <Route path="/users" element={
              <ProtectedRoute tiers={['director']}>
                <Layout>
                  <UsersPage />
                </Layout>
              </ProtectedRoute>
            } />

            {/* Default redirect -- each tier's own landing page. */}
            <Route path="/" element={<HomeRedirect />} />

            {/* Catch all route */}
            <Route path="*" element={<HomeRedirect />} />
          </Routes>
          </Suspense>
        </div>
      </Router>
    </AuthProvider>
  );
}

export default App;
