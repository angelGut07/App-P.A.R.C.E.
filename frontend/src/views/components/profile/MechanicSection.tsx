import { useState } from 'react';
import { Award, CheckCircle2, Clock, Plus, Wrench, XCircle } from 'lucide-react';
import { useAuth } from '../../../controllers/AuthContext';
import { useServiceTypes } from '../../../controllers/useServiceTypes';
import { attachServiceDocuments, errorMessage, fieldErrors, mechanicApplicationService, uploadService } from '../../../services';
import type { DriverLicense, MechanicApplicationServiceItem } from '@/models';
import { Alert, Field, SectionTitle } from '../ui';
import ServiceSelector from '../ServiceSelector';
import { formatDate } from '../../format';

const SERVICE_STATUS: Record<MechanicApplicationServiceItem['status'], { label: string; color: string }> = {
  pending: { label: 'En revisión', color: 'bg-yellow-500/15 text-yellow-300 border-yellow-500/30' },
  approved: { label: 'Aprobado', color: 'bg-green-500/15 text-green-300 border-green-500/30' },
  rejected: { label: 'No aprobado', color: 'bg-red-500/15 text-red-300 border-red-500/30' },
};

/** Lista de servicios pedidos con el estado de cada uno */
function RequestedServices({ services }: { services: MechanicApplicationServiceItem[] }) {
  return (
    <ul className="flex flex-wrap gap-2">
      {services.map(service => (
        <li key={service.serviceTypeCode} className={`px-2.5 py-1 rounded-full border text-xs ${SERVICE_STATUS[service.status].color}`}>
          {service.serviceTypeName} · {SERVICE_STATUS[service.status].label}
        </li>
      ))}
    </ul>
  );
}

/** Lleva a la sección "Licencia de conducción" del perfil (id="licencia" en ProfilePage) */
const goToLicense = () => document.getElementById('licencia')?.scrollIntoView({ behavior: 'smooth', block: 'start' });

/**
 * Qué le falta a la licencia para que un administrador pueda aprobar la
 * solicitud (y para aceptar servicios): número y vencimiento vigente.
 */
function licenseProblem(license: DriverLicense | null): string | null {
  if (!license?.number && !license?.expirationDate) return 'registrar tu licencia de conducción (número y fecha de vencimiento)';
  if (!license.number) return 'registrar el número de tu licencia de conducción';
  if (!license.expirationDate) return 'registrar la fecha de vencimiento de tu licencia de conducción';
  if (license.status === 'expired') return 'renovar tu licencia de conducción: la que registraste está vencida';
  return null;
}

/** Aviso con lo que le falta a la licencia y un botón para ir a completarla */
function LicenseNotice({ missing, pending }: { missing: string; pending: boolean }) {
  return (
    <Alert type="warning">
      {pending ? 'Para que un administrador pueda aprobar tu solicitud' : 'Antes de enviar tu solicitud'} necesitas {missing}.{' '}
      <button type="button" onClick={goToLicense} className="underline font-medium hover:text-yellow-200">
        Completar licencia
      </button>
    </Alert>
  );
}

/**
 * Perfil profesional del mecánico con los servicios que tiene aprobados, el
 * estado de su solicitud y el formulario para pedir el rol o sumar servicios
 * (cada servicio lo aprueba un administrador por separado).
 */
export default function MechanicSection() {
  const { user, refresh } = useAuth();
  const { mechanicProfile, mechanicApplication, roles, driverLicense } = user!.profile;
  const isMechanic = roles.includes('mechanic');
  const missingLicense = licenseProblem(driverLicense);
  const serviceTypes = useServiceTypes();
  const approvedCodes: string[] = mechanicProfile?.services.map(service => service.code) ?? [];
  const pendingApplication = mechanicApplication?.status === 'pending' ? mechanicApplication : null;
  const notApproved = mechanicApplication?.status === 'approved'
    ? mechanicApplication.services.filter(service => service.status === 'rejected')
    : [];
  const canRequestMore = serviceTypes.length === 0 || serviceTypes.some(type => !approvedCodes.includes(type.code));

  const [showForm, setShowForm] = useState(!isMechanic);
  const [form, setForm] = useState({
    certification_title: mechanicProfile?.certificationTitle ?? '',
    specialty: mechanicProfile?.specialty ?? '',
    years_experience: mechanicProfile?.yearsExperience != null ? String(mechanicProfile.yearsExperience) : '',
    justification: '',
  });
  const [services, setServices] = useState<string[]>([]);
  const [serviceFiles, setServiceFiles] = useState<Record<string, File | null>>({});
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [sending, setSending] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (services.length === 0) {
      setErrors({ services: 'Selecciona al menos un servicio que puedas prestar' });
      return;
    }
    setSending(true);
    setError('');
    setErrors({});
    const res = await mechanicApplicationService.create({
      services,
      certification_title: form.certification_title.trim(),
      specialty: form.specialty.trim() || undefined,
      years_experience: form.years_experience || undefined,
      justification: form.justification.trim() || undefined,
    });
    if (!res.success || !res.data) {
      setSending(false);
      setErrors(fieldErrors(res));
      setError(errorMessage(res, 'No se pudo enviar la solicitud'));
      return;
    }
    const applicationId = res.data.application.id;
    if (file) {
      const upload = await uploadService.upload(file, 'document');
      if (upload.success && upload.data) {
        await mechanicApplicationService.attachDocument(applicationId, upload.data.upload.url);
      }
    }
    const failed = await attachServiceDocuments(applicationId, serviceFiles);
    setNotice(failed.length > 0 ? 'La solicitud se envió, pero algún soporte no se pudo subir. El administrador puede pedírtelo después.' : '');
    setSending(false);
    setServices([]);
    setServiceFiles({});
    await refresh();
  };

  const professionalProfile = isMechanic && mechanicProfile && (
    <div className="space-y-3">
      <SectionTitle color="text-green-400">Perfil profesional</SectionTitle>
      <div className="grid sm:grid-cols-3 gap-3">
        {[
          { icon: Wrench, label: 'Especialidad', value: mechanicProfile.specialty ?? '—' },
          { icon: Clock, label: 'Experiencia', value: mechanicProfile.yearsExperience != null ? `${mechanicProfile.yearsExperience} años` : '—' },
          { icon: Award, label: 'Certificación', value: mechanicProfile.certificationTitle ?? '—' },
        ].map(item => (
          <div key={item.label} className="p-3 rounded-xl bg-dark-800/60 border border-anthracite-700">
            <item.icon className="w-4 h-4 text-gold-400 mb-1" />
            <p className="text-xs text-gray-500">{item.label}</p>
            <p className="text-white text-sm font-semibold">{item.value}</p>
          </div>
        ))}
      </div>
      <div className="space-y-2">
        <p className="text-xs text-gray-500">Servicios habilitados (solo ves solicitudes de estos):</p>
        {mechanicProfile.services.length > 0 ? (
          <ul className="flex flex-wrap gap-2">
            {mechanicProfile.services.map(service => (
              <li key={service.code} className="flex items-center gap-1 px-2.5 py-1 rounded-full border border-green-500/30 bg-green-500/10 text-xs text-green-300">
                <CheckCircle2 className="w-3 h-3" /> {service.name}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-yellow-300">Aún no tienes servicios aprobados.</p>
        )}
      </div>
      <p className="text-xs text-gray-500">Mecánico aprobado desde {formatDate(mechanicProfile.approvedAt, false)}.</p>
    </div>
  );

  return (
    <div className="space-y-5">
      {professionalProfile}

      {notApproved.length > 0 && (
        <div className="flex items-start gap-2 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-sm text-red-300">
          <XCircle className="w-4 h-4 flex-shrink-0 mt-0.5" />
          <span>
            En tu última solicitud no se aprobó: {notApproved.map(service => service.serviceTypeName).join(', ')}.
            {mechanicApplication?.reviewNotes && <> Motivo: {mechanicApplication.reviewNotes}.</>} Puedes volver a pedirlo con el soporte que haga falta.
          </span>
        </div>
      )}

      {notice && <Alert type="error">{notice}</Alert>}

      {pendingApplication ? (
        <div className="space-y-3">
          <SectionTitle color="text-green-400">{isMechanic ? 'Solicitud de más servicios' : 'Solicitud para ser mecánico'}</SectionTitle>
          <Alert type="success">Tu solicitud está en revisión desde el {formatDate(pendingApplication.createdAt, false)}. Te avisaremos cuando un administrador la revise.</Alert>
          {missingLicense && <LicenseNotice missing={missingLicense} pending />}
          <RequestedServices services={pendingApplication.services} />
          <button onClick={() => mechanicApplicationService.cancel(pendingApplication.id).then(refresh)}
            className="text-sm text-red-400 hover:text-red-300">Cancelar solicitud</button>
        </div>
      ) : isMechanic && !canRequestMore ? (
        <p className="text-sm text-gray-400">Estás habilitado para todos los servicios de la plataforma.</p>
      ) : isMechanic && !showForm ? (
        <button onClick={() => setShowForm(true)} className="w-full btn-outline flex items-center justify-center gap-2">
          <Plus className="w-4 h-4" /> Solicitar más servicios
        </button>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4">
          <SectionTitle color="text-green-400">{isMechanic ? 'Solicitar más servicios' : '¿Eres mecánico?'}</SectionTitle>
          {!isMechanic && mechanicApplication?.status === 'rejected' && (
            <div className="flex items-start gap-2 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-sm text-red-300">
              <XCircle className="w-4 h-4 flex-shrink-0 mt-0.5" />
              Tu solicitud anterior fue rechazada: {mechanicApplication.reviewNotes}. Puedes enviar una nueva.
            </div>
          )}
          <p className="text-sm text-gray-400">
            {isMechanic
              ? 'Elige los servicios que quieres sumar y adjunta el soporte de cada uno. Un administrador los revisará.'
              : 'Envía tu certificación y elige los servicios que puedes prestar. Un administrador revisará cada uno. Necesitas tu licencia de conducción vigente, con número y fecha de vencimiento.'}
          </p>
          {missingLicense && <LicenseNotice missing={missingLicense} pending={false} />}
          <ServiceSelector
            serviceTypes={serviceTypes}
            selected={services}
            onChange={next => { setServices(next); setErrors(prev => ({ ...prev, services: '' })); }}
            files={serviceFiles}
            onFileChange={(code, picked) => setServiceFiles(prev => ({ ...prev, [code]: picked }))}
            approvedCodes={approvedCodes}
            error={errors.services}
          />
          <div className="grid sm:grid-cols-2 gap-3">
            <Field label="Título o certificación" error={errors.certificationTitle}>
              <input value={form.certification_title} onChange={e => setForm({ ...form, certification_title: e.target.value })}
                placeholder="Técnico en Mecánica Automotriz" className="input-field" required />
            </Field>
            <Field label="Especialidad" error={errors.specialty}>
              <input value={form.specialty} onChange={e => setForm({ ...form, specialty: e.target.value })} placeholder="Mecánica general" className="input-field" />
            </Field>
            <Field label="Años de experiencia" error={errors.yearsExperience}>
              <input type="number" min={0} max={60} value={form.years_experience} onChange={e => setForm({ ...form, years_experience: e.target.value })} className="input-field" />
            </Field>
            <Field label="Diploma o certificado (opcional)">
              <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={e => setFile(e.target.files?.[0] ?? null)}
                className="block w-full text-sm text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-dark-700 file:text-white" />
            </Field>
          </div>
          <Field label="Cuéntanos de tu experiencia (opcional)" error={errors.justification}>
            <textarea value={form.justification} onChange={e => setForm({ ...form, justification: e.target.value })} className="input-field resize-none h-20 text-sm" />
          </Field>
          {error && <Alert type="error">{error}</Alert>}
          <div className="flex gap-3">
            {isMechanic && (
              <button type="button" onClick={() => setShowForm(false)} className="flex-1 btn-outline">Cancelar</button>
            )}
            <button type="submit" disabled={sending || !form.certification_title.trim() || !!missingLicense} className="flex-1 btn-outline disabled:opacity-50">
              {sending ? 'Enviando...' : isMechanic ? 'Enviar solicitud' : 'Solicitar ser mecánico'}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
