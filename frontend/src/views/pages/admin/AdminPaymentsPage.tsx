import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { DollarSign, Clock, CheckCircle, Receipt, XCircle } from 'lucide-react';
import AdminLayout, { StatCard } from '../../components/admin/AdminLayout';
import { Alert, Badge, EmptyState, Pagination } from '../../components/ui';
import { errorMessage, paymentService } from '../../../services';
import type { PaymentListItem, PaymentMethod, PaymentStatus } from '@/models';
import { PAYMENT_METHOD, PAYMENT_STATUS, formatCOP, formatDate, fullName } from '../../format';

/** Historial de pagos de toda la plataforma; el administrador puede resolver pagos pendientes. */
export default function AdminPaymentsPage() {
  const [payments, setPayments] = useState<PaymentListItem[]>([]);
  const [status, setStatus] = useState<PaymentStatus | ''>('');
  const [method, setMethod] = useState<PaymentMethod | ''>('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    const res = await paymentService.list({ status, method, page });
    if (res.success && res.data) {
      setPayments(res.data.payments);
      setTotalPages(res.data.totalPages);
      setTotal(res.data.total);
    } else {
      setError(errorMessage(res, 'No se pudieron cargar los pagos'));
    }
  }, [status, method, page]);

  useEffect(() => { load(); }, [load]);

  const review = async (p: PaymentListItem, confirm: boolean) => {
    const res = confirm ? await paymentService.confirm(p.serviceRequestId) : await paymentService.reject(p.serviceRequestId);
    if (!res.success) setError(errorMessage(res, 'No se pudo actualizar el pago'));
    load();
  };

  const confirmedAmount = payments.filter(p => p.status === 'confirmed').reduce((s, p) => s + p.amount, 0);

  return (
    <AdminLayout title="Historial de Pagos" subtitle={`${total} pagos registrados`}>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard label="Confirmado (esta página)" value={formatCOP(confirmedAmount)} icon={DollarSign} color="from-green-500 to-green-600" />
        <StatCard label="Pendientes (esta página)" value={payments.filter(p => p.status === 'pending').length} icon={Clock} color="from-yellow-500 to-amber-600" />
        <StatCard label="Confirmados (esta página)" value={payments.filter(p => p.status === 'confirmed').length} icon={CheckCircle} color="from-blue-500 to-blue-600" />
      </div>

      <div className="flex flex-wrap gap-3">
        <select value={status} onChange={e => { setStatus(e.target.value as PaymentStatus | ''); setPage(1); }} className="input-field w-auto text-sm">
          <option value="">Todos los estados</option>
          {(Object.keys(PAYMENT_STATUS) as PaymentStatus[]).map(s => <option key={s} value={s}>{PAYMENT_STATUS[s].label}</option>)}
        </select>
        <select value={method} onChange={e => { setMethod(e.target.value as PaymentMethod | ''); setPage(1); }} className="input-field w-auto text-sm">
          <option value="">Todos los métodos</option>
          {(Object.keys(PAYMENT_METHOD) as PaymentMethod[]).map(m => <option key={m} value={m}>{PAYMENT_METHOD[m]}</option>)}
        </select>
      </div>

      {error && <Alert type="error">{error}</Alert>}

      {payments.length === 0 ? (
        <EmptyState icon={DollarSign}>No hay pagos con esos filtros.</EmptyState>
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-gray-400 border-b border-anthracite-800">
              <tr>
                <th className="p-3">Recibo</th><th className="p-3">Servicio</th><th className="p-3">Cliente</th><th className="p-3">Mecánico</th>
                <th className="p-3">Método</th><th className="p-3">Monto</th><th className="p-3">Estado</th><th className="p-3"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-anthracite-800">
              {payments.map(p => (
                <tr key={p.id} className="text-gray-300">
                  <td className="p-3"><p className="font-mono text-gold-400">{p.receiptNumber}</p><p className="text-xs text-gray-500">{formatDate(p.createdAt)}</p></td>
                  <td className="p-3"><p>{p.serviceTypeName}</p><p className="text-xs text-gray-500">{p.serviceCode}</p></td>
                  <td className="p-3">{fullName(p.customerFirstName, p.customerLastName)}</td>
                  <td className="p-3">{fullName(p.mechanicFirstName, p.mechanicLastName)}</td>
                  <td className="p-3">{PAYMENT_METHOD[p.method]}</td>
                  <td className="p-3"><p className="text-white font-semibold">{formatCOP(p.amount)}</p>{p.partsTotal > 0 && <p className="text-xs text-orange-300">Repuestos {formatCOP(p.partsTotal)}</p>}</td>
                  <td className="p-3"><Badge className={PAYMENT_STATUS[p.status].color}>{PAYMENT_STATUS[p.status].label}</Badge></td>
                  <td className="p-3">
                    <div className="flex items-center gap-2 justify-end">
                      {p.status === 'pending' && (
                        <>
                          <button onClick={() => review(p, true)} className="text-green-400 hover:text-green-300" title="Confirmar"><CheckCircle className="w-4 h-4" /></button>
                          <button onClick={() => review(p, false)} className="text-red-400 hover:text-red-300" title="Rechazar"><XCircle className="w-4 h-4" /></button>
                        </>
                      )}
                      <Link to={`/payment/receipt?request=${p.serviceRequestId}`} className="text-gray-400 hover:text-white" title="Comprobante"><Receipt className="w-4 h-4" /></Link>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination page={page} totalPages={totalPages} onChange={setPage} />
    </AdminLayout>
  );
}
