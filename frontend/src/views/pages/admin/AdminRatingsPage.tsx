import { useCallback, useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { Star, ThumbsUp, MessageSquare } from 'lucide-react';
import AdminLayout, { StatCard } from '../../components/admin/AdminLayout';
import { Alert, EmptyState, Pagination } from '../../components/ui';
import { adminService, errorMessage, surveyService } from '../../../services';
import type { AdminRating, Survey } from '@/models';
import { formatDate, fullName } from '../../format';

const Stars = ({ value }: { value: number }) => (
  <span className="flex items-center gap-0.5">
    {[1, 2, 3, 4, 5].map(n => <Star key={n} className={`w-3.5 h-3.5 ${n <= value ? 'text-yellow-400 fill-yellow-400' : 'text-gray-600'}`} />)}
  </span>
);

/** Calificaciones que los clientes dan a los mecánicos y encuestas de satisfacción. */
export default function AdminRatingsPage() {
  const [tab, setTab] = useState<'ratings' | 'surveys'>('ratings');
  const [ratings, setRatings] = useState<AdminRating[]>([]);
  const [surveys, setSurveys] = useState<Survey[]>([]);
  const [minRating, setMinRating] = useState(0);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [surveyPage, setSurveyPage] = useState(1);
  const [surveyPages, setSurveyPages] = useState(1);
  const [error, setError] = useState('');

  const loadRatings = useCallback(async () => {
    const res = await adminService.ratings({ min_rating: minRating || undefined, page });
    if (res.success && res.data) {
      setRatings(res.data.ratings);
      setTotalPages(res.data.totalPages);
      setTotal(res.data.total);
    } else {
      setError(errorMessage(res, 'No se pudieron cargar las calificaciones'));
    }
  }, [minRating, page]);

  useEffect(() => { loadRatings(); }, [loadRatings]);
  useEffect(() => {
    surveyService.adminList(surveyPage).then(res => {
      if (res.data) {
        setSurveys(res.data.surveys);
        setSurveyPages(res.data.totalPages);
      }
    });
  }, [surveyPage]);

  const average = ratings.length ? ratings.reduce((s, r) => s + r.customerRating, 0) / ratings.length : 0;
  const recommend = surveys.length ? Math.round(100 * surveys.filter(s => Number(s.wouldRecommend) === 1 || s.wouldRecommend === true).length / surveys.length) : 0;

  return (
    <AdminLayout title="Calificaciones" subtitle="Opinión de los clientes sobre los servicios">
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard label="Calificaciones registradas" value={total} icon={Star} color="from-yellow-500 to-amber-600" />
        <StatCard label="Promedio (esta página)" value={ratings.length ? `${average.toFixed(1)} ★` : '—'} icon={MessageSquare} color="from-blue-500 to-blue-600" />
        <StatCard label="Recomiendan P.A.R.C.E (encuestas)" value={surveys.length ? `${recommend}%` : '—'} icon={ThumbsUp} color="from-green-500 to-green-600" />
      </div>

      <div className="flex gap-2 p-1 bg-dark-800 rounded-xl w-fit">
        {([['ratings', 'Calificaciones'], ['surveys', 'Encuestas']] as const).map(([key, label]) => (
          <button key={key} onClick={() => setTab(key)}
            className={`px-4 py-2 rounded-lg text-sm font-medium ${tab === key ? 'bg-gold-500 text-anthracite-950' : 'text-gray-400 hover:text-white'}`}>{label}</button>
        ))}
      </div>

      {error && <Alert type="error">{error}</Alert>}

      {tab === 'ratings' && (
        <>
          <div className="card p-5">
            <h3 className="text-white font-bold mb-4">Distribución (esta página)</h3>
            <div className="space-y-2">
              {[5, 4, 3, 2, 1].map(stars => {
                const count = ratings.filter(r => r.customerRating === stars).length;
                const pct = ratings.length ? (count / ratings.length) * 100 : 0;
                return (
                  <div key={stars} className="flex items-center gap-3">
                    <div className="w-24 flex-shrink-0"><Stars value={stars} /></div>
                    <div className="flex-1 h-3 bg-dark-700 rounded-full overflow-hidden">
                      <motion.div initial={{ width: 0 }} animate={{ width: `${pct}%` }} transition={{ duration: 0.8 }} className="h-full bg-gradient-to-r from-yellow-500 to-amber-400 rounded-full" />
                    </div>
                    <span className="text-gray-400 text-xs w-8">{count}</span>
                  </div>
                );
              })}
            </div>
          </div>

          <select value={minRating} onChange={e => { setMinRating(Number(e.target.value)); setPage(1); }} className="input-field w-auto text-sm">
            <option value={0}>Todas las estrellas</option>
            {[5, 4, 3, 2, 1].map(n => <option key={n} value={n}>{n} o más estrellas</option>)}
          </select>

          {ratings.length === 0 ? (
            <EmptyState icon={Star}>No hay calificaciones con ese filtro.</EmptyState>
          ) : (
            <div className="space-y-3">
              {ratings.map(r => (
                <div key={r.id} className="card p-4 space-y-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <p className="text-white font-semibold">{fullName(r.mechanicFirstName, r.mechanicLastName)} <span className="text-gray-500 text-xs">{r.mechanicSpecialty ?? ''}</span></p>
                      <p className="text-xs text-gray-400">{r.serviceTypeName} · {r.serviceCode} · Cliente {fullName(r.customerFirstName, r.customerLastName)} · {formatDate(r.ratedAt)}</p>
                    </div>
                    <Stars value={r.customerRating} />
                  </div>
                  <div className="flex gap-4 text-xs text-gray-500">
                    {r.punctualityRating != null && <span>Puntualidad: {r.punctualityRating}/5</span>}
                    {r.serviceQualityRating != null && <span>Calidad: {r.serviceQualityRating}/5</span>}
                  </div>
                  {r.customerFeedback && <p className="text-sm text-gray-300">“{r.customerFeedback}”</p>}
                </div>
              ))}
              <Pagination page={page} totalPages={totalPages} onChange={setPage} />
            </div>
          )}
        </>
      )}

      {tab === 'surveys' && (
        surveys.length === 0 ? (
          <EmptyState icon={ThumbsUp}>Aún no hay encuestas respondidas.</EmptyState>
        ) : (
          <div className="space-y-3">
            {surveys.map(s => (
              <div key={s.id} className="card p-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-white font-semibold">{fullName(s.customerFirstName, s.customerLastName)} <span className="text-gray-500 text-xs">{s.serviceCode}</span></p>
                  <p className="text-xs text-gray-400">Mecánico {fullName(s.mechanicFirstName, s.mechanicLastName)} · {formatDate(s.createdAt)}</p>
                  {s.comments && <p className="text-sm text-gray-300 mt-1">“{s.comments}”</p>}
                </div>
                <div className="text-right space-y-1">
                  <Stars value={s.overallSatisfaction} />
                  <p className={`text-xs ${Number(s.wouldRecommend) ? 'text-green-400' : 'text-red-400'}`}>{Number(s.wouldRecommend) ? 'Recomienda' : 'No recomienda'}</p>
                </div>
              </div>
            ))}
            <Pagination page={surveyPage} totalPages={surveyPages} onChange={setSurveyPage} />
          </div>
        )
      )}
    </AdminLayout>
  );
}
