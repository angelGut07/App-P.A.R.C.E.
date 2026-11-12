import { useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import Logo from './Logo';
import { POLICY_DATE, POLICY_VERSION } from '../legal';

/**
 * Marco de las páginas legales (públicas, se pueden leer sin cuenta): logo,
 * volver, título, versión vigente y enlace a la otra página.
 */
export default function LegalLayout({ title, intro, children, other }: {
  title: string;
  intro: string;
  children: React.ReactNode;
  /** Enlace a la otra página legal */
  other: { to: string; label: string };
}) {
  const navigate = useNavigate();

  // Al llegar desde el pie de página la ventana venía desplazada hasta abajo:
  // un documento legal se lee desde el principio
  useEffect(() => { window.scrollTo(0, 0); }, [title]);

  return (
    <div className="min-h-screen bg-dark-950 text-gray-300">
      <header className="border-b border-anthracite-800 px-4 py-4">
        <div className="max-w-3xl mx-auto flex items-center justify-between">
          <Link to="/" aria-label="Ir al inicio"><Logo size="sm" /></Link>
          <button onClick={() => navigate(-1)} className="flex items-center gap-2 text-sm text-gray-400 hover:text-white">
            <ArrowLeft className="w-4 h-4" /> Volver
          </button>
        </div>
      </header>

      <main className="max-w-3xl mx-auto px-4 py-10 space-y-8">
        <div className="space-y-2">
          <h1 className="text-3xl font-bold text-white">{title}</h1>
          <p className="text-sm text-gray-500">Versión {POLICY_VERSION} · vigente desde el {POLICY_DATE}</p>
          <p className="text-gray-300">{intro}</p>
        </div>

        <div className="space-y-8 leading-relaxed [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-white [&_h2]:mb-3 [&_ul]:list-disc [&_ul]:pl-6 [&_ul]:space-y-1.5 [&_a]:text-gold-400 [&_a:hover]:underline">
          {children}
        </div>

        <p className="pt-6 border-t border-anthracite-800 text-sm">
          Consulta también: <Link to={other.to} className="text-gold-400 hover:underline">{other.label}</Link>
        </p>
      </main>
    </div>
  );
}
