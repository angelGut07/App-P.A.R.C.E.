import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { MapPin, Clock, Navigation, AlertTriangle, CheckCircle, Car, ToggleLeft, ToggleRight, Receipt, ClipboardList } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import ActiveOrder from '../components/mechanic/ActiveOrder';
import { Alert, EmptyState } from '../components/ui';
import { DEFAULT_CENTER } from '../components/LocationMap';
import { useAuth } from '../../controllers/AuthContext';
import { useMechanic } from '../../controllers/MechanicContext';
import { errorMessage, mechanicService } from '../../services';
import type { Priority, ServiceRequest } from '@/models';
import { REQUEST_STATUS, formatCOP, formatDate } from '../format';

const POLL_MS = 15000;

const PRIORITY: Record<Priority, { label: string; color: string }> = {
  normal: { label: 'Normal', color: 'bg-gray-500/20 text-gray-300' },
  urgent: { label: 'Urgente', color: 'bg-orange-500/20 text-orange-300' },
  critical: { label: 'Crítica', color: 'bg-red-500/20 text-red-300' },
};

/**
 * Órdenes del mecánico: solicitudes pendientes cercanas (cuando está
 * disponible), el servicio que está atendiendo y su historial.
 */
export default function MechanicOrdersPage() {
  const { user } = useAuth();
  const { isActive, setIsActive } = useMechanic();
  const [position, setPosition] = useState(DEFAULT_CENTER);
  const [available, setAvailable] = useState<ServiceRequest[]>([]);
  const [mine, setMine] = useState<ServiceRequest[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [selected, setSelected] = useState<ServiceRequest | null>(null);
  const [error, setError] = useState('');
  const [accepting, setAccepting] = useState<number | null>(null);

  // Posición real del mecánico para ordenar por distancia (Bogotá si no da permiso)
  useEffect(() => {
    navigator.geolocation?.getCurrentPosition(
      pos => setPosition({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
      () => undefined,
      { timeout: 8000 }
    );
  }, []);

  const loadMine = useCallback(async () => {
    const res = await mechanicService.myRequests();
    if (res.success && res.data) {
      setMine(res.data.serviceRequests);
      const current = res.data.serviceRequests.find(r => r.status === 'assigned' || r.status === 'in_progress');
      setSelectedId(prev => prev ?? current?.id ?? null);
    }
  }, []);

  const loadAvailable = useCallback(async () => {
    if (!isActive) return setAvailable([]);
    const res = await mechanicService.available(position.lat, position.lng);
    if (res.success && res.data) setAvailable(res.data.serviceRequests);
    else setError(errorMessage(res, 'No se pudieron cargar las solicitudes'));
  }, [isActive, position.lat, position.lng]);

  useEffect(() => {
    loadMine();
    loadAvailable();
    const timer = setInterval(() => { loadMine(); loadAvailable(); }, POLL_MS);
    return () => clearInterval(timer);
  }, [loadMine, loadAvailable]);

  // Detalle completo (con ubicación exacta) del servicio seleccionado
  useEffect(() => {
    if (!selectedId) return setSelected(null);
    mechanicService.get(selectedId).then(res => res.success && res.data && setSelected(res.data.serviceRequest));
  }, [selectedId]);

  const accept = async (id: number) => {
    setAccepting(id);
    setError('');
    const res = await mechanicService.accept(id);
    setAccepting(null);
    if (!res.success || !res.data) {
      setError(errorMessage(res, 'No se pudo aceptar la solicitud'));
      loadAvailable();
      return;
    }
    setSelectedId(res.data.serviceRequest.id);
    loadMine();
    loadAvailable();
  };

  const activeOrders = mine.filter(r => r.status === 'assigned' || r.status === 'in_progress');
  const history = mine.filter(r => !['assigned', 'in_progress'].includes(r.status));
  const busy = activeOrders.length > 0;

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Mecánico'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24 md:ml-64">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-6 max-w-6xl mx-auto">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-3xl font-bold text-white">Órdenes</h1>
              <p className="text-gray-400 text-sm mt-1">Solicitudes cercanas y servicios en curso</p>
            </div>
            <button onClick={() => setIsActive(!isActive)}
              className={`flex items-center gap-2 px-4 py-2 rounded-xl font-semibold border ${isActive ? 'bg-green-500/15 text-green-400 border-green-500/40' : 'bg-dark-800 text-gray-400 border-anthracite-700'}`}>
              {isActive ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
              {isActive ? 'Disponible' : 'No disponible'}
            </button>
          </div>

          {error && <Alert type="error">{error}</Alert>}

          {selected && (selected.status === 'assigned' || selected.status === 'in_progress' || selected.status === 'completed') && (
            <section className="space-y-3">
              <h2 className="text-xl font-bold text-white">Servicio en atención</h2>
              <ActiveOrder key={selected.id} request={selected} onChange={updated => { setSelected(updated); loadMine(); }} />
            </section>
          )}

          {activeOrders.length > 1 && (
            <div className="flex gap-2 flex-wrap">
              {activeOrders.map(r => (
                <button key={r.id} onClick={() => setSelectedId(r.id)}
                  className={`px-3 py-1.5 rounded-lg text-sm border ${r.id === selectedId ? 'border-gold-500 text-gold-400' : 'border-anthracite-700 text-gray-400'}`}>
                  {r.serviceCode}
                </button>
              ))}
            </div>
          )}

          <section className="space-y-3">
            <h2 className="text-xl font-bold text-white">Solicitudes disponibles</h2>
            {!isActive ? (
              <div className="card p-6 flex items-center gap-3 text-yellow-300 border border-yellow-500/30 bg-yellow-500/5">
                <AlertTriangle className="w-5 h-5" /> Activa tu disponibilidad para ver solicitudes cercanas.
              </div>
            ) : available.length === 0 ? (
              <EmptyState icon={Clock}>No hay solicitudes pendientes cerca. Esta lista se actualiza sola.</EmptyState>
            ) : (
              <div className="grid md:grid-cols-2 gap-4">
                {available.map(r => (
                  <div key={r.id} className="card p-5 space-y-3">
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <p className="text-xs text-gray-500">{r.serviceCode} · {formatDate(r.requestedAt)}</p>
                        <h3 className="text-white font-bold">{r.serviceTypeName}</h3>
                      </div>
                      <span className={`px-2 py-0.5 rounded-full text-xs font-semibold ${PRIORITY[r.priority].color}`}>{PRIORITY[r.priority].label}</span>
                    </div>
                    <p className="text-sm text-gray-400 line-clamp-3 whitespace-pre-line">{r.description}</p>
                    <div className="flex items-center gap-4 text-xs text-gray-400">
                      <span className="flex items-center gap-1"><Navigation className="w-3.5 h-3.5 text-gold-400" /> {Number(r.distanceKm).toFixed(1)} km</span>
                      <span className="flex items-center gap-1"><Car className="w-3.5 h-3.5" /> {r.vehicleMake} {r.vehicleModel}</span>
                      <span className="flex items-center gap-1"><MapPin className="w-3.5 h-3.5" /> Zona aproximada</span>
                    </div>
                    <button onClick={() => accept(r.id)} disabled={accepting !== null || busy}
                      className="w-full btn-primary disabled:opacity-40" title={busy ? 'Termina tu servicio actual antes de aceptar otro' : ''}>
                      {accepting === r.id ? 'Aceptando...' : busy ? 'Tienes un servicio en curso' : 'Aceptar solicitud'}
                    </button>
                  </div>
                ))}
              </div>
            )}
          </section>

          <section className="space-y-3">
            <h2 className="text-xl font-bold text-white">Historial</h2>
            {history.length === 0 ? (
              <EmptyState icon={ClipboardList}>Aún no tienes servicios terminados.</EmptyState>
            ) : (
              <div className="card divide-y divide-anthracite-800">
                {history.map(r => (
                  <div key={r.id} className="p-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                      <p className="text-white font-semibold">{r.serviceTypeName} <span className="text-gray-500 text-xs">{r.serviceCode}</span></p>
                      <p className="text-xs text-gray-400">{r.customerFirstName} {r.customerLastName} · {formatDate(r.completedAt ?? r.requestedAt)}</p>
                    </div>
                    <div className="flex items-center gap-3">
                      <span className={`px-2 py-0.5 rounded-full text-xs border ${REQUEST_STATUS[r.status].color}`}>{REQUEST_STATUS[r.status].label}</span>
                      {r.status === 'completed' && (
                        <>
                          <span className="text-sm text-gold-400">{formatCOP(r.finalCost)}</span>
                          {r.customerRating != null && <span className="text-xs text-yellow-400 flex items-center gap-1"><CheckCircle className="w-3 h-3" /> {r.customerRating}★</span>}
                          <Link to={`/payment/receipt?request=${r.id}`} className="text-gray-400 hover:text-white" title="Comprobante"><Receipt className="w-4 h-4" /></Link>
                        </>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </section>
        </motion.div>
      </main>
    </div>
  );
}
