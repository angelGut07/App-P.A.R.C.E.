import { useCallback, useEffect, useState } from 'react';
import { MessageSquare, Search } from 'lucide-react';
import AdminLayout, { SearchBox } from '../../components/admin/AdminLayout';
import { Alert, Badge, EmptyState, Modal, Pagination } from '../../components/ui';
import { errorMessage, pqrService } from '../../../services';
import type { Pqr, PqrStatus, PqrType } from '@/models';
import { PQR_STATUS, PQR_TYPE, formatDate, fullName } from '../../format';

type Reporter = 'customer' | 'mechanic';

/**
 * PQR radicadas por clientes (/admin/pqr/users) o por mecánicos
 * (/admin/pqr/mechanics). El administrador las pone en revisión, las
 * responde (queda resuelta) o las rechaza.
 */
export default function AdminPQRPage({ view = 'users' }: { view?: 'users' | 'mechanics' }) {
  const reporter: Reporter = view === 'mechanics' ? 'mechanic' : 'customer';
  const [items, setItems] = useState<Pqr[]>([]);
  const [q, setQ] = useState('');
  const [type, setType] = useState<PqrType | ''>('');
  const [status, setStatus] = useState<PqrStatus | ''>('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [selected, setSelected] = useState<Pqr | null>(null);
  const [response, setResponse] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const res = await pqrService.adminList({ reporter, q: q.trim(), type, status, page } as Parameters<typeof pqrService.adminList>[0]);
    if (res.success && res.data) {
      setItems(res.data.pqr);
      setTotalPages(res.data.totalPages);
    } else {
      setError(errorMessage(res, 'No se pudieron cargar las PQR'));
    }
  }, [reporter, q, type, status, page]);

  useEffect(() => {
    const t = setTimeout(load, q ? 400 : 0);
    return () => clearTimeout(t);
  }, [load, q]);

  useEffect(() => { setPage(1); }, [view]);

  const act = async (action: () => ReturnType<typeof pqrService.respond>) => {
    setBusy(true);
    setError('');
    const res = await action();
    setBusy(false);
    if (!res.success) {
      setError(errorMessage(res, 'No se pudo actualizar la PQR'));
      return;
    }
    setSelected(null);
    setResponse('');
    load();
  };

  return (
    <AdminLayout title={view === 'mechanics' ? 'PQR de Mecánicos' : 'PQR de Usuarios'} subtitle="Peticiones, quejas, reclamos y sugerencias">
      <div className="flex flex-wrap gap-3">
        <SearchBox value={q} onChange={v => { setQ(v); setPage(1); }} placeholder="Asunto, descripción o código" />
        <select value={type} onChange={e => { setType(e.target.value as PqrType | ''); setPage(1); }} className="input-field w-auto text-sm">
          <option value="">Todos los tipos</option>
          {(Object.keys(PQR_TYPE) as PqrType[]).map(t => <option key={t} value={t}>{PQR_TYPE[t]}</option>)}
        </select>
        <select value={status} onChange={e => { setStatus(e.target.value as PqrStatus | ''); setPage(1); }} className="input-field w-auto text-sm">
          <option value="">Todos los estados</option>
          {(Object.keys(PQR_STATUS) as PqrStatus[]).map(s => <option key={s} value={s}>{PQR_STATUS[s].label}</option>)}
        </select>
      </div>

      {error && !selected && <Alert type="error">{error}</Alert>}

      {items.length === 0 ? (
        <EmptyState icon={MessageSquare}>No hay PQR con esos filtros.</EmptyState>
      ) : (
        <div className="space-y-3">
          {items.map(p => (
            <div key={p.id} className="card p-4 flex items-start justify-between gap-4">
              <div className="flex-1 space-y-2">
                <div className="flex items-center gap-2 flex-wrap">
                  <h3 className="text-white font-bold">{p.subject}</h3>
                  <Badge className="bg-dark-700 text-gray-300 border-anthracite-600">{PQR_TYPE[p.type]}</Badge>
                  <Badge className={PQR_STATUS[p.status].color}>{PQR_STATUS[p.status].label}</Badge>
                </div>
                <p className="text-gray-400 text-xs">{p.ticketCode} · {fullName(p.reporterFirstName, p.reporterLastName)} · {p.reporterEmail} · {formatDate(p.createdAt)}</p>
                <p className="text-gray-300 text-sm line-clamp-2">{p.description}</p>
              </div>
              <button onClick={() => { setSelected(p); setResponse(''); setError(''); }}
                className="p-2 hover:bg-dark-700 rounded-lg text-gray-400 hover:text-gold-400" title="Ver detalle">
                <Search className="w-4 h-4" />
              </button>
            </div>
          ))}
          <Pagination page={page} totalPages={totalPages} onChange={setPage} />
        </div>
      )}

      <Modal open={!!selected} onClose={() => setSelected(null)} title={`PQR ${selected?.ticketCode ?? ''}`}>
        {selected && (
          <div className="space-y-3 text-sm">
            <div className="flex gap-2">
              <Badge className="bg-dark-700 text-gray-300 border-anthracite-600">{PQR_TYPE[selected.type]}</Badge>
              <Badge className={PQR_STATUS[selected.status].color}>{PQR_STATUS[selected.status].label}</Badge>
            </div>
            <div className="p-3 bg-dark-800 rounded-lg">
              <p className="text-gray-400 text-xs mb-1">De: {fullName(selected.reporterFirstName, selected.reporterLastName)} · {formatDate(selected.createdAt)}</p>
              <p className="text-white font-bold mb-2">{selected.subject}</p>
              <p className="text-gray-300 whitespace-pre-line">{selected.description}</p>
            </div>
            {selected.adminResponse && (
              <div className="p-3 bg-gold-500/10 border border-gold-500/20 rounded-lg">
                <p className="text-gold-400 text-xs mb-1 font-medium">Respuesta del administrador ({formatDate(selected.respondedAt)}):</p>
                <p className="text-gray-300">{selected.adminResponse}</p>
              </div>
            )}
            {(selected.status === 'pending' || selected.status === 'in_review') && (
              <>
                <textarea value={response} onChange={e => setResponse(e.target.value)} placeholder="Escribe tu respuesta..." className="input-field resize-none h-24" />
                {error && <Alert type="error">{error}</Alert>}
                <button onClick={() => act(() => pqrService.respond(selected.id, response.trim()))} disabled={busy || !response.trim()} className="w-full btn-primary disabled:opacity-40">
                  Enviar respuesta y marcar resuelta
                </button>
                <div className="grid grid-cols-2 gap-3">
                  {selected.status === 'pending' && (
                    <button onClick={() => act(() => pqrService.updateStatus(selected.id, 'in_review'))} disabled={busy} className="py-2 rounded-xl bg-blue-500/15 text-blue-300 border border-blue-500/30">Poner en revisión</button>
                  )}
                  <button onClick={() => act(() => pqrService.updateStatus(selected.id, 'rejected'))} disabled={busy} className="py-2 rounded-xl bg-red-500/15 text-red-300 border border-red-500/30">Rechazar</button>
                </div>
              </>
            )}
          </div>
        )}
      </Modal>
    </AdminLayout>
  );
}
