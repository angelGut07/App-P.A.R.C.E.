import { useCallback, useEffect, useState } from 'react';
import { Car, ShieldAlert, ShieldCheck } from 'lucide-react';
import AdminLayout, { SearchBox, StatCard } from '../../components/admin/AdminLayout';
import { Alert, Badge, EmptyState, Pagination } from '../../components/ui';
import { adminService, errorMessage } from '../../../services';
import type { AdminVehicle } from '@/models';
import { formatDate, fullName } from '../../format';

function docBadge(date: string | null, number: string | null) {
  if (!date) return <Badge className="bg-gray-500/20 text-gray-400 border-gray-500/30">{number ? 'Sin fecha de vencimiento' : 'Sin registrar'}</Badge>;
  const expired = new Date(date).getTime() < Date.now();
  return (
    <Badge className={expired ? 'bg-red-500/20 text-red-400 border-red-500/30' : 'bg-green-500/20 text-green-400 border-green-500/30'}>
      {expired ? 'Vencido' : 'Vigente'} · {formatDate(date, false)}
    </Badge>
  );
}

const isExpired = (date: string | null) => !date || new Date(date).getTime() < Date.now();

/** Vehículos registrados en la plataforma con el estado de su SOAT y tecnomecánica. */
export default function AdminVehiclesPage() {
  const [vehicles, setVehicles] = useState<AdminVehicle[]>([]);
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    const res = await adminService.vehicles(search.trim(), page);
    if (res.success && res.data) {
      setVehicles(res.data.vehicles);
      setTotalPages(res.data.totalPages);
      setTotal(res.data.total);
    } else {
      setError(errorMessage(res, 'No se pudieron cargar los vehículos'));
    }
  }, [search, page]);

  useEffect(() => {
    const t = setTimeout(load, search ? 400 : 0);
    return () => clearTimeout(t);
  }, [load, search]);

  const withIssues = vehicles.filter(v => isExpired(v.soatExpirationDate) || isExpired(v.tecnomecanicaExpirationDate)).length;

  return (
    <AdminLayout title="Gestión de Vehículos" subtitle="Vehículos registrados por los usuarios">
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard label="Vehículos registrados" value={total} icon={Car} color="from-blue-500 to-blue-600" />
        <StatCard label="Documentos al día (esta página)" value={vehicles.length - withIssues} icon={ShieldCheck} color="from-green-500 to-green-600" />
        <StatCard label="Con documentos vencidos o faltantes" value={withIssues} icon={ShieldAlert} color="from-red-500 to-red-600" />
      </div>

      <SearchBox value={search} onChange={v => { setSearch(v); setPage(1); }} placeholder="Placa, marca, modelo o propietario" />
      {error && <Alert type="error">{error}</Alert>}

      {vehicles.length === 0 ? (
        <EmptyState icon={Car}>No hay vehículos con esa búsqueda.</EmptyState>
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-gray-400 border-b border-anthracite-800">
              <tr><th className="p-3">Vehículo</th><th className="p-3">Propietario</th><th className="p-3">SOAT</th><th className="p-3">Tecnomecánica</th><th className="p-3">Registrado</th></tr>
            </thead>
            <tbody className="divide-y divide-anthracite-800">
              {vehicles.map(v => (
                <tr key={v.id} className="text-gray-300">
                  <td className="p-3">
                    <p className="text-white font-medium">{v.make} {v.model} {v.year}</p>
                    <p className="text-xs text-gray-500 tracking-widest">{v.licensePlate} · {v.color ?? 'Sin color'}{v.isPrimary && ' · Principal'}</p>
                  </td>
                  <td className="p-3"><p>{fullName(v.ownerFirstName, v.ownerLastName)}</p><p className="text-xs text-gray-500">{v.ownerEmail}</p></td>
                  <td className="p-3">{docBadge(v.soatExpirationDate, v.soatNumber)}</td>
                  <td className="p-3">{docBadge(v.tecnomecanicaExpirationDate, v.tecnomecanicaNumber)}</td>
                  <td className="p-3 text-xs">{formatDate(v.createdAt, false)}</td>
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
