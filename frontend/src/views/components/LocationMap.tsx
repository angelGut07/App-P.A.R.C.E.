import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/** Bogotá: ubicación por defecto cuando aún no hay punto elegido */
export const DEFAULT_CENTER = { lat: 4.7110, lng: -74.0721 };

interface LocationMapProps {
  /** Punto a mostrar (null = sin marcador, centrado en Bogotá) */
  point: { lat: number; lng: number } | null;
  /** Si se pasa, el usuario puede elegir el punto haciendo clic en el mapa */
  onPick?: (lat: number, lng: number) => void;
  /** Texto del popup del marcador */
  label?: string;
  zoom?: number;
}

const markerIcon = L.divIcon({
  className: '',
  html: '<div style="background:#eab308;width:22px;height:22px;border-radius:50%;border:4px solid #1f2937;box-shadow:0 2px 8px rgba(0,0,0,0.4)"></div>',
  iconSize: [22, 22],
  iconAnchor: [11, 11],
});

/**
 * Mapa de OpenStreetMap (Leaflet) con un único marcador. Sirve para que el
 * cliente elija dónde está y para mostrar esa ubicación al mecánico.
 */
export default function LocationMap({ point, onPick, label, zoom = 15 }: LocationMapProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<L.Map | null>(null);
  const markerRef = useRef<L.Marker | null>(null);
  const onPickRef = useRef(onPick);
  onPickRef.current = onPick;

  useEffect(() => {
    if (!containerRef.current || mapRef.current) return;

    const center = point ?? DEFAULT_CENTER;
    const map = L.map(containerRef.current).setView([center.lat, center.lng], point ? zoom : 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      maxZoom: 19,
    }).addTo(map);

    map.on('click', (e: L.LeafletMouseEvent) => onPickRef.current?.(e.latlng.lat, e.latlng.lng));

    mapRef.current = map;
    return () => {
      map.remove();
      mapRef.current = null;
      markerRef.current = null;
    };
    // El mapa se crea una sola vez; los cambios de punto se aplican abajo
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;

    if (!point) {
      markerRef.current?.remove();
      markerRef.current = null;
      return;
    }

    if (markerRef.current) {
      markerRef.current.setLatLng([point.lat, point.lng]);
    } else {
      markerRef.current = L.marker([point.lat, point.lng], { icon: markerIcon }).addTo(map);
    }
    if (label) markerRef.current.bindPopup(label);
    map.setView([point.lat, point.lng], Math.max(map.getZoom(), zoom));
  }, [point?.lat, point?.lng, label, zoom]);

  return (
    <div className="relative w-full h-full">
      <div ref={containerRef} className="w-full h-full" />
      {onPick && (
        <div className="absolute bottom-2 left-2 z-[400] px-3 py-1 rounded-lg bg-dark-900/90 text-xs text-gray-300 pointer-events-none">
          Haz clic en el mapa para marcar tu ubicación
        </div>
      )}
    </div>
  );
}
