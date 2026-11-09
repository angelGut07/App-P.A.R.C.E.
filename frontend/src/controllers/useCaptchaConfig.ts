import { useEffect, useState } from 'react';
import type { CaptchaConfig } from '@/models';
import { authService } from '@/services';

const DISABLED: CaptchaConfig = { enabled: false, siteKey: null, loginRequired: false };

/**
 * Configuración del CAPTCHA (GET /api/auth/captcha): si está activo, la clave
 * del widget y si el inicio de sesión ya lo exige. Mientras carga es null; si
 * la consulta falla se trata como desactivado y el servidor decide al enviar.
 */
export function useCaptchaConfig(): CaptchaConfig | null {
  const [config, setConfig] = useState<CaptchaConfig | null>(null);

  useEffect(() => {
    let cancelled = false;
    authService.captchaConfig().then(res => {
      if (!cancelled) setConfig(res.success && res.data ? res.data : DISABLED);
    });
    return () => { cancelled = true; };
  }, []);

  return config;
}
