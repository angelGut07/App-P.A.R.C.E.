import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ShieldCheck } from 'lucide-react';
import Logo from './Logo';
import { Alert } from './ui';
import { useAuth } from '../../controllers/AuthContext';
import { authService, errorMessage } from '../../services';
import { POLICY_DATE } from '../legal';

/**
 * Pide aceptar la versión vigente de los Términos y la Política de
 * tratamiento de datos a quien ya tenía cuenta (Ley 1581 de 2012: la
 * autorización debe ser previa, expresa e informada). ProtectedRoute la
 * muestra en lugar de la página hasta que acepte; también puede salir.
 */
export default function ConsentGate() {
  const { refresh, logout } = useAuth();
  const navigate = useNavigate();
  const [checked, setChecked] = useState(false);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState('');

  const accept = async () => {
    setSending(true);
    setError('');
    const res = await authService.acceptConsent();
    if (!res.success) {
      setSending(false);
      setError(errorMessage(res, 'No se pudo guardar tu aceptación. Intenta de nuevo.'));
      return;
    }
    await refresh();
  };

  const leave = async () => {
    await logout();
    navigate('/', { replace: true });
  };

  return (
    <div className="min-h-screen bg-dark-950 flex items-center justify-center px-4 py-10">
      <div className="card w-full max-w-lg p-6 sm:p-8 space-y-5">
        <div className="flex justify-center"><Logo size="md" /></div>
        <div className="space-y-2 text-center">
          <ShieldCheck className="w-10 h-10 text-gold-400 mx-auto" />
          <h1 className="text-2xl font-bold text-white">Antes de seguir, revisa y acepta</h1>
          <p className="text-sm text-gray-400">
            Publicamos los Términos y condiciones y la Política de tratamiento de datos personales de P.A.R.C.E
            (vigentes desde el {POLICY_DATE}). Para seguir usando la app necesitamos tu autorización, como exige la Ley 1581 de 2012.
          </p>
        </div>

        <ul className="text-sm text-gray-300 space-y-1.5 list-disc pl-5">
          <li>Usamos tus datos para crear tu cuenta, conectarte con un mecánico y registrar el servicio y su pago.</li>
          <li>El mecánico ve tu nombre, tu teléfono y la ubicación exacta solo cuando acepta tu solicitud.</li>
          <li>No vendemos tus datos. Puedes consultarlos, corregirlos o pedir que los eliminemos.</li>
        </ul>

        <p className="text-sm text-center">
          <Link to="/terminos" target="_blank" className="text-gold-400 hover:underline">Leer los Términos</Link>
          <span className="text-gray-600 mx-2">·</span>
          <Link to="/privacidad" target="_blank" className="text-gold-400 hover:underline">Leer la Política de datos</Link>
        </p>

        <label className="flex items-start gap-3 cursor-pointer text-sm text-gray-300">
          <input type="checkbox" checked={checked} onChange={e => setChecked(e.target.checked)} className="mt-0.5 w-4 h-4 accent-yellow-500" />
          <span>Leí y acepto los Términos y condiciones, y autorizo el tratamiento de mis datos personales según la Política de tratamiento de datos.</span>
        </label>

        {error && <Alert type="error">{error}</Alert>}

        <button onClick={accept} disabled={!checked || sending} className="w-full btn-primary disabled:opacity-50">
          {sending ? 'Guardando…' : 'Aceptar y continuar'}
        </button>
        <button onClick={leave} className="w-full text-sm text-gray-400 hover:text-white">Ahora no, cerrar sesión</button>
      </div>
    </div>
  );
}
