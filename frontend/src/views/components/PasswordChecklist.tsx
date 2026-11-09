import { AlertCircle, Check, X } from 'lucide-react';
import { PASSWORD_RULES } from '@/models/passwordPolicy';

/**
 * Reglas de la contraseña marcadas en vivo mientras se escribe, más el aviso
 * del servidor cuando la rechaza por algo que no se ve aquí (muy común,
 * contiene el nombre o el correo).
 */
export default function PasswordChecklist({ password, serverError }: { password: string; serverError?: string }) {
  return (
    <div className="space-y-1.5" aria-live="polite">
      <ul className="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1">
        {PASSWORD_RULES.map(rule => {
          const ok = rule.test(password);
          return (
            <li key={rule.key} className={`flex items-center gap-1.5 text-xs ${ok ? 'text-green-400' : 'text-gray-500'}`}>
              {ok ? <Check className="w-3.5 h-3.5 flex-shrink-0" /> : <X className="w-3.5 h-3.5 flex-shrink-0" />}
              <span>{rule.label}</span>
            </li>
          );
        })}
      </ul>
      <p className="text-xs text-gray-500">No uses contraseñas comunes ni tu nombre, tu correo o la palabra "parce".</p>
      {serverError && (
        <p className="flex items-center gap-1 text-xs text-red-400">
          <AlertCircle className="w-3 h-3 flex-shrink-0" />{serverError}
        </p>
      )}
    </div>
  );
}
