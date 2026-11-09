import { useEffect, useRef, useState } from 'react';
import { AlertCircle } from 'lucide-react';

declare global {
  interface Window {
    turnstile?: {
      render: (container: HTMLElement, options: Record<string, unknown>) => string;
      reset: (widgetId: string) => void;
      remove: (widgetId: string) => void;
    };
  }
}

export type CaptchaAction = 'login' | 'register' | 'forgot-password';

const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
let scriptPromise: Promise<void> | null = null;

/** Carga el script de Cloudflare una sola vez para toda la app. */
function loadTurnstile(): Promise<void> {
  if (window.turnstile) return Promise.resolve();
  if (!scriptPromise) {
    scriptPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = SCRIPT_URL;
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => {
        scriptPromise = null; // permite reintentar al volver a montar
        reject(new Error('No se pudo cargar Turnstile'));
      };
      document.head.appendChild(script);
    });
  }
  return scriptPromise;
}

interface CaptchaProps {
  siteKey: string;
  /** Formulario que pide el token: el servidor lo exige para ese mismo formulario */
  action: CaptchaAction;
  /** Token listo, o '' cuando vence o falla */
  onToken: (token: string) => void;
  /** Cambiar este número pide un token nuevo (cada token sirve para un solo envío) */
  resetKey?: number;
  error?: string;
}

/**
 * Verificación "no soy un robot" con Cloudflare Turnstile. Casi siempre se
 * resuelve sola; el formulario envía el token en el campo captcha_token.
 */
export default function Captcha({ siteKey, action, onToken, resetKey = 0, error }: CaptchaProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetId = useRef<string | null>(null);
  const onTokenRef = useRef(onToken);
  const [loadError, setLoadError] = useState(false);

  onTokenRef.current = onToken;

  useEffect(() => {
    let cancelled = false;
    loadTurnstile()
      .then(() => {
        if (cancelled || !containerRef.current || !window.turnstile) return;
        widgetId.current = window.turnstile.render(containerRef.current, {
          sitekey: siteKey,
          action,
          theme: 'dark',
          language: 'es',
          callback: (token: string) => onTokenRef.current(token),
          'expired-callback': () => onTokenRef.current(''),
          'error-callback': () => onTokenRef.current(''),
        });
      })
      .catch(() => { if (!cancelled) setLoadError(true); });

    return () => {
      cancelled = true;
      if (widgetId.current && window.turnstile) window.turnstile.remove(widgetId.current);
      widgetId.current = null;
    };
  }, [siteKey, action]);

  useEffect(() => {
    if (resetKey > 0 && widgetId.current && window.turnstile) {
      onTokenRef.current('');
      window.turnstile.reset(widgetId.current);
    }
  }, [resetKey]);

  return (
    <div className="space-y-1">
      <div ref={containerRef} className="flex justify-center min-h-[65px]" />
      {loadError && (
        <p className="text-xs text-red-400 text-center">No se pudo cargar la verificación de seguridad. Revisa tu conexión y recarga la página.</p>
      )}
      {error && (
        <p className="flex items-center justify-center gap-1 text-xs text-red-400">
          <AlertCircle className="w-3 h-3 flex-shrink-0" />{error}
        </p>
      )}
    </div>
  );
}
