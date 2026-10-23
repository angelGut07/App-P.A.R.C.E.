// Servicios de la API por módulo. Cada función devuelve ApiResponse<T> y
// nunca lanza: los componentes revisan `success` y muestran `errorMessage()`.
import { api, query } from './apiClient';
import type {
  AdminRating, AdminServiceRequest, AdminUser, AdminVehicle, Category, DashboardSummary, Evidence,
  MechanicApplication, MechanicPerformance, MechanicStats, Paginated, PaymentDetail, PaymentListItem,
  PaymentMethod, Pqr, PqrStatus, PqrType, Product, Receipt, ServicePart, ServiceRequest, ServiceType,
  Supplier, Survey, Vehicle, VehicleInput,
} from '@/models';

export { authService } from './authService';
export { api, errorMessage, fieldErrors, setUnauthorizedHandler } from './apiClient';

// ── Catálogo ────────────────────────────────────────────────────────────────

export const catalogService = {
  serviceTypes: () => api.get<{ serviceTypes: ServiceType[] }>('/service-types'),
};

// ── Vehículos ───────────────────────────────────────────────────────────────

export const vehicleService = {
  list: () => api.get<{ vehicles: Vehicle[] }>('/vehicles'),
  create: (data: VehicleInput) => api.post<{ vehicle: Vehicle }>('/vehicles', data),
  update: (id: number, data: Partial<VehicleInput>) => api.put<{ vehicle: Vehicle }>(`/vehicles/${id}`, data),
  remove: (id: number) => api.delete<null>(`/vehicles/${id}`),
  setPrimary: (id: number) => api.put<{ vehicle: Vehicle }>(`/vehicles/${id}/primary`),
};

// ── Solicitudes de servicio (cliente) ───────────────────────────────────────

export interface CreateRequestInput {
  vehicle_id: number;
  emergency_type: string;
  description: string;
  latitude: number;
  longitude: number;
  priority?: 'normal' | 'urgent' | 'critical';
}

export const requestService = {
  list: (status?: string) => api.get<{ serviceRequests: ServiceRequest[] }>(`/service-requests${query({ status })}`),
  get: (id: number) => api.get<{ serviceRequest: ServiceRequest }>(`/service-requests/${id}`),
  create: (data: CreateRequestInput) => api.post<{ serviceRequest: ServiceRequest }>('/service-requests', data),
  cancel: (id: number, reason: string) =>
    api.post<{ serviceRequest: ServiceRequest }>(`/service-requests/${id}/cancel`, { cancellation_reason: reason }),
  rate: (id: number, rating: number, feedback: string, punctuality?: number, quality?: number) =>
    api.post<{ serviceRequest: ServiceRequest }>(`/service-requests/${id}/rate`, {
      customer_rating: rating,
      customer_feedback: feedback || undefined,
      punctuality_rating: punctuality,
      service_quality_rating: quality,
    }),
  evidences: (id: number) => api.get<{ evidences: Evidence[] }>(`/service-requests/${id}/evidences`),
};

// ── Mecánico ────────────────────────────────────────────────────────────────

export const mechanicService = {
  myRequests: (status?: string) => api.get<{ serviceRequests: ServiceRequest[] }>(`/mechanic/requests${query({ status })}`),
  available: (latitude: number, longitude: number, radius = 50) =>
    api.get<{ serviceRequests: ServiceRequest[] }>(`/mechanic/requests/available${query({ latitude, longitude, radius })}`),
  get: (id: number) => api.get<{ serviceRequest: ServiceRequest }>(`/mechanic/requests/${id}`),
  accept: (id: number) => api.post<{ serviceRequest: ServiceRequest }>(`/mechanic/requests/${id}/accept`),
  start: (id: number) => api.put<{ serviceRequest: ServiceRequest }>(`/mechanic/requests/${id}/start`),
  complete: (id: number, finalCost: number) =>
    api.put<{ serviceRequest: ServiceRequest }>(`/mechanic/requests/${id}/complete`, { final_cost: finalCost }),
  rateCustomer: (id: number, score: number, comment: string) =>
    api.post<unknown>(`/mechanic/requests/${id}/rate-customer`, { score, comment: comment || undefined }),
  addEvidence: (id: number, data: { evidence_type: string; image_url: string; original_filename?: string; description?: string; file_size?: number }) =>
    api.post<{ evidence: Evidence }>(`/mechanic/requests/${id}/evidence`, data),
  evidences: (id: number) => api.get<{ evidences: Evidence[] }>(`/mechanic/requests/${id}/evidences`),
  stats: () => api.get<{ stats: MechanicStats }>('/mechanic/stats'),
  parts: (id: number) => api.get<{ parts: ServicePart[] }>(`/mechanic/requests/${id}/parts`),
  addPart: (id: number, productId: number, quantity: number) =>
    api.post<{ parts: ServicePart[] }>(`/mechanic/requests/${id}/parts`, { product_id: productId, quantity }),
  removePart: (id: number, productId: number) =>
    api.delete<{ parts: ServicePart[] }>(`/mechanic/requests/${id}/parts/${productId}`),
};

export const mechanicApplicationService = {
  mine: () => api.get<{ applications: MechanicApplication[] }>('/mechanic-applications/me'),
  create: (data: { certification_title: string; specialty?: string; years_experience?: string; certification_document_url?: string; justification?: string }) =>
    api.post<{ application: MechanicApplication }>('/mechanic-applications', data),
  cancel: (id: number) => api.post<{ application: MechanicApplication }>(`/mechanic-applications/${id}/cancel`),
  attachDocument: (id: number, url: string) =>
    api.post<{ application: MechanicApplication }>(`/mechanic-applications/${id}/document`, { certification_document_url: url }),
};

// ── Pagos ───────────────────────────────────────────────────────────────────

export const paymentService = {
  detail: (requestId: number) => api.get<{ payment: PaymentDetail }>(`/service-requests/${requestId}/payment`),
  pay: (requestId: number, method: PaymentMethod, extra: { card_last4?: string; pse_bank?: string } = {}) =>
    api.post<{ payment: PaymentDetail }>(`/service-requests/${requestId}/payment`, { method, ...extra }),
  confirm: (requestId: number) => api.post<{ payment: PaymentDetail }>(`/service-requests/${requestId}/payment/confirm`),
  reject: (requestId: number) => api.post<{ payment: PaymentDetail }>(`/service-requests/${requestId}/payment/reject`),
  list: (filters: { status?: string; method?: string; page?: number } = {}) =>
    api.get<{ payments: PaymentListItem[] } & Paginated>(`/payments${query(filters)}`),
};

// ── Inventario ──────────────────────────────────────────────────────────────

export const inventoryService = {
  categories: () => api.get<{ categories: Category[] }>('/inventory/categories'),
  createCategory: (name: string) => api.post<{ category: Category }>('/inventory/categories', { name }),
  products: (filters: { search?: string; category_id?: number; availability?: string } = {}) =>
    api.get<{ products: Product[] }>(`/inventory/products${query(filters)}`),
  createProduct: (data: Partial<Product> & Record<string, unknown>) => api.post<{ product: Product }>('/inventory/products', data),
  updateProduct: (id: number, data: Record<string, unknown>) => api.put<{ product: Product }>(`/inventory/products/${id}`, data),
  suppliers: (search?: string) => api.get<{ suppliers: Supplier[] }>(`/inventory/suppliers${query({ search })}`),
  createSupplier: (data: { name: string; tax_id?: string; phone?: string; email?: string }) =>
    api.post<{ supplier: Supplier }>('/inventory/suppliers', data),
  receipts: (page = 1) => api.get<{ receipts: Receipt[] } & Paginated>(`/inventory/receipts${query({ page })}`),
  receipt: (id: number) => api.get<{ receipt: Receipt }>(`/inventory/receipts/${id}`),
  createReceipt: (data: { supplier_id: number; invoice_number: string; received_on: string; items: { product_id: number; quantity: number; unit_cost?: number }[] }) =>
    api.post<{ receipt: Receipt }>('/inventory/receipts', data),
  deleteReceipt: (id: number) => api.delete<null>(`/inventory/receipts/${id}`),
};

// ── PQR y encuestas ─────────────────────────────────────────────────────────

export const pqrService = {
  mine: () => api.get<{ pqr: Pqr[] }>('/pqr'),
  create: (data: { type: PqrType; subject: string; description: string }) => api.post<{ pqr: Pqr }>('/pqr', data),
  adminList: (filters: { reporter?: 'customer' | 'mechanic'; status?: string; type?: string; q?: string } = {}) =>
    api.get<{ pqr: Pqr[] } & Paginated>(`/admin/pqr${query(filters)}`),
  updateStatus: (id: number, status: PqrStatus) => api.put<{ pqr: Pqr }>(`/admin/pqr/${id}/status`, { status }),
  respond: (id: number, response: string) => api.post<{ pqr: Pqr }>(`/admin/pqr/${id}/respond`, { admin_response: response }),
};

export const surveyService = {
  mine: () => api.get<{ surveys: Survey[] }>('/surveys'),
  create: (data: { service_request_id: number; overall_satisfaction: number; would_recommend: boolean; comments?: string }) =>
    api.post<null>('/surveys', data),
  adminList: (page = 1) => api.get<{ surveys: Survey[] } & Paginated>(`/admin/surveys${query({ page })}`),
};

// ── Administración ──────────────────────────────────────────────────────────

export const adminService = {
  dashboard: () => api.get<{ summary: DashboardSummary }>('/admin/dashboard'),
  users: (filters: { role?: string; status?: string; search?: string; page?: number } = {}) =>
    api.get<{ users: AdminUser[] } & Paginated>(`/admin/users${query(filters)}`),
  updateUserStatus: (id: number, status: 'active' | 'suspended' | 'deactivated') =>
    api.put<null>(`/admin/users/${id}/status`, { status }),
  vehicles: (search?: string, page = 1) => api.get<{ vehicles: AdminVehicle[] } & Paginated>(`/admin/vehicles${query({ search, page })}`),
  serviceRequests: (filters: { status?: string; search?: string; page?: number } = {}) =>
    api.get<{ serviceRequests: AdminServiceRequest[] } & Paginated>(`/admin/service-requests${query(filters)}`),
  ratings: (filters: { mechanic_id?: number; min_rating?: number; page?: number } = {}) =>
    api.get<{ ratings: AdminRating[] } & Paginated>(`/admin/ratings${query(filters)}`),
  mechanicPerformance: () => api.get<{ mechanics: MechanicPerformance[] }>('/admin/ratings/mechanics'),
  applications: (status?: string) => api.get<{ applications: MechanicApplication[] } & Paginated>(`/admin/mechanic-applications${query({ status })}`),
  approveApplication: (id: number) => api.post<{ application: MechanicApplication }>(`/admin/mechanic-applications/${id}/approve`),
  rejectApplication: (id: number, reason: string) =>
    api.post<{ application: MechanicApplication }>(`/admin/mechanic-applications/${id}/reject`, { rejection_reason: reason }),
};

// ── Archivos y asistente ────────────────────────────────────────────────────

export const uploadService = {
  upload: (file: File, category: 'profile' | 'evidence' | 'document') => {
    const form = new FormData();
    form.append('file', file);
    form.append('category', category);
    return api.post<{ upload: { url: string; originalFilename: string; size: number } }>('/uploads', form);
  },
};

export const chatbotService = {
  reply: (messages: { role: 'user' | 'assistant'; content: string }[]) =>
    api.post<{ reply: string }>('/chatbot', { messages }),
};
