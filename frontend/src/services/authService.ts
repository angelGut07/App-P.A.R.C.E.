import { api } from './apiClient';
import type { UserProfile } from '@/models';

export interface RegisterInput {
  email: string;
  password: string;
  password_confirmation: string;
  first_name: string;
  last_name: string;
  phone?: string;
  id_number?: string;
  driver_license_number?: string;
  role: 'customer' | 'mechanic';
  vehicle_plate?: string;
  vehicle_brand?: string;
  vehicle_model?: string;
  vehicle_year?: string;
  vehicle_color?: string;
  soat_number?: string;
  tecnomecanica_number?: string;
  // Solo para role = mechanic
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
  login: (email: string, password: string) =>
    api.post<{ user: UserProfile }>('/auth/login', { email, password }),

  register: (data: RegisterInput) => api.post<{ user: UserProfile }>('/auth/register', data),

  logout: () => api.post<null>('/auth/logout'),

  me: () => api.get<UserProfile>('/auth/me'),

  updateProfile: (data: ProfileInput) => api.put<UserProfile>('/auth/profile', data),

  changePassword: (currentPassword: string, newPassword: string) =>
    api.put<null>('/auth/password', {
      current_password: currentPassword,
      new_password: newPassword,
      new_password_confirmation: newPassword,
    }),

  forgotPassword: (email: string) => api.post<null>('/auth/forgot-password', { email }),

  resetPassword: (token: string, password: string) =>
    api.post<null>('/auth/reset-password', { token, new_password: password, new_password_confirmation: password }),
};
