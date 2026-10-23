import { useEffect, useState } from 'react';
import { Car, Plus, Star, Trash2, Edit2 } from 'lucide-react';
import { errorMessage, fieldErrors, vehicleService } from '../../../services';
import type { FuelType, Vehicle, VehicleInput, VehicleType } from '@/models';
import { Alert, Badge, Field, Modal, SectionTitle } from '../ui';
import { formatDate } from '../../format';

const VEHICLE_TYPES: Record<VehicleType, string> = {
  sedan: 'Automóvil', suv: 'Camioneta', truck: 'Camión', motorcycle: 'Moto', van: 'Van', other: 'Otro',
};
const FUEL_TYPES: Record<FuelType, string> = {
  gasoline: 'Gasolina', diesel: 'Diésel (ACPM)', electric: 'Eléctrico', hybrid: 'Híbrido', other: 'Otro',
};

const EMPTY: VehicleInput = {
  license_plate: '', make: '', model: '', year: new Date().getFullYear(), color: '',
  vehicle_type: 'sedan', fuel_type: 'gasoline',
  soat_number: '', soat_expiration_date: '', tecnomecanica_number: '', tecnomecanica_expiration_date: '',
};

/** Documento vigente / por vencer (30 días) / vencido según su fecha */
function documentBadge(date: string | null, number: string | null) {
  if (!date) {
    return <Badge className="bg-gray-500/20 text-gray-400 border-gray-500/30">{number ? `N.º ${number} · sin fecha de vencimiento` : 'Sin registrar'}</Badge>;
  }
  const days = (new Date(date).getTime() - Date.now()) / 86400000;
  if (days < 0) return <Badge className="bg-red-500/20 text-red-400 border-red-500/30">Vencido {formatDate(date, false)}</Badge>;
  if (days <= 30) return <Badge className="bg-yellow-500/20 text-yellow-400 border-yellow-500/30">Vence {formatDate(date, false)}</Badge>;
  return <Badge className="bg-green-500/20 text-green-400 border-green-500/30">Vigente hasta {formatDate(date, false)}</Badge>;
}

/** Vehículos del usuario: listar, agregar, editar, marcar principal y eliminar. */
export default function VehiclesSection() {
  const [vehicles, setVehicles] = useState<Vehicle[]>([]);
  const [editing, setEditing] = useState<Vehicle | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<VehicleInput>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState<Vehicle | null>(null);

  const load = () => vehicleService.list().then(res => res.success && res.data && setVehicles(res.data.vehicles));
  useEffect(() => { load(); }, []);

  const openForm = (vehicle: Vehicle | null) => {
    setEditing(vehicle);
    setErrors({});
    setError('');
    setForm(vehicle ? {
      license_plate: vehicle.licensePlate, make: vehicle.make, model: vehicle.model, year: vehicle.year,
      color: vehicle.color ?? '', vehicle_type: vehicle.vehicleType, fuel_type: vehicle.fuelType,
      soat_number: vehicle.soatNumber ?? '', soat_expiration_date: vehicle.soatExpirationDate ?? '',
      tecnomecanica_number: vehicle.tecnomecanicaNumber ?? '', tecnomecanica_expiration_date: vehicle.tecnomecanicaExpirationDate ?? '',
    } : { ...EMPTY, is_primary: vehicles.length === 0 });
    setFormOpen(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    // Los campos opcionales vacíos no se envían (el backend valida formato cuando vienen)
    const payload = Object.fromEntries(Object.entries({ ...form, license_plate: form.license_plate.toUpperCase().replace('-', '') })
      .filter(([, v]) => v !== '' && v !== undefined)) as unknown as VehicleInput;
    const res = editing ? await vehicleService.update(editing.id, payload) : await vehicleService.create(payload);
    setSaving(false);
    if (!res.success) {
      setErrors(fieldErrors(res));
      setError(errorMessage(res, 'No se pudo guardar el vehículo'));
      return;
    }
    setFormOpen(false);
    load();
  };

  const handleDelete = async () => {
    if (!deleting) return;
    const res = await vehicleService.remove(deleting.id);
    setDeleting(null);
    if (!res.success) setError(errorMessage(res, 'No se pudo eliminar el vehículo'));
    load();
  };

  const set = (key: keyof VehicleInput, value: string | number) => setForm(prev => ({ ...prev, [key]: value }));

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <SectionTitle color="text-blue-400">Mis vehículos</SectionTitle>
        <button onClick={() => openForm(null)} className="flex items-center gap-1 text-sm text-gold-400 hover:text-gold-300">
          <Plus className="w-4 h-4" /> Agregar
        </button>
      </div>

      {!formOpen && error && <Alert type="error">{error}</Alert>}

      {vehicles.length === 0 ? (
        <p className="text-sm text-gray-500">Aún no tienes vehículos. Agrega uno para poder pedir asistencia.</p>
      ) : (
        <div className="space-y-3">
          {vehicles.map(v => (
            <div key={v.id} className="p-4 rounded-xl bg-dark-800/60 border border-anthracite-700 space-y-2">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                  <Car className="w-5 h-5 text-blue-400" />
                  <div>
                    <p className="text-white font-semibold">{v.make} {v.model} {v.year}</p>
                    <p className="text-gray-400 text-xs tracking-widest">{v.licensePlate} · {VEHICLE_TYPES[v.vehicleType]} · {v.color ?? 'Sin color'}</p>
                  </div>
                </div>
                <div className="flex items-center gap-1">
                  {v.isPrimary
                    ? <Badge className="bg-gold-500/20 text-gold-400 border-gold-500/30">Principal</Badge>
                    : <button title="Marcar como principal" onClick={() => vehicleService.setPrimary(v.id).then(load)} className="p-1.5 text-gray-400 hover:text-gold-400"><Star className="w-4 h-4" /></button>}
                  <button title="Editar" onClick={() => openForm(v)} className="p-1.5 text-gray-400 hover:text-white"><Edit2 className="w-4 h-4" /></button>
                  <button title="Eliminar" onClick={() => setDeleting(v)} className="p-1.5 text-gray-400 hover:text-red-400"><Trash2 className="w-4 h-4" /></button>
                </div>
              </div>
              <div className="flex flex-wrap gap-2 text-xs text-gray-400">
                <span>SOAT:</span> {documentBadge(v.soatExpirationDate, v.soatNumber)}
                <span className="ml-2">Tecnomecánica:</span> {documentBadge(v.tecnomecanicaExpirationDate, v.tecnomecanicaNumber)}
              </div>
            </div>
          ))}
        </div>
      )}

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Editar vehículo' : 'Nuevo vehículo'} wide>
        <form onSubmit={handleSave} className="space-y-4">
          <div className="grid sm:grid-cols-3 gap-3">
            <Field label="Placa" error={errors.licensePlate}>
              <input value={form.license_plate} onChange={e => set('license_plate', e.target.value.toUpperCase())} placeholder="ABC123" className="input-field" required />
            </Field>
            <Field label="Marca" error={errors.make}>
              <input value={form.make} onChange={e => set('make', e.target.value)} placeholder="Chevrolet" className="input-field" required />
            </Field>
            <Field label="Modelo" error={errors.model}>
              <input value={form.model} onChange={e => set('model', e.target.value)} placeholder="Spark" className="input-field" required />
            </Field>
            <Field label="Año" error={errors.year}>
              <input type="number" value={form.year} onChange={e => set('year', Number(e.target.value))} className="input-field" required />
            </Field>
            <Field label="Color" error={errors.color}>
              <input value={form.color ?? ''} onChange={e => set('color', e.target.value)} className="input-field" />
            </Field>
            <Field label="Tipo" error={errors.vehicleType}>
              <select value={form.vehicle_type} onChange={e => set('vehicle_type', e.target.value)} className="input-field">
                {Object.entries(VEHICLE_TYPES).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
              </select>
            </Field>
            <Field label="Combustible" error={errors.fuelType}>
              <select value={form.fuel_type} onChange={e => set('fuel_type', e.target.value)} className="input-field">
                {Object.entries(FUEL_TYPES).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
              </select>
            </Field>
          </div>
          <div className="grid sm:grid-cols-2 gap-3">
            <Field label="Número de SOAT" error={errors.soatNumber}>
              <input value={form.soat_number ?? ''} onChange={e => set('soat_number', e.target.value)} className="input-field" />
            </Field>
            <Field label="Vencimiento SOAT" error={errors.soatExpirationDate}>
              <input type="date" value={form.soat_expiration_date ?? ''} onChange={e => set('soat_expiration_date', e.target.value)} className="input-field" />
            </Field>
            <Field label="Número de tecnomecánica" error={errors.tecnomecanicaNumber}>
              <input value={form.tecnomecanica_number ?? ''} onChange={e => set('tecnomecanica_number', e.target.value)} className="input-field" />
            </Field>
            <Field label="Vencimiento tecnomecánica" error={errors.tecnomecanicaExpirationDate}>
              <input type="date" value={form.tecnomecanica_expiration_date ?? ''} onChange={e => set('tecnomecanica_expiration_date', e.target.value)} className="input-field" />
            </Field>
          </div>
          {error && <Alert type="error">{error}</Alert>}
          <button type="submit" disabled={saving} className="w-full btn-primary disabled:opacity-50">
            {saving ? 'Guardando...' : 'Guardar vehículo'}
          </button>
        </form>
      </Modal>

      <Modal open={!!deleting} onClose={() => setDeleting(null)} title="Eliminar vehículo">
        <p className="text-gray-300 text-sm">¿Eliminar {deleting?.make} {deleting?.model} ({deleting?.licensePlate})? Esta acción no se puede deshacer.</p>
        <div className="grid grid-cols-2 gap-3">
          <button onClick={() => setDeleting(null)} className="py-2.5 bg-dark-700 rounded-xl text-white">Cancelar</button>
          <button onClick={handleDelete} className="py-2.5 bg-red-600 hover:bg-red-700 rounded-xl text-white font-semibold">Eliminar</button>
        </div>
      </Modal>
    </div>
  );
}
