import { useState } from 'react';
import { motion } from 'framer-motion';
import { Phone, Clock, Send, MessageSquare } from 'lucide-react';
import Navbar from '../components/Navbar';
import { useAuth } from '../../controllers/AuthContext';

export default function ContactPage() {
  const [formData, setFormData] = useState({
    name: '',
    phone: '',
    reason: '',
  });

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [opened, setOpened] = useState(false);
  const [phoneError, setPhoneError] = useState('');
  const { user } = useAuth();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    // Solo dígitos (con + y espacios opcionales): entre 7 y 15 números
    const digits = formData.phone.replace(/[\s+]/g, '');
    if (!/^\+?[\d\s]+$/.test(formData.phone.trim()) || digits.length < 7 || digits.length > 15) {
      setPhoneError('Ingresa un número de teléfono válido (solo números, entre 7 y 15 dígitos)');
      return;
    }
    setPhoneError('');
    setIsSubmitting(true);

    // Crear el mailto link
    const subject = encodeURIComponent(`Contacto de ${formData.name}`);
    const body = encodeURIComponent(
      `Nombre: ${formData.name}\n` +
      `Teléfono: ${formData.phone}\n\n` +
      `Motivo:\n${formData.reason}`
    );

    const mailtoLink = `mailto:proyectoparce@gmail.com?subject=${subject}&body=${body}`;

    // Abrir cliente de correo
    window.location.href = mailtoLink;

    setIsSubmitting(false);

    setFormData({ name: '', phone: '', reason: '' });
    setOpened(true);
  };

  return (
    <div className="min-h-screen bg-gradient-dark">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} hideNavLinks={true} />

      <main className="pt-24 pb-12 px-4">
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          className="max-w-6xl mx-auto"
        >
          <div className="grid lg:grid-cols-2 gap-8">
            {/* Contact Image */}
            <div className="relative h-96 lg:h-auto rounded-2xl overflow-hidden">
              <img
                src="https://images.unsplash.com/photo-1423666639041-f56000c27a9a?w=800&h=600&fit=crop&q=80"
                alt="Contacto"
                className="w-full h-full object-cover opacity-80"
              />
              <div className="absolute inset-0 bg-gradient-to-t from-dark-950 via-dark-950/50 to-transparent flex items-end p-8">
                <div className="text-white">
                  <h2 className="text-3xl font-bold mb-2">¿Necesitas ayuda?</h2>
                  <p className="text-gray-300">Estamos disponibles 24/7 para atenderte</p>
                </div>
              </div>
            </div>

            {/* Contact Form */}
            <div className="card p-8 space-y-6">
              <div className="text-center">
                <h1 className="text-3xl font-bold text-white mb-2">
                  Contáctanos y danos tu opinión
                </h1>
                <p className="text-gray-400">Estamos aquí para ayudarte</p>
              </div>

              <form onSubmit={handleSubmit} className="space-y-4">
                {/* Name */}
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-gray-300">
                    Nombre
                  </label>
                  <input
                    type="text"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    className="input-field"
                    placeholder="Tu nombre completo"
                    required
                  />
                </div>

                {/* Phone */}
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-gray-300">
                    Número
                  </label>
                  <input
                    type="tel"
                    inputMode="tel"
                    maxLength={20}
                    value={formData.phone}
                    onChange={(e) => {
                      // Descarta letras y símbolos mientras se escribe
                      setFormData({ ...formData, phone: e.target.value.replace(/[^\d+\s]/g, '') });
                      setPhoneError('');
                    }}
                    className="input-field"
                    placeholder="+57 300 123 4567"
                    required
                  />
                  {phoneError && <p className="text-red-400 text-xs">{phoneError}</p>}
                </div>

                {/* Reason */}
                <div className="space-y-2">
                  <label className="flex items-center gap-2 text-sm font-medium text-gray-300">
                    <MessageSquare className="w-4 h-4" />
                    Motivo por el cual nos quieres contactar
                  </label>
                  <textarea
                    value={formData.reason}
                    onChange={(e) => setFormData({ ...formData, reason: e.target.value })}
                    className="input-field min-h-[120px] resize-none"
                    placeholder="Cuéntanos cómo podemos ayudarte..."
                    required
                  />
                </div>

                {/* Submit Button */}
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="w-full btn-primary flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <Send className="w-5 h-5" />
                  {isSubmitting ? 'Enviando...' : 'Enviar'}
                </button>
                {opened && (
                  <p className="text-sm text-green-400 text-center">Se abrió tu aplicación de correo con el mensaje listo. Envíalo desde ahí.</p>
                )}
              </form>

              {/* Contact Info */}
              <div className="pt-6 border-t border-anthracite-800 space-y-4">
                <div className="flex items-center gap-3 text-gray-300">
                  <Clock className="w-5 h-5 text-gold-500" />
                  <div>
                    <p className="text-sm text-gray-400">Horario de atención</p>
                    <p className="font-medium">Domingo-Domingo 24hs</p>
                  </div>
                </div>

                <div className="flex items-center gap-3 text-gray-300">
                  <Phone className="w-5 h-5 text-gold-500" />
                  <div>
                    <p className="text-sm text-gray-400">P.A.R.C.E</p>
                    <p className="font-medium">Bogotá: (601) 80 14592</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </motion.div>
      </main>
    </div>
  );
}
