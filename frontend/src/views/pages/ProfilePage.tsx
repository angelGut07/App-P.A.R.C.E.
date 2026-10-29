import { motion } from 'framer-motion';
import { ArrowLeft } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import PersonalInfoForm from '../components/profile/PersonalInfoForm';
import DriverLicenseForm from '../components/profile/DriverLicenseForm';
import PasswordForm from '../components/profile/PasswordForm';
import VehiclesSection from '../components/profile/VehiclesSection';
import MechanicSection from '../components/profile/MechanicSection';
import RatingSummary from '../components/profile/RatingSummary';
import { useAuth } from '../../controllers/AuthContext';

/**
 * Perfil del usuario autenticado. Lo usan el cliente (/profile) y el
 * mecánico (/mechanic-profile); el administrador solo ve sus datos y su
 * contraseña.
 */
export default function ProfilePage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const isAdmin = user?.role === 'admin';

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} />
      <Sidebar hidden={true} />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24 max-w-2xl mx-auto">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">
          <button onClick={() => navigate(-1)} className="flex items-center gap-2 text-gray-400 hover:text-white transition-colors">
            <ArrowLeft className="w-5 h-5" /> Volver
          </button>

          <div className="text-center">
            <h1 className="text-3xl font-bold text-white mb-1">Mi Perfil</h1>
            <p className="text-gray-400 text-sm">Edita y guarda tu información</p>
          </div>

          {!isAdmin && <div className="card p-6 sm:p-8"><RatingSummary /></div>}
          <div className="card p-6 sm:p-8"><PersonalInfoForm /></div>
          {!isAdmin && <div className="card p-6 sm:p-8"><DriverLicenseForm /></div>}
          {!isAdmin && <div className="card p-6 sm:p-8"><VehiclesSection /></div>}
          {!isAdmin && <div className="card p-6 sm:p-8"><MechanicSection /></div>}
          <div className="card p-6 sm:p-8"><PasswordForm /></div>
        </motion.div>
      </main>
    </div>
  );
}
