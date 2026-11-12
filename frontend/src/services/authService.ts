import { api } from './apiClient';
import type { CaptchaConfig, UserProfile } from '@/models';

export interface RegisterInput {
  email: string;
  password: string;
  password_confirmation: string;
  first_name: string;
  last_name: string;
  phone?: string;
  id_number?: string;
  driver_license_number?: string;
  /** Vencimiento de la licencia, AAAA-MM-DD (obligatorio y vigente para mecánicos) */
  driver_license_expiration_date?: string;
  role: 'customer' | 'mechanic';
  vehicle_plate?: string;
  vehicle_brand?: string;
  vehicle_model?: string;
  vehicle_year?: string;
  vehicle_color?: string;
  soat_number?: string;
  tecnomecanica_number?: string;
  /** Token de Turnstile, si el CAPTCHA está activo */
  captcha_token?: string;
  /** Aceptó los Términos y la Política de tratamiento de datos (obligatorio, Ley 1581) */
  accept_terms: boolean;
  // Solo para role = mechanic
  /** Códigos de los servicios que puede prestar (al menos uno) */
  services?: string[];
  certification_title?: string;
  certification_document_url?: string;
  specialty?: string;
  years_experience?: string;
}

export interface ProfileInput {
  first_name?: string;
  last_name?: string;
  phone?: string;
  id_number?: string;
  profile_picture_url?: string;
  driver_license_number?: string;
  driver_license_expiration_date?: string;
  driver_license_document_url?: string;
}

export const authService = {
  login: (email: string, password: string, captchaToken?: string) =>
    api.post<{ user: UserProfile }>('/auth/login', { email, password, captcha_token: captchaToken }),

  captchaConfig: () => api.get<CaptchaConfig>('/auth/captcha'),

  register: (data: RegisterInput) => api.post<{ user: UserProfile }>('/auth/register', data),

  logout: () => api.post<null>('/auth/logout'),

  me: () => api.get<UserProfile>('/auth/me'),

  /** Acepta la versión vigente de los Términos y la Política de tratamiento de datos */
  acceptConsent: () => api.post<UserProfile>('/auth/consent'),

  updateProfile: (data: ProfileInput) => api.put<UserProfile>('/auth/profile', data),

  changePassword: (currentPassword: string, newPassword: string) =>
    api.put<null>('/auth/password', {
      current_password: currentPassword,
      new_password: newPassword,
      new_password_confirmation: newPassword,
    }),

  /** Revisa una contraseña con la política del servidor (no la guarda). */
  checkPassword: (password: string, context: { email?: string; first_name?: string; last_name?: string } = {}) =>
    api.post<{ valid: boolean; errors: string[] }>('/auth/password-check', { password, ...context }),

  forgotPassword: (email: string, captchaToken?: string) =>
    api.post<null>('/auth/forgot-password', { email, captcha_token: captchaToken }),

  resetPassword: (token: string, password: string) =>
    api.post<null>('/auth/reset-password', { token, new_password: password, new_password_confirmation: password }),
};
