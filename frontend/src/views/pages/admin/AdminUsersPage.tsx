import { useCallback, useEffect, useState } from 'react';
import { Users, UserCheck, UserX, Eye } from 'lucide-react';
import AdminLayout, { SearchBox, StatCard } from '../../components/admin/AdminLayout';
import { Alert, Badge, EmptyState, Modal, Pagination } from '../../components/ui';
import { adminService, errorMessage } from '../../../services';
import type { AdminUser } from '@/models';
import { formatDate, fullName } from '../../format';

type Status = AdminUser['accountStatus'];

const STATUS: Record<Status, { label: string; color: string }> = {
  active: { label: 'Activo', color: 'bg-green-500/20 text-green-400 border-green-500/30' },
  suspended: { label: 'Suspendido', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30' },
  deactivated: { label: 'Desactivado', color: 'bg-gray-500/20 text-gray-400 border-gray-500/30' },
  pending_verification: { label: 'Por verificar', color: 'bg-blue-500/20 text-blue-400 border-blue-500/30' },
};

const ROLE_LABEL: Record<string, string> = {
  customer: 'Cliente', mechanic: 'Mecánico', administrator: 'Administrador', super_admin: 'Super admin',
};

/** Gestión de usuarios: búsqueda, filtros y cambio de estado de la cuenta. */
export default function AdminUsersPage() {
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');
  const [selected, setSelected] = useState<AdminUser | null>(null);
  const [pendingChange, setPendingChange] = useState<{ user: AdminUser; status: 'active' | 'suspended' | 'deactivated' } | null>(null);

  const load = useCallback(async () => {
    const res = await adminService.users({ search: search.trim(), role, status, page });
    if (res.success && res.data) {
      setUsers(res.data.users);
      setTotal(res.data.total);
      setTotalPages(res.data.totalPages);
    } else {
      setError(errorMessage(res, 'No se pudieron cargar los usuarios'));
    }
  }, [search, role, status, page]);

  useEffect(() => {
    const t = setTimeout(load, search ? 400 : 0);
    return () => clearTimeout(t);
  }, [load, search]);

  const applyChange = async () => {
    if (!pendingChange) return;
    const res = await adminService.updateUserStatus(pendingChange.user.id, pendingChange.status);
    setPendingChange(null);
    if (!res.success) setError(errorMessage(res, 'No se pudo cambiar el estado'));
    load();
  };

  return (
    <AdminLayout title="Gestión de Usuarios" subtitle="Clientes, mecánicos y administradores de la plataforma">
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard label="Usuarios (filtro actual)" value={total} icon={Users} color="from-blue-500 to-blue-600" />
        <StatCard label="Activos en esta página" value={users.filter(u => u.accountStatus === 'active').length} icon={UserCheck} color="from-green-500 to-green-600" />
        <StatCard label="Suspendidos / desactivados" value={users.filter(u => u.accountStatus !== 'active').length} icon={UserX} color="from-red-500 to-red-600" />
      </div>

      <div className="flex flex-wrap gap-3">
        <SearchBox value={search} onChange={v => { setSearch(v); setPage(1); }} placeholder="Buscar por nombre, correo o cédula" />
        <select value={role} onChange={e => { setRole(e.target.value); setPage(1); }} className="input-field w-auto text-sm">
          <option value="">Todos los roles</option>
          {Object.entries(ROLE_LABEL).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
        </select>
        <select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} className="input-field w-auto text-sm">
          <option value="">Todos los estados</option>
          {(['active', 'suspended', 'deactivated'] as Status[]).map(s => <option key={s} value={s}>{STATUS[s].label}</option>)}
        </select>
      </div>

      {error && <Alert type="error">{error}</Alert>}

      {users.length === 0 ? (
        <EmptyState icon={Users}>No hay usuarios con esos filtros.</EmptyState>
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-gray-400 border-b border-anthracite-800">
              <tr>
                <th className="p-3">Usuario</th><th className="p-3">Roles</th><th className="p-3">Estado</th>
                <th className="p-3">Vehículos</th><th className="p-3">Último ingreso</th><th className="p-3 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-anthracite-800">
              {users.map(u => (
                <tr key={u.id} className="text-gray-300">
                  <td className="p-3">
                    <p className="text-white font-medium">{fullName(u.firstName, u.lastName)}</p>
                    <p className="text-xs text-gray-500">{u.email}</p>
                  </td>
                  <td className="p-3">{u.roles.map(r => ROLE_LABEL[r] ?? r).join(', ')}</td>
                  <td className="p-3"><Badge className={STATUS[u.accountStatus].color}>{STATUS[u.accountStatus].label}</Badge></td>
                  <td className="p-3">{u.vehicleCount}</td>
                  <td className="p-3 text-xs">{formatDate(u.lastLoginAt)}</td>
                  <td className="p-3">
                    <div className="flex justify-end gap-2">
                      <button onClick={() => setSelected(u)} className="p-1.5 text-gray-400 hover:text-white" title="Ver detalle"><Eye className="w-4 h-4" /></button>
                      <select value="" onChange={e => e.target.value && setPendingChange({ user: u, status: e.target.value as 'active' | 'suspended' | 'deactivated' })}
                        className="bg-dark-800 border border-anthracite-700 rounded-lg text-xs px-2">
                        <option value="">Cambiar estado</option>
                        {(['active', 'suspended', 'deactivated'] as const).filter(s => s !== u.accountStatus).map(s => (
                          <option key={s} value={s}>{STATUS[s].label}</option>
                        ))}
                      </select>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <Pagination page={page} totalPages={totalPages} onChange={setPage} />

      <Modal open={!!selected} onClose={() => setSelected(null)} title="Detalle del usuario">
        {selected && (
          <div className="space-y-2 text-sm">
            {selected.profilePictureUrl && <img src={selected.profilePictureUrl} alt="" className="w-20 h-20 rounded-full object-cover mx-auto" />}
            {[
              ['Nombre', fullName(selected.firstName, selected.lastName)],
              ['Correo', selected.email],
              ['Cédula', selected.idNumber ?? '—'],
              ['Teléfono', selected.phone ?? '—'],
              ['Roles', selected.roles.map(r => ROLE_LABEL[r] ?? r).join(', ')],
              ['Estado', STATUS[selected.accountStatus].label],
              ['Vehículos', String(selected.vehicleCount)],
              ['Registrado', formatDate(selected.createdAt)],
              ['Último ingreso', formatDate(selected.lastLoginAt)],
            ].map(([label, value]) => (
              <div key={label} className="flex justify-between gap-3"><span className="text-gray-400">{label}</span><span className="text-white text-right">{value}</span></div>
            ))}
          </div>
        )}
      </Modal>

      <Modal open={!!pendingChange} onClose={() => setPendingChange(null)} title="Cambiar estado de la cuenta">
        {pendingChange && (
          <>
            <p className="text-sm text-gray-300">
              ¿Cambiar la cuenta de <span className="text-white font-semibold">{fullName(pendingChange.user.firstName, pendingChange.user.lastName)}</span> a{' '}
              <span className="text-white font-semibold">{STATUS[pendingChange.status].label}</span>?
              {pendingChange.status !== 'active' && ' Se cerrarán sus sesiones abiertas y no podrá ingresar.'}
            </p>
            <div className="grid grid-cols-2 gap-3">
              <button onClick={() => setPendingChange(null)} className="py-2.5 bg-dark-700 rounded-xl text-white">Cancelar</button>
              <button onClick={applyChange} className="py-2.5 btn-primary">Confirmar</button>
            </div>
          </>
        )}
      </Modal>
    </AdminLayout>
  );
}
