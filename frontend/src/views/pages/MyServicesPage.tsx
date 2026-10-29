import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { History, Navigation, CreditCard, Receipt, Star, Image } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { EmptyState, Modal } from '../components/ui';
import { useAuth } from '../../controllers/AuthContext';
import { requestService } from '../../services';
import type { Evidence, RequestStatus, ServiceRequest } from '@/models';
import { ACTIVE_STATUSES, REQUEST_STATUS, formatCOP, formatDate, fullName } from '../format';

const FILTERS: { value: RequestStatus | ''; label: string }[] = [
  { value: '', label: 'Todos' },
  { value: 'pending', label: 'Pendientes' },
  { value: 'assigned', label: 'Asignados' },
  { value: 'in_progress', label: 'En atención' },
  { value: 'completed', label: 'Completados' },
  { value: 'cancelled', label: 'Cancelados' },
];

/** Historial de solicitudes del cliente con acceso a cada paso del flujo. */
export default function MyServicesPage() {
  const { user } = useAuth();
  const [requests, setRequests] = useState<ServiceRequest[]>([]);
  const [filter, setFilter] = useState<RequestStatus | ''>('');
  const [evidences, setEvidences] = useState<Evidence[] | null>(null);

  useEffect(() => {
    requestService.list(filter || undefined).then(res => res.success && res.data && setRequests(res.data.serviceRequests));
  }, [filter]);

  const showEvidences = async (id: number) => {
    const res = await requestService.evidences(id);
    setEvidences(res.data?.evidences ?? []);
  };

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} />
      <Sidebar />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24 md:ml-64">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-6 max-w-5xl mx-auto">
          <div>
            <h1 className="text-3xl font-bold text-white">Mis servicios</h1>
            <p className="text-gray-400 text-sm mt-1">Historial de tus solicitudes de asistencia</p>
          </div>

          <div className="flex flex-wrap gap-2">
            {FILTERS.map(f => (
              <button key={f.value} onClick={() => setFilter(f.value)}
                className={`px-3 py-1.5 rounded-lg text-sm ${filter === f.value ? 'bg-gold-500 text-anthracite-950 font-semibold' : 'bg-dark-800 text-gray-400 hover:text-white'}`}>
                {f.label}
              </button>
            ))}
          </div>

          {requests.length === 0 ? (
            <EmptyState icon={History}>No hay servicios para mostrar.</EmptyState>
          ) : (
            <div className="space-y-3">
              {requests.map(r => (
                <div key={r.id} className="card p-5 flex flex-wrap items-center justify-between gap-4">
                  <div className="space-y-1">
                    <p className="text-white font-bold">{r.serviceTypeName} <span className="text-gray-500 text-xs">{r.serviceCode}</span></p>
                    <p className="text-gray-400 text-sm">
                      {r.vehicleMake} {r.vehicleModel} · {r.vehicleLicensePlate}
                      {r.mechanicId && <> · Mecánico {fullName(r.mechanicFirstName, r.mechanicLastName)}</>}
                    </p>
                    <p className="text-gray-500 text-xs">
                      {formatDate(r.requestedAt)}{r.finalCost != null && <> · Mano de obra {formatCOP(r.finalCost)}</>}
                      {r.customerRating != null && <> · Tu calificación {r.customerRating}★</>}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className={`px-2 py-0.5 rounded-full text-xs border ${REQUEST_STATUS[r.status].color}`}>{REQUEST_STATUS[r.status].label}</span>
                    {ACTIVE_STATUSES.includes(r.status) && (
                      <Link to={`/service-in-progress?id=${r.id}`} className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-500/15 text-blue-300 text-sm"><Navigation className="w-4 h-4" /> Seguir</Link>
                    )}
                    {r.status === 'completed' && (
                      <>
                        <Link to={`/payment?request=${r.id}`} className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gold-500/15 text-gold-300 text-sm"><CreditCard className="w-4 h-4" /> Pago</Link>
                        <Link to={`/payment/receipt?request=${r.id}`} className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-dark-700 text-gray-300 text-sm"><Receipt className="w-4 h-4" /> Comprobante</Link>
                        <Link to={`/survey?request=${r.id}`} className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-yellow-500/15 text-yellow-300 text-sm"><Star className="w-4 h-4" /> {r.customerRating != null ? 'Ver calificación' : 'Calificar'}</Link>
                      </>
                    )}
                    {r.mechanicId && (
                      <button onClick={() => showEvidences(r.id)} className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-dark-700 text-gray-300 text-sm"><Image className="w-4 h-4" /> Evidencias</button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </motion.div>
      </main>

      <Modal open={evidences !== null} onClose={() => setEvidences(null)} title="Evidencias del servicio" wide>
        {evidences?.length === 0 ? (
          <p className="text-gray-400 text-sm">El mecánico no registró fotos de este servicio.</p>
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
            {evidences?.map(ev => (
              <a key={ev.id} href={ev.imageUrl} target="_blank" rel="noreferrer" className="block rounded-xl overflow-hidden border border-anthracite-700">
                <img src={ev.imageUrl} alt={ev.description ?? ev.evidenceType} className="w-full h-32 object-cover" />
                <p className="text-xs text-gray-400 p-2">{ev.evidenceType === 'before' ? 'Antes' : ev.evidenceType === 'during' ? 'Durante' : 'Después'} · {formatDate(ev.createdAt)}</p>
              </a>
            ))}
          </div>
        )}
      </Modal>
    </div>
  );
}
