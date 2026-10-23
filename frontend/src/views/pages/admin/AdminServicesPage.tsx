import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ClipboardList, Receipt } from 'lucide-react';
import AdminLayout, { SearchBox } from '../../components/admin/AdminLayout';
import { Alert, Badge, EmptyState, Pagination } from '../../components/ui';
import { adminService, errorMessage } from '../../../services';
import type { AdminServiceRequest, RequestStatus } from '@/models';
import { PAYMENT_STATUS, REQUEST_STATUS, formatCOP, formatDate, fullName } from '../../format';

/** Todas las solicitudes de servicio, con cliente, mecánico y estado del pago. */
export default function AdminServicesPage() {
  const [rows, setRows] = useState<AdminServiceRequest[]>([]);
  const [status, setStatus] = useState<RequestStatus | ''>('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    const res = await adminService.serviceRequests({ status, search: search.trim(), page });
    if (res.success && res.data) {
      setRows(res.data.serviceRequests);
      setTotalPages(res.data.totalPages);
      setTotal(res.data.total);
    } else {
      setError(errorMessage(res, 'No se pudieron cargar los servicios'));
    }
  }, [status, search, page]);

  useEffect(() => {
    const t = setTimeout(load, search ? 400 : 0);
    return () => clearTimeout(t);
  }, [load, search]);

  return (
    <AdminLayout title="Servicios" subtitle={`${total} solicitudes de asistencia registradas`}>
      <div className="flex flex-wrap gap-3">
        <SearchBox value={search} onChange={v => { setSearch(v); setPage(1); }} placeholder="Código, cliente o mecánico" />
        <select value={status} onChange={e => { setStatus(e.target.value as RequestStatus | ''); setPage(1); }} className="input-field w-auto text-sm">
          <option value="">Todos los estados</option>
          {(Object.keys(REQUEST_STATUS) as RequestStatus[]).map(s => <option key={s} value={s}>{REQUEST_STATUS[s].label}</option>)}
        </select>
      </div>

      {error && <Alert type="error">{error}</Alert>}

      {rows.length === 0 ? (
        <EmptyState icon={ClipboardList}>No hay servicios con esos filtros.</EmptyState>
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-gray-400 border-b border-anthracite-800">
              <tr>
                <th className="p-3">Servicio</th><th className="p-3">Cliente</th><th className="p-3">Mecánico</th>
                <th className="p-3">Estado</th><th className="p-3">Pago</th><th className="p-3">Solicitado</th><th className="p-3"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-anthracite-800">
              {rows.map(r => (
                <tr key={r.id} className="text-gray-300">
                  <td className="p-3"><p className="text-white font-medium">{r.serviceTypeName}</p><p className="text-xs text-gray-500">{r.serviceCode} · {r.vehicleLicensePlate ?? '—'}</p></td>
                  <td className="p-3">{fullName(r.customerFirstName, r.customerLastName)}</td>
                  <td className="p-3">{r.mechanicId ? fullName(r.mechanicFirstName, r.mechanicLastName) : <span className="text-gray-500">Sin asignar</span>}</td>
                  <td className="p-3"><Badge className={REQUEST_STATUS[r.status].color}>{REQUEST_STATUS[r.status].label}</Badge></td>
                  <td className="p-3">
                    {r.paymentStatus
                      ? <span className="flex items-center gap-2"><Badge className={PAYMENT_STATUS[r.paymentStatus].color}>{PAYMENT_STATUS[r.paymentStatus].label}</Badge>{formatCOP(r.paymentAmount)}</span>
                      : <span className="text-gray-500">—</span>}
                  </td>
                  <td className="p-3 text-xs">{formatDate(r.requestedAt)}</td>
                  <td className="p-3">
                    {r.paymentStatus && (
                      <Link to={`/payment/receipt?request=${r.id}`} className="text-gray-400 hover:text-white" title="Comprobante"><Receipt className="w-4 h-4" /></Link>
                    )}
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
