// Modelos del frontend: la forma exacta (camelCase) de lo que devuelve la API.
// La API convierte snake_case → camelCase en todas sus respuestas.

// ── Comunes ─────────────────────────────────────────────────────────────────

export interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  message?: string;
  error?: string;
  /** Errores por campo (snake_case convertido a camelCase por la API) */
  fields?: Record<string, string | string[]>;
}

export interface Paginated {
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
}

// ── Usuarios y roles ────────────────────────────────────────────────────────

/** Rol tal como lo guarda el backend */
export type ApiRole = 'customer' | 'mechanic' | 'administrator' | 'super_admin';
/** Vista de la app (cada una tiene su propio menú y páginas) */
export type AppRole = 'user' | 'mechanic' | 'admin';

export type LicenseStatus = 'not_set' | 'valid' | 'expiring_soon' | 'expired';

export interface DriverLicense {
  number: string | null;
  expirationDate: string | null;
  documentUrl: string | null;
  status: LicenseStatus;
  uploadedAt: string | null;
}

export interface MechanicProfile {
  specialty: string | null;
  yearsExperience: number | null;
  certificationTitle: string | null;
  certificationDocumentUrl: string | null;
  approvedAt: string | null;
}

export type ApplicationStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export interface RatingAverage {
  average: number | null;
  count: number;
}

export interface UserProfile {
  id: number;
  email: string;
  firstName: string;
  lastName: string;
  idNumber: string | null;
  phone: string | null;
  profilePictureUrl: string | null;
  accountStatus: string;
  createdAt: string;
  lastLoginAt: string | null;
  roles: ApiRole[];
  driverLicense: DriverLicense | null;
  mechanicProfile: MechanicProfile | null;
  /** Promedio de las calificaciones recibidas en cada rol (sin el detalle de cada una). */
  ratingSummary?: {
    asCustomer: RatingAverage;
    asMechanic: RatingAverage;
  };
  mechanicApplication: {
    id: number;
    status: ApplicationStatus;
    reviewNotes: string | null;
    createdAt: string;
  } | null;
}

export interface MechanicApplication {
  id: number;
  userId: number;
  specialty: string | null;
  yearsExperience: number | null;
  certificationTitle: string | null;
  certificationDocumentUrl: string | null;
  justification: string | null;
  status: ApplicationStatus;
  reviewedBy: number | null;
  reviewedAt: string | null;
  reviewNotes: string | null;
  createdAt: string;
  applicantFirstName?: string;
  applicantLastName?: string;
  applicantEmail?: string;
  applicantPhone?: string | null;
  applicantIdNumber?: string | null;
  driverLicenseNumber?: string | null;
  driverLicenseExpirationDate?: string | null;
  driverLicenseDocumentUrl?: string | null;
  reviewerFirstName?: string | null;
  reviewerLastName?: string | null;
}

// ── Vehículos ───────────────────────────────────────────────────────────────

export type VehicleType = 'sedan' | 'suv' | 'truck' | 'motorcycle' | 'van' | 'other';
export type FuelType = 'gasoline' | 'diesel' | 'electric' | 'hybrid' | 'other';

export interface Vehicle {
  id: number;
  userId: number;
  licensePlate: string;
  make: string;
  model: string;
  year: number;
  color: string | null;
  vin: string | null;
  vehicleType: VehicleType;
  fuelType: FuelType;
  nickname: string | null;
  primaryPhotoUrl: string | null;
  isPrimary: boolean;
  status: 'active' | 'inactive';
  soatNumber: string | null;
  soatExpirationDate: string | null;
  soatDocumentUrl: string | null;
  tecnomecanicaNumber: string | null;
  tecnomecanicaExpirationDate: string | null;
  tecnomecanicaDocumentUrl: string | null;
  createdAt: string;
  updatedAt: string;
}

/** Payload de creación/edición (snake_case, como lo valida el backend) */
export interface VehicleInput {
  license_plate: string;
  make: string;
  model: string;
  year: number;
  vehicle_type: VehicleType;
  fuel_type: FuelType;
  color?: string;
  is_primary?: boolean;
  soat_number?: string;
  soat_expiration_date?: string;
  tecnomecanica_number?: string;
  tecnomecanica_expiration_date?: string;
}

// ── Servicios ───────────────────────────────────────────────────────────────

export type EmergencyType = 'fuel' | 'tire' | 'battery' | 'engine' | 'lockout' | 'tow' | 'other';
export type RequestStatus = 'pending' | 'assigned' | 'in_progress' | 'completed' | 'cancelled' | 'expired';
export type Priority = 'normal' | 'urgent' | 'critical';

export interface ServiceType {
  code: EmergencyType;
  name: string;
  description: string | null;
  basePrice: number | string;
  estimatedMinutesMin: number | null;
  estimatedMinutesMax: number | null;
}

export interface ServiceRequest {
  id: number;
  serviceCode: string;
  customerId: number;
  vehicleId: number;
  mechanicId: number | null;
  emergencyType: EmergencyType;
  serviceTypeName?: string;
  description: string;
  priority: Priority;
  latitude?: number | string;
  longitude?: number | string;
  latitudeApproximate?: number | string;
  longitudeApproximate?: number | string;
  status: RequestStatus;
  requestedAt: string;
  assignedAt: string | null;
  startedAt: string | null;
  completedAt: string | null;
  cancelledAt: string | null;
  cancellationReason: string | null;
  estimatedCost: number | string | null;
  finalCost: number | string | null;
  customerFirstName?: string;
  customerLastName?: string;
  customerPhone?: string | null;
  mechanicFirstName?: string | null;
  mechanicLastName?: string | null;
  mechanicPhone?: string | null;
  mechanicPictureUrl?: string | null;
  mechanicJoinedAt?: string | null;
  mechanicSpecialty?: string | null;
  mechanicAverageRating?: number | string | null;
  mechanicCompletedServices?: number;
  vehicleColor?: string | null;
  vehicleMake?: string;
  vehicleModel?: string;
  vehicleYear?: number;
  vehicleLicensePlate?: string;
  customerRating?: number | null;
  punctualityRating?: number | null;
  serviceQualityRating?: number | null;
  customerFeedback?: string | null;
  mechanicRatingScore?: number | null;
  mechanicRatingComment?: string | null;
  distanceKm?: number | string;
}

export interface Evidence {
  id: number;
  uploadedBy: number;
  evidenceType: 'before' | 'during' | 'after';
  imageUrl: string;
  originalFilename: string | null;
  description: string | null;
  createdAt: string;
}

export interface MechanicStats {
  totalCompleted: number;
  totalEarnings: number;
  totalRated: number;
  averageRating: number | null;
  averagePunctuality: number | null;
  averageServiceQuality: number | null;
}

// ── Inventario ──────────────────────────────────────────────────────────────

export type Availability = 'available' | 'low_stock' | 'unavailable';
export type ProductUnit = 'unidad' | 'juego' | 'litro' | 'galón' | 'kit';

export interface Category {
  id: number;
  name: string;
  productCount?: number;
}

export interface Product {
  id: number;
  name: string;
  categoryId: number;
  categoryName: string;
  unit: ProductUnit;
  unitPrice: number;
  stockQuantity: number;
  minStock: number;
  isActive: boolean;
  availability: Availability;
  updatedAt: string;
}

export interface Supplier {
  id: number;
  name: string;
  taxId: string | null;
  phone: string | null;
  email: string | null;
  receiptCount?: number;
  lastReceiptOn?: string | null;
}

export interface ReceiptItem {
  productId: number;
  productName: string;
  categoryName: string;
  unit: ProductUnit;
  quantity: number;
  unitCost: number | null;
}

export interface Receipt {
  id: number;
  invoiceNumber: string;
  receivedOn: string;
  createdAt: string;
  supplierId: number;
  supplierName: string;
  registeredByFirstName: string;
  registeredByLastName: string;
  itemCount?: number;
  totalUnits?: number;
  items?: ReceiptItem[];
}

export interface ServicePart {
  productId: number;
  productName: string;
  unit: ProductUnit;
  quantity: number;
  unitPrice: number;
  subtotal: number;
}

// ── Pagos ───────────────────────────────────────────────────────────────────

export type PaymentMethod = 'cash' | 'card' | 'pse';
export type PaymentStatus = 'pending' | 'confirmed' | 'rejected';

export interface Payment {
  id: number;
  receiptNumber: string;
  method: PaymentMethod;
  status: PaymentStatus;
  amount: number;
  cardLast4: string | null;
  pseBank: string | null;
  reviewedAt: string | null;
  reviewerFirstName?: string | null;
  reviewerLastName?: string | null;
  createdAt: string;
}

/** Desglose de cobro de un servicio y su pago (si existe) */
export interface PaymentDetail {
  serviceRequest: {
    id: number;
    serviceCode: string;
    status: RequestStatus;
    serviceTypeName: string;
    description: string;
    completedAt: string | null;
    customerName: string;
    customerEmail: string;
    customerIdNumber: string | null;
    mechanicName: string | null;
    vehicleLicensePlate: string | null;
  };
  laborAmount: number;
  parts: ServicePart[];
  partsTotal: number;
  total: number;
  payment: Payment | null;
}

export interface PaymentListItem extends Payment {
  serviceRequestId: number;
  serviceCode: string;
  serviceTypeName: string;
  partsTotal: number;
  customerFirstName: string;
  customerLastName: string;
  mechanicFirstName: string | null;
  mechanicLastName: string | null;
}

// ── PQR y encuestas ─────────────────────────────────────────────────────────

export type PqrType = 'peticion' | 'queja' | 'reclamo' | 'sugerencia';
export type PqrStatus = 'pending' | 'in_review' | 'resolved' | 'rejected';

export interface Pqr {
  id: number;
  userId: number;
  ticketCode: string;
  type: PqrType;
  subject: string;
  description: string;
  status: PqrStatus;
  adminResponse: string | null;
  respondedAt: string | null;
  createdAt: string;
  reporterFirstName?: string;
  reporterLastName?: string;
  reporterEmail?: string;
  reporterRole?: 'customer' | 'mechanic';
}

export interface Survey {
  id: number;
  serviceRequestId: number;
  customerId: number;
  overallSatisfaction: number;
  wouldRecommend: boolean | number;
  comments: string | null;
  createdAt: string;
  serviceCode: string;
  emergencyType: EmergencyType;
  customerFirstName?: string;
  customerLastName?: string;
  mechanicFirstName?: string | null;
  mechanicLastName?: string | null;
}

// ── Administración ──────────────────────────────────────────────────────────

export interface DashboardSummary {
  totalUsers: number;
  totalCustomers: number;
  totalMechanics: number;
  pendingMechanicApplications: number;
  totalPqr: number;
  pendingPqr: number;
  totalSurveys: number;
  averageRating: number | null;
  confirmedPaymentsAmount: number;
  pendingPayments: number;
  lowStockProducts: number;
  requestsByStatus: { status: RequestStatus; total: number }[];
}

export interface AdminUser {
  id: number;
  email: string;
  firstName: string;
  lastName: string;
  idNumber: string | null;
  phone: string | null;
  profilePictureUrl: string | null;
  accountStatus: 'active' | 'suspended' | 'deactivated' | 'pending_verification';
  createdAt: string;
  lastLoginAt: string | null;
  roles: ApiRole[];
  vehicleCount: number;
}

export interface AdminVehicle {
  id: number;
  licensePlate: string;
  make: string;
  model: string;
  year: number;
  color: string | null;
  vehicleType: VehicleType;
  fuelType: FuelType;
  status: string;
  isPrimary: boolean;
  createdAt: string;
  ownerId: number;
  ownerFirstName: string;
  ownerLastName: string;
  ownerEmail: string;
  soatNumber: string | null;
  soatExpirationDate: string | null;
  tecnomecanicaNumber: string | null;
  tecnomecanicaExpirationDate: string | null;
}

export interface AdminServiceRequest {
  id: number;
  serviceCode: string;
  emergencyType: EmergencyType;
  serviceTypeName: string;
  priority: Priority;
  status: RequestStatus;
  requestedAt: string;
  completedAt: string | null;
  finalCost: number | null;
  customerId: number;
  customerFirstName: string;
  customerLastName: string;
  mechanicId: number | null;
  mechanicFirstName: string | null;
  mechanicLastName: string | null;
  vehicleLicensePlate: string | null;
  paymentStatus: PaymentStatus | null;
  paymentAmount: number | null;
}

export interface AdminRating {
  id: number;
  serviceCode: string;
  emergencyType: EmergencyType;
  serviceTypeName: string;
  customerRating: number;
  punctualityRating: number | null;
  serviceQualityRating: number | null;
  customerFeedback: string | null;
  ratedAt: string;
  customerFirstName: string;
  customerLastName: string;
  mechanicId: number | null;
  mechanicFirstName: string | null;
  mechanicLastName: string | null;
  mechanicSpecialty: string | null;
}

export interface MechanicPerformance {
  mechanicId: number;
  firstName: string;
  lastName: string;
  specialty: string | null;
  totalRatings: number;
  averageRating: number;
  averageLast30Days: number | null;
  distribution: Record<1 | 2 | 3 | 4 | 5, number>;
}
