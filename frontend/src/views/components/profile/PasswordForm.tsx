import { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { authService, errorMessage } from '../../../services';
import { Alert, Field, SectionTitle } from '../ui';

/** Cambio de contraseña: cierra las demás sesiones abiertas del usuario. */
export default function PasswordForm() {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [show, setShow] = useState(false);
  const [message, setMessage] = useState<{ type: 'error' | 'success'; text: string } | null>(null);
  const [saving, setSaving] = useState(false);

  const nextError = next && next.length < 8 ? 'Mínimo 8 caracteres' : '';

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (nextError) return;
    setSaving(true);
    const res = await authService.changePassword(current, next);
    setSaving(false);
    if (res.success) {
      setCurrent('');
      setNext('');
      setMessage({ type: 'success', text: 'Contraseña actualizada. Se cerraron tus otras sesiones.' });
    } else {
      setMessage({ type: 'error', text: errorMessage(res, 'No se pudo cambiar la contraseña') });
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <SectionTitle color="text-purple-400">Cambiar contraseña</SectionTitle>
      <Field label="Contraseña actual">
        <input type={show ? 'text' : 'password'} value={current} onChange={e => setCurrent(e.target.value)} className="input-field w-full" required />
      </Field>
      <Field label="Nueva contraseña" error={nextError}>
        <div className="relative">
          <input type={show ? 'text' : 'password'} value={next} onChange={e => setNext(e.target.value)} className="input-field w-full pr-10" required />
          <button type="button" onClick={() => setShow(!show)} className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300">
            {show ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
          </button>
        </div>
      </Field>
      {message && <Alert type={message.type}>{message.text}</Alert>}
      <button type="submit" disabled={saving || !current || !next || !!nextError} className="w-full btn-outline disabled:opacity-50">
        {saving ? 'Guardando...' : 'Cambiar contraseña'}
      </button>
    </form>
  );
}
