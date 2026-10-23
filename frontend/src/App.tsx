import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import ProtectedRoute from './views/components/ProtectedRoute';

// Públicas
import LandingPage from './views/pages/LandingPage';
import LoginPage from './views/pages/LoginPage';
import RegisterPage from './views/pages/RegisterPage';
import ForgotPasswordPage from './views/pages/ForgotPasswordPage';
import ResetPasswordPage from './views/pages/ResetPasswordPage';
import RoleSelectionPage from './views/pages/RoleSelectionPage';

// Cliente
import UserHomePage from './views/pages/UserHomePage';
import ServicesPage from './views/pages/ServicesPage';
import ServiceLocationPage from './views/pages/ServiceLocationPage';
import ServiceInProgressPage from './views/pages/ServiceInProgressPage';
import MyServicesPage from './views/pages/MyServicesPage';
import PaymentPage from './views/pages/PaymentPage';
import SatisfactionSurveyPage from './views/pages/SatisfactionSurveyPage';

// Mecánico
import MechanicHomePage from './views/pages/MechanicHomePage';
import MechanicOrdersPage from './views/pages/MechanicOrdersPage';
import MechanicPaymentDashboard from './views/pages/MechanicPaymentDashboard';

// Compartidas
import ProfilePage from './views/pages/ProfilePage';
import PQRPage from './views/pages/PQRPage';
import PaymentReceiptPage from './views/pages/PaymentReceiptPage';
import ContactPage from './views/pages/ContactPage';

// Administración
import DashboardPage from './views/pages/DashboardPage';
import AdminUsersPage from './views/pages/admin/AdminUsersPage';
import AdminMechanicsPage from './views/pages/admin/AdminMechanicsPage';
import AdminServicesPage from './views/pages/admin/AdminServicesPage';
import AdminVehiclesPage from './views/pages/admin/AdminVehiclesPage';
import AdminPaymentsPage from './views/pages/admin/AdminPaymentsPage';
import AdminRatingsPage from './views/pages/admin/AdminRatingsPage';
import AdminProviderRatingsPage from './views/pages/admin/AdminProviderRatingsPage';
import AdminInventoryPage from './views/pages/admin/AdminInventoryPage';
import AdminMerchandisePage from './views/pages/admin/AdminMerchandisePage';
import AdminPQRPage from './views/pages/admin/AdminPQRPage';
import AdminChatbotPage from './views/pages/admin/AdminChatbotPage';

const user = (page: React.ReactNode) => <ProtectedRoute allowedRoles={['user']}>{page}</ProtectedRoute>;
const mechanic = (page: React.ReactNode) => <ProtectedRoute allowedRoles={['mechanic']}>{page}</ProtectedRoute>;
const admin = (page: React.ReactNode) => <ProtectedRoute allowedRoles={['admin']}>{page}</ProtectedRoute>;
const anyRole = (page: React.ReactNode) => <ProtectedRoute>{page}</ProtectedRoute>;

export default function App() {
  return (
    <Router>
      <Routes>
        {/* Públicas */}
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/role-selection" element={<RoleSelectionPage />} />

        {/* Cliente */}
        <Route path="/home" element={user(<UserHomePage />)} />
        <Route path="/services" element={user(<ServicesPage />)} />
        <Route path="/service-location" element={user(<ServiceLocationPage />)} />
        <Route path="/service-in-progress" element={user(<ServiceInProgressPage />)} />
        <Route path="/my-services" element={user(<MyServicesPage />)} />
        <Route path="/payment" element={user(<PaymentPage />)} />
        <Route path="/survey" element={user(<SatisfactionSurveyPage />)} />
        <Route path="/pqr" element={user(<PQRPage />)} />
        <Route path="/profile" element={<ProtectedRoute allowedRoles={['user', 'admin']}><ProfilePage /></ProtectedRoute>} />

        {/* Mecánico */}
        <Route path="/mechanic-home" element={mechanic(<MechanicHomePage />)} />
        <Route path="/mechanic-orders" element={mechanic(<MechanicOrdersPage />)} />
        <Route path="/mechanic-payments" element={mechanic(<MechanicPaymentDashboard />)} />
        <Route path="/mechanic-pqr" element={mechanic(<PQRPage />)} />
        <Route path="/mechanic-profile" element={mechanic(<ProfilePage />)} />

        {/* Administración */}
        <Route path="/dashboard" element={admin(<DashboardPage />)} />
        <Route path="/admin/users" element={admin(<AdminUsersPage />)} />
        <Route path="/admin/mechanics" element={admin(<AdminMechanicsPage />)} />
        <Route path="/admin/services" element={admin(<AdminServicesPage />)} />
        <Route path="/admin/vehicles" element={admin(<AdminVehiclesPage />)} />
        <Route path="/admin/payments" element={admin(<AdminPaymentsPage />)} />
        <Route path="/admin/ratings" element={admin(<AdminRatingsPage />)} />
        <Route path="/admin/ratings/providers" element={admin(<AdminProviderRatingsPage />)} />
        <Route path="/admin/inventory" element={admin(<AdminInventoryPage />)} />
        <Route path="/admin/merchandise" element={admin(<AdminMerchandisePage />)} />
        <Route path="/admin/pqr/users" element={admin(<AdminPQRPage view="users" />)} />
        <Route path="/admin/pqr/mechanics" element={admin(<AdminPQRPage view="mechanics" />)} />
        <Route path="/admin/chatbot" element={admin(<AdminChatbotPage />)} />

        {/* Compartidas */}
        <Route path="/payment/receipt" element={anyRole(<PaymentReceiptPage />)} />
        <Route path="/contact" element={anyRole(<ContactPage />)} />

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Router>
  );
}
