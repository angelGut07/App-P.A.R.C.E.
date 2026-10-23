import { useCallback, useEffect, useState } from 'react';
import { Package, Plus, Truck, Hash, Trash2, Eye, Calendar } from 'lucide-react';
import AdminLayout from '../../components/admin/AdminLayout';
import { Alert, EmptyState, Field, Modal, Pagination } from '../../components/ui';
import { errorMessage, inventoryService } from '../../../services';
import type { Product, Receipt, Supplier } from '@/models';
import { formatCOP, formatDate, fullName } from '../../format';

const todayISO = () => new Date().toISOString().slice(0, 10);

interface Line { productId: number; quantity: string; unitCost: string }
const EMPTY_LINE: Line = { productId: 0, quantity: '1', unitCost: '' };

/** Registro de la mercancía que llega de los proveedores (suma al stock). */
export default function AdminMerchandisePage() {
  const [receipts, setReceipts] = useState<Receipt[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [error, setError] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [viewing, setViewing] = useState<Receipt | null>(null);
  const [deleting, setDeleting] = useState<Receipt | null>(null);

  const [supplierId, setSupplierId] = useState(0);
  const [newSupplier, setNewSupplier] = useState({ name: '', tax_id: '', phone: '' });
  const [invoice, setInvoice] = useState('');
  const [date, setDate] = useState(todayISO());
  const [lines, setLines] = useState<Line[]>([EMPTY_LINE]);
  const [formError, setFormError] = useState('');
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    const res = await inventoryService.receipts(page);
    if (res.success && res.data) {
      setReceipts(res.data.receipts);
      setTotalPages(res.data.totalPages);
    } else {
      setError(errorMessage(res, 'No se pudieron cargar los ingresos'));
    }
  }, [page]);

  const loadCatalogs = () => {
    inventoryService.suppliers().then(res => res.data && setSuppliers(res.data.suppliers));
    inventoryService.products().then(res => res.data && setProducts(res.data.products));
  };

  useEffect(() => { load(); }, [load]);
  useEffect(() => { loadCatalogs(); }, []);

  const openForm = () => {
    setSupplierId(suppliers[0]?.id ?? 0);
    setNewSupplier({ name: '', tax_id: '', phone: '' });
    setInvoice('');
    setDate(todayISO());
    setLines([EMPTY_LINE]);
    setFormError('');
    setShowForm(true);
  };

  const setLine = (i: number, patch: Partial<Line>) => setLines(prev => prev.map((l, idx) => idx === i ? { ...l, ...patch } : l));

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setFormError('');

    let supplier = supplierId;
    if (supplier === -1) {
      const created = await inventoryService.createSupplier({
        name: newSupplier.name.trim(),
        tax_id: newSupplier.tax_id.trim() || undefined,
        phone: newSupplier.phone.trim() || undefined,
      });
      if (!created.success || !created.data) {
        setSaving(false);
        setFormError(errorMessage(created, 'No se pudo crear el proveedor'));
        return;
      }
      supplier = created.data.supplier.id;
    }

    const res = await inventoryService.createReceipt({
      supplier_id: supplier,
      invoice_number: invoice.trim(),
      received_on: date,
      items: lines.filter(l => l.productId).map(l => ({
        product_id: l.productId,
        quantity: Number(l.quantity),
        unit_cost: l.unitCost === '' ? undefined : Number(l.unitCost),
      })),
    });
    setSaving(false);
    if (!res.success) {
      setFormError(errorMessage(res, 'No se pudo registrar el ingreso'));
      return;
    }
    setShowForm(false);
    setPage(1);
    load();
    loadCatalogs();
  };

  const view = async (r: Receipt) => {
    const res = await inventoryService.receipt(r.id);
    if (res.data) setViewing(res.data.receipt);
  };

  const remove = async () => {
    if (!deleting) return;
    const res = await inventoryService.deleteReceipt(deleting.id);
    setDeleting(null);
    if (!res.success) setError(errorMessage(res, 'No se pudo anular el ingreso'));
    load();
    loadCatalogs();
  };

  return (
    <AdminLayout title="Ingreso de Mercancía" subtitle="Registra la mercancía que llega de los proveedores"
      actions={<button onClick={openForm} className="btn-primary flex items-center gap-2"><Plus className="w-4 h-4" /> Registrar ingreso</button>}>
      {error && <Alert type="error">{error}</Alert>}

      {receipts.length === 0 ? (
        <EmptyState icon={Truck}>Aún no hay ingresos de mercancía.</EmptyState>
      ) : (
        <div className="space-y-3">
          {receipts.map(r => (
            <div key={r.id} className="card p-5 flex flex-wrap items-center justify-between gap-4">
              <div className="space-y-1">
                <p className="text-white font-bold flex items-center gap-2"><Truck className="w-4 h-4 text-gold-400" /> {r.supplierName}</p>
                <p className="text-sm text-gray-400 flex items-center gap-3">
                  <span className="flex items-center gap-1"><Hash className="w-3.5 h-3.5" /> {r.invoiceNumber}</span>
                  <span className="flex items-center gap-1"><Calendar className="w-3.5 h-3.5" /> {formatDate(r.receivedOn, false)}</span>
                </p>
                <p className="text-xs text-gray-500">{r.itemCount} producto(s) · {r.totalUnits} unidades · registrado por {fullName(r.registeredByFirstName, r.registeredByLastName)}</p>
              </div>
              <div className="flex gap-2">
                <button onClick={() => view(r)} className="p-2 rounded-lg hover:bg-dark-700 text-gray-400 hover:text-white" title="Ver detalle"><Eye className="w-4 h-4" /></button>
                <button onClick={() => setDeleting(r)} className="p-2 rounded-lg hover:bg-dark-700 text-gray-400 hover:text-red-400" title="Anular"><Trash2 className="w-4 h-4" /></button>
              </div>
            </div>
          ))}
          <Pagination page={page} totalPages={totalPages} onChange={setPage} />
        </div>
      )}

      <Modal open={showForm} onClose={() => setShowForm(false)} title="Registrar ingreso de mercancía" wide>
        <form onSubmit={save} className="space-y-4">
          <div className="grid sm:grid-cols-3 gap-3">
            <Field label="Proveedor">
              <select value={supplierId} onChange={e => setSupplierId(Number(e.target.value))} className="input-field">
                {suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                <option value={-1}>+ Nuevo proveedor</option>
              </select>
            </Field>
            <Field label="N.º de factura o remisión">
              <input value={invoice} onChange={e => setInvoice(e.target.value)} placeholder="FAC-2026-0001" className="input-field" required />
            </Field>
            <Field label="Fecha de llegada">
              <input type="date" value={date} max={todayISO()} onChange={e => setDate(e.target.value)} className="input-field" required />
            </Field>
          </div>

          {supplierId === -1 && (
            <div className="grid sm:grid-cols-3 gap-3 p-3 rounded-xl bg-dark-800/60 border border-anthracite-700">
              <input value={newSupplier.name} onChange={e => setNewSupplier({ ...newSupplier, name: e.target.value })} placeholder="Nombre del proveedor" className="input-field" required />
              <input value={newSupplier.tax_id} onChange={e => setNewSupplier({ ...newSupplier, tax_id: e.target.value })} placeholder="NIT (opcional)" className="input-field" />
              <input value={newSupplier.phone} onChange={e => setNewSupplier({ ...newSupplier, phone: e.target.value })} placeholder="Teléfono (opcional)" className="input-field" />
            </div>
          )}

          <div className="space-y-2">
            <p className="text-sm font-semibold text-gray-300 flex items-center gap-2"><Package className="w-4 h-4" /> Productos recibidos</p>
            {lines.map((line, i) => (
              <div key={i} className="grid grid-cols-12 gap-2 items-center">
                <select value={line.productId} onChange={e => setLine(i, { productId: Number(e.target.value) })} className="input-field col-span-6 text-sm">
                  <option value={0}>Selecciona un producto</option>
                  {products.map(p => <option key={p.id} value={p.id}>{p.name} ({p.categoryName})</option>)}
                </select>
                <input type="number" min={1} value={line.quantity} onChange={e => setLine(i, { quantity: e.target.value })} className="input-field col-span-2 text-sm" placeholder="Cant." />
                <input type="number" min={0} value={line.unitCost} onChange={e => setLine(i, { unitCost: e.target.value })} className="input-field col-span-3 text-sm" placeholder="Costo unit." />
                <button type="button" onClick={() => setLines(lines.length > 1 ? lines.filter((_, idx) => idx !== i) : [EMPTY_LINE])} className="col-span-1 text-gray-500 hover:text-red-400"><Trash2 className="w-4 h-4" /></button>
              </div>
            ))}
            <button type="button" onClick={() => setLines([...lines, EMPTY_LINE])} className="text-sm text-gold-400 hover:text-gold-300 flex items-center gap-1"><Plus className="w-4 h-4" /> Agregar producto</button>
            <p className="text-xs text-gray-500">¿El producto no existe? Créalo primero en Inventario.</p>
          </div>

          {formError && <Alert type="error">{formError}</Alert>}
          <button type="submit" disabled={saving || !lines.some(l => l.productId)} className="w-full btn-primary disabled:opacity-50">
            {saving ? 'Registrando...' : 'Registrar ingreso'}
          </button>
        </form>
      </Modal>

      <Modal open={!!viewing} onClose={() => setViewing(null)} title={`Ingreso ${viewing?.invoiceNumber ?? ''}`} wide>
        {viewing && (
          <div className="space-y-3 text-sm">
            <p className="text-gray-400">{viewing.supplierName} · llegó el {formatDate(viewing.receivedOn, false)} · registrado {formatDate(viewing.createdAt)}</p>
            <table className="w-full">
              <thead className="text-left text-gray-500 text-xs"><tr><th className="py-1">Producto</th><th>Cantidad</th><th className="text-right">Costo unit.</th></tr></thead>
              <tbody className="divide-y divide-anthracite-800">
                {viewing.items?.map(item => (
                  <tr key={item.productId} className="text-gray-300">
                    <td className="py-2">{item.productName} <span className="text-gray-500 text-xs">{item.categoryName}</span></td>
                    <td>{item.quantity} {item.unit}</td>
                    <td className="text-right">{item.unitCost != null ? formatCOP(item.unitCost) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Modal>

      <Modal open={!!deleting} onClose={() => setDeleting(null)} title="Anular ingreso">
        <p className="text-sm text-gray-300">¿Anular el ingreso {deleting?.invoiceNumber} de {deleting?.supplierName}? Las cantidades se restarán del inventario.</p>
        <div className="grid grid-cols-2 gap-3">
          <button onClick={() => setDeleting(null)} className="py-2.5 bg-dark-700 rounded-xl text-white">Cancelar</button>
          <button onClick={remove} className="py-2.5 bg-red-600 hover:bg-red-700 rounded-xl text-white font-semibold">Anular</button>
        </div>
      </Modal>
    </AdminLayout>
  );
}
