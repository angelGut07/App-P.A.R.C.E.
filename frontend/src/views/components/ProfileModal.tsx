import { motion, AnimatePresence } from 'framer-motion';
import { X, User, Star, Phone, Mail, MapPin, Shield, Award, Calendar } from 'lucide-react';

interface ProfileData {
  name: string;
  role: 'user' | 'mechanic';
  phone?: string;
  email?: string;
  rating?: number;
  totalServices?: number;
  joinedDate?: string;
  location?: string;
  specialties?: string[];
  vehicleInfo?: {
    brand: string;
    model: string;
    plate: string;
    color: string;
  };
}

interface ProfileModalProps {
  isOpen: boolean;
  onClose: () => void;
  profile: ProfileData;
}

export default function ProfileModal({ isOpen, onClose, profile }: ProfileModalProps) {
  if (!isOpen) return null;

  return (
    <AnimatePresence>
      {isOpen && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          className="fixed inset-0 z-50 flex items-center justify-center bg-dark-950/80 backdrop-blur-sm"
          onClick={onClose}
        >
          <motion.div
            initial={{ scale: 0.9, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            exit={{ scale: 0.9, opacity: 0 }}
            onClick={(e) => e.stopPropagation()}
            className="card p-8 max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto"
          >
            {/* Header */}
            <div className="flex items-start justify-between mb-6">
              <div className="flex items-center gap-4">
                <div className={`w-20 h-20 rounded-full flex items-center justify-center ${
                  profile.role === 'mechanic'
                    ? 'bg-gradient-to-br from-gold-500 to-gold-700'
                    : 'bg-gradient-to-br from-primary-500 to-primary-700'
                }`}>
                  <User className="w-10 h-10 text-white" />
                </div>
                <div>
                  <h2 className="text-2xl font-bold text-white">{profile.name}</h2>
                  <p className="text-gray-400 flex items-center gap-2 mt-1">
                    {profile.role === 'mechanic' ? (
                      <><Shield className="w-4 h-4" /> Mecánico Certificado</>
                    ) : (
                      <><User className="w-4 h-4" /> Usuario</>
                    )}
                  </p>
                </div>
              </div>
              <button
                onClick={onClose}
                className="text-gray-400 hover:text-white transition-colors"
              >
                <X className="w-6 h-6" />
              </button>
            </div>

            {/* Rating (solo mecánicos) */}
            {profile.role === 'mechanic' && profile.rating !== undefined && (
              <div className="mb-6 p-4 bg-gradient-to-br from-gold-500/10 to-gold-600/10 rounded-xl border border-gold-500/20">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    {[...Array(5)].map((_, i) => (
                      <Star
                        key={i}
                        className={`w-5 h-5 ${
                          i < Math.floor(profile.rating!)
                            ? 'text-yellow-400 fill-yellow-400'
                            : 'text-gray-600'
                        }`}
                      />
                    ))}
                    <span className="text-white font-bold text-lg ml-2">{profile.rating.toFixed(1)}</span>
                  </div>
                  <div className="text-right">
                    <p className="text-gold-400 font-bold text-xl">{profile.totalServices || 0}</p>
                    <p className="text-gray-400 text-xs">Servicios realizados</p>
                  </div>
                </div>
              </div>
            )}

            {/* Información de contacto */}
            <div className="space-y-4 mb-6">
              <h3 className="text-lg font-bold text-white flex items-center gap-2">
                <Phone className="w-5 h-5 text-primary-400" />
                Información de contacto
              </h3>

              {profile.phone && (
                <div className="flex items-center gap-3 p-3 bg-dark-800/60 rounded-xl border border-anthracite-800">
                  <Phone className="w-5 h-5 text-gray-500 flex-shrink-0" />
                  <div>
                    <p className="text-xs text-gray-400">Teléfono</p>
                    <p className="text-white font-semibold">{profile.phone}</p>
                  </div>
                </div>
              )}

              {profile.email && (
                <div className="flex items-center gap-3 p-3 bg-dark-800/60 rounded-xl border border-anthracite-800">
                  <Mail className="w-5 h-5 text-gray-500 flex-shrink-0" />
                  <div>
                    <p className="text-xs text-gray-400">Correo electrónico</p>
                    <p className="text-white font-semibold">{profile.email}</p>
                  </div>
                </div>
              )}

              {profile.location && (
                <div className="flex items-center gap-3 p-3 bg-dark-800/60 rounded-xl border border-anthracite-800">
                  <MapPin className="w-5 h-5 text-gray-500 flex-shrink-0" />
                  <div>
                    <p className="text-xs text-gray-400">Ubicación</p>
                    <p className="text-white font-semibold">{profile.location}</p>
                  </div>
                </div>
              )}

              {profile.joinedDate && (
                <div className="flex items-center gap-3 p-3 bg-dark-800/60 rounded-xl border border-anthracite-800">
                  <Calendar className="w-5 h-5 text-gray-500 flex-shrink-0" />
                  <div>
                    <p className="text-xs text-gray-400">Miembro desde</p>
                    <p className="text-white font-semibold">{profile.joinedDate}</p>
                  </div>
                </div>
              )}
            </div>

            {/* Especialidades (solo mecánicos) */}
            {profile.role === 'mechanic' && profile.specialties && profile.specialties.length > 0 && (
              <div className="mb-6">
                <h3 className="text-lg font-bold text-white flex items-center gap-2 mb-3">
                  <Award className="w-5 h-5 text-gold-400" />
                  Especialidades
                </h3>
                <div className="flex flex-wrap gap-2">
                  {profile.specialties.map((specialty, index) => (
                    <span
                      key={index}
                      className="px-3 py-1.5 bg-gold-500/20 text-gold-300 rounded-full text-sm font-medium"
                    >
                      {specialty}
                    </span>
                  ))}
                </div>
              </div>
            )}

            {/* Información del vehículo (solo usuarios) */}
            {profile.role === 'user' && profile.vehicleInfo && (
              <div className="mb-6">
                <h3 className="text-lg font-bold text-white flex items-center gap-2 mb-3">
                  🚗 Información del vehículo
                </h3>
                <div className="grid grid-cols-2 gap-3">
                  <div className="p-3 bg-dark-800/60 rounded-xl border border-anthracite-800">
                    <p className="text-xs text-gray-400">Marca y Modelo</p>
                    <p className="text-white font-semibold">
                      {profile.vehicleInfo.brand} {profile.vehicleInfo.model}
                    </p>
                  </div>
                  <div className="p-3 bg-dark-800/60 rounded-xl border border-anthracite-800">
                    <p className="text-xs text-gray-400">Placa</p>
                    <p className="text-white font-bold tracking-widest">{profile.vehicleInfo.plate}</p>
                  </div>
                  <div className="p-3 bg-dark-800/60 rounded-xl border border-anthracite-800 col-span-2">
                    <p className="text-xs text-gray-400">Color</p>
                    <p className="text-white font-semibold">{profile.vehicleInfo.color}</p>
                  </div>
                </div>
              </div>
            )}

            {/* Botón cerrar */}
            <button
              onClick={onClose}
              className="w-full btn-primary"
            >
              Cerrar
            </button>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
