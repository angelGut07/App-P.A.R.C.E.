import { Navigate } from 'react-router-dom';
import { useAuth, homePathFor, UserRole } from '../../controllers/AuthContext';

interface ProtectedRouteProps {
  children: React.ReactNode;
  /** Vistas que pueden entrar a esta ruta */
  allowedRoles?: UserRole[];
}

/**
 * Protege una ruta: sin sesión manda al login; con una vista no permitida
 * manda al inicio de la vista activa. Si el usuario tiene acceso a la vista
 * pedida (ej. un mecánico que también es cliente), la activa.
 */
export default function ProtectedRoute({ children, allowedRoles }: ProtectedRouteProps) {
  const { user, isAuthenticated, isLoading } = useAuth();

  if (isLoading) {
    return (
      <div className="min-h-screen bg-dark-950 flex items-center justify-center">
        <div className="w-10 h-10 border-4 border-gold-500 border-t-transparent rounded-full animate-spin" />
      </div>
    );
  }

  if (!isAuthenticated || !user) {
    return <Navigate to="/login" replace />;
  }

  if (allowedRoles && !allowedRoles.includes(user.role)) {
    return <Navigate to={homePathFor(user.role)} replace />;
  }

  return <>{children}</>;
}
