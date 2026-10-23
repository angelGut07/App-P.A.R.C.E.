import { motion } from 'framer-motion';
import Navbar from '../Navbar';
import Sidebar from '../Sidebar';
import { useAuth } from '../../../controllers/AuthContext';

/** Marco común de las páginas de administración: navbar, menú lateral y título. */
export default function AdminLayout({ title, subtitle, actions, children }: {
  title: string;
  subtitle?: string;
  actions?: React.ReactNode;
  children: React.ReactNode;
}) {
  const { user } = useAuth();
  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Administrador'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24 md:ml-64">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h1 className="text-2xl sm:text-3xl font-bold text-white">{title}</h1>
              {subtitle && <p className="text-gray-400 text-sm mt-1">{subtitle}</p>}
            </div>
            {actions}
          </div>
          {children}
        </motion.div>
      </main>
    </div>
  );
}

export function StatCard({ label, value, icon: Icon, color }: { label: string; value: React.ReactNode; icon: React.ElementType; color: string }) {
  return (
    <div className="card p-5 flex items-center gap-4">
      <div className={`w-11 h-11 rounded-lg bg-gradient-to-br ${color} flex items-center justify-center`}>
        <Icon className="w-5 h-5 text-white" />
      </div>
      <div>
        <p className="text-2xl font-bold text-white">{value}</p>
        <p className="text-gray-400 text-xs">{label}</p>
      </div>
    </div>
  );
}

export function SearchBox({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
  return (
    <input value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder}
      className="input-field w-full sm:w-72 text-sm" />
  );
}
