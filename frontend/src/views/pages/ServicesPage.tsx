import { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { Fuel, Battery, Wrench, Clock, ChevronLeft, ChevronRight, MessageCircle, X, Send, Bot, User as UserIcon, Zap, Truck, Key, Tag } from 'lucide-react';
import Navbar from '../components/Navbar';
import Sidebar from '../components/Sidebar';
import { useAuth } from '../../controllers/AuthContext';
import { useService } from '../../controllers/ServiceContext';
import { catalogService, chatbotService, errorMessage } from '../../services';
import type { EmergencyType, ServiceType } from '@/models';
// Presentación de cada servicio (imagen, ícono y color). El nombre, la
// descripción, el precio base y la duración vienen de GET /api/service-types.
const SERVICE_VISUALS: Partial<Record<EmergencyType, { icon: React.ElementType; gradient: string; emoji: string; bgImage: string }>> = {
  fuel:    { icon: Fuel,    gradient: 'from-orange-500 to-red-600',    emoji: '⛽', bgImage: 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=600&q=80' },
  tire:    { icon: Wrench,  gradient: 'from-blue-500 to-cyan-600',     emoji: '🔧', bgImage: 'https://images.unsplash.com/photo-1580273916550-e323be2ae537?w=600&q=80' },
  battery: { icon: Battery, gradient: 'from-yellow-500 to-amber-600',  emoji: '🔋', bgImage: 'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?w=600&q=80' },
  engine:  { icon: Zap,     gradient: 'from-purple-500 to-violet-600', emoji: '🔍', bgImage: 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?w=600&q=80' },
  lockout: { icon: Key,     gradient: 'from-green-500 to-emerald-600', emoji: '🔑', bgImage: 'https://images.unsplash.com/photo-1502877338535-766e1452684a?w=600&q=80' },
  tow:     { icon: Truck,   gradient: 'from-red-500 to-rose-600',      emoji: '🚛', bgImage: 'https://images.unsplash.com/photo-1601584115197-04ecc0da31d7?w=600&q=80' },
};

interface ServiceCard {
  code: EmergencyType;
  title: string;
  description: string;
  duration: string;
  basePrice: number;
  icon: React.ElementType;
  gradient: string;
  emoji: string;
  bgImage: string;
}

function toCard(type: ServiceType): ServiceCard | null {
  const visual = SERVICE_VISUALS[type.code];
  if (!visual) return null;
  const { estimatedMinutesMin: min, estimatedMinutesMax: max } = type;
  return {
    code: type.code,
    title: type.name,
    description: type.description ?? '',
    duration: min && max ? `${min} – ${max} min` : 'Según el caso',
    basePrice: Number(type.basePrice),
    ...visual,
  };
}

const formatCOP = (n: number) =>
  new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(n);

/* ── CHATBOT ── */
// Define el tipo de mensaje del chat — puede ser del usuario o del bot
// suggestedService: si el bot sugiere un servicio, guarda el nombre para mostrar el botón
interface ChatMessage { role: 'user' | 'bot'; text: string; suggestedService?: string; }

// Lista de palabras prohibidas — filtra groserías antes de enviar al bot
const PROFANITY = [
  'mierda','hijueputa','puta','malparido','gonorrea','hp','marica',
  'idiota','imbecil','estupido','pendejo','verga','coño','joder','cabron','perra',
  'bobo','bruto','animal','bestia','fuck','shit','bitch','damn','crap',
  'bastard','asshole','dick','pussy','cunt','retrasado','mongolo','subnormal',
  'inutil','maldito','desgraciado','hdp','ptm','ctm','conchatumadre',
  'webon','weon','huevon','mamahuevo','tonto',
];

// Función que detecta groserías usando expresiones regulares con límite de palabra (\b)
// Esto evita falsos positivos como "vehículo" que contiene "culo"
const hasProfanity = (t: string) =>
  PROFANITY.some(w => new RegExp(`\\b${w}\\b`, 'i').test(t));


export default function ServicesPage() {
  const { user } = useAuth();
  const { setSelectedService } = useService();
  const navigate = useNavigate();
  const [currentPage, setCurrentPage] = useState(0);
  const [services, setServices] = useState<ServiceCard[]>([]);
  const [catalogError, setCatalogError] = useState('');
  const [chatOpen, setChatOpen] = useState(false);
  const [messages, setMessages] = useState<ChatMessage[]>([
    { role: 'bot', text: '👋 ¡Hola! Soy el asistente de PARCE. Si no sabes qué falla tiene tu vehículo, cuéntame los síntomas y te ayudo a identificar el problema.' },
  ]);
  const [inputText, setInputText] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  const itemsPerPage = 3;
  const totalPages = Math.ceil(services.length / itemsPerPage);
  const currentServices = services.slice(currentPage * itemsPerPage, (currentPage + 1) * itemsPerPage);

  useEffect(() => { messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [messages]);

  useEffect(() => {
    catalogService.serviceTypes().then(res => {
      if (res.success && res.data) {
        setServices(res.data.serviceTypes.map(toCard).filter((c): c is ServiceCard => c !== null));
      } else {
        setCatalogError(errorMessage(res, 'No se pudo cargar el catálogo de servicios'));
      }
    });
  }, []);

  const handleSend = async () => {
    const text = inputText.trim(); // Elimina espacios al inicio y final
    if (!text || isLoading) return; // No hace nada si está vacío o ya está cargando

    // Verifica si el mensaje tiene groserías antes de enviarlo
    if (hasProfanity(text)) {
      // Agrega el mensaje del usuario y la advertencia del bot
      setMessages(prev => [...prev,
        { role: 'user', text },
        { role: 'bot', text: '⚠️ Por favor mantén un lenguaje respetuoso. Las groserías no están permitidas en este chat. Estoy aquí para ayudarte con tu vehículo 😊' }
      ]);
      setInputText(''); // Limpia el campo de texto
      return; // No continúa al API
    }

    // Agrega el mensaje del usuario al chat inmediatamente
    setMessages(prev => [...prev, { role: 'user', text }]);
    setInputText('');    // Limpia el campo de texto
    setIsLoading(true);  // Activa el indicador de carga (3 puntitos)

    // Historial para el asistente (sin el saludo inicial del bot)
    const history = [...messages.slice(1), { role: 'user' as const, text }].map(m => ({
      role: m.role === 'user' ? 'user' as const : 'assistant' as const,
      content: m.text,
    }));

    const res = await chatbotService.reply(history);
    setIsLoading(false);

    if (!res.success || !res.data) {
      setMessages(prev => [...prev, { role: 'bot', text: `⚠️ ${errorMessage(res, 'El asistente no está disponible en este momento')}` }]);
      return;
    }

    // El asistente termina su respuesta con "SERVICIO_SUGERIDO: <nombre>"
    const rawReply = res.data.reply;
    const serviceMatch = rawReply.match(/SERVICIO_SUGERIDO:\s*(.+)/);
    const suggestedService = serviceMatch ? serviceMatch[1].trim() : undefined;
    const cleanReply = rawReply.replace(/SERVICIO_SUGERIDO:\s*.+/, '').trim();

    setMessages(prev => [...prev, { role: 'bot', text: cleanReply, suggestedService }]);  };

  // Función que se ejecuta cuando el usuario hace click en "Pedir: [servicio]" en el chat
  const handleRequestFromChat = (suggestedService: string, diagnosis: string) => {
    // Busca el servicio en la lista comparando nombres (insensible a mayúsculas)
    const found = services.find(s =>
      s.title.toLowerCase().includes(suggestedService.toLowerCase()) ||
      suggestedService.toLowerCase().includes(s.title.toLowerCase())
    ) || services.find(s => s.code === 'engine'); // Sin coincidencia: diagnóstico mecánico
    if (!found) return;

    setSelectedService({
      code: found.code,
      title: found.title,
      description: diagnosis,
      duration: found.duration,
      basePrice: found.basePrice,
      chatbotDiagnosis: diagnosis,
    });
    // Navega a la página de ubicación primero
    navigate('/service-location');
  };

  const handleServiceSelect = (service: ServiceCard) => {
    setSelectedService({ code: service.code, title: service.title, description: service.description, duration: service.duration, basePrice: service.basePrice });
    // Primero pide la ubicación antes de ir al servicio en curso
    navigate('/service-location');
  };

  return (
    <div className="min-h-screen bg-dark-950">
      {/* hideNavLinks oculta "Servicios" y "Contacto" del navbar en esta página */}
      <Navbar isAuthenticated userName={user?.name || 'Usuario'} hideNavLinks />
      <Sidebar hidden={true} />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">
          <div className="text-center mb-6 sm:mb-8">
            <h1 className="text-3xl sm:text-4xl font-bold text-white mb-2">SERVICIOS</h1>
            <p className="text-sm sm:text-base text-gray-400">Selecciona el servicio que necesitas o consulta con nuestro asistente</p>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
            {catalogError && <p className="text-red-400 text-sm col-span-full text-center">{catalogError}</p>}
            {currentServices.map((service, index) => (
              <motion.div key={service.code} initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.4, delay: index * 0.1 }}
                className="card overflow-hidden group cursor-pointer hover:ring-2 hover:ring-gold-500 transition-all duration-300">
                {/* Imagen */}
                <div className="relative h-40 sm:h-48 overflow-hidden bg-dark-800">
                  <img src={service.bgImage} alt={service.title}
                    className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500"
                    onError={(e) => { (e.currentTarget as HTMLImageElement).style.display = 'none'; }}
                  />
                  <div className={`absolute inset-0 bg-gradient-to-br ${service.gradient} opacity-50`} />
                  <div className="absolute inset-0 bg-gradient-to-t from-dark-900 via-transparent to-transparent" />
                  <div className="absolute top-3 sm:top-4 left-3 sm:left-4">
                    <div className={`w-10 h-10 sm:w-11 sm:h-11 bg-gradient-to-br ${service.gradient} rounded-xl flex items-center justify-center shadow-lg`}>
                      <service.icon className="w-4 h-4 sm:w-5 sm:h-5 text-white" />
                    </div>
                  </div>
                  <div className="absolute bottom-2 right-2 sm:bottom-3 sm:right-3 text-xl sm:text-2xl">{service.emoji}</div>
                </div>
                {/* Info */}
                <div className="p-4 sm:p-5 space-y-2 sm:space-y-3">
                  <h3 className="text-sm sm:text-base font-bold text-white leading-snug">{service.title}</h3>
                  <p className="text-xs sm:text-sm text-gray-400 line-clamp-2 sm:line-clamp-3 leading-relaxed">{service.description}</p>
                  <div className="flex items-center gap-1 sm:gap-2 pt-1">
                    <Clock className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-gold-500 flex-shrink-0" />
                    <span className="text-xs text-gray-400">Tiempo:</span>
                    <span className="text-xs sm:text-sm font-bold text-gold-400">{service.duration}</span>
                  </div>
                  <div className="flex items-center gap-1 sm:gap-2 pt-1">
                    <Tag className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-gold-500 flex-shrink-0" />
                    <span className="text-xs text-gray-400">Desde:</span>
                    <span className="text-xs sm:text-sm font-bold text-gold-400">{formatCOP(service.basePrice)}</span>
                  </div>
                  <button onClick={() => handleServiceSelect(service)} className="w-full btn-primary mt-2 text-sm">PEDIR</button>
                </div>
              </motion.div>
            ))}
          </div>

          {totalPages > 1 && (
            <div className="flex items-center justify-center gap-2 sm:gap-4 mt-6 sm:mt-8">
              <button onClick={() => setCurrentPage(Math.max(0, currentPage - 1))} disabled={currentPage === 0}
                className="p-2 sm:p-3 bg-dark-800 rounded-lg hover:bg-dark-700 disabled:opacity-50 transition-colors">
                <ChevronLeft className="w-5 h-5 sm:w-6 sm:h-6 text-gray-400" />
              </button>
              <div className="flex gap-1 sm:gap-2">
                {Array.from({ length: totalPages }).map((_, i) => (
                  <button key={i} onClick={() => setCurrentPage(i)}
                    className={`w-2 h-2 sm:w-3 sm:h-3 rounded-full transition-colors ${currentPage === i ? 'bg-gold-500' : 'bg-dark-700 hover:bg-dark-600'}`} />
                ))}
              </div>
              <button onClick={() => setCurrentPage(Math.min(totalPages - 1, currentPage + 1))} disabled={currentPage === totalPages - 1}
                className="p-2 sm:p-3 bg-dark-800 rounded-lg hover:bg-dark-700 disabled:opacity-50 transition-colors">
                <ChevronRight className="w-5 h-5 sm:w-6 sm:h-6 text-gray-400" />
              </button>
            </div>
          )}
        </motion.div>
      </main>

      {/* CHATBOT FLOTANTE */}
      <AnimatePresence>
        {chatOpen && (
          <motion.div initial={{ opacity: 0, y: 40, scale: 0.95 }} animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 40, scale: 0.95 }} transition={{ duration: 0.25 }}
            className="fixed bottom-24 right-4 sm:right-6 w-full sm:w-96 max-w-[calc(100vw-2rem)] max-h-[60vh] sm:max-h-[560px] flex flex-col bg-dark-900 border border-anthracite-700 rounded-2xl shadow-2xl z-50 overflow-hidden">
            <div className="flex items-center justify-between px-4 sm:px-5 py-3 sm:py-4 bg-gradient-to-r from-gold-600 to-gold-500">
              <div className="flex items-center gap-2 sm:gap-3">
                <div className="w-8 h-8 sm:w-9 sm:h-9 bg-white/20 rounded-full flex items-center justify-center flex-shrink-0">
                  <Bot className="w-4 h-4 sm:w-5 sm:h-5 text-anthracite-950" />
                </div>
                <div className="min-w-0">
                  <p className="text-anthracite-950 font-bold text-xs sm:text-sm truncate">Asistente PARCE</p>
                  <p className="text-anthracite-800 text-xs hidden sm:block">Diagnóstico de vehículos</p>
                </div>
              </div>
              <button onClick={() => setChatOpen(false)} className="p-1.5 hover:bg-white/20 rounded-lg transition-colors flex-shrink-0">
                <X className="w-4 h-4 sm:w-5 sm:h-5 text-anthracite-950" />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto p-3 sm:p-4 space-y-3 min-h-0">
              {messages.map((msg, i) => (
                <div key={i} className={`flex gap-2 ${msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'}`}>
                  <div className={`w-6 h-6 sm:w-7 sm:h-7 rounded-full flex-shrink-0 flex items-center justify-center text-xs sm:text-sm ${msg.role === 'bot' ? 'bg-gradient-to-br from-gold-500 to-gold-600' : 'bg-dark-600'}`}>
                    {msg.role === 'bot' ? <Bot className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-anthracite-950" /> : <UserIcon className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-white" />}
                  </div>
                  <div className={`max-w-[calc(100%-2rem)] sm:max-w-[75%] flex flex-col gap-2`}>
                    <div className={`px-3 sm:px-4 py-2 sm:py-2.5 rounded-2xl text-xs sm:text-sm leading-relaxed break-words ${msg.role === 'bot' ? 'bg-dark-800 text-gray-200 rounded-tl-sm' : 'bg-gold-600 text-anthracite-950 rounded-tr-sm'}`}>
                      {msg.text}
                    </div>
                    {/* Botón "Pedir servicio" cuando el bot sugiere uno */}
                    {msg.role === 'bot' && msg.suggestedService && (
                      <button
                        onClick={() => handleRequestFromChat(msg.suggestedService!, msg.text)}
                        className="self-start flex items-center gap-1.5 px-3 py-1.5 sm:px-4 sm:py-2 bg-gold-500 hover:bg-gold-600 text-anthracite-950 text-xs font-bold rounded-xl transition-colors whitespace-nowrap"
                      >
                        <Wrench className="w-3 h-3" />
                        <span className="hidden sm:inline">Pedir: {msg.suggestedService}</span>
                        <span className="sm:hidden">Pedir</span>
                      </button>
                    )}
                  </div>
                </div>
              ))}
              {/* Indicador de escritura mientras Groq (LLaMA) responde */}
              {isLoading && (
                <div className="flex gap-2">
                  <div className="w-6 h-6 sm:w-7 sm:h-7 rounded-full flex-shrink-0 flex items-center justify-center bg-gradient-to-br from-gold-500 to-gold-600">
                    <Bot className="w-3.5 h-3.5 sm:w-4 sm:h-4 text-anthracite-950" />
                  </div>
                  <div className="bg-dark-800 px-3 sm:px-4 py-2 sm:py-3 rounded-2xl rounded-tl-sm flex gap-1 items-center">
                    <span className="w-1.5 h-1.5 sm:w-2 sm:h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
                    <span className="w-1.5 h-1.5 sm:w-2 sm:h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
                    <span className="w-1.5 h-1.5 sm:w-2 sm:h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
                  </div>
                </div>
              )}
              <div ref={messagesEndRef} />
            </div>
            <div className="p-2 sm:p-3 border-t border-anthracite-700 bg-dark-900">
              <div className="flex gap-2">
                <input type="text" value={inputText} onChange={e => setInputText(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && handleSend()}
                  placeholder="Describe el problema..."
                  className="flex-1 bg-dark-800 border border-anthracite-700 rounded-xl px-3 sm:px-4 py-2 text-xs sm:text-sm text-white placeholder-gray-500 focus:outline-none focus:border-gold-500 transition-colors" />
                <button onClick={handleSend} disabled={!inputText.trim() || isLoading}
                  className="p-2 sm:p-2.5 bg-gold-500 hover:bg-gold-600 disabled:opacity-40 rounded-xl transition-colors flex-shrink-0">
                  <Send className="w-4 h-4 text-anthracite-950" />
                </button>
              </div>
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* Botón flotante */}
      <motion.button whileHover={{ scale: 1.1 }} whileTap={{ scale: 0.95 }}
        onClick={() => setChatOpen(prev => !prev)}
        className="fixed bottom-6 right-4 sm:right-6 w-12 h-12 sm:w-14 sm:h-14 bg-gradient-to-br from-gold-500 to-gold-600 rounded-full shadow-2xl flex items-center justify-center z-50">
        <AnimatePresence mode="wait">
          {chatOpen
            ? <motion.div key="x" initial={{ rotate: -90, opacity: 0 }} animate={{ rotate: 0, opacity: 1 }} exit={{ rotate: 90, opacity: 0 }}><X className="w-5 h-5 sm:w-6 sm:h-6 text-anthracite-950" /></motion.div>
            : <motion.div key="chat" initial={{ rotate: 90, opacity: 0 }} animate={{ rotate: 0, opacity: 1 }} exit={{ rotate: -90, opacity: 0 }}><MessageCircle className="w-5 h-5 sm:w-6 sm:h-6 text-anthracite-950" /></motion.div>
          }
        </AnimatePresence>
        {!chatOpen && <span className="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full text-white text-xs flex items-center justify-center font-bold">?</span>}
      </motion.button>
    </div>
  );
}
