import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { Mail, Lock, Eye, EyeOff, ArrowLeft, User, Phone, Car, FileText, Upload, ChevronRight, ChevronLeft, Shield, AlertCircle, CheckCircle } from 'lucide-react';
import Logo from '../components/Logo';
import { authService, errorMessage, mechanicApplicationService, uploadService } from '../../services';
import { useAuth } from '../../controllers/AuthContext';

// ── Validadores colombianos ──────────────────────────────────────────────────

/** Cédula colombiana: solo dígitos, entre 6 y 10 caracteres */
function validateCedula(value: string): string {
  if (!value) return 'La cédula es requerida';
  if (!/^\d+$/.test(value)) return 'La cédula solo debe contener números';
  if (value.length < 6) return 'La cédula debe tener mínimo 6 dígitos';
  if (value.length > 10) return 'La cédula no puede tener más de 10 dígitos';
  return '';
}

/** Teléfono colombiano: +57 seguido de 10 dígitos, o 10 dígitos directos (3XX XXX XXXX) */
function validatePhone(value: string): string {
  if (!value) return 'El teléfono es requerido';
  const digits = value.replace(/[\s\-\+]/g, '');
  const col = digits.startsWith('57') ? digits.slice(2) : digits;
  if (!/^\d{10}$/.test(col)) return 'Ingresa un número colombiano válido (10 dígitos, ej: 300 123 4567)';
  if (!col.startsWith('3')) return 'Los celulares colombianos deben empezar por 3';
  return '';
}

/** Placa colombiana: AAA-000 o AAA000 (3 letras + 3 números) */
function validatePlate(value: string): string {
  if (!value) return 'La placa es requerida';
  if (!/^[A-Z]{3}[\-]?\d{3}$/.test(value.toUpperCase())) {
    return 'Formato de placa inválido (ej: ABC-123)';
  }
  return '';
}

/** Código de licencia colombiana */
function validateLicense(value: string): string {
  if (!value) return 'El código de licencia es requerido';
  if (value.trim().length < 6) return 'Código de licencia demasiado corto';
  return '';
}

// ── Helper UI ────────────────────────────────────────────────────────────────
type FieldErr = string;

function FieldFeedback({ error, touched }: { error: FieldErr; touched: boolean }) {
  if (!touched) return null;
  if (error) return (
    <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
      <AlertCircle className="w-3 h-3 flex-shrink-0" />{error}
    </p>
  );
  return (
    <p className="flex items-center gap-1 text-xs text-green-400 mt-1">
      <CheckCircle className="w-3 h-3 flex-shrink-0" />Campo válido
    </p>
  );
}

// ── Tipos ────────────────────────────────────────────────────────────────────
type Step = 'credentials' | 'role' | 'personal' | 'vehicle' | 'mechanic-cert';

export default function RegisterPage() {
  const navigate = useNavigate();
  const { refresh } = useAuth();
  const [step, setStep] = useState<Step>('credentials');
  const [role, setRole] = useState<'user' | 'mechanic' | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [certFile, setCertFile] = useState<File | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const [form, setForm] = useState({
    email: '', password: '', confirmPassword: '',
    firstName: '', lastName: '', phone: '', idNumber: '',
    licenseCode: '', soatCode: '', technoCode: '',
    vehicleBrand: '', vehicleModel: '', vehiclePlate: '',
    vehicleYear: '', vehicleColor: '',
    certTitle: '',
  });

  // Touched map para mostrar errores solo tras interacción
  const [touched, setTouched] = useState<Record<string, boolean>>({});

  const set = (field: string, value: string) =>
    setForm(prev => ({ ...prev, [field]: value }));

  const touch = (field: string) =>
    setTouched(prev => ({ ...prev, [field]: true }));

  const touchAll = (...fields: string[]) =>
    setTouched(prev => fields.reduce((acc, f) => ({ ...acc, [f]: true }), prev));

  // ── Errores calculados ───────────────────────────────────────────────────────
  const errs = {
    email:       !form.email ? 'El correo es requerido' : !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email) ? 'Correo inválido' : '',
    password:    form.password.length < 8 ? 'Mínimo 8 caracteres' : (!/[A-Z]/.test(form.password) && !/[0-9]/.test(form.password)) ? 'Debe tener mayúscula o número' : '',
    confirmPassword: form.confirmPassword !== form.password ? 'Las contraseñas no coinciden' : '',
    firstName:   !form.firstName.trim() ? 'El nombre es requerido' : '',
    lastName:    !form.lastName.trim() ? 'El apellido es requerido' : '',
    phone:       validatePhone(form.phone),
    idNumber:    validateCedula(form.idNumber),
    licenseCode: validateLicense(form.licenseCode),
    vehicleBrand:  !form.vehicleBrand.trim() ? 'La marca es requerida' : '',
    vehicleModel:  !form.vehicleModel.trim() ? 'El modelo es requerido' : '',
    vehiclePlate:  validatePlate(form.vehiclePlate),
    vehicleYear:   !form.vehicleYear ? 'El año es requerido' : '',
    vehicleColor:  !form.vehicleColor.trim() ? 'El color es requerido' : '',
    soatCode:    !form.soatCode.trim() ? 'El código SOAT es requerido' : '',
    technoCode:  !form.technoCode.trim() ? 'El código Tecnomecánica es requerido' : '',
    certTitle:   !form.certTitle.trim() ? 'El título del certificado es requerido' : '',
  };

  // ── Handlers de pasos ───────────────────────────────────────────────────────
  const handleCredentials = (e: React.FormEvent) => {
    e.preventDefault();
    touchAll('email', 'password', 'confirmPassword');
    if (errs.email || errs.password || errs.confirmPassword) return;
    setStep('role');
  };

  const handleRoleSelect = (r: 'user' | 'mechanic') => {
    setRole(r);
    setStep('personal');
  };

  const handlePersonal = (e: React.FormEvent) => {
    e.preventDefault();
    const fields = ['firstName', 'lastName', 'phone', 'idNumber'];
    if (role === 'user') fields.push('licenseCode');
    touchAll(...fields);
    if (fields.some(f => errs[f as keyof typeof errs])) return;
    setStep('vehicle');
  };

  const handleVehicle = async (e: React.FormEvent) => {
    e.preventDefault();
    const fields = ['vehicleBrand', 'vehicleModel', 'vehiclePlate', 'vehicleYear', 'vehicleColor', 'soatCode', 'technoCode'];
    if (role === 'mechanic') fields.push('licenseCode');
    touchAll(...fields);
    if (fields.some(f => errs[f as keyof typeof errs])) return;

    // Si es mecánico, pasa al paso de certificación
    if (role === 'mechanic') {
      setStep('mechanic-cert');
      return;
    }

    await submit();
  };

  const handleCert = async (e: React.FormEvent) => {
    e.preventDefault();
    touchAll('certTitle');
    if (errs.certTitle || !certFile) return;
    await submit();
  };

  /**
   * Crea la cuenta. El registro ya deja la sesión iniciada (cookie). Si pidió
   * ser mecánico, la cuenta queda como cliente con una solicitud pendiente
   * y aquí se sube y adjunta su certificado.
   */
  const submit = async () => {
    setIsSubmitting(true);
    setSubmitError(null);

    const res = await authService.register({
      email: form.email,
      password: form.password,
      password_confirmation: form.confirmPassword,
      first_name: form.firstName,
      last_name: form.lastName,
      phone: form.phone,
      role: role === 'mechanic' ? 'mechanic' : 'customer',
      id_number: form.idNumber,
      driver_license_number: form.licenseCode,
      vehicle_brand: form.vehicleBrand,
      vehicle_model: form.vehicleModel,
      vehicle_plate: form.vehiclePlate.toUpperCase().replace('-', ''),
      vehicle_year: form.vehicleYear,
      vehicle_color: form.vehicleColor,
      soat_number: form.soatCode,
      tecnomecanica_number: form.technoCode,
      ...(role === 'mechanic' ? { certification_title: form.certTitle } : {}),
    });

    if (!res.success || !res.data?.user) {
      setSubmitError(errorMessage(res, 'No se pudo crear la cuenta'));
      setIsSubmitting(false);
      return;
    }

    const application = res.data.user.mechanicApplication;
    if (role === 'mechanic' && certFile && application) {
      const upload = await uploadService.upload(certFile, 'document');
      if (upload.success && upload.data) {
        await mechanicApplicationService.attachDocument(application.id, upload.data.upload.url);
      }
    }

    await refresh();
    navigate('/home');
  };
  // ── Pasos UI ─────────────────────────────────────────────────────────────────
  const stepLabels: Record<Step, string> = {
    credentials: 'Cuenta',
    role: 'Rol',
    personal: 'Datos Personales',
    vehicle: role === 'mechanic' ? 'Vehículo Propio' : 'Vehículo Averiado',
    'mechanic-cert': 'Certificación',
  };

  const stepOrder: Step[] = role === 'mechanic'
    ? ['credentials', 'role', 'personal', 'vehicle', 'mechanic-cert']
    : ['credentials', 'role', 'personal', 'vehicle'];

  const currentStepIndex = stepOrder.indexOf(step);

  // ── Clases de input ──────────────────────────────────────────────────────────
  const inputClass = (field: string) =>
    `input-field text-sm ${touched[field] && errs[field as keyof typeof errs] ? 'border-red-500 focus:border-red-500' : touched[field] ? 'border-green-500/50' : ''}`;

  return (
    <div className="min-h-screen bg-gradient-dark flex items-center justify-center px-4 relative overflow-hidden py-10">
      <div className="absolute inset-0">
        <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-gold-500/10 rounded-full blur-3xl animate-pulse-slow" />
        <div className="absolute bottom-1/4 right-1/4 w-96 h-96 bg-anthracite-500/10 rounded-full blur-3xl animate-pulse-slow" style={{ animationDelay: '1s' }} />
      </div>

      <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.5 }} className="relative w-full max-w-lg">
        {step === 'credentials' ? (
          <Link to="/" className="inline-flex items-center gap-2 text-gray-400 hover:text-white mb-4 transition-colors">
            <ArrowLeft className="w-5 h-5" /> Volver
          </Link>
        ) : (
          <button onClick={() => setStep(stepOrder[currentStepIndex - 1])}
            className="inline-flex items-center gap-2 text-gray-400 hover:text-white mb-4 transition-colors">
            <ChevronLeft className="w-5 h-5" /> Atrás
          </button>
        )}

        <div className="card p-8 space-y-6">
          <div className="flex justify-center"><Logo size="md" /></div>

          {step !== 'role' && (
            <div className="flex items-center justify-center gap-2">
              {stepOrder.filter(s => s !== 'role').map((s, i) => {
                const idx = stepOrder.filter(x => x !== 'role').indexOf(s);
                const activeIdx = stepOrder.filter(x => x !== 'role').indexOf(step);
                return (
                  <div key={s} className="flex items-center gap-2">
                    <div className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-colors ${
                      idx < activeIdx ? 'bg-gold-500 text-anthracite-950' :
                      idx === activeIdx ? 'bg-gold-500/30 border-2 border-gold-500 text-gold-400' :
                      'bg-dark-700 text-gray-500'
                    }`}>{idx + 1}</div>
                    {i < stepOrder.filter(x => x !== 'role').length - 1 && (
                      <div className={`w-8 h-0.5 ${idx < activeIdx ? 'bg-gold-500' : 'bg-dark-700'}`} />
                    )}
                  </div>
                );
              })}
            </div>
          )}

          <div className="text-center">
            <h2 className="text-2xl font-bold text-white mb-1">REGISTRO</h2>
            <p className="text-gray-400 text-sm">{stepLabels[step]}</p>
          </div>

          <AnimatePresence mode="wait">

            {/* PASO 1: Credenciales */}
            {step === 'credentials' && (
              <motion.form key="credentials" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -20 }}
                onSubmit={handleCredentials} className="space-y-4">
                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">Correo electrónico</label>
                  <div className="relative">
                    <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-500" />
                    <input type="email" value={form.email}
                      onChange={e => set('email', e.target.value)}
                      onBlur={() => touch('email')}
                      placeholder="example@gmail.com"
                      className={`${inputClass('email')} pl-10`} required />
                  </div>
                  <FieldFeedback error={errs.email} touched={!!touched.email} />
                </div>

                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">Contraseña</label>
                  <div className="relative">
                    <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-500" />
                    <input type={showPassword ? 'text' : 'password'} value={form.password}
                      onChange={e => set('password', e.target.value)}
                      onBlur={() => touch('password')}
                      placeholder="••••••••••••"
                      className={`${inputClass('password')} pl-10 pr-10`} required />
                    <button type="button" onClick={() => setShowPassword(!showPassword)}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300">
                      {showPassword ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
                    </button>
                  </div>
                  <FieldFeedback error={errs.password} touched={!!touched.password} />
                  {!touched.password && (
                    <p className="text-xs text-gray-500">Mínimo 8 caracteres, con al menos una mayúscula o un número</p>
                  )}
                </div>

                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">Confirmar contraseña</label>
                  <div className="relative">
                    <Lock className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-500" />
                    <input type={showConfirm ? 'text' : 'password'} value={form.confirmPassword}
                      onChange={e => set('confirmPassword', e.target.value)}
                      onBlur={() => touch('confirmPassword')}
                      placeholder="••••••••••••"
                      className={`${inputClass('confirmPassword')} pl-10 pr-10`} required />
                    <button type="button" onClick={() => setShowConfirm(!showConfirm)}
                      className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-300">
                      {showConfirm ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
                    </button>
                  </div>
                  <FieldFeedback error={errs.confirmPassword} touched={!!touched.confirmPassword} />
                </div>

                <button type="submit" className="w-full btn-primary flex items-center justify-center gap-2">
                  Siguiente <ChevronRight className="w-4 h-4" />
                </button>
                <div className="text-center text-sm text-gray-500">
                  ¿Ya tienes cuenta?{' '}
                  <Link to="/login" className="text-gold-400 hover:text-gold-300 transition-colors">Inicia sesión</Link>
                </div>
              </motion.form>
            )}

            {/* PASO 2: Selección de rol */}
            {step === 'role' && (
              <motion.div key="role" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -20 }}
                className="space-y-4">
                <p className="text-gray-400 text-center text-sm">¿Cómo vas a usar P.A.R.C.E?</p>
                <button onClick={() => handleRoleSelect('user')}
                  className="w-full card p-5 hover:ring-2 hover:ring-gold-500 transition-all text-left flex items-center gap-4 group">
                  <div className="w-12 h-12 bg-gradient-to-br from-gold-500 to-gold-600 rounded-full flex items-center justify-center flex-shrink-0 group-hover:shadow-glow-gold">
                    <User className="w-6 h-6 text-anthracite-950" />
                  </div>
                  <div>
                    <h3 className="text-white font-bold text-lg">Conductor / Usuario</h3>
                    <p className="text-gray-400 text-sm">Solicita servicios de asistencia vehicular en emergencias</p>
                  </div>
                </button>
                <button onClick={() => handleRoleSelect('mechanic')}
                  className="w-full card p-5 hover:ring-2 hover:ring-gold-500 transition-all text-left flex items-center gap-4 group">
                  <div className="w-12 h-12 bg-gradient-to-br from-anthracite-500 to-anthracite-600 rounded-full flex items-center justify-center flex-shrink-0">
                    <Shield className="w-6 h-6 text-white" />
                  </div>
                  <div>
                    <h3 className="text-white font-bold text-lg">Mecánico</h3>
                    <p className="text-gray-400 text-sm">Ofrece servicios de reparación y asistencia a conductores</p>
                  </div>
                </button>
              </motion.div>
            )}

            {/* PASO 3: Información personal */}
            {step === 'personal' && (
              <motion.form key="personal" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -20 }}
                onSubmit={handlePersonal} className="space-y-4">

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="block text-sm font-medium text-gray-300">Nombre</label>
                    <div className="relative">
                      <User className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" />
                      <input type="text" value={form.firstName}
                        onChange={e => set('firstName', e.target.value)}
                        onBlur={() => touch('firstName')}
                        placeholder="Juan" className={`${inputClass('firstName')} pl-9`} required />
                    </div>
                    <FieldFeedback error={errs.firstName} touched={!!touched.firstName} />
                  </div>
                  <div className="space-y-1">
                    <label className="block text-sm font-medium text-gray-300">Apellido</label>
                    <input type="text" value={form.lastName}
                      onChange={e => set('lastName', e.target.value)}
                      onBlur={() => touch('lastName')}
                      placeholder="García" className={inputClass('lastName')} required />
                    <FieldFeedback error={errs.lastName} touched={!!touched.lastName} />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">Teléfono celular</label>
                  <div className="relative">
                    <Phone className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" />
                    <input type="tel" value={form.phone}
                      onChange={e => set('phone', e.target.value.replace(/[^\d\s\+\-]/g, ''))}
                      onBlur={() => touch('phone')}
                      placeholder="300 123 4567" className={`${inputClass('phone')} pl-9`}
                      maxLength={15} required />
                  </div>
                  <FieldFeedback error={errs.phone} touched={!!touched.phone} />
                  {!touched.phone && <p className="text-xs text-gray-500">Número colombiano (ej: 300 123 4567)</p>}
                </div>

                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">Número de cédula</label>
                  <div className="relative">
                    <FileText className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" />
                    <input type="text" value={form.idNumber}
                      onChange={e => set('idNumber', e.target.value.replace(/\D/g, '').slice(0, 10))}
                      onBlur={() => touch('idNumber')}
                      placeholder="1234567890" className={`${inputClass('idNumber')} pl-9`}
                      maxLength={10} inputMode="numeric" required />
                  </div>
                  <FieldFeedback error={errs.idNumber} touched={!!touched.idNumber} />
                  {!touched.idNumber && <p className="text-xs text-gray-500">Solo números, entre 6 y 10 dígitos</p>}
                </div>

                {role === 'user' && (
                  <div className="space-y-1">
                    <label className="block text-sm font-medium text-gray-300">Código de licencia de conducción</label>
                    <div className="relative">
                      <FileText className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" />
                      <input type="text" value={form.licenseCode}
                        onChange={e => set('licenseCode', e.target.value.toUpperCase())}
                        onBlur={() => touch('licenseCode')}
                        placeholder="LIC-2024-XXXX" className={`${inputClass('licenseCode')} pl-9`} required />
                    </div>
                    <FieldFeedback error={errs.licenseCode} touched={!!touched.licenseCode} />
                  </div>
                )}

                <button type="submit" className="w-full btn-primary flex items-center justify-center gap-2">
                  Siguiente <ChevronRight className="w-4 h-4" />
                </button>
              </motion.form>
            )}

            {/* PASO 4: Información del vehículo */}
            {step === 'vehicle' && (
              <motion.form key="vehicle" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -20 }}
                onSubmit={handleVehicle} className="space-y-4">
                <p className="text-xs text-gray-500 bg-dark-800 rounded-lg p-3 border border-anthracite-700">
                  {role === 'mechanic' ? '🔧 Información de tu vehículo de trabajo' : '🚗 Información del vehículo que necesita asistencia'}
                </p>

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="block text-sm font-medium text-gray-300">Marca</label>
                    <input type="text" value={form.vehicleBrand}
                      onChange={e => set('vehicleBrand', e.target.value)}
                      onBlur={() => touch('vehicleBrand')}
                      placeholder="Toyota" className={inputClass('vehicleBrand')} required />
                    <FieldFeedback error={errs.vehicleBrand} touched={!!touched.vehicleBrand} />
                  </div>
                  <div className="space-y-1">
                    <label className="block text-sm font-medium text-gray-300">Modelo</label>
                    <input type="text" value={form.vehicleModel}
                      onChange={e => set('vehicleModel', e.target.value)}
                      onBlur={() => touch('vehicleModel')}
                      placeholder="Corolla" className={inputClass('vehicleModel')} required />
                    <FieldFeedback error={errs.vehicleModel} touched={!!touched.vehicleModel} />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="block text-sm font-medium text-gray-300">Placa</label>
                    <div className="relative">
                      <Car className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-500" />
                      <input type="text" value={form.vehiclePlate}
                        onChange={e => set('vehiclePlate', e.target.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '').slice(0, 7))}
                        onBlur={() => touch('vehiclePlate')}
                        placeholder="ABC-123" className={`${inputClass('vehiclePlate')} pl-9 font-mono tracking-wider`}
                        maxLength={7} required />
                    </div>
                    <FieldFeedback error={errs.vehiclePlate} touched={!!touched.vehiclePlate} />
                    {!touched.vehiclePlate && <p className="text-xs text-gray-500">Formato: ABC-123</p>}
                  </div>
                  <div className="space-y-1">
                    <label className="block text-sm font-medium text-gray-300">Año</label>
                    <input type="number" value={form.vehicleYear}
                      onChange={e => set('vehicleYear', e.target.value)}
                      onBlur={() => touch('vehicleYear')}
                      placeholder="2020" className={inputClass('vehicleYear')}
                      required min="1990" max="2026" />
                    <FieldFeedback error={errs.vehicleYear} touched={!!touched.vehicleYear} />
                  </div>
                </div>

                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">Color</label>
                  <input type="text" value={form.vehicleColor}
                    onChange={e => set('vehicleColor', e.target.value)}
                    onBlur={() => touch('vehicleColor')}
                    placeholder="Blanco" className={inputClass('vehicleColor')} required />
                  <FieldFeedback error={errs.vehicleColor} touched={!!touched.vehicleColor} />
                </div>

                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">
                    Código SOAT <span className="text-gray-500 text-xs">(para verificar vigencia)</span>
                  </label>
                  <input type="text" value={form.soatCode}
                    onChange={e => set('soatCode', e.target.value)}
                    onBlur={() => touch('soatCode')}
                    placeholder="SOAT-2024-XXXXXXXX" className={inputClass('soatCode')} required />
                  <FieldFeedback error={errs.soatCode} touched={!!touched.soatCode} />
                </div>

                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">
                    Código Tecnomecánica <span className="text-gray-500 text-xs">(para verificar vigencia)</span>
                  </label>
                  <input type="text" value={form.technoCode}
                    onChange={e => set('technoCode', e.target.value)}
                    onBlur={() => touch('technoCode')}
                    placeholder="TM-2024-XXXXXXXX" className={inputClass('technoCode')} required />
                  <FieldFeedback error={errs.technoCode} touched={!!touched.technoCode} />
                </div>

                {role === 'mechanic' && (
                  <div className="space-y-1">
                    <label className="block text-sm font-medium text-gray-300">
                      Licencia de Conducción <span className="text-gray-500 text-xs">(para verificar vigencia)</span>
                    </label>
                    <input type="text" value={form.licenseCode}
                      onChange={e => set('licenseCode', e.target.value.toUpperCase())}
                      onBlur={() => touch('licenseCode')}
                      placeholder="LC-2024-XXXXXXXX" className={inputClass('licenseCode')} required />
                    <FieldFeedback error={errs.licenseCode} touched={!!touched.licenseCode} />
                  </div>
                )}

                <button type="submit" className="w-full btn-primary flex items-center justify-center gap-2" disabled={isSubmitting}>
                  {isSubmitting ? (
                    <>
                      <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                      Creando cuenta...
                    </>
                  ) : (
                    role === 'mechanic' ? <><span>Siguiente</span><ChevronRight className="w-4 h-4" /></> : 'Crear cuenta'
                  )}
                </button>
                {submitError && (
                  <div className="flex items-center gap-2 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-sm">
                    <AlertCircle className="w-4 h-4 flex-shrink-0" />
                    <span>{submitError}</span>
                  </div>
                )}
              </motion.form>
            )}

            {/* PASO 5: Certificación mecánico */}
            {step === 'mechanic-cert' && (
              <motion.form key="mechanic-cert" initial={{ opacity: 0, x: 20 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -20 }}
                onSubmit={handleCert} className="space-y-4">
                <p className="text-xs text-gray-500 bg-dark-800 rounded-lg p-3 border border-anthracite-700">
                  📄 Sube tu diploma o certificado técnico / tecnológico de mecánica. Un administrador revisará tu solicitud; mientras tanto podrás usar la app como cliente.
                </p>
                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">Título del certificado</label>
                  <input type="text" value={form.certTitle}
                    onChange={e => set('certTitle', e.target.value)}
                    onBlur={() => touch('certTitle')}
                    placeholder="Ej: Técnico en Mecánica Automotriz"
                    className={inputClass('certTitle')} required />
                  <FieldFeedback error={errs.certTitle} touched={!!touched.certTitle} />
                </div>
                <div className="space-y-1">
                  <label className="block text-sm font-medium text-gray-300">Foto del diploma / certificado</label>
                  <label className={`flex flex-col items-center justify-center w-full h-36 border-2 border-dashed rounded-xl cursor-pointer transition-colors ${
                    certFile ? 'border-gold-500 bg-gold-500/10' : 'border-anthracite-600 bg-dark-800 hover:border-gold-500/50 hover:bg-dark-700'
                  }`}>
                    <div className="text-center p-4">
                      {certFile ? (
                        <>
                          <Upload className="w-8 h-8 text-gold-500 mx-auto mb-2" />
                          <p className="text-gold-400 text-sm font-medium">{certFile.name}</p>
                          <p className="text-gray-500 text-xs mt-1">Click para cambiar</p>
                        </>
                      ) : (
                        <>
                          <Upload className="w-8 h-8 text-gray-500 mx-auto mb-2" />
                          <p className="text-gray-400 text-sm">Haz click o arrastra la imagen aquí</p>
                          <p className="text-gray-600 text-xs mt-1">JPG, PNG, WebP o PDF — máx. 5MB</p>
                        </>
                      )}
                    </div>
                    <input type="file" accept="image/jpeg,image/png,image/webp,application/pdf" className="hidden"
                      onChange={e => setCertFile(e.target.files?.[0] ?? null)} required />
                  </label>
                  {touched.certTitle && !certFile && (
                    <p className="flex items-center gap-1 text-xs text-red-400 mt-1">
                      <AlertCircle className="w-3 h-3" />Debes subir el certificado
                    </p>
                  )}
                </div>
                <button type="submit" className="w-full btn-primary" disabled={isSubmitting}>
                  {isSubmitting ? (
                    <div className="flex items-center justify-center gap-2">
                      <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                      Creando cuenta...
                    </div>
                  ) : (
                    'Crear cuenta'
                  )}
                </button>
                {submitError && (
                  <div className="flex items-center gap-2 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-sm">
                    <AlertCircle className="w-4 h-4 flex-shrink-0" />
                    <span>{submitError}</span>
                  </div>
                )}
              </motion.form>
            )}

          </AnimatePresence>
        </div>
      </motion.div>
    </div>
  );
}
