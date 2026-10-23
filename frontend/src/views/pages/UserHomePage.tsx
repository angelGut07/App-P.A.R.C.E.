// Inicio del cliente autenticado: presentación de la plataforma, avisos de
// estado (servicio en curso, pago pendiente) y acceso al catálogo.

import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Wrench, Clock, Headphones } from 'lucide-react';
import Navbar from '../components/Navbar';
import WaveBackground from '../components/WaveBackground';
import CustomerStatusBanner from '../components/CustomerStatusBanner';
import { useAuth } from '../../controllers/AuthContext';

export default function UserHomePage() {
  // Obtener datos del usuario autenticado para pasarlos al Navbar
  const { user } = useAuth();

  return (
    <div className="min-h-screen bg-[#050505]">
      {/* Fondo de ondas animadas — ocupa toda la pantalla */}
      <WaveBackground />

      {/* Navbar autenticado: muestra nombre del usuario y menú de perfil */}
      <Navbar
        isAuthenticated={true}
        userName={user?.name}
        userAvatar={user?.avatar}
      />

      {/* Hero Section */}
      <section className="relative pt-20 sm:pt-28 md:pt-32 pb-12 sm:pb-16 md:pb-20 px-4 overflow-hidden">
        <div className="relative max-w-7xl mx-auto z-10">
          <motion.div
            initial={{ opacity: 0, y: 30 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8 }}
            className="text-center space-y-6 sm:space-y-8"
          >
            {/* Logo */}
            <div className="flex justify-center mb-4 sm:mb-8">
              <img
                src="/Logo.jpg"
                alt="P.A.R.C.E Logo"
                className="w-24 h-24 sm:w-32 sm:h-32 md:w-40 md:h-40 object-contain"
              />
            </div>

            <div className="flex justify-center">
              <span className="text-5xl sm:text-6xl md:text-8xl font-bold text-gradient">P.A.R.C.E</span>
            </div>

            <p className="text-lg sm:text-xl md:text-2xl text-gray-300 max-w-3xl mx-auto px-2">
              Plataforma de Asistencia Rápida Para Conductores en Emergencia
            </p>

            <CustomerStatusBanner />

            <div className="flex justify-center items-center pt-6 sm:pt-8">
              <Link to="/services" className="btn-primary px-8 sm:px-12 md:px-16 py-2 sm:py-3">
                Ver Servicios
              </Link>
            </div>
          </motion.div>

          {/* Car Image */}
          <motion.div
            initial={{ opacity: 0, scale: 0.8 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 1, delay: 0.3 }}
            className="mt-12 sm:mt-16 md:mt-20 relative"
          >
            <div className="w-full max-w-4xl mx-auto aspect-video rounded-lg sm:rounded-xl md:rounded-2xl overflow-hidden border border-anthracite-800">
              <img
                src="https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?w=1200&h=675&fit=crop&q=80"
                alt="Mecánico trabajando en vehículo"
                className="w-full h-full object-cover opacity-80"
              />
            </div>
          </motion.div>
        </div>
      </section>

      {/* Features Section */}
      <section className="relative py-12 sm:py-16 md:py-20 px-4 z-10">
        <div className="max-w-7xl mx-auto">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            className="text-center mb-8 sm:mb-12 md:mb-16"
          >
            <h2 className="text-3xl sm:text-4xl font-bold text-white mb-3 sm:mb-4">¿Quiénes Somos?</h2>
            <p className="text-base sm:text-lg md:text-xl text-gray-400 max-w-3xl mx-auto px-2">
              Somos una plataforma innovadora que conecta conductores en emergencia con mecánicos profesionales certificados.
              Nuestra misión es brindar asistencia rápida, confiable y de calidad en cualquier momento y lugar.
            </p>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 md:gap-8"
          >
            <div className="card p-6 sm:p-8 text-center space-y-4">
              <div className="w-12 h-12 sm:w-14 sm:h-14 md:w-16 md:h-16 mx-auto bg-gradient-to-br from-gold-500 to-gold-600 rounded-full flex items-center justify-center">
                <Clock className="w-6 h-6 sm:w-7 sm:h-7 md:w-8 md:h-8 text-anthracite-950" />
              </div>
              <h3 className="text-lg sm:text-xl font-bold text-white">Respuesta Rápida</h3>
              <p className="text-sm sm:text-base text-gray-400">
                Mecánicos disponibles en minutos para atender tu emergencia
              </p>
            </div>

            <div className="card p-6 sm:p-8 text-center space-y-4">
              <div className="w-12 h-12 sm:w-14 sm:h-14 md:w-16 md:h-16 mx-auto bg-gradient-to-br from-anthracite-500 to-anthracite-600 rounded-full flex items-center justify-center">
                <Wrench className="w-6 h-6 sm:w-7 sm:h-7 md:w-8 md:h-8 text-white" />
              </div>
              <h3 className="text-lg sm:text-xl font-bold text-white">Profesionales Certificados</h3>
              <p className="text-sm sm:text-base text-gray-400">
                Mecánicos verificados y calificados por usuarios
              </p>
            </div>

            <div className="card p-6 sm:p-8 text-center space-y-4">
              <div className="w-12 h-12 sm:w-14 sm:h-14 md:w-16 md:h-16 mx-auto bg-gradient-to-br from-gold-500 to-anthracite-600 rounded-full flex items-center justify-center">
                <Headphones className="w-6 h-6 sm:w-7 sm:h-7 md:w-8 md:h-8 text-white" />
              </div>
              <h3 className="text-lg sm:text-xl font-bold text-white">Soporte 24/7</h3>
              <p className="text-sm sm:text-base text-gray-400">
                Atención al cliente disponible en todo momento
              </p>
            </div>
          </motion.div>
        </div>
      </section>


      {/* Footer */}
      <footer className="relative border-t border-anthracite-800 py-6 sm:py-8 px-4 z-10">
        <div className="max-w-7xl mx-auto text-center text-gray-500 text-sm">
          <p>&copy; 2026 P.A.R.C.E. Todos los derechos reservados.</p>
          <div className="mt-3 sm:mt-4 space-x-3 sm:space-x-4 text-xs sm:text-sm">
            <Link to="/contact" className="hover:text-primary-400 transition-colors">
              Contacto
            </Link>
            <span>•</span>
            <a href="#" className="hover:text-primary-400 transition-colors">
              Términos
            </a>
            <span>•</span>
            <a href="#" className="hover:text-primary-400 transition-colors">
              Privacidad
            </a>
          </div>
        </div>
      </footer>
    </div>
  );
}
