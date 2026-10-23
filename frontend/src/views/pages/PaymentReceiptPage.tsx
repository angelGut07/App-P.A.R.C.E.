import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { motion } from 'framer-motion';
import { ArrowLeft, CheckCircle, Clock, XCircle, Hash, Package, Printer, Star, MessageSquare, DollarSign, CreditCard, Building2 } from 'lucide-react';
import Navbar from '../components/Navbar';
import { useAuth } from '../../controllers/AuthContext';
import { errorMessage, paymentService } from '../../services';
import type { PaymentDetail, PaymentMethod } from '@/models';
import { PAYMENT_METHOD, formatCOP, formatDate } from '../format';

const METHOD_ICON: Record<PaymentMethod, { icon: React.ElementType; color: string }> = {
  cash: { icon: DollarSign, color: 'text-green-400' },
  card: { icon: CreditCard, color: 'text-blue-400' },
  pse:  { icon: Building2, color: 'text-purple-400' },
};

/**
 * Comprobante de pago (RF 5.4) de un servicio: ?request=ID. Lo ven el
 * cliente, el mecánico asignado y los administradores.
 */
export default function PaymentReceiptPage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [detail, setDetail] = useState<PaymentDetail | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const id = Number(searchParams.get('request'));
    if (!id) {
      setError('No se indicó el servicio');
      return;
    }
    paymentService.detail(id).then(res => {
      if (res.success && res.data) setDetail(res.data.payment);
      else setError(errorMessage(res, 'No se pudo cargar el comprobante'));
    });
  }, [searchParams]);

  const payment = detail?.payment;

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} userAvatar={user?.avatar} hideNavLinks />
      <main className="pt-20 pb-12 px-4">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="max-w-xl mx-auto space-y-6">
          <button onClick={() => navigate(-1)} className="flex items-center gap-2 text-gray-400 hover:text-white transition-colors text-sm print:hidden">
            <ArrowLeft className="w-4 h-4" /> Volver
          </button>

          {error && <div className="card p-6 text-center text-red-400">{error}</div>}
          {!error && !detail && <p className="text-gray-400 text-center">Cargando comprobante...</p>}

          {detail && !payment && (
            <div className="card p-6 text-center space-y-3">
              <p className="text-white font-semibold">Este servicio aún no tiene un pago registrado.</p>
              {user?.role === 'user' && (
                <button onClick={() => navigate(`/payment?request=${detail.serviceRequest.id}`)} className="btn-primary">Ir a pagar</button>
              )}
            </div>
          )}

          {detail && payment && (() => {
            const sr = detail.serviceRequest;
            const { icon: MethodIcon, color } = METHOD_ICON[payment.method];
            const StatusIcon = payment.status === 'confirmed' ? CheckCircle : payment.status === 'pending' ? Clock : XCircle;
            const statusStyle = payment.status === 'confirmed'
              ? 'bg-green-500/20 text-green-400 border-green-500/30'
              : payment.status === 'pending' ? 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' : 'bg-red-500/20 text-red-400 border-red-500/30';

            return (
              <>
                <div className="card p-6 text-center space-y-3">
                  <div className={`w-16 h-16 rounded-full flex items-center justify-center mx-auto border ${statusStyle}`}>
                    <StatusIcon className="w-8 h-8" />
                  </div>
                  <div>
                    <h1 className="text-2xl font-bold text-white">Comprobante de Pago</h1>
                    <p className="text-gray-400 text-sm mt-1">Constancia digital del servicio realizado</p>
                  </div>
                  <div className="flex items-center justify-center gap-2 text-sm">
                    <Hash className="w-4 h-4 text-gold-400" />
                    <span className="text-gold-400 font-mono font-bold">{payment.receiptNumber}</span>
                  </div>
                  <span className={`inline-block px-3 py-1 text-xs rounded-full font-semibold border ${statusStyle}`}>
                    {payment.status === 'confirmed' ? '✓ Pago confirmado' : payment.status === 'pending' ? 'Pendiente de confirmación' : 'Pago rechazado'}
                  </span>
                </div>

                <div className="card divide-y divide-anthracite-800">
                  <div className="px-6 py-4">
                    <p className="text-xs text-gray-500 uppercase tracking-wider mb-1">Fecha y hora</p>
                    <p className="text-white font-medium text-sm">{formatDate(payment.createdAt)}</p>
                  </div>

                  <div className="px-6 py-4">
                    <p className="text-xs text-gold-400 uppercase tracking-wider font-semibold mb-2">Cliente</p>
                    <div className="space-y-1 text-sm">
                      <div className="flex justify-between"><span className="text-gray-400">Nombre</span><span className="text-white">{sr.customerName}</span></div>
                      <div className="flex justify-between"><span className="text-gray-400">Email</span><span className="text-gray-300">{sr.customerEmail}</span></div>
                      <div className="flex justify-between"><span className="text-gray-400">Documento</span><span className="text-gray-300">{sr.customerIdNumber ?? '—'}</span></div>
                    </div>
                  </div>

                  <div className="px-6 py-4">
                    <p className="text-xs text-blue-400 uppercase tracking-wider font-semibold mb-2">Proveedor del servicio</p>
                    <div className="space-y-1 text-sm">
                      <div className="flex justify-between"><span className="text-gray-400">Mecánico</span><span className="text-white">{sr.mechanicName ?? '—'}</span></div>
                      <div className="flex justify-between"><span className="text-gray-400">Vehículo atendido</span><span className="text-gray-300">{sr.vehicleLicensePlate ?? '—'}</span></div>
                    </div>
                  </div>

                  <div className="px-6 py-4">
                    <p className="text-xs text-purple-400 uppercase tracking-wider font-semibold mb-2">Servicio realizado</p>
                    <div className="space-y-1 text-sm">
                      <div className="flex justify-between"><span className="text-gray-400">Servicio</span><span className="text-white">{sr.serviceTypeName}</span></div>
                      <div className="flex justify-between"><span className="text-gray-400">Código</span><span className="text-gray-300">{sr.serviceCode}</span></div>
                      <div className="flex justify-between"><span className="text-gray-400">Mano de obra</span><span className="text-white">{formatCOP(detail.laborAmount)}</span></div>
                    </div>
                  </div>

                  {detail.parts.length > 0 && (
                    <div className="px-6 py-4">
                      <p className="text-xs text-orange-400 uppercase tracking-wider font-semibold mb-2">Repuestos utilizados</p>
                      {detail.parts.map(p => (
                        <div key={p.productId} className="flex items-center justify-between text-sm py-1">
                          <span className="text-gray-300 flex items-center gap-1.5">
                            <Package className="w-3.5 h-3.5 text-orange-400" /> {p.productName} × {p.quantity}
                          </span>
                          <span className="text-orange-400">{formatCOP(p.subtotal)}</span>
                        </div>
                      ))}
                      <div className="flex justify-between text-sm pt-2 border-t border-anthracite-800 mt-2">
                        <span className="text-gray-400">Subtotal repuestos</span>
                        <span className="text-orange-400 font-semibold">{formatCOP(detail.partsTotal)}</span>
                      </div>
                    </div>
                  )}

                  <div className="px-6 py-4">
                    <p className="text-xs text-gray-500 uppercase tracking-wider font-semibold mb-2">Método de pago</p>
                    <div className="flex items-center gap-2">
                      <MethodIcon className={`w-5 h-5 ${color}`} />
                      <span className={`font-semibold text-sm ${color}`}>{PAYMENT_METHOD[payment.method]}</span>
                      {payment.pseBank && <span className="text-gray-400 text-sm">— {payment.pseBank}</span>}
                      {payment.cardLast4 && <span className="text-gray-400 text-sm">— **** {payment.cardLast4}</span>}
                    </div>
                  </div>

                  <div className="px-6 py-5 bg-gradient-to-r from-gold-600/10 to-transparent">
                    <div className="flex items-center justify-between">
                      <span className="text-white font-bold text-lg">TOTAL</span>
                      <span className="text-gold-400 font-bold text-2xl">{formatCOP(payment.amount)}</span>
                    </div>
                    <p className="text-gray-500 text-xs mt-1">Mano de obra: {formatCOP(detail.laborAmount)} + Repuestos: {formatCOP(detail.partsTotal)}</p>
                  </div>
                </div>

                <div className="grid grid-cols-1 gap-3 print:hidden">
                  <button onClick={() => window.print()}
                    className="flex items-center justify-center gap-2 py-3 bg-dark-800 hover:bg-dark-700 border border-anthracite-700 text-white rounded-xl transition-colors text-sm font-semibold">
                    <Printer className="w-4 h-4" /> Imprimir o guardar como PDF
                  </button>
                </div>

                {user?.role === 'user' && (
                  <div className="grid grid-cols-2 gap-3 print:hidden">
                    <button onClick={() => navigate(`/survey?request=${sr.id}`)}
                      className="flex items-center justify-center gap-2 py-3 bg-yellow-500/10 hover:bg-yellow-500/20 border border-yellow-500/30 text-yellow-400 rounded-xl transition-colors text-sm font-semibold">
                      <Star className="w-4 h-4" /> Calificar servicio
                    </button>
                    <button onClick={() => navigate('/pqr')}
                      className="flex items-center justify-center gap-2 py-3 bg-purple-500/10 hover:bg-purple-500/20 border border-purple-500/30 text-purple-400 rounded-xl transition-colors text-sm font-semibold">
                      <MessageSquare className="w-4 h-4" /> Enviar PQR
                    </button>
                  </div>
                )}

                <p className="text-center text-gray-600 text-xs leading-relaxed">
                  Este comprobante es la constancia digital del servicio prestado por P.A.R.C.E.
                  Número de referencia: <span className="font-mono text-gray-500">{payment.receiptNumber}</span>
                </p>
              </>
            );
          })()}
        </motion.div>
      </main>
    </div>
  );
}
