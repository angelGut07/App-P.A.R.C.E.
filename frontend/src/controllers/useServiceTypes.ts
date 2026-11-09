import { useEffect, useState } from 'react';
import type { ServiceType } from '@/models';
import { catalogService } from '@/services';

/** Catálogo de tipos de servicio activos (GET /api/service-types). */
export function useServiceTypes(): ServiceType[] {
  const [serviceTypes, setServiceTypes] = useState<ServiceType[]>([]);

  useEffect(() => {
    let cancelled = false;
    catalogService.serviceTypes().then(res => {
      if (!cancelled && res.success && res.data) setServiceTypes(res.data.serviceTypes);
    });
    return () => { cancelled = true; };
  }, []);

  return serviceTypes;
}
