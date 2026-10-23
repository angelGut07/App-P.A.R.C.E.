// Servicio de mapas y geocodificación

const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const NOMINATIM_REVERSE_URL = 'https://nominatim.openstreetmap.org/reverse';
const OSRM_URL = 'https://router.project-osrm.org/route/v1/driving';

export interface Coordinates {
  lat: number;
  lng: number;
}

export interface GeocodingResult {
  lat: string;
  lon: string;
  display_name: string;
  address?: {
    road?: string;
    house_number?: string;
    city?: string;
    state?: string;
    country?: string;
  };
}

export interface RouteResult {
  distance: number; // metros
  duration: number; // segundos
  coordinates: [number, number][]; // [lng, lat] formato OSRM
}

/**
 * Busca direcciones usando Nominatim (OpenStreetMap)
 */
export async function searchAddress(query: string): Promise<GeocodingResult[]> {
  if (!query || query.length < 3) return [];

  try {
    const params = new URLSearchParams({
      q: query,
      format: 'json',
      addressdetails: '1',
      limit: '5',
      countrycodes: 'co', // Colombia
    });

    const response = await fetch(`${NOMINATIM_URL}?${params}`, {
      headers: {
        'User-Agent': 'PARCE-App/1.0',
      },
    });

    if (!response.ok) {
      throw new Error('Error en la búsqueda de dirección');
    }

    return await response.json();
  } catch (error) {
    console.error('Error buscando dirección:', error);
    return [];
  }
}

/**
 * Geocodificación inversa: convierte coordenadas a dirección
 */
export async function reverseGeocode(lat: number, lng: number): Promise<string> {
  try {
    const params = new URLSearchParams({
      lat: lat.toString(),
      lon: lng.toString(),
      format: 'json',
      addressdetails: '1',
      zoom: '18',
    });

    const response = await fetch(`${NOMINATIM_REVERSE_URL}?${params}`, {
      headers: {
        'User-Agent': 'PARCE-App/1.0',
      },
    });

    if (!response.ok) {
      throw new Error('Error en geocodificación inversa');
    }

    const data: GeocodingResult = await response.json();
    return data.display_name || `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
  } catch (error) {
    console.error('Error en geocodificación inversa:', error);
    // Retornar coordenadas como fallback
    return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
  }
}

/**
 * Calcula la ruta entre dos puntos usando OSRM
 */
export async function calculateRoute(
  from: Coordinates,
  to: Coordinates
): Promise<RouteResult | null> {
  try {
    // OSRM usa formato lng,lat
    const coords = `${from.lng},${from.lat};${to.lng},${to.lat}`;
    const params = new URLSearchParams({
      overview: 'full',
      geometries: 'geojson',
      steps: 'true',
    });

    const response = await fetch(`${OSRM_URL}/${coords}?${params}`);

    if (!response.ok) {
      throw new Error('Error calculando ruta');
    }

    const data = await response.json();

    if (data.code !== 'Ok' || !data.routes || data.routes.length === 0) {
      throw new Error('No se encontró ruta');
    }

    const route = data.routes[0];

    return {
      distance: route.distance, // metros
      duration: route.duration, // segundos
      coordinates: route.geometry.coordinates, // [lng, lat][]
    };
  } catch (error) {
    console.error('Error calculando ruta:', error);
    return null;
  }
}

/**
 * Calcula la distancia en línea recta entre dos puntos (Haversine)
 */
export function calculateDistance(from: Coordinates, to: Coordinates): number {
  const R = 6371; // Radio de la Tierra en km
  const dLat = toRad(to.lat - from.lat);
  const dLng = toRad(to.lng - from.lng);

  const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(toRad(from.lat)) *
      Math.cos(toRad(to.lat)) *
      Math.sin(dLng / 2) *
      Math.sin(dLng / 2);

  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return R * c; // Distancia en km
}

function toRad(degrees: number): number {
  return degrees * (Math.PI / 180);
}

/**
 * Formatea la duración en minutos o horas
 */
export function formatDuration(seconds: number): string {
  const minutes = Math.round(seconds / 60);

  if (minutes < 60) {
    return `${minutes} min`;
  }

  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;

  return `${hours}h ${remainingMinutes}min`;
}

/**
 * Formatea la distancia en metros o kilómetros
 */
export function formatDistance(meters: number): string {
  if (meters < 1000) {
    return `${Math.round(meters)} m`;
  }

  return `${(meters / 1000).toFixed(1)} km`;
}
