import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Zap, Star, ClipboardList, DollarSign, MapPin, ToggleLeft, ToggleRight } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../../controllers/AuthContext';
import { useMechanic } from '../../controllers/MechanicContext';
import { mechanicService } from '../../services';
import type { MechanicStats } from '@/models';
import { formatCOP } from '../format';

export default function MechanicHomePage() {
  const { user } = useAuth();
  const { isActive, setIsActive } = useMechanic();
  const navigate = useNavigate();
  // Iniciar servicio = quedar disponible e ir a las solicitudes cercanas
  const handleStartService = () => {
    setIsActive(true);
    navigate('/mechanic-orders');
  };

  const [data, setData] = useState<MechanicStats | null>(null);
  useEffect(() => {
    mechanicService.stats().then(res => res.success && res.data && setData(res.data.stats));
  }, []);

  const stats = [
    { label: 'Servicios completados', value: data ? String(data.totalCompleted) : '—', icon: ClipboardList, color: 'from-blue-500 to-blue-600' },
    { label: 'Calificación promedio', value: data?.averageRating != null ? `${data.averageRating} ★` : 'Sin calificaciones', icon: Star, color: 'from-yellow-500 to-amber-600' },
    { label: 'Ingresos (mano de obra)', value: data ? formatCOP(data.totalEarnings) : '—', icon: DollarSign, color: 'from-green-500 to-emerald-600' },
  ];

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Mecánico'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar hidden={true} />

      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24">
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          className="space-y-8 max-w-4xl mx-auto"
        >
          {/* Bienvenida */}
          <div className="text-center space-y-2">
            <div className="flex justify-center mb-4">
              <img src="/Logo.png" alt="P.A.R.C.E Logo" className="w-20 h-20 object-contain" />
            </div>
            <h1 className="text-4xl font-bold text-white">
              Hola, <span className="text-gradient">{user?.name?.split(' ')[0] || 'Mecánico'}</span>
            </h1>
            <p className="text-gray-400 text-lg">Bienvenido a tu panel de trabajo</p>
          </div>

          {/* Estado de disponibilidad */}
          <motion.div
            className={`card p-6 border-2 transition-colors duration-300 ${
              isActive ? 'border-green-500/50 bg-green-500/5' : 'border-anthracite-700'
            }`}
          >
            <div className="flex items-center justify-between mb-4">
              <div>
                <h2 className="text-xl font-bold text-white">Estado de disponibilidad</h2>
                <p className="text-gray-400 text-sm mt-1">
                  {isActive
                    ? '🟢 Estás activo — los usuarios pueden enviarte solicitudes'
                    : '🔴 Estás inactivo — no recibirás solicitudes de servicio'}
                </p>
              </div>
              <button
                onClick={() => setIsActive(!isActive)}
                className="text-gray-400 hover:text-white transition-colors"
              >
                {isActive
                  ? <ToggleRight className="w-12 h-12 text-green-400" />
                  : <ToggleLeft className="w-12 h-12 text-gray-600" />
                }
              </button>
            </div>

            {/* Indicador de ubicación cuando está activo */}
            {isActive && (
              <motion.div
                initial={{ opacity: 0, height: 0 }}
                animate={{ opacity: 1, height: 'auto' }}
                className="flex items-center gap-2 p-3 bg-green-500/10 rounded-lg border border-green-500/20"
              >
                <MapPin className="w-4 h-4 text-green-400 animate-pulse" />
                <span className="text-green-300 text-sm">Ubicación activada — los usuarios pueden verte en el mapa</span>
              </motion.div>
            )}
          </motion.div>

          {/* Botón principal: Iniciar Servicio */}
          <motion.button
            whileHover={{ scale: 1.02 }}
            whileTap={{ scale: 0.98 }}
            onClick={handleStartService}
            className="w-full py-6 bg-gradient-to-r from-gold-500 to-gold-600 hover:from-gold-400 hover:to-gold-500 text-anthracite-950 font-bold text-2xl rounded-2xl shadow-2xl shadow-gold-500/20 flex items-center justify-center gap-4 transition-all duration-300"
          >
            <Zap className="w-8 h-8" />
            Iniciar Servicio
          </motion.button>
          <p className="text-center text-gray-500 text-sm -mt-4">
            Al iniciar, tu cuenta quedará activa y recibirás solicitudes de usuarios cercanos
          </p>

          {/* Estadísticas */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {stats.map((stat, i) => (
              <motion.div
                key={stat.label}
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: i * 0.1 }}
                className="card p-5"
              >
                <div className="flex items-center gap-4">
                  <div className={`w-12 h-12 bg-gradient-to-br ${stat.color} rounded-xl flex items-center justify-center flex-shrink-0`}>
                    <stat.icon className="w-6 h-6 text-white" />
                  </div>
                  <div>
                    <p className="text-2xl font-bold text-white">{stat.value}</p>
                    <p className="text-gray-400 text-xs">{stat.label}</p>
                  </div>
                </div>
              </motion.div>
            ))}
          </div>

          {/* Accesos rápidos */}
          <div className="grid grid-cols-2 gap-4">
            <button onClick={() => navigate('/mechanic-orders')}
              className="card p-5 hover:ring-2 hover:ring-gold-500/50 transition-all text-left">
              <ClipboardList className="w-8 h-8 text-gold-500 mb-3" />
              <h3 className="text-white font-bold">Ver Solicitudes</h3>
              <p className="text-gray-400 text-sm">Revisa los servicios pendientes</p>
            </button>
            <button onClick={() => navigate('/mechanic-profile')}
              className="card p-5 hover:ring-2 hover:ring-gold-500/50 transition-all text-left">
              <Star className="w-8 h-8 text-yellow-500 mb-3" />
              <h3 className="text-white font-bold">Mi Perfil</h3>
              <p className="text-gray-400 text-sm">Gestiona tu información y reputación</p>
            </button>
          </div>
        </motion.div>
      </main>

      {/* Modal: debe activar disponibilidad primero */}
    </div>
  );
}
