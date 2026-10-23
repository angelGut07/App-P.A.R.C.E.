import { useState, useRef, useEffect } from 'react';
import { motion } from 'framer-motion';
import { Bot, Send, User } from 'lucide-react';
import Navbar from '../../components/Navbar';
import Sidebar from '../../components/Sidebar';
import { useAuth } from '../../../controllers/AuthContext';

interface ChatMessage { role: 'user' | 'bot'; text: string; }

const FAQ: { q: string; a: string }[] = [
  { q: '¿Cómo suspendo o desactivo un usuario?', a: 'Ve a Gestión → Usuarios, busca el usuario y usa "Cambiar estado". Una cuenta suspendida o desactivada no puede iniciar sesión y se cierran sus sesiones abiertas. Para reactivarla, vuelve a ponerla en "Activo".' },
  { q: '¿Cómo apruebo a un mecánico?', a: 'Ve a Gestión → Mecánicos, pestaña Solicitudes. Revisa el certificado y la licencia; para aprobar, la licencia debe tener fecha de vencimiento vigente. Si la rechazas, escribe el motivo: el usuario lo verá en su perfil.' },
  { q: '¿Cómo veo las calificaciones de un mecánico?', a: 'En Calificaciones → Por proveedor verás el promedio, la distribución y la tendencia de cada mecánico. Haz clic en una tarjeta para ver cada calificación con su comentario.' },
  { q: '¿Cómo respondo una PQR?', a: 'Ve a PQR → Usuarios o Mecánicos, abre la PQR con la lupa, escribe la respuesta y envíala: queda marcada como resuelta. También puedes ponerla en revisión o rechazarla.' },
  { q: '¿Cómo veo los pagos pendientes?', a: 'Ve a Pagos → Historial de pagos y filtra por estado "Pendiente". Son pagos en efectivo que el mecánico aún no confirma; desde ahí también puedes confirmarlos o rechazarlos.' },
  { q: '¿Cómo registro mercancía de un proveedor?', a: 'Ve a Inventario → Ingreso de mercancía y pulsa "Registrar ingreso": elige el proveedor (o créalo), la factura, la fecha y los productos con sus cantidades. El stock se suma automáticamente.' },
  { q: '¿Cómo actualizo el stock o el precio de un producto?', a: 'Ve a Inventario → Consultar inventario y usa el lápiz del producto. El cambio de stock ahí es un ajuste manual; las entradas normales se registran como ingreso de mercancía.' },
];

const INITIAL_MESSAGES: ChatMessage[] = [
  { role: 'bot', text: '👋 Hola, soy el asistente de administración de P.A.R.C.E. Puedo ayudarte con preguntas sobre cómo usar el panel. ¿En qué te puedo ayudar?' },
];

export default function AdminChatbotPage() {
  const { user } = useAuth();
  const [messages, setMessages] = useState<ChatMessage[]>(INITIAL_MESSAGES);
  const [input, setInput] = useState('');
  const endRef = useRef<HTMLDivElement>(null);

  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }); }, [messages]);

  const handleSend = () => {
    const text = input.trim();
    if (!text) return;
    setMessages(prev => [...prev, { role: 'user', text }]);
    setInput('');

    // Busca coincidencia en FAQ
    const lower = text.toLowerCase();
    const match = FAQ.find(f =>
      f.q.toLowerCase().split(' ').some(w => w.length > 4 && lower.includes(w))
    );
    setTimeout(() => {
      setMessages(prev => [...prev, {
        role: 'bot',
        text: match
          ? match.a
          : 'No encontré una respuesta exacta para eso. Te recomiendo revisar la documentación del sistema o contactar al equipo de soporte técnico de P.A.R.C.E.',
      }]);
    }, 600);
  };

  return (
    <div className="min-h-screen bg-dark-950">
      <Navbar isAuthenticated userName={user?.name || 'Admin'} userAvatar={user?.avatar} hideNavLinks />
      <Sidebar hidden={true} />
      <main className="p-4 sm:p-6 md:p-8 pt-20 sm:pt-20 md:pt-24 max-w-2xl mx-auto">
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }} className="max-w-2xl mx-auto space-y-6">
          <div>
            <h1 className="text-3xl font-bold text-white">Ayuda / Chatbot</h1>
            <p className="text-gray-400 text-sm mt-1">Asistente para el uso del panel de administración</p>
          </div>

          {/* Preguntas rápidas */}
          <div className="space-y-2">
            <p className="text-xs text-gray-500 uppercase tracking-wider">Preguntas frecuentes</p>
            <div className="flex flex-wrap gap-2">
              {FAQ.map((f, i) => (
                <button key={i} onClick={() => {
                  setMessages(prev => [...prev, { role: 'user', text: f.q }, { role: 'bot', text: f.a }]);
                }}
                  className="px-3 py-1.5 bg-dark-800 hover:bg-dark-700 border border-anthracite-700 text-gray-300 text-xs rounded-lg transition-colors">
                  {f.q}
                </button>
              ))}
            </div>
          </div>

          {/* Chat */}
          <div className="card flex flex-col h-[420px]">
            <div className="flex items-center gap-3 p-4 border-b border-anthracite-700">
              <div className="w-9 h-9 bg-gradient-to-br from-gold-500 to-gold-600 rounded-full flex items-center justify-center">
                <Bot className="w-5 h-5 text-anthracite-950" />
              </div>
              <div>
                <p className="text-white font-bold text-sm">Asistente Admin</p>
                <p className="text-gray-500 text-xs">Panel de administración P.A.R.C.E</p>
              </div>
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-3">
              {messages.map((msg, i) => (
                <div key={i} className={`flex gap-2 ${msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'}`}>
                  <div className={`w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center ${msg.role === 'bot' ? 'bg-gradient-to-br from-gold-500 to-gold-600' : 'bg-dark-600'}`}>
                    {msg.role === 'bot' ? <Bot className="w-4 h-4 text-anthracite-950" /> : <User className="w-4 h-4 text-white" />}
                  </div>
                  <div className={`max-w-[80%] px-4 py-2.5 rounded-2xl text-sm leading-relaxed ${msg.role === 'bot' ? 'bg-dark-800 text-gray-200 rounded-tl-sm' : 'bg-gold-600 text-anthracite-950 rounded-tr-sm'}`}>
                    {msg.text}
                  </div>
                </div>
              ))}
              <div ref={endRef} />
            </div>

            <div className="p-3 border-t border-anthracite-700">
              <div className="flex gap-2">
                <input type="text" value={input} onChange={e => setInput(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && handleSend()}
                  placeholder="Escribe tu pregunta..."
                  className="flex-1 bg-dark-800 border border-anthracite-700 rounded-xl px-4 py-2.5 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-gold-500 transition-colors" />
                <button onClick={handleSend} disabled={!input.trim()}
                  className="p-2.5 bg-gold-500 hover:bg-gold-600 disabled:opacity-40 rounded-xl transition-colors">
                  <Send className="w-4 h-4 text-anthracite-950" />
                </button>
              </div>
            </div>
          </div>
        </motion.div>
      </main>
    </div>
  );
}
