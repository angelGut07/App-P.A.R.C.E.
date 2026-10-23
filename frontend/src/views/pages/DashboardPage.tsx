import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Users, Wrench, FileText, DollarSign, Star, MessageSquare, Package, ClipboardCheck } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { Alert } from '../components/ui';
import { useAuth } from '../../controllers/AuthContext';
import { adminService, errorMessage } from '../../services';
import type { DashboardSummary } from '@/models';
import { REQUEST_STATUS, formatCOP } from '../format';

/** Resumen de la plataforma para el administrador. */
export default function DashboardPage() {
  const { user } = useAuth();
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    adminService.dashboard().then(res => {
      if (res.success && res.data) setSummary(res.data.summary);
      else setError(errorMessage(res, 'No se pudo cargar el resumen'));
    });
  }, []);

  const cards = summary ? [
    { title: 'Clientes', value: summary.totalCustomers, hint: `${summary.totalUsers} usuarios en total`, icon: Users, color: 'from-blue-500 to-blue-600', path: '/admin/users' },
    { title: 'Mecánicos', value: summary.totalMechanics, hint: `${summary.pendingMechanicApplications} solicitudes por revisar`, icon: Wrench, color: 'from-purple-500 to-purple-600', path: '/admin/mechanics' },
    { title: 'Pagos confirmados', value: formatCOP(summary.confirmedPaymentsAmount), hint: `${summary.pendingPayments} pendientes`, icon: DollarSign, color: 'from-green-500 to-green-600', path: '/admin/payments' },
    { title: 'Calificación promedio', value: summary.averageRating != null ? `${summary.averageRating} ★` : '—', hint: `${summary.totalSurveys} encuestas`, icon: Star, color: 'from-yellow-500 to-yellow-600', path: '/admin/ratings' },
    { title: 'PQR', value: summary.totalPqr, hint: `${summary.pendingPqr} sin atender`, icon: MessageSquare, color: 'from-pink-500 to-rose-600', path: '/admin/pqr/users' },
    { title: 'Stock bajo', value: summary.lowStockProducts, hint: 'productos por reponer', icon: Package, color: 'from-orange-500 to-red-600', path: '/admin/inventory' },
  ] : [];

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Administrador'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24 md:ml-64 transition-all">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold text-white mb-2">Dashboard</h1>
            <p className="text-gray-400 text-sm sm:text-base">Bienvenido de vuelta, {user?.profile.firstName}</p>
          </div>

          {error && <Alert type="error">{error}</Alert>}

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
            {cards.map((card, index) => (
              <motion.div key={card.title} initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: index * 0.05 }}>
                <Link to={card.path} className="card p-5 sm:p-6 hover:ring-2 hover:ring-gold-500 transition-all group flex items-center gap-4">
                  <div className={`w-12 h-12 sm:w-14 sm:h-14 bg-gradient-to-br ${card.color} rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform`}>
                    <card.icon className="w-6 h-6 sm:w-7 sm:h-7 text-white" />
                  </div>
                  <div>
                    <p className="text-sm text-gray-400">{card.title}</p>
                    <p className="text-2xl font-bold text-white">{card.value}</p>
                    <p className="text-xs text-gray-500">{card.hint}</p>
                  </div>
                </Link>
              </motion.div>
            ))}
          </div>

          {summary && (
            <div className="card p-6">
              <div className="flex items-center gap-2 mb-4">
                <ClipboardCheck className="w-5 h-5 text-gold-400" />
                <h2 className="text-lg font-bold text-white">Solicitudes por estado</h2>
                <Link to="/admin/services" className="ml-auto text-sm text-gold-400 hover:underline flex items-center gap-1"><FileText className="w-4 h-4" /> Ver todas</Link>
              </div>
              {summary.requestsByStatus.length === 0 ? (
                <p className="text-gray-500 text-sm">Aún no hay solicitudes.</p>
              ) : (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                  {summary.requestsByStatus.map(s => (
                    <div key={s.status} className={`p-4 rounded-xl border text-center ${REQUEST_STATUS[s.status].color}`}>
                      <p className="text-2xl font-bold">{s.total}</p>
                      <p className="text-xs">{REQUEST_STATUS[s.status].label}</p>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}
        </motion.div>
      </main>
    </div>
  );
}
