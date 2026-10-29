// Importa el hook useState para manejar estados locales del componente
import { useState } from 'react';
// Importa Link para navegación sin recargar página, useNavigate para navegación programática
import { Link, useNavigate } from 'react-router-dom';
// Importa iconos de menú, cierre, usuario y logout de lucide-react
import { Menu, X, User, LogOut, History, ClipboardList, MessageSquare } from 'lucide-react';
// Importa motion para animaciones y AnimatePresence para animar entrada/salida de elementos
import { motion, AnimatePresence } from 'framer-motion';
// Importa el hook de autenticación para acceder al usuario actual y función de logout
import { useAuth } from '../../controllers/AuthContext';

// Define las propiedades que puede recibir el Navbar desde otros componentes
interface NavbarProps {
  isAuthenticated?: boolean;  // Si el usuario está autenticado
  userName?: string;           // Nombre del usuario para mostrar
  userAvatar?: string;         // URL del avatar del usuario
  hideNavLinks?: boolean;      // Si es true, oculta los links de navegación (Servicios, Contacto)
}

// Componente principal del Navbar con valores por defecto para sus props
export default function Navbar({ isAuthenticated = false, userName, userAvatar, hideNavLinks = false }: NavbarProps) {
  // Obtiene el usuario actual y la función de logout del contexto de autenticación
  const { user, logout: authLogout } = useAuth();
  // Estado para controlar si el menú móvil está abierto o cerrado
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  // Estado para controlar si el menú desplegable del usuario está visible
  const [showUserMenu, setShowUserMenu] = useState(false);
  // Estado para controlar si el modal de confirmación de logout está visible
  const [showLogoutModal, setShowLogoutModal] = useState(false);
  // Hook para navegar programáticamente entre rutas
  const navigate = useNavigate();

  // Función que cierra el menú del usuario y muestra el modal de confirmación de logout
  const handleLogout = () => {
    setShowUserMenu(false);
    setShowLogoutModal(true);
  };

  // Función que ejecuta el logout real, cierra el modal y redirige al inicio
  const confirmLogout = async () => {
    await authLogout();
    setShowLogoutModal(false);
    navigate('/login', { replace: true });
  };

  // Función que cancela el logout y cierra el modal
  const cancelLogout = () => {
    setShowLogoutModal(false);
  };

  // Función que determina a qué ruta va el botón de perfil según el rol del usuario
  const getProfilePath = () => {
    return user?.role === 'mechanic' ? '/mechanic-profile' : '/profile';
  };

  // Guarda la ruta del perfil calculada
  const profilePath = getProfilePath();

  // Accesos del menú de usuario: varias páginas ocultan el sidebar, así que
  // las secciones principales (incluida PQR) también están aquí
  const menuLinks = user?.role === 'user'
    ? [
        { to: '/my-services', label: 'Mis servicios', icon: History },
        { to: '/pqr', label: 'PQR', icon: MessageSquare },
      ]
    : user?.role === 'mechanic'
      ? [
          { to: '/mechanic-orders', label: 'Órdenes', icon: ClipboardList },
          { to: '/mechanic-pqr', label: 'PQR', icon: MessageSquare },
        ]
      : [];

  return (
    // Barra de navegación fija en la parte superior, con fondo semitransparente y blur
    <nav className="fixed top-0 left-0 right-0 z-50 bg-dark-950/80 backdrop-blur-lg border-b border-anthracite-800">
      {/* Navbar pegado a los bordes, sin max-width centrado */}
      {/* Contenedor a ancho completo con padding lateral */}
      <div className="w-full px-3 sm:px-4 md:px-6">
        {/* Fila principal del navbar con logo a la izquierda y links a la derecha */}
        <div className="flex items-center justify-between h-14 sm:h-16">

          {/* Logo P.A.R.C.E - Redirige según rol del usuario */}
          {/* Usuario → /home | Mecánico → /mechanic-orders | Otros → / */}
          <Link to={user?.role === 'user' ? '/home' : user?.role === 'mechanic' ? '/mechanic-home' : user?.role === 'admin' ? '/dashboard' : '/'} className="flex-shrink-0">
            <span className="text-xl sm:text-2xl font-bold text-gradient">P.A.R.C.E</span>
          </Link>

          {/* Navegación de escritorio - solo visible en pantallas medianas y grandes */}
          <div className="hidden md:flex items-center gap-6 md:gap-8">
            {/* Si NO está autenticado: muestra links públicos */}
            {!isAuthenticated ? (
              <>
                {/* Link a servicios — apunta a /services (redirige a login si no está autenticado) */}
                <Link to="/services" className="text-gray-300 hover:text-white transition-colors text-sm">
                  Servicios
                </Link>
                {/* Link a página de contacto */}
                <Link to="/contact" className="text-gray-300 hover:text-white transition-colors text-sm">
                  Contacto
                </Link>
                {/* Botón de iniciar sesión con estilo outline */}
                <Link to="/login" className="btn-outline py-2 px-4 text-sm">
                  Iniciar Sesión
                </Link>
                {/* Botón de registro con estilo primario */}
                <Link to="/register" className="btn-primary py-2 px-4 text-sm">
                  Registro
                </Link>
              </>
            ) : user?.role === 'admin' || user?.role === 'mechanic' || hideNavLinks ? (
              // Si es admin, mecánico o hideNavLinks: solo muestra el menú de usuario sin links
              <>
                <div className="relative ml-auto">
                  {/* Botón que muestra el avatar y nombre del usuario */}
                  <button
                    onClick={() => setShowUserMenu(!showUserMenu)}
                    className="flex items-center gap-2 p-2 rounded-lg hover:bg-dark-800 transition-colors"
                  >
                    {/* Si tiene avatar lo muestra, si no muestra ícono de usuario */}
                    {userAvatar ? (
                      <img src={userAvatar} alt={userName} className="w-8 h-8 rounded-full" />
                    ) : (
                      <div className="w-8 h-8 rounded-full bg-gradient-to-br from-gold-500 to-gold-700 flex items-center justify-center">
                        <User className="w-5 h-5 text-anthracite-950" />
                      </div>
                    )}
                    {/* Muestra el nombre del usuario */}
                    <span className="text-gray-300 text-sm hidden sm:inline">{userName}</span>
                  </button>

                  {/* Menú desplegable del usuario con animación de entrada/salida */}
                  <AnimatePresence>
                    {showUserMenu && (
                      <motion.div
                        initial={{ opacity: 0, y: -10 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -10 }}
                        className="absolute right-0 mt-2 w-48 bg-dark-900 border border-anthracite-800 rounded-lg shadow-xl overflow-hidden"
                      >
                        {user && (
                          <Link
                            to={profilePath}
                            className="flex items-center gap-2 px-4 py-3 text-gray-300 hover:bg-dark-800 transition-colors text-sm"
                            onClick={() => setShowUserMenu(false)}
                          >
                            <User className="w-4 h-4" />
                            Perfil
                          </Link>
                        )}
                        {menuLinks.map(link => (
                          <Link
                            key={link.to}
                            to={link.to}
                            className="flex items-center gap-2 px-4 py-3 text-gray-300 hover:bg-dark-800 transition-colors text-sm"
                            onClick={() => setShowUserMenu(false)}
                          >
                            <link.icon className="w-4 h-4" />
                            {link.label}
                          </Link>
                        ))}
                        {/* Botón de cerrar sesión en rojo */}
                        <button
                          onClick={handleLogout}
                          className="w-full flex items-center gap-2 px-4 py-3 text-red-400 hover:bg-dark-800 transition-colors text-sm"
                        >
                          <LogOut className="w-4 h-4" />
                          Cerrar Sesión
                        </button>
                      </motion.div>
                    )}
                  </AnimatePresence>
                </div>
              </>
            ) : (
              // Si es usuario autenticado normal: muestra links según hideNavLinks
              <>
                {/* Servicios y Contacto solo visibles cuando hideNavLinks es false */}
                {/* Esto permite que ServicesPage los oculte pero UserHomePage los muestre */}
                {!hideNavLinks && (
                  <>
                    <Link to="/services" className="text-gray-300 hover:text-white transition-colors text-sm">
                      Servicios
                    </Link>
                    <Link to="/contact" className="text-gray-300 hover:text-white transition-colors text-sm">
                      Contacto
                    </Link>
                  </>
                )}
                {/* Menú de usuario con avatar y nombre */}
                <div className="relative">
                  <button
                    onClick={() => setShowUserMenu(!showUserMenu)}
                    className="flex items-center gap-2 p-2 rounded-lg hover:bg-dark-800 transition-colors"
                  >
                    {userAvatar ? (
                      <img src={userAvatar} alt={userName} className="w-8 h-8 rounded-full" />
                    ) : (
                      <div className="w-8 h-8 rounded-full bg-gradient-to-br from-gold-500 to-gold-700 flex items-center justify-center">
                        <User className="w-5 h-5 text-anthracite-950" />
                      </div>
                    )}
                    <span className="text-gray-300 text-sm hidden sm:inline">{userName}</span>
                  </button>

                  {/* Menú desplegable animado con opciones de perfil y logout */}
                  <AnimatePresence>
                    {showUserMenu && (
                      <motion.div
                        initial={{ opacity: 0, y: -10 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -10 }}
                        className="absolute right-0 mt-2 w-48 bg-dark-900 border border-anthracite-800 rounded-lg shadow-xl overflow-hidden"
                      >
                        <Link
                          to={profilePath}
                          className="flex items-center gap-2 px-4 py-3 text-gray-300 hover:bg-dark-800 transition-colors text-sm"
                          onClick={() => setShowUserMenu(false)}
                        >
                          <User className="w-4 h-4" />
                          Perfil
                        </Link>
                      {menuLinks.map(link => (
                        <Link
                          key={link.to}
                          to={link.to}
                          className="flex items-center gap-2 px-4 py-3 text-gray-300 hover:bg-dark-800 transition-colors text-sm"
                          onClick={() => setShowUserMenu(false)}
                        >
                          <link.icon className="w-4 h-4" />
                          {link.label}
                        </Link>
                      ))}
                        <button
                          onClick={handleLogout}
                          className="w-full flex items-center gap-2 px-4 py-3 text-red-400 hover:bg-dark-800 transition-colors text-sm"
                        >
                          <LogOut className="w-4 h-4" />
                          Cerrar Sesión
                        </button>
                      </motion.div>
                    )}
                  </AnimatePresence>
                </div>
              </>
            )}
          </div>

          {/* Botón de menú hamburguesa para móvil - solo visible en pantallas pequeñas */}
          <button
            onClick={() => setIsMenuOpen(!isMenuOpen)}
            className="md:hidden p-2 rounded-lg hover:bg-dark-800 transition-colors"
          >
            {/* Muestra X si el menú está abierto, hamburguesa si está cerrado */}
            {isMenuOpen ? (
              <X className="w-5 h-5 sm:w-6 sm:h-6 text-gray-300" />
            ) : (
              <Menu className="w-5 h-5 sm:w-6 sm:h-6 text-gray-300" />
            )}
          </button>
        </div>
      </div>

      {/* Menú móvil desplegable con animación de altura */}
      <AnimatePresence>
        {isMenuOpen && (
          <motion.div
            initial={{ opacity: 0, height: 0 }}
            animate={{ opacity: 1, height: 'auto' }}
            exit={{ opacity: 0, height: 0 }}
            className="md:hidden bg-dark-900 border-t border-anthracite-800"
          >
            <div className="px-3 sm:px-4 py-3 sm:py-4 space-y-2">
              {/* Links del menú móvil según estado de autenticación */}
              {!isAuthenticated ? (
                <>
                  {/* Link a servicios en móvil */}
                  <Link to="/services" className="block px-4 py-2 text-gray-300 hover:bg-dark-800 rounded-lg text-sm">
                    Servicios
                  </Link>
                  <Link to="/contact" className="block px-4 py-2 text-gray-300 hover:bg-dark-800 rounded-lg text-sm">
                    Contacto
                  </Link>
                  <Link to="/login" className="block px-4 py-2 text-primary-400 hover:bg-dark-800 rounded-lg text-sm">
                    Iniciar Sesión
                  </Link>
                  <Link to="/register" className="block px-4 py-2 bg-primary-600 text-white rounded-lg text-center text-sm">
                    Registro
                  </Link>
                </>
              ) : hideNavLinks ? (
                <>
                  {user && (
                    <Link to={profilePath} className="block px-4 py-2 text-gray-300 hover:bg-dark-800 rounded-lg text-sm">
                      Perfil
                    </Link>
                  )}
                  {menuLinks.map(link => (
                    <Link key={link.to} to={link.to} className="block px-4 py-2 text-gray-300 hover:bg-dark-800 rounded-lg text-sm">
                      {link.label}
                    </Link>
                  ))}
                  <button
                    onClick={handleLogout}
                    className="w-full text-left px-4 py-2 text-red-400 hover:bg-dark-800 rounded-lg text-sm"
                  >
                    Cerrar Sesión
                  </button>
                </>
              ) : (
                // Usuario autenticado con links según hideNavLinks
                <>
                  {/* En móvil también respeta hideNavLinks */}
                  {!hideNavLinks && (
                    <>
                      <Link to="/services" className="block px-4 py-2 text-gray-300 hover:bg-dark-800 rounded-lg text-sm">
                        Servicios
                      </Link>
                      <Link to="/contact" className="block px-4 py-2 text-gray-300 hover:bg-dark-800 rounded-lg text-sm">
                        Contacto
                      </Link>
                    </>
                  )}
                  <Link to={profilePath} className="block px-4 py-2 text-gray-300 hover:bg-dark-800 rounded-lg text-sm">
                    Perfil
                  </Link>
                  {menuLinks.map(link => (
                    <Link key={link.to} to={link.to} className="block px-4 py-2 text-gray-300 hover:bg-dark-800 rounded-lg text-sm">
                      {link.label}
                    </Link>
                  ))}
                  <button
                    onClick={handleLogout}
                    className="w-full text-left px-4 py-2 text-red-400 hover:bg-dark-800 rounded-lg text-sm"
                  >
                    Cerrar Sesión
                  </button>
                </>
              )}
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* Modal de confirmación de cierre de sesión */}
      <AnimatePresence>
        {showLogoutModal && (
          // Fondo oscuro semitransparente que cubre toda la pantalla
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 backdrop-blur-sm"
            style={{ marginLeft: 0 }}
            onClick={cancelLogout} // Click fuera del modal cancela el logout
          >
            {/* Tarjeta del modal con animación de escala */}
            <motion.div
              initial={{ scale: 0.9, opacity: 0, y: 20 }}
              animate={{ scale: 1, opacity: 1, y: 0 }}
              exit={{ scale: 0.9, opacity: 0, y: 20 }}
              onClick={(e) => e.stopPropagation()} // Evita que el click dentro cierre el modal
              className="bg-anthracite-950 border-2 border-anthracite-700 rounded-2xl p-8 w-full max-w-md shadow-2xl"
            >
              <div className="text-center space-y-6">
                {/* Ícono de logout en círculo rojo */}
                <div className="w-16 h-16 mx-auto bg-red-600/20 rounded-full flex items-center justify-center">
                  <LogOut className="w-8 h-8 text-red-500" />
                </div>

                <div>
                  <h3 className="text-2xl font-bold text-white mb-2">Cerrar Sesión</h3>
                  <p className="text-gray-400">¿Estás seguro de que deseas cerrar sesión?</p>
                </div>

                {/* Botones de cancelar y confirmar */}
                <div className="flex gap-3">
                  <button
                    onClick={cancelLogout}
                    className="flex-1 px-6 py-3 bg-anthracite-800 hover:bg-anthracite-700 text-white font-semibold rounded-lg transition-colors"
                  >
                    Cancelar
                  </button>
                  <button
                    onClick={confirmLogout}
                    className="flex-1 px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg transition-colors"
                  >
                    Cerrar Sesión
                  </button>
                </div>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>
    </nav>
  );
}
