import { useEffect, useState } from 'react';
import { Car, Camera, CheckCircle, MapPin, MessageCircle, Navigation, Package, Phone, Play, Star, Trash2, User } from 'lucide-react';
import LocationMap from '../LocationMap';
import { Alert, Field, Modal } from '../ui';
import { errorMessage, inventoryService, mechanicService, uploadService } from '../../../services';
import type { Evidence, Product, ServicePart, ServiceRequest } from '@/models';
import { REQUEST_STATUS, formatCOP, fullName, whatsappLink } from '../../format';
import { reverseGeocode } from '../../../services/mapService';

const EVIDENCE_LABEL: Record<Evidence['evidenceType'], string> = { before: 'Antes', during: 'Durante', after: 'Después' };

interface ActiveOrderProps {
  request: ServiceRequest;
  /** Se llama con la solicitud actualizada después de iniciar o completar */
  onChange: (request: ServiceRequest) => void;
}

/**
 * Servicio que el mecánico está atendiendo: ubicación exacta del cliente,
 * contacto, repuestos del inventario, fotos de evidencia y cierre con el
 * costo de mano de obra. Al completar, el mecánico califica al cliente.
 */
export default function ActiveOrder({ request, onChange }: ActiveOrderProps) {
  const [address, setAddress] = useState('');
  const [parts, setParts] = useState<ServicePart[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [evidences, setEvidences] = useState<Evidence[]>([]);
  const [partForm, setPartForm] = useState({ productId: 0, quantity: 1 });
  const [evidenceType, setEvidenceType] = useState<Evidence['evidenceType']>('before');
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [completeOpen, setCompleteOpen] = useState(false);
  const [finalCost, setFinalCost] = useState('');
  const [rateOpen, setRateOpen] = useState(false);
  const [score, setScore] = useState(0);
  const [comment, setComment] = useState('');
  const [rated, setRated] = useState(false);

  const lat = Number(request.latitude);
  const lng = Number(request.longitude);
  const hasPoint = Number.isFinite(lat) && Number.isFinite(lng) && request.latitude !== undefined;
  const editable = request.status === 'assigned' || request.status === 'in_progress';

  useEffect(() => {
    if (hasPoint) reverseGeocode(lat, lng).then(setAddress);
    mechanicService.parts(request.id).then(res => res.data && setParts(res.data.parts));
    mechanicService.evidences(request.id).then(res => res.data && setEvidences(res.data.evidences));
    inventoryService.products().then(res => res.data && setProducts(res.data.products.filter(p => p.stockQuantity > 0)));
  }, [request.id, hasPoint, lat, lng]);

  const partsTotal = parts.reduce((sum, p) => sum + p.subtotal, 0);

  const run = async <T,>(action: () => Promise<{ success: boolean; data?: T; error?: string; fields?: Record<string, string | string[]> }>, onOk: (data: T) => void, fallback: string) => {
    setBusy(true);
    setError('');
    const res = await action();
    setBusy(false);
    if (res.success && res.data !== undefined) onOk(res.data);
    else setError(errorMessage(res, fallback));
  };

  const start = () => run(() => mechanicService.start(request.id), d => onChange(d.serviceRequest), 'No se pudo iniciar el servicio');

  const addPart = () => {
    if (!partForm.productId) return;
    run(() => mechanicService.addPart(request.id, partForm.productId, partForm.quantity), d => {
      setParts(d.parts);
      setPartForm({ productId: 0, quantity: 1 });
      inventoryService.products().then(res => res.data && setProducts(res.data.products.filter(p => p.stockQuantity > 0)));
    }, 'No se pudo agregar el repuesto');
  };

  const removePart = (productId: number) =>
    run(() => mechanicService.removePart(request.id, productId), d => setParts(d.parts), 'No se pudo quitar el repuesto');

  const uploadEvidence = async (file: File) => {
    setUploading(true);
    setError('');
    const upload = await uploadService.upload(file, 'evidence');
    if (!upload.success || !upload.data) {
      setUploading(false);
      setError(errorMessage(upload, 'No se pudo subir la foto'));
      return;
    }
    const res = await mechanicService.addEvidence(request.id, {
      evidence_type: evidenceType,
      image_url: upload.data.upload.url,
      original_filename: upload.data.upload.originalFilename,
      file_size: upload.data.upload.size,
    });
    setUploading(false);
    if (res.success) mechanicService.evidences(request.id).then(r => r.data && setEvidences(r.data.evidences));
    else setError(errorMessage(res, 'No se pudo registrar la evidencia'));
  };

  const complete = () =>
    run(() => mechanicService.complete(request.id, Number(finalCost)), d => {
      setCompleteOpen(false);
      setRateOpen(true);
      onChange(d.serviceRequest);
    }, 'No se pudo completar el servicio');

  const rateCustomer = () =>
    run(() => mechanicService.rateCustomer(request.id, score, comment.trim()), () => {
      setRated(true);
      setTimeout(() => setRateOpen(false), 1200);
    }, 'No se pudo guardar la calificación');

  const customerName = fullName(request.customerFirstName, request.customerLastName);

  return (
    <div className="grid lg:grid-cols-2 gap-4">
      <div className="card p-5 flex flex-col space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-lg font-bold text-white flex items-center gap-2"><MapPin className="w-5 h-5 text-gold-400" /> Ubicación del cliente</h3>
          {hasPoint && (
            <a href={`https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`} target="_blank" rel="noreferrer"
              className="flex items-center gap-1 text-sm text-gold-400 hover:text-gold-300">
              <Navigation className="w-4 h-4" /> Cómo llegar
            </a>
          )}
        </div>
        <div className="flex-1 min-h-[300px] rounded-xl overflow-hidden">
          <LocationMap point={hasPoint ? { lat, lng } : null} label={address || 'Cliente'} />
        </div>
        {address && <p className="text-sm text-gray-400">{address}</p>}
      </div>

      <div className="card p-5 space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="text-xs text-gray-500">{request.serviceCode}</p>
            <h3 className="text-lg font-bold text-white">{request.serviceTypeName}</h3>
          </div>
          <span className={`px-3 py-1 rounded-full text-xs font-bold border ${REQUEST_STATUS[request.status].color}`}>{REQUEST_STATUS[request.status].label}</span>
        </div>

        <div className="flex items-center gap-3">
          <div className="w-11 h-11 bg-gradient-to-br from-primary-500 to-primary-700 rounded-full flex items-center justify-center">
            <User className="w-5 h-5 text-white" />
          </div>
          <div className="flex-1">
            <p className="text-white font-semibold">{customerName}</p>
            <p className="text-xs text-gray-400 flex items-center gap-1"><Car className="w-3.5 h-3.5" /> {request.vehicleMake} {request.vehicleModel} · {request.vehicleLicensePlate}</p>
          </div>
          {request.customerPhone && (
            <div className="flex gap-2">
              <a href={`tel:${request.customerPhone}`} className="p-2.5 bg-dark-700 hover:bg-dark-600 rounded-xl" title="Llamar"><Phone className="w-4 h-4 text-white" /></a>
              <a href={whatsappLink(request.customerPhone, `Hola ${request.customerFirstName}, soy tu mecánico de P.A.R.C.E (${request.serviceCode}).`)}
                target="_blank" rel="noreferrer" className="p-2.5 bg-green-600 hover:bg-green-700 rounded-xl" title="WhatsApp">
                <MessageCircle className="w-4 h-4 text-white" />
              </a>
            </div>
          )}
        </div>

        <p className="text-sm text-gray-300 whitespace-pre-line bg-dark-800/60 rounded-xl p-3 border border-anthracite-800">{request.description}</p>

        {/* Repuestos */}
        <div className="space-y-2">
          <p className="text-sm font-semibold text-orange-400 flex items-center gap-2"><Package className="w-4 h-4" /> Repuestos e insumos</p>
          {parts.map(p => (
            <div key={p.productId} className="flex items-center justify-between text-sm">
              <span className="text-gray-300">{p.productName} × {p.quantity}</span>
              <span className="flex items-center gap-2 text-orange-300">
                {formatCOP(p.subtotal)}
                {editable && <button onClick={() => removePart(p.productId)} className="text-gray-500 hover:text-red-400"><Trash2 className="w-4 h-4" /></button>}
              </span>
            </div>
          ))}
          {parts.length === 0 && <p className="text-xs text-gray-500">Sin repuestos registrados.</p>}
          {editable && (
            <div className="flex gap-2">
              <select value={partForm.productId} onChange={e => setPartForm({ ...partForm, productId: Number(e.target.value) })} className="input-field flex-1 text-sm">
                <option value={0}>Agregar del inventario...</option>
                {products.map(p => <option key={p.id} value={p.id}>{p.name} ({p.stockQuantity} disp.) · {formatCOP(p.unitPrice)}</option>)}
              </select>
              <input type="number" min={1} value={partForm.quantity} onChange={e => setPartForm({ ...partForm, quantity: Math.max(1, Number(e.target.value)) })} className="input-field w-20 text-sm" />
              <button onClick={addPart} disabled={busy || !partForm.productId} className="px-3 rounded-xl bg-orange-500/20 text-orange-300 border border-orange-500/30 disabled:opacity-40">+</button>
            </div>
          )}
        </div>

        {/* Evidencias */}
        <div className="space-y-2">
          <p className="text-sm font-semibold text-blue-400 flex items-center gap-2"><Camera className="w-4 h-4" /> Evidencias</p>
          <div className="flex gap-2 flex-wrap">
            {evidences.map(ev => (
              <a key={ev.id} href={ev.imageUrl} target="_blank" rel="noreferrer" className="relative w-16 h-16 rounded-lg overflow-hidden border border-anthracite-700">
                <img src={ev.imageUrl} alt={EVIDENCE_LABEL[ev.evidenceType]} className="w-full h-full object-cover" />
                <span className="absolute bottom-0 inset-x-0 text-[10px] text-center bg-black/60 text-white">{EVIDENCE_LABEL[ev.evidenceType]}</span>
              </a>
            ))}
          </div>
          {editable && (
            <div className="flex gap-2 items-center">
              <select value={evidenceType} onChange={e => setEvidenceType(e.target.value as Evidence['evidenceType'])} className="input-field text-sm w-32">
                {Object.entries(EVIDENCE_LABEL).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
              </select>
              <label className={`flex-1 text-center py-2 rounded-xl border border-dashed border-anthracite-600 text-sm text-gray-400 cursor-pointer hover:border-blue-500/50 ${uploading ? 'opacity-50' : ''}`}>
                {uploading ? 'Subiendo...' : 'Subir foto'}
                <input type="file" accept="image/jpeg,image/png,image/webp" className="hidden" disabled={uploading}
                  onChange={e => { const f = e.target.files?.[0]; if (f) uploadEvidence(f); e.target.value = ''; }} />
              </label>
            </div>
          )}
        </div>

        {error && <Alert type="error">{error}</Alert>}

        <div className="space-y-2 pt-2">
          {request.status === 'assigned' && (
            <button onClick={start} disabled={busy} className="w-full btn-primary flex items-center justify-center gap-2 disabled:opacity-50">
              <Play className="w-4 h-4" /> Llegué: iniciar servicio
            </button>
          )}
          {request.status === 'in_progress' && (
            <button onClick={() => { setFinalCost(String(Number(request.estimatedCost ?? 0) || '')); setCompleteOpen(true); }}
              className="w-full py-3 bg-green-600 hover:bg-green-700 text-white rounded-xl font-semibold flex items-center justify-center gap-2">
              <CheckCircle className="w-4 h-4" /> Completar servicio
            </button>
          )}
        </div>
      </div>

      <Modal open={completeOpen} onClose={() => setCompleteOpen(false)} title="Completar servicio">
        <p className="text-sm text-gray-400">Indica el valor de la mano de obra. Los repuestos ({formatCOP(partsTotal)}) se suman automáticamente al cobro del cliente.</p>
        <Field label="Mano de obra (COP)">
          <input type="number" min={0} step={1000} value={finalCost} onChange={e => setFinalCost(e.target.value)} className="input-field" />
        </Field>
        <p className="text-white font-semibold">Total a cobrar: <span className="text-gold-400">{formatCOP(Number(finalCost || 0) + partsTotal)}</span></p>
        {error && <Alert type="error">{error}</Alert>}
        <button onClick={complete} disabled={busy || finalCost === '' || Number(finalCost) < 0} className="w-full btn-primary disabled:opacity-50">
          {busy ? 'Guardando...' : 'Confirmar y completar'}
        </button>
      </Modal>

      <Modal open={rateOpen} onClose={() => setRateOpen(false)} title="Califica al cliente">
        {rated ? (
          <p className="text-green-400 text-center py-4">¡Gracias! Calificación guardada.</p>
        ) : (
          <>
            <p className="text-sm text-gray-400">¿Cómo fue tu experiencia con {customerName}?</p>
            <div className="flex justify-center gap-2">
              {[1, 2, 3, 4, 5].map(n => (
                <button key={n} onClick={() => setScore(n)}>
                  <Star className={`w-9 h-9 ${n <= score ? 'text-yellow-400 fill-yellow-400' : 'text-gray-600'}`} />
                </button>
              ))}
            </div>
            <textarea value={comment} onChange={e => setComment(e.target.value)} placeholder="Comentario opcional" className="input-field resize-none h-20 text-sm" />
            {error && <Alert type="error">{error}</Alert>}
            <button onClick={rateCustomer} disabled={busy || score === 0} className="w-full btn-primary disabled:opacity-50">Enviar calificación</button>
          </>
        )}
      </Modal>
    </div>
  );
}
