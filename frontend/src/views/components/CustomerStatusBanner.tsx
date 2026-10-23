import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Car, CreditCard, Clock, AlertTriangle } from 'lucide-react';
import { useAuth } from '../../controllers/AuthContext';
import { paymentService, requestService } from '../../services';
import type { ServiceRequest } from '@/models';
import { ACTIVE_STATUSES, REQUEST_STATUS } from '../format';

/**
 * Avisos para el cliente en su inicio: servicio en curso, servicio
 * completado sin pagar, licencia vencida y estado de su solicitud para ser
 * mecánico.
 */
export default function CustomerStatusBanner() {
  const { user } = useAuth();
  const [active, setActive] = useState<ServiceRequest | null>(null);
  const [unpaid, setUnpaid] = useState<ServiceRequest | null>(null);

  useEffect(() => {
    requestService.list().then(async res => {
      const list = res.data?.serviceRequests ?? [];
      setActive(list.find(r => ACTIVE_STATUSES.includes(r.status)) ?? null);
      const lastCompleted = list.find(r => r.status === 'completed');
      if (lastCompleted) {
        const detail = await paymentService.detail(lastCompleted.id);
        if (detail.data && !detail.data.payment.payment) setUnpaid(lastCompleted);
      }
    });
  }, []);

  const application = user?.profile.mechanicApplication;
  const license = user?.profile.driverLicense;
  const items: React.ReactNode[] = [];

  if (active) {
    items.push(
      <Link key="active" to={`/service-in-progress?id=${active.id}`} className="flex items-center gap-3 p-4 rounded-xl bg-blue-500/10 border border-blue-500/30 hover:bg-blue-500/15">
        <Car className="w-5 h-5 text-blue-400" />
        <span className="text-sm text-blue-200">Tienes un servicio en curso ({REQUEST_STATUS[active.status].label}). <u>Ver seguimiento</u></span>
      </Link>
    );
  }
  if (unpaid) {
    items.push(
      <Link key="unpaid" to={`/payment?request=${unpaid.id}`} className="flex items-center gap-3 p-4 rounded-xl bg-gold-500/10 border border-gold-500/30 hover:bg-gold-500/15">
        <CreditCard className="w-5 h-5 text-gold-400" />
        <span className="text-sm text-gold-200">Tu servicio {unpaid.serviceCode} terminó y está pendiente de pago. <u>Pagar ahora</u></span>
      </Link>
    );
  }
  if (license?.status === 'expired') {
    items.push(
      <Link key="license" to="/profile" className="flex items-center gap-3 p-4 rounded-xl bg-red-500/10 border border-red-500/30">
        <AlertTriangle className="w-5 h-5 text-red-400" />
        <span className="text-sm text-red-200">Tu licencia de conducción está vencida. <u>Actualízala</u></span>
      </Link>
    );
  }
  if (application?.status === 'pending' && !user?.profile.roles.includes('mechanic')) {
    items.push(
      <div key="application" className="flex items-center gap-3 p-4 rounded-xl bg-dark-800/80 border border-anthracite-700">
        <Clock className="w-5 h-5 text-gray-400" />
        <span className="text-sm text-gray-300">Tu solicitud para ser mecánico está en revisión. Mientras tanto puedes usar la app como cliente.</span>
      </div>
    );
  }

  if (items.length === 0) return null;
  return <div className="max-w-3xl mx-auto space-y-2 relative z-10">{items}</div>;
}
