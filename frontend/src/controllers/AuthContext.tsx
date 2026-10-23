import { createContext, useCallback, useContext, useEffect, useState, ReactNode } from 'react';
import type { AppRole, UserProfile } from '@/models';
import { authService, errorMessage, setUnauthorizedHandler } from '@/services';

export type UserRole = AppRole;

/** Usuario autenticado tal como lo usan las vistas. */
export interface AuthUser {
  id: number;
  name: string;
  email: string;
  avatar?: string;
  /** Vista activa (menú y páginas que ve) */
  role: AppRole;
  /** Vistas a las que tiene acceso según sus roles reales */
  availableRoles: AppRole[];
  profile: UserProfile;
}

interface AuthContextType {
  user: AuthUser | null;
  isAuthenticated: boolean;
  /** true mientras se verifica la sesión al cargar la app */
  isLoading: boolean;
  login: (email: string, password: string) => Promise<{ ok: boolean; error?: string; user?: AuthUser }>;
  logout: () => Promise<void>;
  /** Vuelve a leer el perfil (después de editarlo o de un cambio de rol) */
  refresh: () => Promise<void>;
  /** Cambia de vista entre las permitidas (cliente ↔ mecánico) */
  selectRole: (role: AppRole) => void;
}

const VIEW_ROLE_KEY = 'parce_view_role';

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function homePathFor(role: AppRole): string {
  return role === 'admin' ? '/dashboard' : role === 'mechanic' ? '/mechanic-home' : '/home';
}

function availableRolesOf(profile: UserProfile): AppRole[] {
  if (profile.roles.some(r => r === 'administrator' || r === 'super_admin')) return ['admin'];
  const roles: AppRole[] = [];
  if (profile.roles.includes('mechanic')) roles.push('mechanic');
  if (profile.roles.includes('customer') || roles.length === 0) roles.push('user');
  return roles;
}

function toAuthUser(profile: UserProfile): AuthUser {
  const availableRoles = availableRolesOf(profile);
  let saved: string | null = null;
  try { saved = localStorage.getItem(VIEW_ROLE_KEY); } catch { /* almacenamiento no disponible */ }
  const role = availableRoles.includes(saved as AppRole) ? (saved as AppRole) : availableRoles[0];

  return {
    id: profile.id,
    name: `${profile.firstName} ${profile.lastName}`.trim(),
    email: profile.email,
    avatar: profile.profilePictureUrl ?? undefined,
    role,
    availableRoles,
    profile,
  };
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const refresh = useCallback(async () => {
    const res = await authService.me();
    setUser(res.success && res.data ? toAuthUser(res.data) : null);
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => setUser(null));
    refresh().finally(() => setIsLoading(false));
    return () => setUnauthorizedHandler(null);
  }, [refresh]);

  const login = async (email: string, password: string) => {
    const res = await authService.login(email, password);
    if (!res.success || !res.data?.user) {
      return { ok: false, error: errorMessage(res, 'Correo o contraseña incorrectos') };
    }
    const authUser = toAuthUser(res.data.user);
    setUser(authUser);
    return { ok: true, user: authUser };
  };

  const logout = async () => {
    await authService.logout();
    try { localStorage.removeItem(VIEW_ROLE_KEY); } catch { /* sin almacenamiento */ }
    setUser(null);
  };

  const selectRole = (role: AppRole) => {
    if (!user || !user.availableRoles.includes(role)) return;
    try { localStorage.setItem(VIEW_ROLE_KEY, role); } catch { /* sin almacenamiento */ }
    setUser({ ...user, role });
  };

  return (
    <AuthContext.Provider value={{ user, isAuthenticated: !!user, isLoading, login, logout, refresh, selectRole }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth debe usarse dentro de AuthProvider');
  }
  return context;
}
