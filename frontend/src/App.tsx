import { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate, Outlet } from 'react-router-dom';
import { AuthProvider, useAuth } from '@/context/AuthContext';
import { ToastProvider } from '@/components/Toast';
import Layout from '@/components/Layout';
import Spinner from '@/components/Spinner';
import Login from '@/pages/Login';
import ErrorBoundary from '@/components/ErrorBoundary';
import { departmentHome } from '@/lib/api';

const Dashboard = lazy(() => import('@/pages/Dashboard'));
const DashboardInbound = lazy(() => import('@/pages/DashboardInbound'));
const DashboardOutbound = lazy(() => import('@/pages/DashboardOutbound'));
const DashboardInventory = lazy(() => import('@/pages/DashboardInventory'));
const InboundList = lazy(() => import('@/pages/InboundList'));
const InboundDetail = lazy(() => import('@/pages/InboundDetail'));
const OutboundList = lazy(() => import('@/pages/OutboundList'));
const OutboundDetail = lazy(() => import('@/pages/OutboundDetail'));
const StockPage = lazy(() => import('@/pages/StockPage'));
const LedgerPage = lazy(() => import('@/pages/LedgerPage'));
const PicklistList = lazy(() => import('@/pages/PicklistList'));
const PicklistDetail = lazy(() => import('@/pages/PicklistDetail'));
const StockTakeList = lazy(() => import('@/pages/StockTakeList'));
const StockTakeDetail = lazy(() => import('@/pages/StockTakeDetail'));
const CycleCountPage = lazy(() => import('@/pages/CycleCountPage'));
const BinTransferPage = lazy(() => import('@/pages/BinTransferPage'));
const PutawayTasksPage = lazy(() => import('@/pages/PutawayTasksPage'));
const PutawayScanPage = lazy(() => import('@/pages/PutawayScanPage'));
const PendingReplenishmentPage = lazy(() => import('@/pages/PendingReplenishmentPage'));
const StockReconciliationPage = lazy(() => import('@/pages/StockReconciliationPage'));
const WavesPage = lazy(() => import('@/pages/WavesPage'));
const WaveDetail = lazy(() => import('@/pages/WaveDetail'));
const AsnList = lazy(() => import('@/pages/AsnList'));
const AsnDetail = lazy(() => import('@/pages/AsnDetail'));
const ProductsPage = lazy(() => import('@/pages/ProductsPage'));
const CustomersPage = lazy(() => import('@/pages/CustomersPage'));
const LocationsPage = lazy(() => import('@/pages/LocationsPage'));
const ZoningPage = lazy(() => import('@/pages/ZoningPage'));
const ReportsPage = lazy(() => import('@/pages/ReportsPage'));
const ImportPage = lazy(() => import('@/pages/ImportPage'));
const AutoImportPage = lazy(() => import('@/pages/AutoImportPage'));
const UsersPage = lazy(() => import('@/pages/UsersPage'));
const ActivityLogPage = lazy(() => import('@/pages/ActivityLogPage'));
const SecurityAuditPage = lazy(() => import('@/pages/SecurityAuditPage'));
const ResetDataPage = lazy(() => import('@/pages/ResetDataPage'));
const QualityPage = lazy(() => import('@/pages/QualityPage'));
const RmaPage = lazy(() => import('@/pages/RmaPage'));
const MonitoringPage = lazy(() => import('@/pages/MonitoringPage'));
const NotificationsPage = lazy(() => import('@/pages/NotificationsPage'));
const PickfaceManagementPage = lazy(() => import('@/pages/PickfaceManagementPage'));
const CheckerPage = lazy(() => import('@/pages/CheckerPage'));

function RequireAuth() {
  const { isAuthenticated, department } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  return <Outlet />;
}

function HomeRedirect() {
  const { department } = useAuth();
  return <Navigate to={departmentHome(department)} replace />;
}

function RequireWrite() {
  const { isAuthenticated, canWrite } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  if (!canWrite) return <Navigate to="/" replace />;
  return <Outlet />;
}

function RequireAdmin() {
  const { isAuthenticated, canAdmin } = useAuth();
  if (!isAuthenticated) return <Navigate to="/login" replace />;
  if (!canAdmin) return <Navigate to="/" replace />;
  return <Outlet />;
}

export default function App() {
  return (
    <BrowserRouter>
      <ToastProvider>
        <AuthProvider>
          <ErrorBoundary>
            <Suspense fallback={<div className="flex items-center justify-center h-screen"><Spinner /></div>}>
            <Routes>
              <Route path="/login" element={<Login />} />
              <Route element={<RequireAuth />}>
                <Route element={<Layout />}>
                  <Route path="/" element={<HomeRedirect />} />
                  <Route path="/dashboard" element={<Dashboard />} />
                  <Route path="/dashboard/inbound" element={<DashboardInbound />} />
                  <Route path="/dashboard/outbound" element={<DashboardOutbound />} />
                  <Route path="/dashboard/inventory" element={<DashboardInventory />} />
                  <Route path="/inbound" element={<InboundList />} />
                  <Route path="/inbound/:id" element={<InboundDetail />} />
                  <Route path="/outbound" element={<OutboundList />} />
                  <Route path="/outbound/:id" element={<OutboundDetail />} />
                  <Route path="/stock" element={<StockPage />} />
                  <Route path="/ledger" element={<LedgerPage />} />
                  <Route path="/picklist" element={<PicklistList />} />
                  <Route path="/picklist/:id" element={<PicklistDetail />} />
                  <Route path="/waves" element={<WavesPage />} />
                  <Route path="/waves/:id" element={<WaveDetail />} />
                  <Route path="/asn" element={<AsnList />} />
                  <Route path="/asn/:id" element={<AsnDetail />} />
                  <Route path="/stocktake" element={<StockTakeList />} />
                  <Route path="/stocktake/:id" element={<StockTakeDetail />} />
                  <Route path="/cycle-count" element={<CycleCountPage />} />
                  <Route path="/bin-transfer" element={<BinTransferPage />} />
                  <Route path="/replenishment" element={<PendingReplenishmentPage />} />
                  <Route path="/reconciliation" element={<StockReconciliationPage />} />
                  <Route path="/pickface-management" element={<PickfaceManagementPage />} />
                  <Route path="/putaway-tasks" element={<PutawayTasksPage />} />
                  <Route path="/putaway-scan" element={<PutawayScanPage />} />
                  <Route path="/checker" element={<CheckerPage />} />
                  <Route path="/reports" element={<ReportsPage />} />
                  <Route path="/quality" element={<QualityPage />} />
                  <Route path="/rma" element={<RmaPage />} />
                  <Route path="/monitoring" element={<MonitoringPage />} />
                  <Route path="/notifications" element={<NotificationsPage />} />
                  <Route element={<RequireWrite />}>
                    <Route path="/import" element={<ImportPage />} />
                    <Route path="/import-auto" element={<AutoImportPage />} />
                    <Route path="/products" element={<ProductsPage />} />
                    <Route path="/customers" element={<CustomersPage />} />
                    <Route path="/locations" element={<LocationsPage />} />
                    <Route path="/zoning" element={<ZoningPage />} />
                  </Route>
                  <Route element={<RequireAdmin />}>
                    <Route path="/users" element={<UsersPage />} />
                    <Route path="/activity-log" element={<ActivityLogPage />} />
                    <Route path="/security-audit" element={<SecurityAuditPage />} />
                    <Route path="/reset-data" element={<ResetDataPage />} />
                  </Route>
                  <Route path="*" element={<Navigate to="/" replace />} />
                </Route>
              </Route>
            </Routes>
            </Suspense>
          </ErrorBoundary>
        </AuthProvider>
      </ToastProvider>
    </BrowserRouter>
  );
}
