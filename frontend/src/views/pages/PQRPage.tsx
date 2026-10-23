import { useEffect, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { MessageSquare, Send, CheckCircle, AlertCircle, FileText, Clock } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../../controllers/AuthContext';
import { errorMessage, pqrService } from '../../services';
import type { Pqr, PqrStatus, PqrType } from '@/models';
import { formatDate } from '../format';

type PQRType = PqrType;

const typeConfig = {
  peticion: { label: 'Petición', color: 'bg-blue-500/20 text-blue-400', desc: 'Solicitud de un nuevo servicio o mejora' },
  queja: { label: 'Queja', color: 'bg-orange-500/20 text-orange-400', desc: 'Inconformidad con un servicio recibido' },
  reclamo: { label: 'Reclamo', color: 'bg-red-500/20 text-red-400', desc: 'Exigencia de solución por un problema concreto' },
  sugerencia: { label: 'Sugerencia', color: 'bg-green-500/20 text-green-400', desc: 'Idea para mejorar la plataforma' },
};

const statusConfig: Record<PqrStatus, { label: string; color: string; icon: React.ElementType }> = {
  pending: { label: 'Abierta', color: 'text-yellow-400', icon: AlertCircle },
  in_review: { label: 'En revisión', color: 'text-purple-400', icon: Clock },
  resolved: { label: 'Resuelta', color: 'text-green-400', icon: CheckCircle },
  rejected: { label: 'Rechazada', color: 'text-red-400', icon: AlertCircle },
};


export default function PQRPage() {
  const { user } = useAuth();
  const [tab, setTab] = useState<'new' | 'my'>('new');
  const [submitted, setSubmitted] = useState(false);
  const [form, setForm] = useState({ type: '' as PQRType | '', subject: '', description: '' });
  const [myPQRs, setMyPQRs] = useState<Pqr[]>([]);
  const [error, setError] = useState('');
  const [sending, setSending] = useState(false);

  const loadMine = () => pqrService.mine().then(res => res.success && res.data && setMyPQRs(res.data.pqr));
  useEffect(() => { loadMine(); }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.type) return;
    setSending(true);
    setError('');
    const res = await pqrService.create({ type: form.type, subject: form.subject.trim(), description: form.description.trim() });
    setSending(false);
    if (!res.success) {
      setError(errorMessage(res, 'No se pudo enviar la PQR'));
      return;
    }
    setSubmitted(true);
    loadMine();
    setTimeout(() => {
      setSubmitted(false);
      setForm({ type: '', subject: '', description: '' });
      setTab('my');
    }, 2500);
  };
  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar hidden={true} />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="max-w-2xl mx-auto space-y-6">
          <div>
            <h1 className="text-3xl font-bold text-white">PQR</h1>
            <p className="text-gray-400 text-sm mt-1">Peticiones, quejas, reclamos y sugerencias — tu voz importa</p>
          </div>

          {/* Tabs */}
          <div className="flex gap-2 p-1 bg-dark-800 rounded-xl w-fit">
            <button onClick={() => setTab('new')}
              className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${tab === 'new' ? 'bg-gold-500 text-anthracite-950' : 'text-gray-400 hover:text-white'}`}>
              Nueva PQR
            </button>
            <button onClick={() => setTab('my')}
              className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5 ${tab === 'my' ? 'bg-gold-500 text-anthracite-950' : 'text-gray-400 hover:text-white'}`}>
              Mis PQR <span className="text-xs bg-dark-600 rounded-full px-1.5">{myPQRs.length}</span>
            </button>
          </div>

          <AnimatePresence mode="wait">
            {tab === 'new' && (
              <motion.div key="new" initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -10 }}>
                {submitted ? (
                  <motion.div initial={{ scale: 0.9, opacity: 0 }} animate={{ scale: 1, opacity: 1 }}
                    className="card p-12 text-center space-y-4">
                    <CheckCircle className="w-16 h-16 text-green-400 mx-auto" />
                    <h3 className="text-xl font-bold text-white">¡PQR enviada!</h3>
                    <p className="text-gray-400">El equipo de PARCE revisará tu solicitud y te responderá pronto.</p>
                  </motion.div>
                ) : (
                  <form onSubmit={handleSubmit} className="card p-6 space-y-5">
                    {/* Tipo de PQR */}
                    <div className="space-y-2">
                      <label className="block text-sm font-medium text-gray-300">Tipo de solicitud</label>
                      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        {(Object.entries(typeConfig) as [PQRType, typeof typeConfig[PQRType]][]).map(([key, cfg]) => (
                          <button key={key} type="button" onClick={() => setForm(f => ({ ...f, type: key }))}
                            className={`p-3 rounded-xl border-2 text-center transition-all ${
                              form.type === key ? 'border-gold-500 bg-gold-500/10' : 'border-anthracite-700 hover:border-anthracite-500'
                            }`}>
                            <p className={`text-sm font-bold mb-1 ${form.type === key ? 'text-gold-400' : 'text-white'}`}>{cfg.label}</p>
                            <p className="text-gray-500 text-xs leading-tight">{cfg.desc}</p>
                          </button>
                        ))}
                      </div>
                    </div>

                    <div className="space-y-1">
                      <label className="block text-sm font-medium text-gray-300">Asunto</label>
                      <input type="text" value={form.subject} onChange={e => setForm(f => ({ ...f, subject: e.target.value }))}
                        placeholder="Resumen breve de tu solicitud" className="input-field text-sm" required />
                    </div>

                    <div className="space-y-1">
                      <label className="block text-sm font-medium text-gray-300">Descripción detallada</label>
                      <textarea value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
                        placeholder="Describe tu petición, queja o reclamo con el mayor detalle posible..."
                        className="input-field resize-none h-32 text-sm" required />
                    </div>

                    {error && <p className="text-sm text-red-400">{error}</p>}
                    <button type="submit" disabled={sending || !form.type || !form.subject.trim() || !form.description.trim()}
                      className="w-full btn-primary flex items-center justify-center gap-2 disabled:opacity-40">
                      <Send className="w-4 h-4" /> Enviar PQR
                    </button>
                  </form>
                )}
              </motion.div>
            )}

            {tab === 'my' && (
              <motion.div key="my" initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -10 }}
                className="space-y-3">
                {myPQRs.length === 0 ? (
                  <div className="card p-12 text-center text-gray-500">
                    <MessageSquare className="w-12 h-12 mx-auto mb-3 opacity-50" />
                    <p>No has enviado ninguna PQR todavía</p>
                  </div>
                ) : myPQRs.map(p => {
                  const tCfg = typeConfig[p.type];
                  const sCfg = statusConfig[p.status];
                  const StatusIcon = sCfg.icon;
                  return (
                    <div key={p.id} className="card p-4 space-y-3">
                      <div className="flex items-start justify-between">
                        <div>
                          <div className="flex items-center gap-2 mb-1">
                            <span className={`px-2 py-0.5 rounded-full text-xs ${tCfg.color}`}>{tCfg.label}</span>
                            <span className={`flex items-center gap-1 text-xs font-medium ${sCfg.color}`}>
                              <StatusIcon className="w-3 h-3" /> {sCfg.label}
                            </span>
                          </div>
                          <h3 className="text-white font-bold">{p.subject}</h3>
                          <p className="text-gray-500 text-xs">{p.ticketCode} · {formatDate(p.createdAt)}</p>
                        </div>
                        <FileText className="w-5 h-5 text-gray-600" />
                      </div>
                      <p className="text-gray-400 text-sm">{p.description}</p>
                      {p.adminResponse && (
                        <div className="p-3 bg-gold-500/10 border border-gold-500/20 rounded-lg">
                          <p className="text-gold-400 text-xs font-medium mb-1">Respuesta del administrador:</p>
                          <p className="text-gray-300 text-sm">{p.adminResponse}</p>
                        </div>
                      )}
                    </div>
                  );
                })}
              </motion.div>
            )}
          </AnimatePresence>
        </motion.div>
      </main>
    </div>
  );
}
