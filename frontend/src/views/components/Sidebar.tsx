import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import {
  Home, Wrench, Phone, ClipboardList, User,
  LayoutDashboard, Users, Star, MessageSquare,
  DollarSign, ChevronDown, ChevronRight, Bot, Car, Package, Repeat, History
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth, homePathFor } from '../../controllers/AuthContext';

interface SidebarProps {
  isOpen?: boolean;
  hidden?: boolean;
}

// Sección colapsable del sidebar del admin
function AdminSection({ icon: Icon, label, children, isOpen: sidebarOpen }: {
  icon: React.ElementType;
  label: string;
  children: React.ReactNode;
  isOpen: boolean;
}) {
  const [expanded, setExpanded] = useState(false);
  return (
    <div>
      <button
        onClick={() => setExpanded(!expanded)}
        className="w-full flex items-center justify-between px-3 py-2.5 rounded-lg text-gray-400 hover:bg-dark-800 hover:text-white transition-colors group"
      >
        <div className="flex items-center gap-3">
          <Icon className="w-5 h-5 flex-shrink-0" />
          {sidebarOpen && <span className="text-sm font-medium">{label}</span>}
        </div>
        {sidebarOpen && (
          <motion.div animate={{ rotate: expanded ? 180 : 0 }} transition={{ duration: 0.2 }}>
            <ChevronDown className="w-4 h-4" />
          </motion.div>
        )}
      </button>
      <AnimatePresence>
        {expanded && sidebarOpen && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2 }}
            className="overflow-hidden pl-4"
          >
            {children}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

export default function Sidebar({ isOpen = true, hidden = false }: SidebarProps) {
  const location = useLocation();
  const { user, selectRole } = useAuth();
  const navigate = useNavigate();

  // Quien es cliente y mecánico a la vez puede cambiar de vista
  const otherRole = user?.availableRoles.find(r => r !== user.role);
  const switchRole = () => {
    if (!otherRole) return;
    selectRole(otherRole);
    navigate(homePathFor(otherRole));
  };

  const isPathActive = (path: string) => location.pathname === path;

  const linkClass = (path: string) =>
    `flex items-center gap-3 px-3 py-2.5 rounded-lg transition-colors text-sm ${
      isPathActive(path)
        ? 'bg-gold-500/20 text-gold-400 border border-gold-500/30'
        : 'text-gray-400 hover:bg-dark-800 hover:text-white'
    }`;

  const subLinkClass = (path: string) =>
    `flex items-center gap-2 px-3 py-2 rounded-lg transition-colors text-sm ${
      isPathActive(path)
        ? 'bg-gold-500/20 text-gold-400'
        : 'text-gray-500 hover:bg-dark-800 hover:text-gray-300'
    }`;

  if (hidden) return null;

  return (
    <motion.aside
      initial={{ x: -300 }}
      animate={{ x: isOpen ? 0 : -300 }}
      transition={{ type: 'spring', damping: 20, stiffness: 300 }}
      className={`fixed left-0 top-16 h-[calc(100vh-4rem)] bg-dark-900/50 backdrop-blur-sm border-r border-anthracite-800 transition-all duration-300 overflow-y-auto z-40 ${
        isOpen ? 'w-64' : 'hidden'
      } md:z-10 md:block md:translate-x-0`}
    >
      <nav className="p-3 space-y-1">
        {/* ── ADMIN ── */}
        {user?.role === 'admin' && (
          <>
            {/* Dashboard */}
            <Link to="/dashboard" className={linkClass('/dashboard')}>
              <LayoutDashboard className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Dashboard</span>}
            </Link>

            {isOpen && <div className="pt-2 pb-1 px-3"><p className="text-xs text-gray-600 uppercase tracking-wider font-semibold">Gestión</p></div>}

            {/* Usuarios */}
            <AdminSection icon={Users} label="Usuarios" isOpen={isOpen}>
              <Link to="/admin/users" className={subLinkClass('/admin/users')}>
                <ChevronRight className="w-3.5 h-3.5" /> Gestión de Usuarios
              </Link>
            </AdminSection>

            {/* Mecánicos */}
            <AdminSection icon={Wrench} label="Mecánicos" isOpen={isOpen}>
              <Link to="/admin/mechanics" className={subLinkClass('/admin/mechanics')}>
                <ChevronRight className="w-3.5 h-3.5" /> Gestión de Mecánicos
              </Link>
            </AdminSection>

            {isOpen && <div className="pt-2 pb-1 px-3"><p className="text-xs text-gray-600 uppercase tracking-wider font-semibold">Operaciones</p></div>}

            {/* Servicios */}
            <AdminSection icon={ClipboardList} label="Servicios" isOpen={isOpen}>
              <Link to="/admin/services" className={subLinkClass('/admin/services')}>
                <ChevronRight className="w-3.5 h-3.5" /> Solicitudes de servicio
              </Link>
            </AdminSection>

            {/* Vehículos */}
            <AdminSection icon={Car} label="Vehículos" isOpen={isOpen}>
              <Link to="/admin/vehicles" className={subLinkClass('/admin/vehicles')}>
                <ChevronRight className="w-3.5 h-3.5" /> Gestión de Vehículos
              </Link>
            </AdminSection>

            {/* Inventario */}
            <AdminSection icon={Package} label="Inventario" isOpen={isOpen}>
              <Link to="/admin/inventory" className={subLinkClass('/admin/inventory')}>
                <ChevronRight className="w-3.5 h-3.5" /> Consultar Inventario
              </Link>
              <Link to="/admin/merchandise" className={subLinkClass('/admin/merchandise')}>
                <ChevronRight className="w-3.5 h-3.5" /> Ingreso de Mercancía
              </Link>
            </AdminSection>

            {/* Pagos */}
              <AdminSection icon={DollarSign} label="Pagos" isOpen={isOpen}>
                <Link to="/admin/payments" className={subLinkClass('/admin/payments')}>
                  <ChevronRight className="w-3.5 h-3.5" /> Historial de Pagos
                </Link>

              </AdminSection>

            {/* Calificaciones */}
            <AdminSection icon={Star} label="Calificaciones" isOpen={isOpen}>
              <Link to="/admin/ratings" className={subLinkClass('/admin/ratings')}>
                <ChevronRight className="w-3.5 h-3.5" /> Ver Calificaciones
              </Link>
              <Link to="/admin/ratings/providers" className={subLinkClass('/admin/ratings/providers')}>
                <ChevronRight className="w-3.5 h-3.5" /> Por Proveedor
              </Link>
            </AdminSection>

            {isOpen && <div className="pt-2 pb-1 px-3"><p className="text-xs text-gray-600 uppercase tracking-wider font-semibold">Soporte</p></div>}

            {/* PQR */}
            <AdminSection icon={MessageSquare} label="PQR" isOpen={isOpen}>
              <Link to="/admin/pqr/users" className={subLinkClass('/admin/pqr/users')}>
                <ChevronRight className="w-3.5 h-3.5" /> PQR Usuarios
              </Link>
              <Link to="/admin/pqr/mechanics" className={subLinkClass('/admin/pqr/mechanics')}>
                <ChevronRight className="w-3.5 h-3.5" /> PQR Mecánicos
              </Link>
            </AdminSection>

            {/* Chatbot / Ayuda */}
            <Link to="/admin/chatbot" className={linkClass('/admin/chatbot')}>
              <Bot className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Ayuda / Chatbot</span>}
            </Link>
          </>
        )}

        {/* ── USUARIO ── */}
        {user?.role === 'user' && (
          <>
            <Link to="/home" className={linkClass('/home')}>
              <Home className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Inicio</span>}
            </Link>
            <Link to="/services" className={linkClass('/services')}>
              <Wrench className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Servicios</span>}
            </Link>
            <Link to="/my-services" className={linkClass('/my-services')}>
              <History className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Mis servicios</span>}
            </Link>
            <Link to="/profile" className={linkClass('/profile')}>
              <User className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Mi Perfil</span>}
            </Link>
            <Link to="/pqr" className={linkClass('/pqr')}>
              <MessageSquare className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>PQR</span>}
            </Link>
            <Link to="/contact" className={linkClass('/contact')}>
              <Phone className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Contacto</span>}
            </Link>
          </>
        )}

        {/* ── MECÁNICO ── */}
        {user?.role === 'mechanic' && (
          <>
            <Link to="/mechanic-home" className={linkClass('/mechanic-home')}>
              <Home className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Inicio</span>}
            </Link>

            <Link to="/mechanic-orders" className={linkClass('/mechanic-orders')}>
              <ClipboardList className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Órdenes</span>}
            </Link>

            <Link to="/mechanic-payments" className={linkClass('/mechanic-payments')}>
              <DollarSign className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Pagos</span>}
            </Link>
            <Link to="/mechanic-pqr" className={linkClass('/mechanic-pqr')}>
              <MessageSquare className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>PQR</span>}
            </Link>
            <Link to="/mechanic-profile" className={linkClass('/mechanic-profile')}>
              <User className="w-5 h-5 flex-shrink-0" />
              {isOpen && <span>Mi Perfil</span>}
            </Link>
          </>
        )}
        {otherRole && (
          <button onClick={switchRole} className="w-full flex items-center gap-3 px-3 py-2.5 mt-4 rounded-lg text-sm text-gold-400 hover:bg-dark-800 border border-gold-500/20">
            <Repeat className="w-5 h-5 flex-shrink-0" />
            {isOpen && <span>Usar como {otherRole === 'mechanic' ? 'mecánico' : 'cliente'}</span>}
          </button>
        )}
      </nav>
    </motion.aside>
  );
}
