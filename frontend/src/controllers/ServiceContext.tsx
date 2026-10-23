import { createContext, useContext, useState, ReactNode } from 'react';
import type { EmergencyType } from '@/models';

/** Servicio elegido en el catálogo, mientras el cliente completa la solicitud. */
export interface ServiceData {
  code: EmergencyType;
  title: string;
  description: string;
  duration: string;
  basePrice: number;
  /** Diagnóstico del asistente, si el cliente lo usó */
  chatbotDiagnosis?: string;
}

interface ServiceContextType {
  selectedService: ServiceData | null;
  setSelectedService: (s: ServiceData | null) => void;
}

const ServiceContext = createContext<ServiceContextType | undefined>(undefined);

export function ServiceProvider({ children }: { children: ReactNode }) {
  const [selectedService, setSelectedService] = useState<ServiceData | null>(null);

  return (
    <ServiceContext.Provider value={{ selectedService, setSelectedService }}>
      {children}
    </ServiceContext.Provider>
  );
}

export function useService() {
  const ctx = useContext(ServiceContext);
  if (!ctx) throw new Error('useService debe usarse dentro de ServiceProvider');
  return ctx;
}
