import { useEffect, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';
import { MapPin, Navigation, ArrowRight, Search, Car, AlertCircle, Zap } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import LocationMap from '../components/LocationMap';
import { useAuth } from '../../controllers/AuthContext';
import { useService } from '../../controllers/ServiceContext';
import { searchAddress, reverseGeocode, GeocodingResult } from '../../services/mapService';
import { errorMessage, requestService, vehicleService } from '../../services';
import type { Priority, Vehicle } from '@/models';

const PRIORITIES: { value: Priority; label: string; hint: string }[] = [
  { value: 'normal', label: 'Normal', hint: 'Puedo esperar' },
  { value: 'urgent', label: 'Urgente', hint: 'Estoy detenido en la vía' },
  { value: 'critical', label: 'Crítica', hint: 'Situación de riesgo' },
];

/**
 * Paso final de la solicitud: ubicación, vehículo y prioridad. Al confirmar
 * se crea la solicitud real y el cliente pasa a seguirla en vivo.
 */
export default function ServiceLocationPage() {
  const { user } = useAuth();
  const { selectedService, setSelectedService } = useService();
  const navigate = useNavigate();

  const [address, setAddress] = useState('');
  const [point, setPoint] = useState<{ lat: number; lng: number } | null>(null);
  const [additionalInfo, setAdditionalInfo] = useState('');
  const [suggestions, setSuggestions] = useState<GeocodingResult[]>([]);
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [searching, setSearching] = useState(false);
  const [locating, setLocating] = useState(false);
  const [vehicles, setVehicles] = useState<Vehicle[] | null>(null);
  const [vehicleId, setVehicleId] = useState<number | null>(null);
  const [priority, setPriority] = useState<Priority>('normal');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    vehicleService.list().then(res => {
      const list = res.success && res.data ? res.data.vehicles : [];
      setVehicles(list);
      setVehicleId((list.find(v => v.isPrimary) ?? list[0])?.id ?? null);
    });
  }, []);

  if (!selectedService) return <Navigate to="/services" replace />;

  const pickPoint = async (lat: number, lng: number, knownAddress?: string) => {
    setPoint({ lat, lng });
    setAddress(knownAddress ?? 'Buscando dirección...');
    if (!knownAddress) setAddress(await reverseGeocode(lat, lng));
  };

  const handleAddressChange = async (value: string) => {
    setAddress(value);
    if (value.length < 3) {
      setShowSuggestions(false);
      return;
    }
    setSearching(true);
    const results = await searchAddress(value);
    setSuggestions(results);
    setShowSuggestions(results.length > 0);
    setSearching(false);
  };

  const handleCurrentLocation = () => {
    if (!navigator.geolocation) {
      setError('Tu navegador no permite obtener la ubicación. Escribe la dirección o márcala en el mapa.');
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      pos => {
        setLocating(false);
        pickPoint(pos.coords.latitude, pos.coords.longitude);
      },
      () => {
        setLocating(false);
        setError('No pudimos obtener tu ubicación. Revisa el permiso de ubicación del navegador.');
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  };

  const handleConfirm = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    if (!point) return setError('Marca tu ubicación en el mapa o elige una dirección de la lista.');
    if (!vehicleId) return setError('Selecciona el vehículo que necesita asistencia.');

    const description = [
      selectedService.chatbotDiagnosis ? `Diagnóstico del asistente: ${selectedService.chatbotDiagnosis}` : selectedService.title,
      additionalInfo.trim() && `Indicaciones: ${additionalInfo.trim()}`,
    ].filter(Boolean).join('\n');

    setSubmitting(true);
    const res = await requestService.create({
      vehicle_id: vehicleId,
      emergency_type: selectedService.code,
      description,
      latitude: point.lat,
      longitude: point.lng,
      priority,
    });
    setSubmitting(false);

    if (!res.success || !res.data) {
      setError(errorMessage(res, 'No se pudo crear la solicitud'));
      return;
    }

    setSelectedService(null);
    navigate(`/service-in-progress?id=${res.data.serviceRequest.id}`);
  };

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar hidden={true} />

      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="max-w-2xl mx-auto space-y-6">
          <div>
            <h1 className="text-3xl font-bold text-white mb-1">¿Dónde estás?</h1>
            <p className="text-gray-400">El mecánico necesita saber tu ubicación para llegar hasta ti</p>
          </div>

          <div className="card p-4 border border-gold-500/30 bg-gold-500/5">
            <p className="text-xs text-gold-400 font-medium uppercase tracking-wider mb-1">Servicio seleccionado</p>
            <p className="text-white font-bold">{selectedService.title}</p>
            <p className="text-gray-400 text-sm">
              {selectedService.duration} · desde{' '}
              {new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(selectedService.basePrice)}
            </p>
          </div>

          <form onSubmit={handleConfirm} className="card p-6 space-y-5">
            {/* Vehículo */}
            <div className="space-y-2">
              <label className="flex items-center gap-2 text-sm font-medium text-gray-300">
                <Car className="w-4 h-4 text-gold-400" /> Vehículo
              </label>
              {vehicles === null ? (
                <p className="text-sm text-gray-500">Cargando vehículos...</p>
              ) : vehicles.length === 0 ? (
                <div className="p-3 rounded-xl bg-yellow-500/10 border border-yellow-500/30 text-sm text-yellow-300">
                  Aún no tienes vehículos registrados.{' '}
                  <Link to="/profile" className="underline text-yellow-200">Agrega uno en tu perfil</Link>.
                </div>
              ) : (
                <select value={vehicleId ?? ''} onChange={e => setVehicleId(Number(e.target.value))} className="input-field">
                  {vehicles.map(v => (
                    <option key={v.id} value={v.id}>{v.make} {v.model} {v.year} · {v.licensePlate}</option>
                  ))}
                </select>
              )}
            </div>

            {/* Prioridad */}
            <div className="space-y-2">
              <label className="flex items-center gap-2 text-sm font-medium text-gray-300">
                <Zap className="w-4 h-4 text-gold-400" /> Prioridad
              </label>
              <div className="grid grid-cols-3 gap-2">
                {PRIORITIES.map(p => (
                  <button key={p.value} type="button" onClick={() => setPriority(p.value)}
                    className={`p-3 rounded-xl border text-left transition-all ${priority === p.value ? 'border-gold-500 bg-gold-500/10' : 'border-anthracite-700 bg-dark-800 hover:border-gold-500/40'}`}>
                    <p className="text-white text-sm font-semibold">{p.label}</p>
                    <p className="text-gray-500 text-xs">{p.hint}</p>
                  </button>
                ))}
              </div>
            </div>

            {/* Ubicación actual */}
            <button type="button" onClick={handleCurrentLocation} disabled={locating}
              className="w-full flex items-center gap-3 p-4 bg-dark-800 hover:bg-dark-700 border border-anthracite-700 hover:border-gold-500/50 rounded-xl transition-all">
              <div className="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 bg-gold-500/20">
                {locating
                  ? <span className="w-4 h-4 border-2 border-gold-400 border-t-transparent rounded-full animate-spin" />
                  : <Navigation className="w-5 h-5 text-gold-400" />}
              </div>
              <div className="text-left">
                <p className="text-white font-semibold text-sm">{locating ? 'Obteniendo ubicación...' : 'Usar mi ubicación actual'}</p>
                <p className="text-gray-500 text-xs">Activa el GPS para mayor precisión</p>
              </div>
            </button>

            <div className="flex items-center gap-3">
              <div className="flex-1 h-px bg-anthracite-700" />
              <span className="text-gray-500 text-sm">o escribe la dirección</span>
              <div className="flex-1 h-px bg-anthracite-700" />
            </div>

            {/* Dirección */}
            <div className="space-y-2 relative">
              <label className="block text-sm font-medium text-gray-300">Dirección exacta</label>
              <div className="relative">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-500" />
                <input type="text" value={address}
                  onChange={e => handleAddressChange(e.target.value)}
                  onFocus={() => setShowSuggestions(suggestions.length > 0)}
                  onBlur={() => setTimeout(() => setShowSuggestions(false), 200)}
                  placeholder="Ej: Calle 72 #10-34, Bogotá" className="input-field pl-10" />
                {searching && (
                  <div className="absolute right-3 top-1/2 -translate-y-1/2">
                    <div className="w-4 h-4 border-2 border-gold-400 border-t-transparent rounded-full animate-spin" />
                  </div>
                )}
              </div>
              {showSuggestions && (
                <div className="absolute z-[500] w-full bg-dark-800 border border-anthracite-700 rounded-xl shadow-xl overflow-hidden max-h-60 overflow-y-auto">
                  {suggestions.map((result, i) => (
                    <button key={i} type="button"
                      onClick={() => { setShowSuggestions(false); pickPoint(parseFloat(result.lat), parseFloat(result.lon), result.display_name); }}
                      className="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-dark-700 transition-colors text-sm text-gray-300">
                      <MapPin className="w-4 h-4 text-gray-500 flex-shrink-0" />
                      <span className="line-clamp-2">{result.display_name}</span>
                    </button>
                  ))}
                </div>
              )}
            </div>

            <div className="rounded-xl overflow-hidden border border-anthracite-700 bg-dark-800 h-80">
              <LocationMap point={point} label={address} onPick={(lat, lng) => pickPoint(lat, lng)} />
            </div>

            <div className="space-y-2">
              <label className="block text-sm font-medium text-gray-300">
                Información adicional <span className="text-gray-500">(opcional)</span>
              </label>
              <textarea value={additionalInfo} onChange={e => setAdditionalInfo(e.target.value)}
                placeholder="Ej: Estoy en el parqueadero del centro comercial, nivel -1, puesto 34..."
                className="input-field resize-none h-24 text-sm" />
            </div>

            {error && (
              <div className="flex items-start gap-2 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-sm">
                <AlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" /> {error}
              </div>
            )}

            <button type="submit" disabled={submitting || !vehicles?.length}
              className="w-full btn-primary flex items-center justify-center gap-3 py-4 text-lg disabled:opacity-40 disabled:cursor-not-allowed">
              <MapPin className="w-5 h-5" />
              {submitting ? 'Enviando solicitud...' : 'Solicitar asistencia'}
              <ArrowRight className="w-5 h-5" />
            </button>
          </form>
        </motion.div>
      </main>
    </div>
  );
}
