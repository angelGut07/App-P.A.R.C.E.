import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Wrench, Clock, Headphones } from 'lucide-react';
import Navbar from '../components/Navbar';
import WaveBackground from '../components/WaveBackground';

export default function LandingPage() {
  return (
    <div className="min-h-screen bg-[#050505]">
      {/* Fondo de ondas animadas — ocupa toda la pantalla */}
      <WaveBackground />

      <Navbar />

      {/* Hero Section */}
      <section className="relative pt-32 pb-20 px-4 overflow-hidden">
        {/* Glow sutil encima de las ondas */}
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-gold-500/5 rounded-full blur-3xl animate-pulse-slow"></div>
        </div>

        <div className="relative max-w-7xl mx-auto" style={{ zIndex: 1 }}>
          <motion.div
            initial={{ opacity: 0, y: 30 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8 }}
            className="text-center space-y-8"
          >
            <div className="flex justify-center mb-8">
              <img
                src="/Logo.jpg"
                alt="P.A.R.C.E Logo"
                className="w-32 h-32 md:w-40 md:h-40 object-contain"
                style={{
                  mixBlendMode: 'screen',
                  filter: 'brightness(1.5) contrast(1.2)'
                }}
              />
            </div>

            <div className="flex justify-center">
              <span className="text-6xl md:text-8xl font-bold text-gradient">P.A.R.C.E</span>
            </div>

            <p className="text-xl md:text-2xl text-gray-300 max-w-3xl mx-auto">
              Plataforma de Asistencia Rápida Para Conductores en Emergencia
            </p>

            <div className="flex flex-col sm:flex-row gap-4 justify-center items-center pt-8">
              <Link to="/register" className="btn-primary">
                Registro
              </Link>
              <Link to="/services" className="btn-outline">
                Ver Servicios
              </Link>
            </div>
          </motion.div>

          {/* Car Image Placeholder */}
          <motion.div
            initial={{ opacity: 0, scale: 0.8 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ duration: 1, delay: 0.3 }}
            className="mt-20 relative"
          >
            <div className="w-full max-w-4xl mx-auto aspect-video rounded-2xl overflow-hidden border border-anthracite-800">
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
      <section className="py-20 px-4 bg-dark-900/60 relative" style={{ zIndex: 1 }}>
        <div className="max-w-7xl mx-auto">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            className="text-center mb-16"
          >
            <h2 className="text-4xl font-bold text-white mb-4">¿Quiénes Somos?</h2>
            <p className="text-xl text-gray-400 max-w-3xl mx-auto">
              Somos una plataforma innovadora que conecta conductores en emergencia con mecánicos profesionales certificados.
              Nuestra misión es brindar asistencia rápida, confiable y de calidad en cualquier momento y lugar.
            </p>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            className="grid md:grid-cols-3 gap-8"
          >
            <div className="card p-8 text-center space-y-4">
              <div className="w-16 h-16 mx-auto bg-gradient-to-br from-gold-500 to-gold-600 rounded-full flex items-center justify-center">
                <Clock className="w-8 h-8 text-anthracite-950" />
              </div>
              <h3 className="text-xl font-bold text-white">Respuesta Rápida</h3>
              <p className="text-gray-400">
                Mecánicos disponibles en minutos para atender tu emergencia
              </p>
            </div>

            <div className="card p-8 text-center space-y-4">
              <div className="w-16 h-16 mx-auto bg-gradient-to-br from-anthracite-500 to-anthracite-600 rounded-full flex items-center justify-center">
                <Wrench className="w-8 h-8 text-white" />
              </div>
              <h3 className="text-xl font-bold text-white">Profesionales Certificados</h3>
              <p className="text-gray-400">
                Mecánicos verificados y calificados por usuarios
              </p>
            </div>

            <div className="card p-8 text-center space-y-4">
              <div className="w-16 h-16 mx-auto bg-gradient-to-br from-gold-500 to-anthracite-600 rounded-full flex items-center justify-center">
                <Headphones className="w-8 h-8 text-white" />
              </div>
              <h3 className="text-xl font-bold text-white">Soporte 24/7</h3>
              <p className="text-gray-400">
                Atención al cliente disponible en todo momento
              </p>
            </div>
          </motion.div>
        </div>
      </section>

      {/* CTA Section */}
      <section className="py-20 px-4 relative" style={{ zIndex: 1 }}>
        <div className="max-w-4xl mx-auto text-center space-y-8">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
          >
            <h2 className="text-4xl font-bold text-white mb-4">
              ¿Listo para comenzar?
            </h2>
            <p className="text-xl text-gray-400 mb-8">
              Únete a miles de conductores que confían en P.A.R.C.E
            </p>
            <Link to="/register" className="btn-primary inline-block">
              Crear Cuenta Gratis
            </Link>
          </motion.div>
        </div>
      </section>

      {/* Footer */}
      <footer className="border-t border-anthracite-800 py-8 px-4 relative" style={{ zIndex: 1 }}>
        <div className="max-w-7xl mx-auto text-center text-gray-500">
          <p>&copy; 2026 P.A.R.C.E. Todos los derechos reservados.</p>
          <div className="mt-4 space-x-4">
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
