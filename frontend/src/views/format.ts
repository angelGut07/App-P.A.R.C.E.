// Formatos y etiquetas compartidos por las vistas (todo en español, COP).
import type { PaymentMethod, PaymentStatus, PqrStatus, PqrType, RequestStatus } from '@/models';

export const formatCOP = (value: number | string | null | undefined): string =>
  new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 })
    .format(Number(value ?? 0));

/** Fecha sin hora, como las de vencimiento: "2028-06-30" */
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Convierte una fecha del backend en Date en hora local. new Date("2028-06-30")
 * la toma como medianoche UTC, que en Colombia (UTC-5) cae el día anterior:
 * por eso una fecha sin hora se lee como medianoche local.
 */
export function parseDate(value: string): Date {
  return new Date(DATE_ONLY.test(value) ? `${value}T00:00:00` : value.replace(' ', 'T'));
}

/** "2026-10-22 19:41:55" o "2028-06-30" (hora de Colombia, como los guarda el backend) → fecha legible */
export function formatDate(value: string | null | undefined, withTime = true): string {
  if (!value) return '—';
  const date = parseDate(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString('es-CO', withTime
    ? { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }
    : { day: '2-digit', month: 'short', year: 'numeric' });
}

export const fullName = (first?: string | null, last?: string | null): string =>
  [first, last].filter(Boolean).join(' ') || '—';

/**
 * Fecha de hoy "AAAA-MM-DD" en la hora local del navegador. No usar
 * toISOString(): da la fecha en UTC, que en Colombia ya es "mañana" desde las
 * 7 p. m., y el backend rechaza esas fechas por futuras.
 */
export function todayIso(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/**
 * Un documento con vencimiento "AAAA-MM-DD" sigue vigente durante todo ese
 * día, igual que en el backend (vencido solo si la fecha es anterior a hoy).
 */
export const isExpiredDate = (date: string): boolean => date < todayIso();

/** Días que faltan para una fecha "AAAA-MM-DD": 0 = hoy, negativo = ya pasó */
export function daysUntil(date: string): number {
  return Math.round((parseDate(date).getTime() - parseDate(todayIso()).getTime()) / 86_400_000);
}

export const REQUEST_STATUS: Record<RequestStatus, { label: string; color: string }> = {
  pending:     { label: 'Buscando mecánico', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' },
  assigned:    { label: 'Mecánico en camino', color: 'bg-blue-500/20 text-blue-400 border-blue-500/30' },
  in_progress: { label: 'En atención', color: 'bg-purple-500/20 text-purple-400 border-purple-500/30' },
  completed:   { label: 'Completado', color: 'bg-green-500/20 text-green-400 border-green-500/30' },
  cancelled:   { label: 'Cancelado', color: 'bg-red-500/20 text-red-400 border-red-500/30' },
  expired:     { label: 'Expirado', color: 'bg-gray-500/20 text-gray-400 border-gray-500/30' },
};

export const ACTIVE_STATUSES: RequestStatus[] = ['pending', 'assigned', 'in_progress'];

export const PAYMENT_METHOD: Record<PaymentMethod, string> = {
  cash: 'Efectivo',
  card: 'Tarjeta débito/crédito',
  pse: 'PSE',
};

export const PAYMENT_STATUS: Record<PaymentStatus, { label: string; color: string }> = {
  pending:   { label: 'Pendiente', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' },
  confirmed: { label: 'Confirmado', color: 'bg-green-500/20 text-green-400 border-green-500/30' },
  rejected:  { label: 'Rechazado', color: 'bg-red-500/20 text-red-400 border-red-500/30' },
};

export const PQR_TYPE: Record<PqrType, string> = {
  peticion: 'Petición',
  queja: 'Queja',
  reclamo: 'Reclamo',
  sugerencia: 'Sugerencia',
};

export const PQR_STATUS: Record<PqrStatus, { label: string; color: string }> = {
  pending:   { label: 'Pendiente', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' },
  in_review: { label: 'En revisión', color: 'bg-blue-500/20 text-blue-400 border-blue-500/30' },
  resolved:  { label: 'Resuelta', color: 'bg-green-500/20 text-green-400 border-green-500/30' },
  rejected:  { label: 'Rechazada', color: 'bg-red-500/20 text-red-400 border-red-500/30' },
};

/** Enlace de WhatsApp a un celular colombiano */
export const whatsappLink = (phone: string, text = ''): string => {
  const digits = phone.replace(/\D/g, '');
  return `https://wa.me/${digits.startsWith('57') ? digits : `57${digits}`}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
};
