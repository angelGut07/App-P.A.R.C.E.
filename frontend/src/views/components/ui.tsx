// Piezas de UI pequeñas reutilizadas por varias vistas.
import { AnimatePresence, motion } from 'framer-motion';
import { AlertCircle, Check, X } from 'lucide-react';

export function SectionTitle({ children, color = 'text-gold-400' }: { children: React.ReactNode; color?: string }) {
  return (
    <div className="border-b border-anthracite-800 pb-1 mb-3">
      <h3 className={`text-sm font-semibold uppercase tracking-wider ${color}`}>{children}</h3>
    </div>
  );
}

export function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label className="block text-sm font-medium text-gray-300">{label}</label>
      {children}
      {error && (
        <p className="flex items-center gap-1 text-xs text-red-400">
          <AlertCircle className="w-3 h-3 flex-shrink-0" />{error}
        </p>
      )}
    </div>
  );
}

export function Alert({ type, children }: { type: 'error' | 'success'; children: React.ReactNode }) {
  if (!children) return null;
  const styles = type === 'error'
    ? 'bg-red-500/10 border-red-500/30 text-red-400'
    : 'bg-green-500/10 border-green-500/30 text-green-400';
  const Icon = type === 'error' ? AlertCircle : Check;
  return (
    <div className={`flex items-start gap-2 p-3 border rounded-xl text-sm ${styles}`}>
      <Icon className="w-4 h-4 flex-shrink-0 mt-0.5" /> <span>{children}</span>
    </div>
  );
}

export function Modal({ open, onClose, title, children, wide = false }: {
  open: boolean; onClose: () => void; title: string; children: React.ReactNode; wide?: boolean;
}) {
  return (
    <AnimatePresence>
      {open && (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4" onClick={onClose}>
          <motion.div initial={{ scale: 0.95, opacity: 0 }} animate={{ scale: 1, opacity: 1 }} exit={{ scale: 0.95, opacity: 0 }}
            onClick={e => e.stopPropagation()}
            className={`card p-6 w-full ${wide ? 'max-w-2xl' : 'max-w-md'} max-h-[90vh] overflow-y-auto space-y-4`}>
            <div className="flex items-center justify-between">
              <h3 className="text-lg font-bold text-white">{title}</h3>
              <button onClick={onClose} className="text-gray-400 hover:text-white"><X className="w-5 h-5" /></button>
            </div>
            {children}
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}

export function Badge({ className, children }: { className: string; children: React.ReactNode }) {
  return <span className={`px-2 py-0.5 rounded-full text-xs font-semibold border ${className}`}>{children}</span>;
}

export function EmptyState({ icon: Icon, children }: { icon: React.ElementType; children: React.ReactNode }) {
  return (
    <div className="card p-10 text-center text-gray-500">
      <Icon className="w-12 h-12 mx-auto mb-3 opacity-50" />
      <p>{children}</p>
    </div>
  );
}

export function Pagination({ page, totalPages, onChange }: { page: number; totalPages: number; onChange: (p: number) => void }) {
  if (totalPages <= 1) return null;
  return (
    <div className="flex items-center justify-center gap-3 pt-2 text-sm">
      <button disabled={page <= 1} onClick={() => onChange(page - 1)} className="px-3 py-1.5 rounded-lg bg-dark-800 text-gray-300 disabled:opacity-40">Anterior</button>
      <span className="text-gray-400">Página {page} de {totalPages}</span>
      <button disabled={page >= totalPages} onClick={() => onChange(page + 1)} className="px-3 py-1.5 rounded-lg bg-dark-800 text-gray-300 disabled:opacity-40">Siguiente</button>
    </div>
  );
}
