import { Star } from 'lucide-react';
import { useAuth } from '../../../controllers/AuthContext';
import { SectionTitle } from '../ui';

/**
 * Promedio de las calificaciones que ha recibido el usuario en la vista
 * actual: como cliente (de los mecánicos) o como mecánico (de los clientes).
 * Solo el promedio: el detalle de cada calificación no se muestra al calificado.
 */
export default function RatingSummary() {
  const { user } = useAuth();
  const isMechanic = user?.role === 'mechanic';
  const summary = isMechanic ? user?.profile.ratingSummary?.asMechanic : user?.profile.ratingSummary?.asCustomer;
  const average = summary?.average ?? null;
  const count = summary?.count ?? 0;

  return (
    <div>
      <SectionTitle>Mi calificación</SectionTitle>
      {average === null ? (
        <p className="text-gray-400 text-sm">
          {isMechanic ? 'Aún no has recibido calificaciones de clientes.' : 'Aún no has recibido calificaciones de mecánicos.'}
        </p>
      ) : (
        <div className="flex items-center gap-4">
          <span className="text-4xl font-bold text-white">{average.toFixed(1)}</span>
          <div className="space-y-1">
            <span className="inline-flex gap-0.5">
              {[1, 2, 3, 4, 5].map(star => (
                <Star key={star} className={`w-5 h-5 ${star <= Math.round(average) ? 'text-yellow-500 fill-yellow-500' : 'text-gray-600'}`} />
              ))}
            </span>
            <p className="text-gray-400 text-xs">
              Promedio de {count} {count === 1 ? 'calificación' : 'calificaciones'} {isMechanic ? 'de clientes' : 'de mecánicos'}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}
