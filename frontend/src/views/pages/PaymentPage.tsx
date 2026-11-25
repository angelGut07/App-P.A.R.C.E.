import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { motion } from 'framer-motion';
import { CreditCard, DollarSign, Building2, Check, X, Clock, ChevronLeft, Shield, Wrench, Package, AlertCircle, Receipt, Star, ExternalLink, RotateCcw } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../../controllers/AuthContext';
import { errorMessage, paymentService, requestService } from '../../services';
import type { Payment, PaymentDetail, PaymentMethod } from '@/models';
import { PAYMENT_METHOD, PAYMENT_STATUS, formatCOP } from '../format';

const METHODS: { id: PaymentMethod; title: string; description: string; icon: React.ElementType }[] = [
  { id: 'cash', title: 'Efectivo', description: 'Pagas al mecánico y él confirma que recibió el dinero. Funciona sin internet.', icon: DollarSign },
  { id: 'card', title: 'Tarjeta', description: 'Débito o crédito, en Mercado Pago', icon: CreditCard },
  { id: 'pse', title: 'PSE', description: 'Transferencia desde tu banco, en Mercado Pago', icon: Building2 },
];

/** Motivos de rechazo más comunes de Mercado Pago, en palabras del cliente. */
const REJECTION_REASONS: Record<string, string> = {
  cc_rejected_insufficient_amount: 'La tarjeta no tiene fondos suficientes.',
  cc_rejected_bad_filled_security_code: 'El código de seguridad es incorrecto.',
  cc_rejected_bad_filled_date: 'La fecha de vencimiento es incorrecta.',
  cc_rejected_bad_filled_card_number: 'El número de la tarjeta es incorrecto.',
  cc_rejected_bad_filled_other: 'Algún dato de la tarjeta es incorrecto.',
  cc_rejected_call_for_authorize: 'Tu banco pide que autorices el pago. Llámalo o usa otro medio.',
  cc_rejected_card_disabled: 'La tarjeta está desactivada.',
  cc_rejected_duplicated_payment: 'Ya hiciste un pago por este mismo valor hace poco.',
  cc_rejected_high_risk: 'El pago fue rechazado por seguridad. Prueba con otro medio.',
  cc_rejected_max_attempts: 'Superaste el número de intentos permitidos con esta tarjeta.',
};

type Step = 1 | 2 | 3;

/** Un pago en línea que el cliente abandonó (sin pago en Mercado Pago) se puede reintentar. */
const isAbandonedOnline = (p: Payment | null) =>
  !!p && p.status === 'pending' && p.method !== 'cash' && !p.mpPaymentId;

/** Paso que corresponde según el pago guardado. */
const stepFor = (p: Payment | null): Step => (!p || isAbandonedOnline(p) ? 1 : 3);

/**
 * Pago de un servicio completado (?request=ID; si no viene, el último
 * servicio completado sin pagar).
 *
 * - Efectivo: se registra pendiente y el mecánico lo confirma.
 * - Tarjeta y PSE: Mercado Pago Checkout Pro (redirección, sin SDK ni
 *   formularios de tarjeta en esta página: menos datos con señal débil).
 *   Al volver, Mercado Pago agrega ?payment_id=... a la URL y el servidor
 *   consulta el estado real en su API: el "status" de la URL no se usa.
 */
export default function PaymentPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const [detail, setDetail] = useState<PaymentDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [verifying, setVerifying] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [suggestCash, setSuggestCash] = useState(false);
  const [step, setStep] = useState<Step>(1);
  const [method, setMethod] = useState<PaymentMethod | null>(null);
  const [processing, setProcessing] = useState(false);
  // En desarrollo React ejecuta los efectos dos veces: evita verificar dos veces el mismo pago
  const verifiedRef = useRef<string | null>(null);

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

      // ¿Volvemos de Mercado Pago? Si el cliente canceló, llega payment_id=null
      const mpPaymentId = searchParams.get('payment_id');
      const returnedFromMp = searchParams.has('collection_status') || searchParams.has('payment_id');

      if (mpPaymentId && /^\d+$/.test(mpPaymentId) && verifiedRef.current !== mpPaymentId) {
        verifiedRef.current = mpPaymentId;
        setVerifying(true);
        const res = await paymentService.verify(requestId, mpPaymentId);
        setVerifying(false);
        // Limpia la URL: recargar la página no vuelve a verificar
        setSearchParams({ request: String(requestId) }, { replace: true });
        if (res.success && res.data) {
          setDetail(res.data.payment);
          setStep(stepFor(res.data.payment.payment));
          setLoading(false);
          return;
        }
        setError(errorMessage(res, 'No pudimos confirmar tu pago. Si se cobró, se reflejará en unos minutos.'));
      } else if (returnedFromMp && !mpPaymentId?.match(/^\d+$/)) {
        setSearchParams({ request: String(requestId) }, { replace: true });
        setNotice('No se completó el pago en Mercado Pago. Puedes intentarlo de nuevo o pagar en efectivo.');
      }

      const res = await paymentService.detail(requestId);
      if (res.success && res.data) {
        setDetail(res.data.payment);
        setStep(stepFor(res.data.payment.payment));
      } else {
        setError(errorMessage(res, 'No se pudo cargar el detalle del pago'));
      }
      setLoading(false);
    })();
    // Solo al montar: después la URL la cambiamos nosotros (setSearchParams)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const chooseMethod = (m: PaymentMethod) => {
    setMethod(m);
    setError('');
    setSuggestCash(false);
  };

  const handlePay = async () => {
    if (!detail || !method) return;
    const requestId = detail.serviceRequest.id;
    setProcessing(true);
    setError('');
    setNotice('');
    setSuggestCash(false);

    if (method === 'cash') {
      const res = await paymentService.payCash(requestId);
      setProcessing(false);
      if (res.success && res.data) {
        setDetail(res.data.payment);
        setStep(3);
      } else {
        setError(errorMessage(res, 'No se pudo registrar el pago'));
      }
      return;
    }

    const res = await paymentService.checkout(requestId, method);
    if (res.success && res.data?.checkoutUrl) {
      // Redirección completa: la pasarela es una página de Mercado Pago
      window.location.href = res.data.checkoutUrl;
      return; // "processing" se queda activo mientras el navegador cambia de página
    }

    // Sin señal o Mercado Pago caído: el efectivo siempre funciona
    setProcessing(false);
    setError(errorMessage(res, 'No pudimos abrir Mercado Pago.'));
    setSuggestCash(true);
  };

  const retry = () => {
    setMethod(null);
    setError('');
    setNotice('');
    setStep(1);
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

  if (verifying) {
    return shell(
      <div className="card p-10 text-center space-y-3">
        <Clock className="w-12 h-12 text-gold-400 mx-auto animate-pulse" />
        <h2 className="text-xl font-bold text-white">Confirmando tu pago…</h2>
        <p className="text-gray-400 text-sm">Estamos consultando el resultado con Mercado Pago. Puede tardar unos segundos si la señal es débil; no cierres esta página.</p>
      </div>
    );
  }

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

  const errorBox = (error || notice) && (
    <div className={`flex items-start gap-2 p-3 rounded-xl text-sm border ${error ? 'bg-red-500/10 border-red-500/30 text-red-400' : 'bg-yellow-500/10 border-yellow-500/30 text-yellow-300'}`}>
      <AlertCircle className="w-4 h-4 flex-shrink-0 mt-0.5" />
      <div className="space-y-2">
        <p>{error || notice}</p>
        {suggestCash && (
          <button onClick={() => { chooseMethod('cash'); setStep(2); }} className="underline font-semibold">
            Pagar en efectivo al mecánico
          </button>
        )}
      </div>
    </div>
  );

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

  const rejectionReason = payment?.mpStatusDetail ? REJECTION_REASONS[payment.mpStatusDetail] : undefined;

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
          {errorBox}
          <div className="grid sm:grid-cols-3 gap-3">
            {METHODS.map(m => (
              <button key={m.id} onClick={() => chooseMethod(m.id)}
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

          {method === 'cash' ? (
            <div className="p-4 rounded-xl bg-dark-800 border border-anthracite-700 text-sm text-gray-300 space-y-2">
              <p className="text-white font-semibold">Pago en efectivo</p>
              <p>Entrega <span className="text-gold-400 font-bold">{formatCOP(detail.total)}</span> al mecánico. El pago quedará pendiente hasta que él confirme que lo recibió.</p>
            </div>
          ) : (
            <div className="p-4 rounded-xl bg-dark-800 border border-anthracite-700 text-sm text-gray-300 space-y-2">
              <p className="text-white font-semibold">Pago con {method === 'card' ? 'tarjeta' : 'PSE'} en Mercado Pago</p>
              <p>Te llevaremos a la página segura de Mercado Pago para pagar <span className="text-gold-400 font-bold">{formatCOP(detail.total)}</span>. Al terminar, volverás aquí automáticamente.</p>
              <p className="flex items-center gap-2 text-xs text-gray-500">
                <Shield className="w-4 h-4" /> P.A.R.C.E nunca ve ni guarda los datos de tu tarjeta o de tu banco.
              </p>
            </div>
          )}

          {errorBox}

          <button disabled={processing} onClick={handlePay} className="w-full btn-primary disabled:opacity-40 flex items-center justify-center gap-2">
            {processing
              ? (method === 'cash' ? 'Registrando…' : 'Abriendo Mercado Pago…')
              : method === 'cash'
                ? 'Confirmar pago en efectivo'
                : <>Ir a Mercado Pago <ExternalLink className="w-4 h-4" /></>}
          </button>
        </div>
      )}

      {step === 3 && payment && (
        <div className="card p-6 text-center space-y-4">
          {errorBox}
          <div className={`w-16 h-16 mx-auto rounded-full flex items-center justify-center ${payment.status === 'rejected' ? 'bg-red-500/20' : payment.status === 'pending' ? 'bg-yellow-500/20' : 'bg-green-500/20'}`}>
            {payment.status === 'rejected'
              ? <X className="w-8 h-8 text-red-400" />
              : payment.status === 'pending'
                ? <Clock className="w-8 h-8 text-yellow-400" />
                : <Check className="w-8 h-8 text-green-400" />}
          </div>
          <div>
            <h3 className="text-xl font-bold text-white">
              {payment.status === 'confirmed' ? '¡Pago confirmado!' : payment.status === 'pending' ? 'Pago pendiente' : 'Pago rechazado'}
            </h3>
            <p className="text-gray-400 text-sm mt-1">
              {PAYMENT_METHOD[payment.method]}{payment.cardLast4 ? ` •••• ${payment.cardLast4}` : ''} · Recibo {payment.receiptNumber} ·{' '}
              <span className={`px-2 py-0.5 rounded-full border text-xs ${PAYMENT_STATUS[payment.status].color}`}>{PAYMENT_STATUS[payment.status].label}</span>
            </p>
            {payment.status === 'pending' && payment.method === 'cash' && (
              <p className="text-gray-500 text-xs mt-2">El mecánico confirmará cuando reciba el efectivo.</p>
            )}
            {payment.status === 'pending' && payment.method !== 'cash' && (
              <p className="text-gray-500 text-xs mt-2">Mercado Pago está procesando el pago. Te avisaremos cuando se confirme; puedes recargar esta página en unos minutos.</p>
            )}
            {payment.status === 'rejected' && (
              <p className="text-gray-400 text-sm mt-2">{rejectionReason ?? 'El pago no se aprobó.'} No se te cobró nada.</p>
            )}
          </div>

          {payment.status === 'rejected' ? (
            <button onClick={retry} className="btn-primary w-full flex items-center justify-center gap-2">
              <RotateCcw className="w-4 h-4" /> Intentar de nuevo o pagar en efectivo
            </button>
          ) : (
            <div className="grid sm:grid-cols-2 gap-3">
              <button onClick={() => navigate(`/payment/receipt?request=${sr.id}`)} className="btn-outline flex items-center justify-center gap-2">
                <Receipt className="w-4 h-4" /> Ver comprobante
              </button>
              <button onClick={() => navigate(`/survey?request=${sr.id}`)} className="btn-primary flex items-center justify-center gap-2">
                <Star className="w-4 h-4" /> Calificar servicio
              </button>
            </div>
          )}
        </div>
      )}
    </>
  );
}
