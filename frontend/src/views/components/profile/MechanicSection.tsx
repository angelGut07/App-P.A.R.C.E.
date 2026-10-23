import { useState } from 'react';
import { Award, Clock, Wrench, XCircle } from 'lucide-react';
import { useAuth } from '../../../controllers/AuthContext';
import { errorMessage, fieldErrors, mechanicApplicationService, uploadService } from '../../../services';
import { Alert, Field, SectionTitle } from '../ui';
import { formatDate } from '../../format';

/**
 * Perfil profesional del mecánico o, si aún no lo es, el estado de su
 * solicitud y el formulario para pedir el rol.
 */
export default function MechanicSection() {
  const { user, refresh } = useAuth();
  const { mechanicProfile, mechanicApplication, roles } = user!.profile;
  const isMechanic = roles.includes('mechanic');

  const [form, setForm] = useState({ certification_title: '', specialty: '', years_experience: '', justification: '' });
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [sending, setSending] = useState(false);

  if (isMechanic && mechanicProfile) {
    return (
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
        <p className="text-xs text-gray-500">Mecánico aprobado desde {formatDate(mechanicProfile.approvedAt, false)}.</p>
      </div>
    );
  }

  if (mechanicApplication?.status === 'pending') {
    return (
      <div className="space-y-3">
        <SectionTitle color="text-green-400">Solicitud para ser mecánico</SectionTitle>
        <Alert type="success">Tu solicitud está en revisión desde el {formatDate(mechanicApplication.createdAt, false)}. Te avisaremos cuando un administrador la apruebe.</Alert>
        <button onClick={() => mechanicApplicationService.cancel(mechanicApplication.id).then(refresh)}
          className="text-sm text-red-400 hover:text-red-300">Cancelar solicitud</button>
      </div>
    );
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true);
    setError('');
    const res = await mechanicApplicationService.create({
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
    if (file) {
      const upload = await uploadService.upload(file, 'document');
      if (upload.success && upload.data) {
        await mechanicApplicationService.attachDocument(res.data.application.id, upload.data.upload.url);
      }
    }
    setSending(false);
    await refresh();
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <SectionTitle color="text-green-400">¿Eres mecánico?</SectionTitle>
      {mechanicApplication?.status === 'rejected' && (
        <div className="flex items-start gap-2 p-3 rounded-xl bg-red-500/10 border border-red-500/30 text-sm text-red-300">
          <XCircle className="w-4 h-4 flex-shrink-0 mt-0.5" />
          Tu solicitud anterior fue rechazada: {mechanicApplication.reviewNotes}. Puedes enviar una nueva.
        </div>
      )}
      <p className="text-sm text-gray-400">Envía tu certificación y un administrador revisará tu solicitud. Necesitas tener registrada tu licencia de conducción.</p>
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
      <button type="submit" disabled={sending || !form.certification_title.trim()} className="w-full btn-outline disabled:opacity-50">
        {sending ? 'Enviando...' : 'Solicitar ser mecánico'}
      </button>
    </form>
  );
}
