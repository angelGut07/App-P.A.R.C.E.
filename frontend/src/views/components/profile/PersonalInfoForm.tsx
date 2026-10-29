import { useRef, useState } from 'react';
import { User, Camera } from 'lucide-react';
import { useAuth } from '../../../controllers/AuthContext';
import { authService, errorMessage, fieldErrors, uploadService } from '../../../services';
import { Alert, Field, SectionTitle } from '../ui';

/** Foto y datos personales del usuario autenticado. */
export default function PersonalInfoForm() {
  const { user, refresh } = useAuth();
  const profile = user!.profile;
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [form, setForm] = useState({
    firstName: profile.firstName,
    lastName: profile.lastName,
    phone: profile.phone ?? '',
    idNumber: profile.idNumber ?? '',
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<{ type: 'error' | 'success'; text: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);

  const save = async (extra: Record<string, string> = {}) => {
    setSaving(true);
    setMessage(null);
    const res = await authService.updateProfile({
      first_name: form.firstName.trim(),
      last_name: form.lastName.trim(),
      phone: form.phone.trim(),
      id_number: form.idNumber.trim(),
      ...extra,
    });
    setSaving(false);
    if (res.success) {
      setErrors({});
      setMessage({ type: 'success', text: 'Perfil actualizado correctamente' });
      await refresh();
    } else {
      setErrors(fieldErrors(res));
      setMessage({ type: 'error', text: errorMessage(res, 'No se pudo actualizar el perfil') });
    }
  };

  const handlePhoto = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    const res = await uploadService.upload(file, 'profile');
    setUploading(false);
    if (!res.success || !res.data) {
      setMessage({ type: 'error', text: errorMessage(res, 'No se pudo subir la foto') });
      return;
    }
    await save({ profile_picture_url: res.data.upload.url });
  };

  const input = (key: keyof typeof form, placeholder = '', type = 'text') => (
    <input type={type} value={form[key]} placeholder={placeholder}
      onChange={e => {
        // Teléfono y cédula solo admiten números (el teléfono también "+", espacios y guiones)
        const value = key === 'phone' ? e.target.value.replace(/[^\d+\s-]/g, '')
          : key === 'idNumber' ? e.target.value.replace(/\D/g, '') : e.target.value;
        setForm(prev => ({ ...prev, [key]: value }));
      }}
      className={`input-field w-full ${errors[key] ? 'border-red-500' : ''}`} />
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-col items-center gap-3">
        <div className="relative">
          {profile.profilePictureUrl ? (
            <img src={profile.profilePictureUrl} alt="Foto de perfil" className="w-28 h-28 rounded-full object-cover border-4 border-gold-500/30" />
          ) : (
            <div className="w-28 h-28 bg-gradient-to-br from-gold-500 to-gold-700 rounded-full flex items-center justify-center border-4 border-gold-500/30">
              <User className="w-14 h-14 text-anthracite-950" />
            </div>
          )}
          <button type="button" onClick={() => fileInputRef.current?.click()} disabled={uploading}
            className="absolute bottom-0 right-0 p-2 bg-gold-600 hover:bg-gold-700 rounded-full transition-colors shadow-lg">
            {uploading
              ? <span className="block w-4 h-4 border-2 border-anthracite-950 border-t-transparent rounded-full animate-spin" />
              : <Camera className="w-4 h-4 text-anthracite-950" />}
          </button>
          <input ref={fileInputRef} type="file" accept="image/jpeg,image/png,image/webp" onChange={handlePhoto} className="hidden" />
        </div>
        {profile.profilePictureUrl && (
          <button type="button" onClick={() => save({ profile_picture_url: '' })}
            className="px-4 py-1.5 bg-dark-800 hover:bg-dark-700 text-white text-sm rounded-lg transition-colors">
            Quitar foto
          </button>
        )}
      </div>

      <form onSubmit={e => { e.preventDefault(); save(); }} className="space-y-4">
        <SectionTitle>Datos personales</SectionTitle>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Nombre" error={errors.firstName}>{input('firstName')}</Field>
          <Field label="Apellido" error={errors.lastName}>{input('lastName')}</Field>
        </div>
        <Field label="Correo electrónico">
          <input value={profile.email} disabled className="input-field w-full bg-dark-800/30 cursor-not-allowed text-gray-500" />
        </Field>
        <div className="grid sm:grid-cols-2 gap-4">
          <Field label="Teléfono" error={errors.phone}>{input('phone', '300 000 0000', 'tel')}</Field>
          <Field label="Cédula" error={errors.idNumber}>{input('idNumber', '1000000000')}</Field>
        </div>
        {message && <Alert type={message.type}>{message.text}</Alert>}
        <button type="submit" disabled={saving} className="w-full btn-primary disabled:opacity-50">
          {saving ? 'Guardando...' : 'Guardar datos personales'}
        </button>
      </form>
    </div>
  );
}
