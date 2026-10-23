import { useCallback, useEffect, useState } from 'react';
import { Wrench, ClipboardCheck, Star, FileText, Check, X } from 'lucide-react';
import AdminLayout, { StatCard } from '../../components/admin/AdminLayout';
import { Alert, Badge, EmptyState, Modal } from '../../components/ui';
import { adminService, errorMessage } from '../../../services';
import type { AdminUser, ApplicationStatus, MechanicApplication, MechanicPerformance } from '@/models';
import { formatDate, fullName } from '../../format';

const APP_STATUS: Record<ApplicationStatus, { label: string; color: string }> = {
  pending: { label: 'Pendiente', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' },
  approved: { label: 'Aprobada', color: 'bg-green-500/20 text-green-400 border-green-500/30' },
  rejected: { label: 'Rechazada', color: 'bg-red-500/20 text-red-400 border-red-500/30' },
  cancelled: { label: 'Cancelada', color: 'bg-gray-500/20 text-gray-400 border-gray-500/30' },
};

/** Solicitudes para ser mecánico y listado de mecánicos con su desempeño. */
export default function AdminMechanicsPage() {
  const [tab, setTab] = useState<'applications' | 'mechanics'>('applications');
  const [statusFilter, setStatusFilter] = useState<ApplicationStatus | ''>('pending');
  const [applications, setApplications] = useState<MechanicApplication[]>([]);
  const [mechanics, setMechanics] = useState<AdminUser[]>([]);
  const [performance, setPerformance] = useState<Record<number, MechanicPerformance>>({});
  const [selected, setSelected] = useState<MechanicApplication | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const loadApplications = useCallback(async () => {
    const res = await adminService.applications(statusFilter || undefined);
    if (res.success && res.data) setApplications(res.data.applications);
    else setError(errorMessage(res, 'No se pudieron cargar las solicitudes'));
  }, [statusFilter]);

  const loadMechanics = useCallback(async () => {
    const [users, perf] = await Promise.all([adminService.users({ role: 'mechanic' }), adminService.mechanicPerformance()]);
    if (users.data) setMechanics(users.data.users);
    if (perf.data) setPerformance(Object.fromEntries(perf.data.mechanics.map(m => [m.mechanicId, m])));
  }, []);

  useEffect(() => { loadApplications(); }, [loadApplications]);
  useEffect(() => { loadMechanics(); }, [loadMechanics]);

  const decide = async (approve: boolean) => {
    if (!selected) return;
    setBusy(true);
    const res = approve
      ? await adminService.approveApplication(selected.id)
      : await adminService.rejectApplication(selected.id, rejectReason.trim());
    setBusy(false);
    if (!res.success) {
      setError(errorMessage(res, 'No se pudo revisar la solicitud'));
      return;
    }
    setSelected(null);
    setRejectReason('');
    loadApplications();
    loadMechanics();
  };

  const pendingCount = applications.filter(a => a.status === 'pending').length;

  return (
    <AdminLayout title="Gestión de Mecánicos" subtitle="Revisa solicitudes y consulta el desempeño de los mecánicos">
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard label="Mecánicos aprobados" value={mechanics.length} icon={Wrench} color="from-purple-500 to-purple-600" />
        <StatCard label="Solicitudes pendientes" value={statusFilter === 'pending' ? pendingCount : '—'} icon={ClipboardCheck} color="from-yellow-500 to-amber-600" />
        <StatCard label="Con calificaciones" value={Object.keys(performance).length} icon={Star} color="from-green-500 to-emerald-600" />
      </div>

      <div className="flex gap-2 p-1 bg-dark-800 rounded-xl w-fit">
        {([['applications', 'Solicitudes'], ['mechanics', 'Mecánicos']] as const).map(([key, label]) => (
          <button key={key} onClick={() => setTab(key)}
            className={`px-4 py-2 rounded-lg text-sm font-medium ${tab === key ? 'bg-gold-500 text-anthracite-950' : 'text-gray-400 hover:text-white'}`}>{label}</button>
        ))}
      </div>

      {error && <Alert type="error">{error}</Alert>}

      {tab === 'applications' && (
        <>
          <select value={statusFilter} onChange={e => setStatusFilter(e.target.value as ApplicationStatus | '')} className="input-field w-auto text-sm">
            <option value="">Todas</option>
            {(Object.keys(APP_STATUS) as ApplicationStatus[]).map(s => <option key={s} value={s}>{APP_STATUS[s].label}</option>)}
          </select>
          {applications.length === 0 ? (
            <EmptyState icon={ClipboardCheck}>No hay solicitudes con ese estado.</EmptyState>
          ) : (
            <div className="space-y-3">
              {applications.map(a => (
                <div key={a.id} className="card p-5 flex flex-wrap items-center justify-between gap-4">
                  <div>
                    <p className="text-white font-bold">{fullName(a.applicantFirstName, a.applicantLastName)}</p>
                    <p className="text-sm text-gray-400">{a.certificationTitle ?? 'Sin certificación'} · {a.specialty ?? 'Sin especialidad'}{a.yearsExperience != null && ` · ${a.yearsExperience} años`}</p>
                    <p className="text-xs text-gray-500">{a.applicantEmail} · enviada {formatDate(a.createdAt)}</p>
                  </div>
                  <div className="flex items-center gap-3">
                    <Badge className={APP_STATUS[a.status].color}>{APP_STATUS[a.status].label}</Badge>
                    <button onClick={() => { setSelected(a); setRejectReason(''); setError(''); }} className="btn-outline py-1.5 px-3 text-sm">
                      {a.status === 'pending' ? 'Revisar' : 'Ver'}
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </>
      )}

      {tab === 'mechanics' && (
        mechanics.length === 0 ? (
          <EmptyState icon={Wrench}>Aún no hay mecánicos aprobados.</EmptyState>
        ) : (
          <div className="card overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-left text-gray-400 border-b border-anthracite-800">
                <tr><th className="p-3">Mecánico</th><th className="p-3">Especialidad</th><th className="p-3">Calificación</th><th className="p-3">Calificaciones</th><th className="p-3">Estado</th></tr>
              </thead>
              <tbody className="divide-y divide-anthracite-800">
                {mechanics.map(m => {
                  const perf = performance[m.id];
                  return (
                    <tr key={m.id} className="text-gray-300">
                      <td className="p-3"><p className="text-white font-medium">{fullName(m.firstName, m.lastName)}</p><p className="text-xs text-gray-500">{m.email} · {m.phone ?? 'sin teléfono'}</p></td>
                      <td className="p-3">{perf?.specialty ?? '—'}</td>
                      <td className="p-3">{perf ? <span className="text-yellow-400">{perf.averageRating} ★</span> : 'Sin calificaciones'}</td>
                      <td className="p-3">{perf?.totalRatings ?? 0}</td>
                      <td className="p-3">{m.accountStatus === 'active' ? 'Activo' : 'Inactivo'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )
      )}

      <Modal open={!!selected} onClose={() => setSelected(null)} title="Solicitud de mecánico" wide>
        {selected && (
          <div className="space-y-4 text-sm">
            <div className="grid sm:grid-cols-2 gap-x-6 gap-y-2">
              {[
                ['Solicitante', fullName(selected.applicantFirstName, selected.applicantLastName)],
                ['Correo', selected.applicantEmail ?? '—'],
                ['Cédula', selected.applicantIdNumber ?? '—'],
                ['Teléfono', selected.applicantPhone ?? '—'],
                ['Certificación', selected.certificationTitle ?? '—'],
                ['Especialidad', selected.specialty ?? '—'],
                ['Experiencia', selected.yearsExperience != null ? `${selected.yearsExperience} años` : '—'],
                ['Licencia', `${selected.driverLicenseNumber ?? '—'}${selected.driverLicenseExpirationDate ? ` (vence ${formatDate(selected.driverLicenseExpirationDate, false)})` : ''}`],
              ].map(([label, value]) => (
                <div key={label}><span className="text-gray-500 text-xs">{label}</span><p className="text-white">{value}</p></div>
              ))}
            </div>
            <div className="flex gap-4">
              {selected.certificationDocumentUrl && (
                <a href={selected.certificationDocumentUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-gold-400 hover:underline"><FileText className="w-4 h-4" /> Ver certificado</a>
              )}
              {selected.driverLicenseDocumentUrl && (
                <a href={selected.driverLicenseDocumentUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-gold-400 hover:underline"><FileText className="w-4 h-4" /> Ver licencia</a>
              )}
            </div>
            {selected.justification && <p className="text-gray-300 bg-dark-800 rounded-xl p-3">{selected.justification}</p>}
            {selected.reviewNotes && <p className="text-gray-400">Notas de revisión: {selected.reviewNotes}</p>}

            {selected.status === 'pending' && (
              <>
                <p className="text-xs text-gray-500">Para aprobar, la licencia de conducción del solicitante debe tener fecha de vencimiento vigente.</p>
                <textarea value={rejectReason} onChange={e => setRejectReason(e.target.value)} placeholder="Motivo (obligatorio para rechazar)" className="input-field resize-none h-20" />
                {error && <Alert type="error">{error}</Alert>}
                <div className="grid grid-cols-2 gap-3">
                  <button disabled={busy || !rejectReason.trim()} onClick={() => decide(false)}
                    className="flex items-center justify-center gap-2 py-2.5 rounded-xl bg-red-600/20 border border-red-500/40 text-red-300 disabled:opacity-40"><X className="w-4 h-4" /> Rechazar</button>
                  <button disabled={busy} onClick={() => decide(true)}
                    className="flex items-center justify-center gap-2 py-2.5 rounded-xl bg-green-600 hover:bg-green-700 text-white disabled:opacity-40"><Check className="w-4 h-4" /> Aprobar</button>
                </div>
              </>
            )}
          </div>
        )}
      </Modal>
    </AdminLayout>
  );
}
