import { useState } from 'react';
import { FileText } from 'lucide-react';
import { useAuth } from '../../../controllers/AuthContext';
import { authService, errorMessage, fieldErrors, uploadService } from '../../../services';
import type { LicenseStatus } from '@/models';
import { Alert, Badge, Field, SectionTitle } from '../ui';

const STATUS: Record<LicenseStatus, { label: string; color: string }> = {
  not_set: { label: 'Sin fecha de vencimiento', color: 'bg-gray-500/20 text-gray-400 border-gray-500/30' },
  valid: { label: 'Vigente', color: 'bg-green-500/20 text-green-400 border-green-500/30' },
  expiring_soon: { label: 'Vence pronto', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' },
  expired: { label: 'Vencida', color: 'bg-red-500/20 text-red-400 border-red-500/30' },
};

/** Licencia de conducción (requerida para mecánicos). */
export default function DriverLicenseForm() {
  const { user, refresh } = useAuth();
  const license = user!.profile.driverLicense;

  const [number, setNumber] = useState(license?.number ?? '');
  const [expiration, setExpiration] = useState(license?.expirationDate ?? '');
  const [file, setFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ type: 'error' | 'success'; text: string } | null>(null);
  const [saving, setSaving] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setMessage(null);

    let documentUrl: string | undefined;
    if (file) {
      const upload = await uploadService.upload(file, 'document');
      if (!upload.success || !upload.data) {
        setSaving(false);
        setMessage({ type: 'error', text: errorMessage(upload, 'No se pudo subir el documento') });
        return;
      }
      documentUrl = upload.data.upload.url;
    }

    const res = await authService.updateProfile({
      driver_license_number: number.trim().toUpperCase(),
      ...(expiration ? { driver_license_expiration_date: expiration } : {}),
      ...(documentUrl ? { driver_license_document_url: documentUrl } : {}),
    });
    setSaving(false);

    if (res.success) {
      setErrors({});
      setFile(null);
      setMessage({ type: 'success', text: 'Licencia actualizada' });
      await refresh();
    } else {
      setErrors(fieldErrors(res));
      setMessage({ type: 'error', text: errorMessage(res, 'No se pudo actualizar la licencia') });
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <SectionTitle color="text-blue-400">Licencia de conducción</SectionTitle>
      {license && (
        <div className="flex items-center gap-2 text-sm text-gray-400">
          Estado: <Badge className={STATUS[license.status].color}>{STATUS[license.status].label}</Badge>
          {license.documentUrl && (
            <a href={license.documentUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-gold-400 hover:underline">
              <FileText className="w-4 h-4" /> Ver documento
            </a>
          )}
        </div>
      )}
      <div className="grid sm:grid-cols-2 gap-4">
        <Field label="Número de licencia" error={errors.driverLicenseNumber}>
          <input value={number} onChange={e => setNumber(e.target.value)} placeholder="LIC-2024-XXXX" className="input-field w-full" required />
        </Field>
        <Field label="Fecha de vencimiento" error={errors.driverLicenseExpirationDate}>
          <input type="date" value={expiration} onChange={e => setExpiration(e.target.value)} className="input-field w-full" />
        </Field>
      </div>
      <Field label="Foto o PDF de la licencia (opcional)">
        <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf"
          onChange={e => setFile(e.target.files?.[0] ?? null)}
          className="block w-full text-sm text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-dark-700 file:text-white" />
      </Field>
      {message && <Alert type={message.type}>{message.text}</Alert>}
      <button type="submit" disabled={saving || !number.trim()} className="w-full btn-outline disabled:opacity-50">
        {saving ? 'Guardando...' : 'Guardar licencia'}
      </button>
    </form>
  );
}
