import type { ApiResponse } from '@/models';

// Todas las peticiones van a /api del mismo origen: en desarrollo Vite las
// reenvía al servidor PHP (vite.config.ts) y en producción las sirve el mismo
// contenedor. Así la cookie de sesión siempre es de primera parte.
const BASE_URL = '/api';

let onUnauthorized: (() => void) | null = null;

/** AuthContext registra aquí qué hacer cuando la sesión expira (401). */
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  onUnauthorized = handler;
}

async function request<T>(method: string, endpoint: string, body?: unknown): Promise<ApiResponse<T>> {
  const isForm = body instanceof FormData;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json';

  try {
    const response = await fetch(`${BASE_URL}${endpoint}`, {
      method,
      credentials: 'include',
      headers,
      body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });

    // /auth/me y /auth/login responden 401 de forma esperada; no son "sesión expirada"
    if (response.status === 401 && !endpoint.startsWith('/auth/')) {
      onUnauthorized?.();
    }

    const text = await response.text();
    try {
      return JSON.parse(text) as ApiResponse<T>;
    } catch {
      return { success: false, error: 'El servidor devolvió una respuesta inválida' };
    }
  } catch {
    return { success: false, error: 'No se pudo conectar con el servidor. Revisa tu conexión.' };
  }
}

export const api = {
  get: <T>(endpoint: string) => request<T>('GET', endpoint),
  post: <T>(endpoint: string, body: unknown = {}) => request<T>('POST', endpoint, body),
  put: <T>(endpoint: string, body: unknown = {}) => request<T>('PUT', endpoint, body),
  delete: <T>(endpoint: string) => request<T>('DELETE', endpoint),
};

/** Construye un query string ignorando valores vacíos. */
export function query(params: Record<string, string | number | boolean | null | undefined>): string {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value));
  });
  const qs = search.toString();
  return qs ? `?${qs}` : '';
}

/** Primer mensaje de error de una respuesta (general o del primer campo inválido). */
export function errorMessage(response: ApiResponse<unknown>, fallback = 'Ocurrió un error inesperado'): string {
  if (response.fields) {
    const first = Object.values(response.fields)[0];
    const message = Array.isArray(first) ? first[0] : first;
    if (typeof message === 'string' && message) return message;
  }
  return response.error || response.message || fallback;
}

/** Errores por campo como { campo: mensaje } (para pintarlos en el formulario). */
export function fieldErrors(response: ApiResponse<unknown>): Record<string, string> {
  const result: Record<string, string> = {};
  Object.entries(response.fields ?? {}).forEach(([key, value]) => {
    result[key] = Array.isArray(value) ? value[0] : value;
  });
  return result;
}
