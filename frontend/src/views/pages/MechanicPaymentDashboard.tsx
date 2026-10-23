import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { DollarSign, Clock, CheckCircle, XCircle, Receipt, Package } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { Alert, EmptyState, Pagination } from '../components/ui';
import { useAuth } from '../../controllers/AuthContext';
import { errorMessage, paymentService } from '../../services';
import type { PaymentListItem } from '@/models';
import { PAYMENT_METHOD, PAYMENT_STATUS, formatCOP, formatDate, fullName } from '../format';

/**
 * Pagos de los servicios del mecánico. Los pagos en efectivo quedan
 * pendientes hasta que el mecánico confirma que recibió el dinero.
 */
export default function MechanicPaymentDashboard() {
  const { user } = useAuth();
  const [payments, setPayments] = useState<PaymentListItem[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState<number | null>(null);

  const load = useCallback(async () => {
    const res = await paymentService.list({ page });
    if (res.success && res.data) {
      setPayments(res.data.payments);
      setTotalPages(res.data.totalPages);
    } else {
      setError(errorMessage(res, 'No se pudieron cargar los pagos'));
    }
  }, [page]);

  useEffect(() => { load(); }, [load]);

  const review = async (payment: PaymentListItem, confirm: boolean) => {
    setBusy(payment.serviceRequestId);
    const res = confirm
      ? await paymentService.confirm(payment.serviceRequestId)
      : await paymentService.reject(payment.serviceRequestId);
    setBusy(null);
    if (!res.success) setError(errorMessage(res, 'No se pudo actualizar el pago'));
    load();
  };

  const confirmed = payments.filter(p => p.status === 'confirmed');
  const totalEarned = confirmed.reduce((sum, p) => sum + p.amount, 0);
  const pending = payments.filter(p => p.status === 'pending').length;

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Mecánico'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24 md:ml-64">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-6 max-w-5xl mx-auto">
          <div>
            <h1 className="text-3xl font-bold text-white">Mis Pagos</h1>
            <p className="text-gray-400 text-sm mt-1">Confirma los pagos en efectivo que recibiste</p>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div className="card p-5 flex items-center gap-4">
              <DollarSign className="w-10 h-10 text-green-400" />
              <div><p className="text-2xl font-bold text-white">{formatCOP(totalEarned)}</p><p className="text-gray-400 text-xs">Total confirmado (esta página)</p></div>
            </div>
            <div className="card p-5 flex items-center gap-4">
              <Clock className="w-10 h-10 text-yellow-400" />
              <div><p className="text-2xl font-bold text-white">{pending}</p><p className="text-gray-400 text-xs">Por confirmar</p></div>
            </div>
            <div className="card p-5 flex items-center gap-4">
              <CheckCircle className="w-10 h-10 text-blue-400" />
              <div><p className="text-2xl font-bold text-white">{confirmed.length}</p><p className="text-gray-400 text-xs">Confirmados</p></div>
            </div>
          </div>

          {error && <Alert type="error">{error}</Alert>}

          {payments.length === 0 ? (
            <EmptyState icon={DollarSign}>Aún no tienes pagos registrados.</EmptyState>
          ) : (
            <div className="space-y-3">
              {payments.map(p => (
                <div key={p.id} className={`card p-5 border-l-4 ${p.status === 'confirmed' ? 'border-green-500' : p.status === 'pending' ? 'border-yellow-500' : 'border-red-500'}`}>
                  <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                      <p className="text-white font-bold">{p.serviceTypeName} <span className="text-gray-500 text-xs">{p.serviceCode}</span></p>
                      <p className="text-gray-400 text-sm">Cliente: {fullName(p.customerFirstName, p.customerLastName)}</p>
                      <p className="text-gray-500 text-xs">{formatDate(p.createdAt)} · {PAYMENT_METHOD[p.method]} · Recibo {p.receiptNumber}</p>
                      <div className="flex items-center gap-4 pt-1">
                        <span className="text-white font-bold text-lg">{formatCOP(p.amount)}</span>
                        {p.partsTotal > 0 && (
                          <span className="text-xs text-orange-300 flex items-center gap-1"><Package className="w-3 h-3" /> Repuestos {formatCOP(p.partsTotal)}</span>
                        )}
                      </div>
                    </div>
                    <div className="flex flex-col items-end gap-2">
                      <span className={`px-2 py-0.5 rounded-full text-xs border ${PAYMENT_STATUS[p.status].color}`}>{PAYMENT_STATUS[p.status].label}</span>
                      {p.status === 'pending' && (
                        <div className="flex gap-2">
                          <button disabled={busy !== null} onClick={() => review(p, true)}
                            className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm disabled:opacity-50">
                            <CheckCircle className="w-4 h-4" /> Recibí el pago
                          </button>
                          <button disabled={busy !== null} onClick={() => review(p, false)}
                            className="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-red-600/20 text-red-300 border border-red-500/30 text-sm disabled:opacity-50">
                            <XCircle className="w-4 h-4" /> No lo recibí
                          </button>
                        </div>
                      )}
                      <Link to={`/payment/receipt?request=${p.serviceRequestId}`} className="flex items-center gap-1 text-xs text-gray-400 hover:text-white">
                        <Receipt className="w-3.5 h-3.5" /> Comprobante
                      </Link>
                    </div>
                  </div>
                </div>
              ))}
              <Pagination page={page} totalPages={totalPages} onChange={setPage} />
            </div>
          )}
        </motion.div>
      </main>
    </div>
  );
}
