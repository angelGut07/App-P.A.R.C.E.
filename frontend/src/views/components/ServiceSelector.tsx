import { AlertCircle, Check, Paperclip } from 'lucide-react';
import type { ServiceType } from '@/models';

/** Soporte sugerido por servicio: el administrador lo revisa antes de aprobar */
const SUPPORT_HINTS: Partial<Record<string, string>> = {
  tow: 'Adjunta la tarjeta de propiedad y el SOAT del vehículo de remolque',
  lockout: 'Adjunta tu certificado de cerrajería, si lo tienes',
};

interface ServiceSelectorProps {
  serviceTypes: ServiceType[];
  selected: string[];
  onChange: (selected: string[]) => void;
  /** Soporte opcional por servicio seleccionado */
  files: Record<string, File | null>;
  onFileChange: (code: string, file: File | null) => void;
  /** Servicios que ya tiene aprobados (no se pueden volver a pedir) */
  approvedCodes?: string[];
  error?: string;
}

/**
 * Servicios que el mecánico puede prestar, con un soporte opcional para cada
 * uno. Un administrador aprueba o rechaza cada servicio por separado.
 */
export default function ServiceSelector({ serviceTypes, selected, onChange, files, onFileChange, approvedCodes = [], error }: ServiceSelectorProps) {
  const toggle = (code: string) => {
    if (selected.includes(code)) {
      onChange(selected.filter(c => c !== code));
      onFileChange(code, null);
    } else {
      onChange([...selected, code]);
    }
  };

  return (
    <fieldset className="space-y-2">
      <legend className="block text-sm font-medium text-gray-300 mb-1">Servicios que puedes prestar</legend>
      {serviceTypes.length === 0 && <p className="text-xs text-gray-500">Cargando servicios...</p>}
      <div className="grid gap-2">
        {serviceTypes.map(type => {
          const approved = approvedCodes.includes(type.code);
          const checked = selected.includes(type.code);
          return (
            <div key={type.code} className={`rounded-xl border p-3 transition-colors ${checked ? 'border-gold-500/60 bg-gold-500/10' : 'border-anthracite-700 bg-dark-800/60'} ${approved ? 'opacity-60' : ''}`}>
              <label className={`flex items-center gap-3 ${approved ? 'cursor-not-allowed' : 'cursor-pointer'}`}>
                <input type="checkbox" checked={checked || approved} disabled={approved} onChange={() => toggle(type.code)}
                  className="w-4 h-4 accent-yellow-500" />
                <span className="text-sm text-white flex-1">{type.name}</span>
                {approved && <span className="flex items-center gap-1 text-xs text-green-400"><Check className="w-3 h-3" /> Ya habilitado</span>}
              </label>
              {checked && (
                <label className="mt-2 flex flex-col gap-1 text-xs text-gray-400">
                  <span className="flex items-center gap-1"><Paperclip className="w-3 h-3" /> {SUPPORT_HINTS[type.code] ?? 'Soporte de este servicio (opcional)'}</span>
                  <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf"
                    onChange={e => onFileChange(type.code, e.target.files?.[0] ?? null)}
                    className="block w-full text-xs text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-dark-700 file:text-white" />
                  {files[type.code] && <span className="text-gold-400">{files[type.code]!.name}</span>}
                </label>
              )}
            </div>
          );
        })}
      </div>
      <p className="text-xs text-gray-500">Un administrador revisa y aprueba cada servicio por separado. Solo verás solicitudes de los servicios aprobados.</p>
      {error && (
        <p className="flex items-center gap-1 text-xs text-red-400">
          <AlertCircle className="w-3 h-3 flex-shrink-0" />{error}
        </p>
      )}
    </fieldset>
  );
}
