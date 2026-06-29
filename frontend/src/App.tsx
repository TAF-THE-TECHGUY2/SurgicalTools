import { Routes, Route } from 'react-router-dom'
import { ProtectedRoute } from '@/auth/ProtectedRoute'
import { AppLayout } from '@/components/layout/AppLayout'
import LoginPage from '@/pages/LoginPage'
import DashboardPage from '@/pages/DashboardPage'
import InventoryListPage from '@/pages/inventory/InventoryListPage'
import InventoryDetailPage from '@/pages/inventory/InventoryDetailPage'
import TransferListPage from '@/pages/transfers/TransferListPage'
import TransferCreatePage from '@/pages/transfers/TransferCreatePage'
import TransferDetailPage from '@/pages/transfers/TransferDetailPage'
import StockCountListPage from '@/pages/stock-counts/StockCountListPage'
import StockCountDetailPage from '@/pages/stock-counts/StockCountDetailPage'
import HospitalListPage from '@/pages/hospitals/HospitalListPage'
import HospitalDetailPage from '@/pages/hospitals/HospitalDetailPage'
import DoctorListPage from '@/pages/doctors/DoctorListPage'
import DoctorDetailPage from '@/pages/doctors/DoctorDetailPage'
import PreferenceCardListPage from '@/pages/preference-cards/PreferenceCardListPage'
import ApprovalCentrePage from '@/pages/approvals/ApprovalCentrePage'
import NotificationsPage from '@/pages/NotificationsPage'
import ReportsPage from '@/pages/reports/ReportsPage'
import PastelExportPage from '@/pages/PastelExportPage'
import UsersPage from '@/pages/users/UsersPage'
import AuditLogPage from '@/pages/AuditLogPage'
import NotFoundPage from '@/pages/NotFoundPage'

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/inventory" element={<InventoryListPage />} />
          <Route path="/inventory/:id" element={<InventoryDetailPage />} />
          <Route path="/transfers" element={<TransferListPage />} />
          <Route path="/transfers/new" element={<TransferCreatePage />} />
          <Route path="/transfers/:id" element={<TransferDetailPage />} />
          <Route path="/stock-counts" element={<StockCountListPage />} />
          <Route path="/stock-counts/:id" element={<StockCountDetailPage />} />
          <Route path="/hospitals" element={<HospitalListPage />} />
          <Route path="/hospitals/:id" element={<HospitalDetailPage />} />
          <Route path="/doctors" element={<DoctorListPage />} />
          <Route path="/doctors/:id" element={<DoctorDetailPage />} />
          <Route path="/preference-cards" element={<PreferenceCardListPage />} />
          <Route path="/approvals" element={<ApprovalCentrePage />} />
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="/reports" element={<ReportsPage />} />
          <Route path="/pastel" element={<PastelExportPage />} />
          <Route path="/users" element={<UsersPage />} />
          <Route path="/audit" element={<AuditLogPage />} />
        </Route>
      </Route>

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  )
}
