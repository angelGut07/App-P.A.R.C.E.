import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { motion } from 'framer-motion';
import { CreditCard, DollarSign, Building2, Check, ChevronLeft, Shield, Wrench, Package, AlertCircle, Receipt, Star } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../../controllers/AuthContext';
import { errorMessage, paymentService, requestService } from '../../services';
import type { PaymentDetail, PaymentMethod } from '@/models';
import { PAYMENT_METHOD, PAYMENT_STATUS, formatCOP } from '../format';

const BANKS = [
  'Bancolombia', 'Banco de Bogotá', 'Davivienda', 'BBVA', 'Banco Popular',
  'Banco Caja Social', 'Colpatria', 'Occidente', 'AV Villas', 'Nequi',
];

const METHODS: { id: PaymentMethod; title: string; description: string; icon: React.ElementType }[] = [
  { id: 'cash', title: 'Efectivo', description: 'Pagas al mecánico y él confirma que recibió el dinero', icon: DollarSign },
  { id: 'card', title: 'Tarjeta', description: 'Débito o crédito', icon: CreditCard },
  { id: 'pse', title: 'PSE', description: 'Transferencia desde tu banco', icon: Building2 },
];

type Step = 1 | 2 | 3;

/**
 * Pago de un servicio completado (?request=ID; si no viene, el último
 * servicio completado sin pagar). La pasarela es simulada: no se hace un
 * cargo real ni se guardan datos de la tarjeta, solo sus últimos 4 dígitos.
 */
export default function PaymentPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const [detail, setDetail] = useState<PaymentDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [step, setStep] = useState<Step>(1);
  const [method, setMethod] = useState<PaymentMethod | null>(null);
  const [card, setCard] = useState({ number: '', name: '', expiry: '', cvv: '' });
  const [pse, setPse] = useState({ bank: '', personType: 'natural', document: '' });
  const [processing, setProcessing] = useState(false);

  useEffect(() => {
    (async () => {
      let requestId = Number(searchParams.get('request')) || null;
      if (!requestId) {
        const list = await requestService.list('completed');
        requestId = list.data?.serviceRequests[0]?.id ?? null;
      }
      if (!requestId) {
        setLoading(false);
        return;
      }
      const res = await paymentService.detail(requestId);
      if (res.success && res.data) {
        setDetail(res.data.payment);
        if (res.data.payment.payment) setStep(3);
      } else {
        setError(errorMessage(res, 'No se pudo cargar el detalle del pago'));
      }
      setLoading(false);
    })();
  }, [searchParams]);

  const cardDigits = card.number.replace(/\D/g, '');
  const cardValid = cardDigits.length >= 15 && card.name.trim().length > 2 && /^\d{2}\/\d{2}$/.test(card.expiry) && /^\d{3,4}$/.test(card.cvv);
  const pseValid = pse.bank !== '' && /^\d{6,12}$/.test(pse.document);
  const canPay = method === 'cash' || (method === 'card' && cardValid) || (method === 'pse' && pseValid);

  const handlePay = async () => {
    if (!detail || !method) return;
    setProcessing(true);
    setError('');
    const res = await paymentService.pay(detail.serviceRequest.id, method, {
      card_last4: method === 'card' ? cardDigits.slice(-4) : undefined,
      pse_bank: method === 'pse' ? pse.bank : undefined,
    });
    setProcessing(false);
    if (res.success && res.data) {
      setDetail(res.data.payment);
      setStep(3);
    } else {
      setError(errorMessage(res, 'No se pudo registrar el pago'));
    }
  };

  const shell = (content: React.ReactNode) => (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} />
      <Sidebar />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24 md:ml-64">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="max-w-3xl mx-auto space-y-6">
          {content}
        </motion.div>
      </main>
    </div>
  );

  if (loading) return shell(<p className="text-gray-400">Cargando...</p>);

  if (!detail) {
    return shell(
      <div className="card p-10 text-center space-y-4">
        <Receipt className="w-12 h-12 text-gold-400 mx-auto" />
        <h2 className="text-2xl font-bold text-white">No tienes servicios por pagar</h2>
        <p className="text-gray-400">{error || 'Cuando un mecánico complete tu servicio podrás pagarlo aquí.'}</p>
        <Link to="/services" className="btn-primary inline-block">Ver servicios</Link>
      </div>
    );
  }

  const { serviceRequest: sr, payment } = detail;

  const summary = (
    <div className="card p-6 space-y-4">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-xs text-gray-500 uppercase tracking-wider">Servicio {sr.serviceCode}</p>
          <h2 className="text-xl font-bold text-white">{sr.serviceTypeName}</h2>
          <p className="text-gray-400 text-sm">Mecánico: {sr.mechanicName ?? '—'} · Vehículo {sr.vehicleLicensePlate}</p>
        </div>
        <div className="text-right">
          <p className="text-xs text-gray-500">Total</p>
          <p className="text-3xl font-bold text-gold-400">{formatCOP(payment?.amount ?? detail.total)}</p>
        </div>
      </div>
      <div className="space-y-2 text-sm border-t border-anthracite-800 pt-4">
        <div className="flex justify-between text-gray-300">
          <span className="flex items-center gap-2"><Wrench className="w-4 h-4 text-gray-500" /> Mano de obra</span>
          <span>{formatCOP(detail.laborAmount)}</span>
        </div>
        {detail.parts.map(part => (
          <div key={part.productId} className="flex justify-between text-gray-400">
            <span className="flex items-center gap-2"><Package className="w-4 h-4 text-gray-600" /> {part.productName} × {part.quantity}</span>
            <span>{formatCOP(part.subtotal)}</span>
          </div>
        ))}
        <div className="flex justify-between text-white font-bold border-t border-anthracite-800 pt-2">
          <span>Total</span><span className="text-gold-400">{formatCOP(detail.total)}</span>
        </div>
      </div>
    </div>
  );

  return shell(
    <>
      <div>
        <h1 className="text-3xl font-bold text-white">Pago del servicio</h1>
        <p className="text-gray-400 text-sm mt-1">Elige cómo quieres pagar</p>
      </div>

      {summary}

      {step === 1 && (
        <div className="card p-6 space-y-4">
          <h3 className="text-lg font-bold text-white">Método de pago</h3>
          <div className="grid sm:grid-cols-3 gap-3">
            {METHODS.map(m => (
              <button key={m.id} onClick={() => setMethod(m.id)}
                className={`p-4 rounded-xl border-2 text-left transition-all ${method === m.id ? 'border-gold-500 bg-gold-500/10' : 'border-anthracite-700 bg-dark-800 hover:border-gold-500/40'}`}>
                <m.icon className={`w-6 h-6 mb-2 ${method === m.id ? 'text-gold-400' : 'text-gray-400'}`} />
                <p className="text-white font-semibold">{m.title}</p>
                <p className="text-gray-500 text-xs mt-1">{m.description}</p>
              </button>
            ))}
          </div>
          <button disabled={!method} onClick={() => setStep(2)} className="w-full btn-primary disabled:opacity-40">Continuar</button>
        </div>
      )}

      {step === 2 && method && (
        <div className="card p-6 space-y-5">
          <button onClick={() => setStep(1)} className="flex items-center gap-1 text-sm text-gray-400 hover:text-white">
            <ChevronLeft className="w-4 h-4" /> Cambiar método
          </button>

          {method === 'cash' && (
            <div className="p-4 rounded-xl bg-dark-800 border border-anthracite-700 text-sm text-gray-300 space-y-2">
              <p className="text-white font-semibold">Pago en efectivo</p>
              <p>Entrega <span className="text-gold-400 font-bold">{formatCOP(detail.total)}</span> al mecánico. El pago quedará pendiente hasta que él confirme que lo recibió.</p>
            </div>
          )}

          {method === 'card' && (
            <div className="space-y-3">
              <input className="input-field" placeholder="Número de la tarjeta" inputMode="numeric"
                value={card.number} onChange={e => setCard({ ...card, number: e.target.value.replace(/\D/g, '').slice(0, 16).replace(/(.{4})/g, '$1 ').trim() })} />
              <input className="input-field" placeholder="Nombre en la tarjeta"
                value={card.name} onChange={e => setCard({ ...card, name: e.target.value })} />
              <div className="grid grid-cols-2 gap-3">
                <input className="input-field" placeholder="MM/AA" inputMode="numeric"
                  value={card.expiry} onChange={e => setCard({ ...card, expiry: e.target.value.replace(/\D/g, '').slice(0, 4).replace(/(.{2})(.+)/, '$1/$2') })} />
                <input className="input-field" placeholder="CVV" inputMode="numeric" type="password"
                  value={card.cvv} onChange={e => setCard({ ...card, cvv: e.target.value.replace(/\D/g, '').slice(0, 4) })} />
              </div>
            </div>
          )}

          {method === 'pse' && (
            <div className="space-y-3">
              <select className="input-field" value={pse.bank} onChange={e => setPse({ ...pse, bank: e.target.value })}>
                <option value="">Selecciona tu banco</option>
                {BANKS.map(b => <option key={b} value={b}>{b}</option>)}
              </select>
              <select className="input-field" value={pse.personType} onChange={e => setPse({ ...pse, personType: e.target.value })}>
                <option value="natural">Persona natural</option>
                <option value="juridica">Persona jurídica</option>
              </select>
              <input className="input-field" placeholder="Número de documento" inputMode="numeric"
                value={pse.document} onChange={e => setPse({ ...pse, document: e.target.value.replace(/\D/g, '').slice(0, 12) })} />
            </div>
          )}

          {method !== 'cash' && (
            <p className="flex items-center gap-2 text-xs text-gray-500">
              <Shield className="w-4 h-4" /> Pasarela de pruebas: no se realiza ningún cargo real y no guardamos los datos de tu tarjeta.
            </p>
          )}

          {error && (
            <div className="flex items-start gap-2 p-3 bg-red-500/10 border border-red-500/30 rounded-xl text-red-400 text-sm">
              <AlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" /> {error}
            </div>
          )}

          <button disabled={!canPay || processing} onClick={handlePay} className="w-full btn-primary disabled:opacity-40">
            {processing ? 'Procesando...' : method === 'cash' ? 'Confirmar pago en efectivo' : `Pagar ${formatCOP(detail.total)}`}
          </button>
        </div>
      )}

      {step === 3 && payment && (
        <div className="card p-6 text-center space-y-4">
          <div className={`w-16 h-16 mx-auto rounded-full flex items-center justify-center ${payment.status === 'rejected' ? 'bg-red-500/20' : 'bg-green-500/20'}`}>
            <Check className={`w-8 h-8 ${payment.status === 'rejected' ? 'text-red-400' : 'text-green-400'}`} />
          </div>
          <div>
            <h3 className="text-xl font-bold text-white">
              {payment.status === 'confirmed' ? '¡Pago confirmado!' : payment.status === 'pending' ? 'Pago registrado' : 'Pago rechazado'}
            </h3>
            <p className="text-gray-400 text-sm mt-1">
              {PAYMENT_METHOD[payment.method]} · Recibo {payment.receiptNumber} ·{' '}
              <span className={`px-2 py-0.5 rounded-full border text-xs ${PAYMENT_STATUS[payment.status].color}`}>{PAYMENT_STATUS[payment.status].label}</span>
            </p>
            {payment.status === 'pending' && <p className="text-gray-500 text-xs mt-2">El mecánico confirmará cuando reciba el efectivo.</p>}
          </div>
          <div className="grid sm:grid-cols-2 gap-3">
            <button onClick={() => navigate(`/payment/receipt?request=${sr.id}`)} className="btn-outline flex items-center justify-center gap-2">
              <Receipt className="w-4 h-4" /> Ver comprobante
            </button>
            <button onClick={() => navigate(`/survey?request=${sr.id}`)} className="btn-primary flex items-center justify-center gap-2">
              <Star className="w-4 h-4" /> Calificar servicio
            </button>
          </div>
        </div>
      )}
    </>
  );
}
