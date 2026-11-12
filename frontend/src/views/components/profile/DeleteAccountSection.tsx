import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Trash2 } from 'lucide-react';
import { useAuth } from '../../../controllers/AuthContext';
import { authService, errorMessage, fieldErrors } from '../../../services';
import { Alert, Field, SectionTitle } from '../ui';

/**
 * Eliminar la cuenta (derecho de supresión, Ley 1581 de 2012). Explica qué se
 * borra y qué se conserva sin datos personales, y pide la contraseña y una
 * confirmación expresa: no se puede deshacer.
 */
export default function DeleteAccountSection() {
  const { logout } = useAuth();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [password, setPassword] = useState('');
  const [understood, setUnderstood] = useState(false);
  const [passwordError, setPasswordError] = useState('');
  const [error, setError] = useState('');
  const [sending, setSending] = useState(false);

  const handleDelete = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true);
    setError('');
    setPasswordError('');
    const res = await authService.deleteAccount(password);
    if (!res.success) {
      setSending(false);
      const fieldError = fieldErrors(res).password;
      if (fieldError) setPasswordError(fieldError);
      else setError(errorMessage(res, 'No se pudo eliminar la cuenta'));
      return;
    }
    // La sesión ya no existe en el servidor: se limpia la de la app y se avisa en el inicio
    await logout();
    navigate('/', { replace: true, state: { accountDeleted: true, emailSent: Boolean(res.data?.emailSent) } });
  };

  return (
    <div className="space-y-4">
      <SectionTitle color="text-red-400">Eliminar mi cuenta</SectionTitle>
      <div className="text-sm text-gray-400 space-y-2">
        <p>Si eliminas tu cuenta, borramos tus datos personales: nombre, cédula, teléfono, correo, licencia, vehículos, documentos y solicitudes para ser mecánico. Tu sesión se cerrará en todos tus dispositivos.</p>
        <p>Conservamos, sin datos que te identifiquen, el historial de servicios, pagos y PQR que la ley nos obliga a guardar. <strong className="text-gray-300">No se puede deshacer.</strong></p>
      </div>

      {!open ? (
        <button onClick={() => setOpen(true)} className="flex items-center gap-2 px-4 py-2 rounded-xl border border-red-500/40 text-red-300 hover:bg-red-500/10 text-sm">
          <Trash2 className="w-4 h-4" /> Quiero eliminar mi cuenta
        </button>
      ) : (
        <form onSubmit={handleDelete} className="space-y-4 p-4 rounded-xl border border-red-500/30 bg-red-500/5">
          <Field label="Escribe tu contraseña para confirmar" error={passwordError}>
            <input type="password" value={password} onChange={e => { setPassword(e.target.value); setPasswordError(''); }}
              autoComplete="current-password" className="input-field" required />
          </Field>
          <label className="flex items-start gap-3 cursor-pointer text-sm text-gray-300">
            <input type="checkbox" checked={understood} onChange={e => setUnderstood(e.target.checked)} className="mt-0.5 w-4 h-4 accent-red-500" />
            <span>Entiendo que mi cuenta y mis datos personales se eliminarán y que no se puede deshacer.</span>
          </label>
          {error && <Alert type="error">{error}</Alert>}
          <div className="flex gap-3">
            <button type="button" onClick={() => { setOpen(false); setPassword(''); setUnderstood(false); setError(''); setPasswordError(''); }}
              className="flex-1 btn-outline">Cancelar</button>
            <button type="submit" disabled={sending || !password || !understood}
              className="flex-1 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-medium disabled:opacity-40">
              {sending ? 'Eliminando…' : 'Eliminar mi cuenta'}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
