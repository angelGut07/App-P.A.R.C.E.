import { useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Mail, ArrowLeft, CheckCircle, AlertCircle, Loader } from 'lucide-react';
import Logo from '../components/Logo';
import { authService, errorMessage } from '../../services';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    const response = await authService.forgotPassword(email);
    setLoading(false);

    if (response.success) {
      setSent(true);
    } else {
      setError(errorMessage(response, 'No se pudo procesar la solicitud'));
    }
  };

  return (
    <div className="min-h-screen bg-gradient-dark flex items-center justify-center px-4 relative overflow-hidden">
      <div className="absolute inset-0">
        <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-gold-500/10 rounded-full blur-3xl animate-pulse-slow" />
        <div className="absolute bottom-1/4 right-1/4 w-96 h-96 bg-anthracite-500/10 rounded-full blur-3xl animate-pulse-slow" style={{ animationDelay: '1s' }} />
      </div>

      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5 }}
        className="relative w-full max-w-md"
      >
        <Link to="/login" className="inline-flex items-center gap-2 text-gray-400 hover:text-white mb-4 transition-colors">
          <ArrowLeft className="w-5 h-5" /> Volver al login
        </Link>

        <div className="card p-8 space-y-6">
          <div className="flex justify-center">
            <Logo size="md" />
          </div>

          {sent ? (
            <motion.div initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} className="text-center space-y-4">
              <CheckCircle className="w-16 h-16 text-green-400 mx-auto" />
              <h2 className="text-2xl font-bold text-white">Revisa tu correo</h2>
              <p className="text-gray-400 text-sm">Si <span className="text-white">{email}</span> está registrado, te enviamos un enlace para restablecer tu contraseña. El enlace vence en 1 hora.</p>
              <Link to="/login" className="block w-full btn-outline text-center mt-4">Volver al login</Link>
            </motion.div>
          ) : (
            <>
              <div className="text-center">
                <h2 className="text-2xl font-bold text-white mb-2">Olvidé mi contraseña</h2>
                <p className="text-gray-400 text-sm">Ingresa tu correo y te enviaremos un enlace para restablecerla</p>
              </div>

              {error && (
                <motion.div
                  initial={{ opacity: 0, y: -10 }}
                  animate={{ opacity: 1, y: 0 }}
                  className="bg-red-500/20 border border-red-500/50 rounded-lg p-4 flex items-start gap-3"
                >
                  <AlertCircle className="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
                  <p className="text-sm text-red-300">{error}</p>
                </motion.div>
              )}

              <form onSubmit={handleSubmit} className="space-y-4">
                <div className="space-y-2">
                  <label className="block text-sm font-medium text-gray-300">Correo electrónico</label>
                  <div className="relative">
                    <Mail className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-500" />
                    <input
                      type="email"
                      value={email}
                      onChange={e => setEmail(e.target.value)}
                      placeholder="example@gmail.com"
                      className="input-field pl-10"
                      required
                      disabled={loading}
                    />
                  </div>
                </div>
                <button
                  type="submit"
                  className="w-full btn-primary flex items-center justify-center gap-2"
                  disabled={loading}
                >
                  {loading ? (
                    <>
                      <Loader className="w-4 h-4 animate-spin" />
                      Procesando...
                    </>
                  ) : (
                    'Enviar enlace'
                  )}
                </button>
              </form>
              <Link to="/login" className="block text-center text-gray-500 hover:text-gray-300 transition-colors text-sm">
                Volver al inicio de sesión
              </Link>
            </>
          )}
        </div>
      </motion.div>
    </div>
  );
}
