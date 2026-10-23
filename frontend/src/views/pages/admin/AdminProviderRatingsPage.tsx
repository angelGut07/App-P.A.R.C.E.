import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { Star, TrendingUp, TrendingDown, Award, AlertTriangle, CheckCircle, Wrench, Minus } from 'lucide-react';
import AdminLayout from '../../components/admin/AdminLayout';
import { Alert, EmptyState, Modal } from '../../components/ui';
import { adminService, errorMessage } from '../../../services';
import type { AdminRating, MechanicPerformance } from '@/models';
import { formatDate, fullName } from '../../format';

function perfBadge(avg: number) {
  if (avg >= 4.5) return { label: 'Excelente', color: 'bg-green-500/20 text-green-400 border-green-500/30', icon: CheckCircle };
  if (avg >= 3.5) return { label: 'Bueno', color: 'bg-blue-500/20 text-blue-400 border-blue-500/30', icon: Award };
  if (avg >= 2.5) return { label: 'Regular', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30', icon: AlertTriangle };
  return { label: 'Bajo', color: 'bg-red-500/20 text-red-400 border-red-500/30', icon: AlertTriangle };
}

/** Tendencia: promedio de los últimos 30 días frente al promedio histórico. */
function trend(m: MechanicPerformance) {
  if (m.averageLast30Days == null) return { icon: Minus, color: 'text-gray-500', label: 'Sin datos recientes' };
  if (m.averageLast30Days > m.averageRating + 0.3) return { icon: TrendingUp, color: 'text-green-400', label: 'Mejorando' };
  if (m.averageLast30Days < m.averageRating - 0.3) return { icon: TrendingDown, color: 'text-red-400', label: 'Bajando' };
  return { icon: Minus, color: 'text-gray-400', label: 'Estable' };
}

/** RF 6.4 — Desempeño de cada mecánico según las calificaciones de los clientes. */
export default function AdminProviderRatingsPage() {
  const [mechanics, setMechanics] = useState<MechanicPerformance[]>([]);
  const [selected, setSelected] = useState<MechanicPerformance | null>(null);
  const [detail, setDetail] = useState<AdminRating[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    adminService.mechanicPerformance().then(res => {
      if (res.success && res.data) setMechanics(res.data.mechanics);
      else setError(errorMessage(res, 'No se pudo cargar el desempeño'));
    });
  }, []);

  const open = async (m: MechanicPerformance) => {
    setSelected(m);
    setDetail([]);
    const res = await adminService.ratings({ mechanic_id: m.mechanicId });
    if (res.data) setDetail(res.data.ratings);
  };

  return (
    <AdminLayout title="Calificaciones por Mecánico" subtitle="Control de desempeño de los proveedores del servicio">
      {error && <Alert type="error">{error}</Alert>}
      {mechanics.length === 0 ? (
        <EmptyState icon={Wrench}>Aún no hay mecánicos con calificaciones.</EmptyState>
      ) : (
        <div className="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
          {mechanics.map((m, i) => {
            const badge = perfBadge(m.averageRating);
            const t = trend(m);
            return (
              <motion.button key={m.mechanicId} initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.05 }}
                onClick={() => open(m)} className="card p-5 text-left space-y-3 hover:ring-2 hover:ring-gold-500/50">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-white font-bold">{fullName(m.firstName, m.lastName)}</p>
                    <p className="text-xs text-gray-400">{m.specialty ?? 'Mecánico'}</p>
                  </div>
                  <span className={`flex items-center gap-1 px-2 py-0.5 rounded-full text-xs border ${badge.color}`}><badge.icon className="w-3 h-3" /> {badge.label}</span>
                </div>
                <div className="flex items-end gap-3">
                  <p className="text-3xl font-bold text-white">{m.averageRating.toFixed(1)}</p>
                  <Star className="w-6 h-6 text-yellow-400 fill-yellow-400 mb-1" />
                  <p className="text-xs text-gray-500 mb-1">{m.totalRatings} calificaciones</p>
                  <span className={`ml-auto flex items-center gap-1 text-xs ${t.color}`}><t.icon className="w-4 h-4" /> {t.label}</span>
                </div>
                <div className="space-y-1">
                  {([5, 4, 3, 2, 1] as const).map(n => (
                    <div key={n} className="flex items-center gap-2 text-xs">
                      <span className="text-gray-500 w-3">{n}</span>
                      <div className="flex-1 h-2 bg-dark-700 rounded-full overflow-hidden">
                        <div className="h-full bg-yellow-500" style={{ width: `${(m.distribution[n] / m.totalRatings) * 100}%` }} />
                      </div>
                      <span className="text-gray-500 w-4 text-right">{m.distribution[n]}</span>
                    </div>
                  ))}
                </div>
              </motion.button>
            );
          })}
        </div>
      )}

      <Modal open={!!selected} onClose={() => setSelected(null)} title={selected ? fullName(selected.firstName, selected.lastName) : ''} wide>
        {detail.length === 0 ? (
          <p className="text-gray-400 text-sm">Cargando calificaciones...</p>
        ) : (
          <div className="space-y-3">
            {detail.map(r => (
              <div key={r.id} className="p-3 rounded-xl bg-dark-800/60 border border-anthracite-700">
                <div className="flex justify-between text-sm">
                  <span className="text-white">{r.serviceTypeName} · {fullName(r.customerFirstName, r.customerLastName)}</span>
                  <span className="text-yellow-400">{r.customerRating} ★</span>
                </div>
                <p className="text-xs text-gray-500">{formatDate(r.ratedAt)}</p>
                {r.customerFeedback && <p className="text-sm text-gray-300 mt-1">“{r.customerFeedback}”</p>}
              </div>
            ))}
          </div>
        )}
      </Modal>
    </AdminLayout>
  );
}
