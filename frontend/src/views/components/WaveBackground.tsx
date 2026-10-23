// Importa hooks de React: useEffect para efectos secundarios, useRef para referencias persistentes y useState para manejar estado
import { useEffect, useRef, useState } from 'react';

// Componente principal que renderiza un fondo animado con ondas que brillan al pasar el mouse
export default function WaveBackground() {
  // Referencia al elemento canvas donde se dibujará la animación
  const canvasRef = useRef<HTMLCanvasElement>(null);
  // Almacena la posición actual del mouse (inicializada fuera del área visible)
  const mouseRef = useRef({ x: -1, y: -1 });
  // Almacena el ID del frame de animación para poder cancelarlo después
  const animRef = useRef<number>(0);
  // Estado que indica si el mouse está sobre el canvas
  const [hovered, setHovered] = useState(false);

  // Effect hook que se ejecuta al montar el componente
  useEffect(() => {
    // Obtiene la referencia al elemento canvas
    const canvas = canvasRef.current;
    // Si no existe el canvas, termina la ejecución
    if (!canvas) return;
    // Obtiene el contexto 2D del canvas para poder dibujar
    const ctx = canvas.getContext('2d');
    // Si no se puede obtener el contexto, termina la ejecución
    if (!ctx) return;

    // Variables que almacenan las dimensiones del canvas
    let width = 0;
    let height = 0;
    // Variable que controla el tiempo de la animación (se incrementa en cada frame)
    let t = 0;

    // Función que ajusta el tamaño del canvas al tamaño de la ventana
    const resize = () => {
      // Establece el ancho y altura del canvas iguales a las dimensiones de la ventana
      width = canvas.width = window.innerWidth;
      height = canvas.height = window.innerHeight;
    };
    // Ejecuta el redimensionamiento inicial
    resize();
    // Agrega un listener para redimensionar el canvas cuando cambie el tamaño de la ventana
    window.addEventListener('resize', resize);

    // Define el número total de ondas a dibujar
    const WAVE_COUNT = 8;

    // Función principal de animación que se ejecuta en cada frame
    const draw = () => {
      // Limpia el canvas en cada frame para redibujar
      ctx.clearRect(0, 0, width, height);

      // Obtiene las coordenadas actuales del mouse
      const mx = mouseRef.current.x;
      const my = mouseRef.current.y;
      // Determina si el mouse está dentro del área visible del canvas
      const isHovering = mx >= 0 && my >= 0;

      // Dibuja el fondo negro profundo
      ctx.fillStyle = '#050505';
      ctx.fillRect(0, 0, width, height);

      // Recorre cada onda para dibujarla con propiedades únicas
      for (let i = 0; i < WAVE_COUNT; i++) {
        // Calcula el progreso de 0 a 1 para cada onda
        const progress = i / (WAVE_COUNT - 1); // 0..1
        // Calcula la posición vertical base de cada onda (distribuidas verticalmente)
        const yBase = height * (0.25 + progress * 0.5);
        // Amplitud de la onda: qué tan alto oscila (aumenta con cada onda)
        const amp = 38 + i * 6;
        // Frecuencia: qué tan rápido oscila horizontalmente (aumenta con cada onda)
        const freq = 0.0018 + i * 0.0003;
        // Velocidad de animación: qué tan rápido se mueve en el tiempo (aumenta con cada onda)
        const speed = 0.004 + i * 0.0008;
        // Desfase inicial para que las ondas no estén sincronizadas
        const phase = t * speed + i * 0.7;

        // Calcula el factor de brillo basado en la distancia del mouse a esta onda
        let glowFactor = 0;
        if (isHovering) {
          // Distancia vertical entre el mouse y la posición base de esta onda
          const dist = Math.abs(my - yBase);
          // Factor de brillo que disminuye con la distancia (máximo 1, mínimo 0)
          glowFactor = Math.max(0, 1 - dist / 160);
        }

        // Calcula el color base: gris antracita oscuro que transiciona a amarillo dorado al pasar el mouse
        const baseL = 14 + i * 2; // Luminosidad base que aumenta con cada onda
        // Calcula los componentes RGB: gris oscuro base + amarillo dorado según glowFactor
        const r = Math.round(baseL * 2.2 + glowFactor * 210); // Rojo aumenta con brillo
        const g = Math.round(baseL * 2.2 + glowFactor * 165); // Verde aumenta con brillo
        const b = Math.round(baseL * 2.2 + glowFactor * 0);    // Azul permanece bajo
        // Opacidad que aumenta con cada onda y con el brillo del mouse
        const alpha = 0.45 + i * 0.05 + glowFactor * 0.35;

        // Inicia un nuevo camino de dibujo para la onda
        ctx.beginPath();
        // Mueve el punto inicial a la posición base vertical de la onda
        ctx.moveTo(0, yBase);

        // Dibuja la forma de la onda recorriendo el ancho del canvas
        for (let x = 0; x <= width; x += 4) {
          // Calcula el desplazamiento vertical causado por el mouse (la onda se eleva cerca del cursor)
          let mouseWarp = 0;
          if (isHovering) {
            // Distancia horizontal entre el punto actual y el mouse
            const dx = x - mx;
            // Desplazamiento que disminuye exponencialmente con la distancia al mouse
            mouseWarp = glowFactor * 22 * Math.exp(-dx * dx / (2 * 12000));
          }
          // Calcula la posición Y final usando dos funciones seno superpuestas y el efecto del mouse
          const y = yBase + Math.sin(x * freq + phase) * amp                    // Onda principal
                          + Math.sin(x * freq * 1.7 + phase * 0.6) * (amp * 0.4) // Onda secundaria para complejidad
                          - mouseWarp;                                              // Desplazamiento por mouse
          // Dibuja una línea hasta el punto calculado
          ctx.lineTo(x, y);
        }

        // Aplica el estilo de línea con el color calculado
        ctx.strokeStyle = `rgba(${r},${g},${b},${alpha})`;
        // Grosor de línea que aumenta con el brillo del mouse
        ctx.lineWidth = 1.2 + glowFactor * 1.8;
        // Aplica sombra amarilla si hay brillo por el mouse
        ctx.shadowColor = glowFactor > 0.1
          ? `rgba(212,170,0,${glowFactor * 0.6})` // Color amarillo dorado con opacidad basada en brillo
          : 'transparent';                         // Sin sombra si no hay brillo
        // Intensidad del desenfoque de la sombra
        ctx.shadowBlur = glowFactor * 18;
        // Dibuja el trazo de la onda
        ctx.stroke();
        // Resetea el desenfoque de sombra para la siguiente onda
        ctx.shadowBlur = 0;
      }

      // Incrementa el contador de tiempo para animar las ondas
      t++;
      // Solicita el siguiente frame de animación y almacena su ID
      animRef.current = requestAnimationFrame(draw);
    };

    // Inicia la animación
    animRef.current = requestAnimationFrame(draw);

    // Función de limpieza que se ejecuta cuando el componente se desmonta
    return () => {
      // Cancela el frame de animación para detener el loop
      cancelAnimationFrame(animRef.current);
      // Remueve el listener del evento resize
      window.removeEventListener('resize', resize);
    };
  }, []); // Array vacío significa que el effect solo se ejecuta al montar/desmontar

  // Manejador que captura la posición del mouse cuando se mueve sobre el canvas
  const handleMouseMove = (e: React.MouseEvent<HTMLCanvasElement>) => {
    // Obtiene las dimensiones y posición del canvas en la ventana
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect) return;
    // Actualiza la posición del mouse relativa al canvas
    mouseRef.current = { x: e.clientX - rect.left, y: e.clientY - rect.top };
    // Marca que el mouse está sobre el canvas
    setHovered(true);
  };

  // Manejador que se ejecuta cuando el mouse sale del canvas
  const handleMouseLeave = () => {
    // Resetea la posición del mouse fuera del área visible
    mouseRef.current = { x: -1, y: -1 };
    // Marca que el mouse ya no está sobre el canvas
    setHovered(false);
  };

  // Renderiza el elemento canvas con estilos para que cubra toda la pantalla
  return (
    <canvas
      ref={canvasRef}                     // Asigna la referencia al canvas
      onMouseMove={handleMouseMove}        // Captura el movimiento del mouse
      onMouseLeave={handleMouseLeave}      // Detecta cuando el mouse sale
      className="fixed inset-0 w-full h-full pointer-events-auto" // Posicionamiento fijo, tamaño completo, intercepta eventos del mouse
      style={{
        zIndex: 0,                         // Coloca el canvas detrás de todos los demás elementos
        cursor: hovered ? 'crosshair' : 'default', // Cambia el cursor cuando está sobre el canvas
      }}
    />
  );
}
