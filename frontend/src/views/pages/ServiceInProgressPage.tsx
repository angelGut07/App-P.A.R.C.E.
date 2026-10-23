import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { MapPin, Clock, User, Star, Phone, MessageCircle, Car, AlertTriangle, CheckCircle, CreditCard, Search, Wrench } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import ProfileModal from '../components/ProfileModal';
import LocationMap from '../components/LocationMap';
import { useAuth } from '../../controllers/AuthContext';
import { errorMessage, requestService } from '../../services';
import type { ServiceRequest } from '@/models';
import { ACTIVE_STATUSES, REQUEST_STATUS, formatDate, fullName, whatsappLink } from '../format';

const POLL_MS = 10000;

const STEPS = [
  { status: 'pending', label: 'Solicitado', icon: Search },
  { status: 'assigned', label: 'En camino', icon: Car },
  { status: 'in_progress', label: 'En atención', icon: Wrench },
  { status: 'completed', label: 'Completado', icon: CheckCircle },
] as const;

function CancelModal({ onConfirm, onClose, busy }: { onConfirm: (reason: string) => void; onClose: () => void; busy: boolean }) {
  const [reason, setReason] = useState('');
  return (
    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
      className="fixed inset-0 z-50 flex items-center justify-center bg-dark-950/80 backdrop-blur-sm">
      <motion.div initial={{ scale: 0.9, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} exit={{ scale: 0.9, opacity: 0 }}
        className="card p-8 max-w-sm w-full mx-4 space-y-5 text-center">
        <div className="w-16 h-16 mx-auto bg-red-500/20 rounded-full flex items-center justify-center">
          <AlertTriangle className="w-8 h-8 text-red-400" />
        </div>
        <div>
          <h3 className="text-xl font-bold text-white mb-2">¿Cancelar servicio?</h3>
          <p className="text-gray-400 text-sm">Cuéntanos el motivo para avisarle al mecánico.</p>
        </div>
        <textarea value={reason} onChange={e => setReason(e.target.value)} placeholder="Ej: Ya pude solucionar el problema"
          className="input-field resize-none h-20 text-sm" />
        <div className="grid grid-cols-2 gap-3">
          <button onClick={onClose} className="px-4 py-3 bg-dark-700 hover:bg-dark-600 text-white rounded-xl font-semibold transition-colors">
            Seguir servicio
          </button>
          <button onClick={() => onConfirm(reason.trim())} disabled={busy || reason.trim().length < 3}
            className="px-4 py-3 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white rounded-xl font-semibold transition-colors">
            {busy ? 'Cancelando...' : 'Cancelar'}
          </button>
        </div>
      </motion.div>
    </motion.div>
  );
}

/**
 * Seguimiento en vivo de la solicitud del cliente: consulta el estado cada
 * 10 segundos y muestra al mecánico asignado, su contacto y la ubicación.
 */
export default function ServiceInProgressPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const requestedId = Number(searchParams.get('id')) || null;

  const [request, setRequest] = useState<ServiceRequest | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [showCancelModal, setShowCancelModal] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [showMechanicProfile, setShowMechanicProfile] = useState(false);

  const load = useCallback(async () => {
    let id = requestedId;
    if (!id) {
      // Sin ?id: la solicitud activa más reciente del cliente
      const list = await requestService.list();
      const active = list.data?.serviceRequests.find(r => ACTIVE_STATUSES.includes(r.status));
      if (!active) {
        setRequest(null);
        setLoading(false);
        return;
      }
      id = active.id;
    }
    const res = await requestService.get(id);
    if (res.success && res.data) {
      setRequest(res.data.serviceRequest);
      setError('');
    } else {
      setError(errorMessage(res, 'No se pudo cargar la solicitud'));
    }
    setLoading(false);
  }, [requestedId]);

  useEffect(() => {
    load();
    const timer = setInterval(load, POLL_MS);
    return () => clearInterval(timer);
  }, [load]);

  const handleCancel = async (reason: string) => {
    if (!request) return;
    setCancelling(true);
    const res = await requestService.cancel(request.id, reason);
    setCancelling(false);
    setShowCancelModal(false);
    if (res.success && res.data) setRequest(res.data.serviceRequest);
    else setError(errorMessage(res, 'No se pudo cancelar la solicitud'));
  };

  const layout = (content: React.ReactNode) => (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar hidden />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24">{content}</main>
    </div>
  );

  if (loading) {
    return layout(<p className="text-gray-400 text-center mt-20">Cargando tu servicio...</p>);
  }

  if (!request) {
    return layout(
      <div className="card p-10 max-w-lg mx-auto mt-10 text-center space-y-4">
        <Car className="w-12 h-12 text-gold-400 mx-auto" />
        <h2 className="text-2xl font-bold text-white">No tienes servicios en curso</h2>
        <p className="text-gray-400">{error || 'Cuando pidas asistencia podrás seguirla desde aquí.'}</p>
        <Link to="/services" className="btn-primary inline-block">Pedir un servicio</Link>
      </div>
    );
  }

  const status = REQUEST_STATUS[request.status];
  const stepIndex = STEPS.findIndex(s => s.status === request.status);
  const isActive = ACTIVE_STATUSES.includes(request.status);
  const hasMechanic = request.mechanicId !== null;
  const mechanicName = fullName(request.mechanicFirstName, request.mechanicLastName);
  const point = request.latitude && request.longitude
    ? { lat: Number(request.latitude), lng: Number(request.longitude) }
    : null;

  return layout(
    <>
      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-6 max-w-6xl mx-auto">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-3xl font-bold text-white">Servicio {request.serviceCode}</h1>
            <p className="text-gray-400 text-sm mt-1">{request.serviceTypeName} · solicitado {formatDate(request.requestedAt)}</p>
          </div>
          <span className={`px-4 py-2 rounded-full text-sm font-bold border ${status.color}`}>{status.label}</span>
        </div>

        {/* Progreso */}
        {stepIndex >= 0 && (
          <div className="card p-5">
            <div className="flex items-center justify-between">
              {STEPS.map((step, i) => (
                <div key={step.status} className="flex-1 flex flex-col items-center relative">
                  {i > 0 && <div className={`absolute top-5 right-1/2 w-full h-0.5 ${i <= stepIndex ? 'bg-gold-500' : 'bg-dark-700'}`} />}
                  <div className={`relative z-10 w-10 h-10 rounded-full flex items-center justify-center ${i <= stepIndex ? 'bg-gold-500 text-anthracite-950' : 'bg-dark-700 text-gray-500'}`}>
                    <step.icon className="w-5 h-5" />
                  </div>
                  <span className={`mt-2 text-xs ${i <= stepIndex ? 'text-gold-400' : 'text-gray-500'}`}>{step.label}</span>
                </div>
              ))}
            </div>
            {request.status === 'pending' && (
              <p className="text-center text-sm text-gray-400 mt-4">Estamos avisando a los mecánicos cercanos. Esta página se actualiza sola.</p>
            )}
          </div>
        )}

        {error && <p className="text-red-400 text-sm">{error}</p>}

        <div className="grid lg:grid-cols-2 gap-4">
          <div className="card p-5 flex flex-col">
            <div className="flex items-center gap-2 mb-4">
              <MapPin className="w-5 h-5 text-gold-400" />
              <h3 className="text-xl font-bold text-white">Tu ubicación</h3>
            </div>
            <div className="flex-1 min-h-[320px] rounded-xl overflow-hidden">
              <LocationMap point={point} label="Aquí te espera el mecánico" />
            </div>
          </div>

          <div className="card p-6 flex flex-col space-y-4">
            <h3 className="text-xl font-bold text-white">Detalle</h3>

            {hasMechanic ? (
              <div className="flex items-center gap-4">
                <button onClick={() => setShowMechanicProfile(true)}
                  className="w-14 h-14 bg-gradient-to-br from-gold-500 to-gold-700 rounded-full flex items-center justify-center flex-shrink-0 overflow-hidden">
                  {request.mechanicPictureUrl
                    ? <img src={request.mechanicPictureUrl} alt={mechanicName} className="w-full h-full object-cover" />
                    : <User className="w-7 h-7 text-anthracite-950" />}
                </button>
                <div className="flex-1 min-w-0">
                  <button onClick={() => setShowMechanicProfile(true)} className="text-lg font-bold text-white hover:text-gold-400 transition-colors text-left">
                    {mechanicName}
                  </button>
                  <div className="flex items-center gap-1 mt-0.5 text-xs text-gray-400">
                    <Star className="w-3.5 h-3.5 text-yellow-400 fill-yellow-400" />
                    {request.mechanicAverageRating ? `${request.mechanicAverageRating} · ` : 'Sin calificaciones · '}
                    {request.mechanicSpecialty || 'Mecánico'}
                  </div>
                </div>
                {request.mechanicPhone && isActive && (
                  <div className="flex gap-2">
                    <a href={`tel:${request.mechanicPhone}`} className="p-2.5 bg-dark-700 hover:bg-dark-600 rounded-xl" title="Llamar">
                      <Phone className="w-4 h-4 text-white" />
                    </a>
                    <a href={whatsappLink(request.mechanicPhone, `Hola, soy el cliente del servicio ${request.serviceCode}`)} target="_blank" rel="noreferrer"
                      className="p-2.5 bg-green-600 hover:bg-green-700 rounded-xl" title="WhatsApp">
                      <MessageCircle className="w-4 h-4 text-white" />
                    </a>
                  </div>
                )}
              </div>
            ) : isActive ? (
              <div className="flex items-center gap-3 p-4 bg-yellow-500/10 rounded-xl border border-yellow-500/30">
                <div className="w-5 h-5 border-2 border-yellow-400 border-t-transparent rounded-full animate-spin flex-shrink-0" />
                <p className="text-yellow-300 text-sm">Esperando a que un mecánico acepte tu solicitud...</p>
              </div>
            ) : null}

            <div className="flex items-center gap-3 p-4 bg-dark-800/60 rounded-xl border border-anthracite-800">
              <Car className="w-5 h-5 text-primary-400 flex-shrink-0" />
              <div>
                <p className="text-xs text-gray-400">Vehículo</p>
                <p className="text-white font-semibold text-sm">
                  {request.vehicleMake} {request.vehicleModel} {request.vehicleYear} · <span className="tracking-widest">{request.vehicleLicensePlate}</span>
                </p>
              </div>
            </div>

            {request.assignedAt && (
              <div className="flex items-center gap-3 p-4 bg-dark-800/60 rounded-xl border border-anthracite-800">
                <Clock className="w-5 h-5 text-primary-400 flex-shrink-0" />
                <div>
                  <p className="text-xs text-gray-400">Aceptado</p>
                  <p className="text-white font-semibold text-sm">{formatDate(request.assignedAt)}</p>
                </div>
              </div>
            )}

            <div className="p-4 bg-gradient-to-br from-primary-600/15 to-purple-600/15 rounded-xl border border-primary-500/25">
              <h4 className="text-base font-bold text-white mb-1">{request.serviceTypeName}</h4>
              <p className="text-gray-300 text-sm leading-relaxed whitespace-pre-line line-clamp-6">{request.description}</p>
            </div>

            {request.status === 'cancelled' && request.cancellationReason && (
              <p className="text-sm text-red-300">Motivo de cancelación: {request.cancellationReason}</p>
            )}

            <div className="pt-2 space-y-3 mt-auto">
              {request.status === 'completed' && (
                <button onClick={() => navigate(`/payment?request=${request.id}`)}
                  className="w-full py-3 bg-gold-600 hover:bg-gold-700 text-anthracite-950 rounded-xl font-semibold transition-all flex items-center justify-center gap-2">
                  <CreditCard className="w-5 h-5" /> Ir a pagar
                </button>
              )}
              {isActive && request.status !== 'in_progress' && (
                <button onClick={() => setShowCancelModal(true)}
                  className="w-full py-3 bg-dark-700 hover:bg-red-900/40 border border-dark-600 hover:border-red-700 text-gray-300 hover:text-red-300 rounded-xl font-semibold transition-all">
                  Cancelar servicio
                </button>
              )}
              {!isActive && request.status !== 'completed' && (
                <Link to="/services" className="block w-full text-center btn-primary">Pedir otro servicio</Link>
              )}
            </div>
          </div>
        </div>
      </motion.div>

      <AnimatePresence>
        {showCancelModal && <CancelModal busy={cancelling} onConfirm={handleCancel} onClose={() => setShowCancelModal(false)} />}
      </AnimatePresence>

      {hasMechanic && (
        <ProfileModal
          isOpen={showMechanicProfile}
          onClose={() => setShowMechanicProfile(false)}
          profile={{
            name: mechanicName,
            role: 'mechanic',
            phone: request.mechanicPhone ?? undefined,
            rating: request.mechanicAverageRating ? Number(request.mechanicAverageRating) : undefined,
            totalServices: request.mechanicCompletedServices,
            joinedDate: request.mechanicJoinedAt ? formatDate(request.mechanicJoinedAt, false) : undefined,
            specialties: request.mechanicSpecialty ? [request.mechanicSpecialty] : undefined,
          }}
        />
      )}
    </>
  );
}
