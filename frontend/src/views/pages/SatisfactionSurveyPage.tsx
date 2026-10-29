import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Star, Send, CheckCircle, ThumbsUp, ThumbsDown, AlertCircle } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../../controllers/AuthContext';
import { errorMessage, requestService, surveyService } from '../../services';
import type { ServiceRequest, Survey } from '@/models';
import { fullName } from '../format';

function StarInput({ value, onChange }: { value: number; onChange: (n: number) => void }) {
  const [hover, setHover] = useState(0);
  return (
    <div className="flex gap-2 justify-center">
      {[1, 2, 3, 4, 5].map(star => (
        <button key={star} type="button" onClick={() => onChange(star)}
          onMouseEnter={() => setHover(star)} onMouseLeave={() => setHover(0)}
          className="group transition-transform hover:scale-110">
          <Star className={`w-9 h-9 transition-colors ${star <= (hover || value) ? 'text-yellow-500 fill-yellow-500' : 'text-gray-600 group-hover:text-gray-500'}`} />
        </button>
      ))}
    </div>
  );
}

function StarsView({ value }: { value: number }) {
  return (
    <span className="inline-flex gap-0.5">
      {[1, 2, 3, 4, 5].map(star => (
        <Star key={star} className={`w-4 h-4 ${star <= value ? 'text-yellow-500 fill-yellow-500' : 'text-gray-600'}`} />
      ))}
    </span>
  );
}

function SummaryRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 py-2 border-b border-anthracite-800 last:border-0">
      <span className="text-gray-400 text-sm">{label}</span>
      <span className="text-white text-sm text-right">{children}</span>
    </div>
  );
}

/**
 * Después de un servicio completado (?request=ID): el cliente califica al
 * mecánico (tabla ratings) y responde la encuesta de satisfacción de la
 * plataforma (tabla surveys). Cada parte se envía una sola vez.
 */
export default function SatisfactionSurveyPage() {
  const { user } = useAuth();
  const [searchParams] = useSearchParams();
  const [request, setRequest] = useState<ServiceRequest | null>(null);
  const [mySurvey, setMySurvey] = useState<Survey | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [sending, setSending] = useState(false);

  const [rating, setRating] = useState({ overall: 0, punctuality: 0, quality: 0, comment: '' });
  const [survey, setSurvey] = useState({ satisfaction: 0, recommend: null as boolean | null, comments: '' });

  useEffect(() => {
    (async () => {
      let id = Number(searchParams.get('request')) || null;
      if (!id) {
        const list = await requestService.list('completed');
        id = list.data?.serviceRequests[0]?.id ?? null;
      }
      if (!id) {
        setLoading(false);
        return;
      }
      const [res, mine] = await Promise.all([requestService.get(id), surveyService.mine()]);
      if (res.success && res.data) setRequest(res.data.serviceRequest);
      else setError(errorMessage(res, 'No se pudo cargar el servicio'));
      setMySurvey(mine.data?.surveys.find(s => s.serviceRequestId === id) ?? null);
      setLoading(false);
    })();
  }, [searchParams]);

  const alreadyRated = request?.customerRating != null;
  const needsRating = !alreadyRated;
  const needsSurvey = mySurvey === null;
  const ratingReady = rating.overall > 0;
  const surveyReady = survey.satisfaction > 0 && survey.recommend !== null;
  const canSend = (needsRating ? ratingReady : true) && (needsSurvey ? surveyReady : true) && (needsRating || needsSurvey);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!request || !canSend) return;
    setSending(true);
    setError('');

    if (needsRating) {
      const res = await requestService.rate(request.id, rating.overall, rating.comment.trim(),
        rating.punctuality || undefined, rating.quality || undefined);
      if (!res.success) {
        setSending(false);
        setError(errorMessage(res, 'No se pudo guardar la calificación'));
        return;
      }
    }

    if (needsSurvey) {
      const res = await surveyService.create({
        service_request_id: request.id,
        overall_satisfaction: survey.satisfaction,
        would_recommend: survey.recommend === true,
        comments: survey.comments.trim() || undefined,
      });
      if (!res.success) {
        setSending(false);
        setError(errorMessage(res, 'No se pudo guardar la encuesta'));
        return;
      }
    }

    // Recarga lo guardado para mostrar el resumen de lo que se envió
    const [res, mine] = await Promise.all([requestService.get(request.id), surveyService.mine()]);
    if (res.success && res.data) setRequest(res.data.serviceRequest);
    setMySurvey(mine.data?.surveys.find(s => s.serviceRequestId === request.id) ?? null);
    setSending(false);
  };

  return (
    <div className="min-h-screen bg-gradient-dark">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} />
      <Sidebar />
      <main className="pt-24 pb-12 px-4 md:ml-64">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="max-w-3xl mx-auto">
          <div className="card p-8 space-y-8">
            <div className="text-center">
              <h1 className="text-3xl font-bold text-white mb-2">Encuesta de satisfacción</h1>
              <p className="text-gray-400">Tu opinión nos ayuda a mejorar</p>
            </div>

            {loading ? (
              <p className="text-gray-400 text-center">Cargando...</p>
            ) : !request ? (
              <div className="text-center space-y-3">
                <p className="text-gray-400">{error || 'Aún no tienes servicios completados para calificar.'}</p>
                <Link to="/services" className="btn-primary inline-block">Ver servicios</Link>
              </div>
            ) : !needsRating && !needsSurvey ? (
              <div className="space-y-6">
                <div className="text-center space-y-2">
                  <CheckCircle className="w-14 h-14 text-green-400 mx-auto" />
                  <p className="text-white text-xl font-bold">¡Gracias por tu opinión!</p>
                  <p className="text-gray-400 text-sm">
                    Servicio <span className="text-white">{request.serviceCode}</span> · {request.serviceTypeName} · Mecánico{' '}
                    <span className="text-white">{fullName(request.mechanicFirstName, request.mechanicLastName)}</span>
                  </p>
                </div>

                <section>
                  <h2 className="text-lg font-bold text-gold-400 mb-2">Tu calificación al mecánico</h2>
                  <SummaryRow label="Atención recibida"><StarsView value={request.customerRating ?? 0} /></SummaryRow>
                  {request.punctualityRating != null && <SummaryRow label="Puntualidad"><StarsView value={request.punctualityRating} /></SummaryRow>}
                  {request.serviceQualityRating != null && <SummaryRow label="Solución del problema"><StarsView value={request.serviceQualityRating} /></SummaryRow>}
                  {request.customerFeedback && <SummaryRow label="Comentario">“{request.customerFeedback}”</SummaryRow>}
                </section>

                {mySurvey && (
                  <section>
                    <h2 className="text-lg font-bold text-gold-400 mb-2">Tu encuesta de satisfacción</h2>
                    <SummaryRow label="Satisfacción general"><StarsView value={mySurvey.overallSatisfaction} /></SummaryRow>
                    <SummaryRow label="¿Recomendarías P.A.R.C.E?">{mySurvey.wouldRecommend ? 'Sí' : 'No'}</SummaryRow>
                    {mySurvey.comments && <SummaryRow label="Comentarios">“{mySurvey.comments}”</SummaryRow>}
                  </section>
                )}

                <div className="flex justify-center gap-3">
                  <Link to="/my-services" className="btn-outline inline-block">Mis servicios</Link>
                  <Link to="/home" className="btn-primary inline-block">Volver al inicio</Link>
                </div>
              </div>
            ) : (
              <form onSubmit={handleSubmit} className="space-y-8">
                <p className="text-sm text-gray-400 text-center">
                  Servicio <span className="text-white">{request.serviceCode}</span> · {request.serviceTypeName} · Mecánico{' '}
                  <span className="text-white">{fullName(request.mechanicFirstName, request.mechanicLastName)}</span>
                </p>

                {needsRating && (
                  <section className="space-y-5">
                    <h2 className="text-lg font-bold text-gold-400">Califica al mecánico</h2>
                    <div className="space-y-2">
                      <p className="text-white font-medium">1. ¿Cómo calificarías la atención recibida?</p>
                      <StarInput value={rating.overall} onChange={n => setRating({ ...rating, overall: n })} />
                    </div>
                    <div className="space-y-2">
                      <p className="text-white font-medium">2. ¿Llegó en el tiempo esperado? <span className="text-gray-500 text-sm">(opcional)</span></p>
                      <StarInput value={rating.punctuality} onChange={n => setRating({ ...rating, punctuality: n })} />
                    </div>
                    <div className="space-y-2">
                      <p className="text-white font-medium">3. ¿Solucionó el problema? <span className="text-gray-500 text-sm">(opcional)</span></p>
                      <StarInput value={rating.quality} onChange={n => setRating({ ...rating, quality: n })} />
                    </div>
                    <textarea value={rating.comment} onChange={e => setRating({ ...rating, comment: e.target.value })}
                      placeholder="Comentario para el mecánico (opcional)" rows={3} className="input-field resize-none text-sm" />
                  </section>
                )}

                {needsSurvey && (
                  <section className="space-y-5">
                    <h2 className="text-lg font-bold text-gold-400">Tu experiencia con P.A.R.C.E</h2>
                    <div className="space-y-2">
                      <p className="text-white font-medium">4. En general, ¿qué tan satisfecho quedaste?</p>
                      <StarInput value={survey.satisfaction} onChange={n => setSurvey({ ...survey, satisfaction: n })} />
                    </div>
                    <div className="space-y-2">
                      <p className="text-white font-medium">5. ¿Recomendarías P.A.R.C.E a un amigo?</p>
                      <div className="flex justify-center gap-3">
                        {[{ v: true, label: 'Sí', icon: ThumbsUp }, { v: false, label: 'No', icon: ThumbsDown }].map(opt => (
                          <button key={opt.label} type="button" onClick={() => setSurvey({ ...survey, recommend: opt.v })}
                            className={`flex items-center gap-2 px-6 py-3 rounded-xl border transition-all ${survey.recommend === opt.v ? 'border-gold-500 bg-gold-500/10 text-gold-400' : 'border-anthracite-700 text-gray-400 hover:border-gold-500/40'}`}>
                            <opt.icon className="w-4 h-4" /> {opt.label}
                          </button>
                        ))}
                      </div>
                    </div>
                    <textarea value={survey.comments} onChange={e => setSurvey({ ...survey, comments: e.target.value })}
                      placeholder="¿Qué podemos mejorar? (opcional)" rows={3} className="input-field resize-none text-sm" />
                  </section>
                )}

                {error && (
                  <div className="flex items-start gap-2 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-sm">
                    <AlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" /> {error}
                  </div>
                )}

                <button type="submit" disabled={!canSend || sending} className="w-full btn-primary flex items-center justify-center gap-2 disabled:opacity-40">
                  <Send className="w-5 h-5" /> {sending ? 'Enviando...' : 'ENVIAR'}
                </button>
              </form>
            )}
          </div>
        </motion.div>
      </main>
    </div>
  );
}
