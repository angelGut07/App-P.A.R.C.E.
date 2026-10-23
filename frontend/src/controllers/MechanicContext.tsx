import { createContext, useContext, useState, ReactNode } from 'react';

/**
 * Disponibilidad del mecánico ("activo para recibir solicitudes"). Es una
 * preferencia de la vista: mientras está activo, la página de órdenes
 * consulta las solicitudes cercanas. Se recuerda en este navegador.
 */
interface MechanicContextType {
  isActive: boolean;
  setIsActive: (v: boolean) => void;
}

const STORAGE_KEY = 'parce_mechanic_active';
const MechanicContext = createContext<MechanicContextType | undefined>(undefined);

function readSaved(): boolean {
  try { return localStorage.getItem(STORAGE_KEY) === '1'; } catch { return false; }
}

export function MechanicProvider({ children }: { children: ReactNode }) {
  const [isActive, setActive] = useState(readSaved);

  const setIsActive = (value: boolean) => {
    setActive(value);
    try { localStorage.setItem(STORAGE_KEY, value ? '1' : '0'); } catch { /* sin almacenamiento */ }
  };

  return (
    <MechanicContext.Provider value={{ isActive, setIsActive }}>
      {children}
    </MechanicContext.Provider>
  );
}

export function useMechanic() {
  const ctx = useContext(MechanicContext);
  if (!ctx) throw new Error('useMechanic debe usarse dentro de MechanicProvider');
  return ctx;
}
