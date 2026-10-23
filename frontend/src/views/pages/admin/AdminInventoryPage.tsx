// RF 4.3 – Consultar inventario disponible
// RF 4.2 – Actualizar disponibilidad
import { useCallback, useEffect, useState } from 'react';
import { Package, Edit2, CheckCircle, XCircle, AlertTriangle, Plus } from 'lucide-react';
import AdminLayout, { SearchBox } from '../../components/admin/AdminLayout';
import { Alert, EmptyState, Field, Modal } from '../../components/ui';
import { errorMessage, fieldErrors, inventoryService } from '../../../services';
import type { Availability, Category, Product, ProductUnit } from '@/models';
import { formatCOP, formatDate } from '../../format';

const STATUS_CFG: Record<Availability, { label: string; color: string; icon: React.ElementType }> = {
  available: { label: 'Disponible', color: 'bg-green-500/20 text-green-400 border-green-500/30', icon: CheckCircle },
  low_stock: { label: 'Stock bajo', color: 'bg-yellow-500/20 text-yellow-400 border-yellow-500/30', icon: AlertTriangle },
  unavailable: { label: 'Sin stock', color: 'bg-red-500/20 text-red-400 border-red-500/30', icon: XCircle },
};

const UNITS: ProductUnit[] = ['unidad', 'juego', 'litro', 'galón', 'kit'];
const EMPTY_FORM = { name: '', category_id: 0, unit: 'unidad' as ProductUnit, unit_price: '', stock_quantity: '0', min_stock: '0' };

export default function AdminInventoryPage() {
  const [products, setProducts] = useState<Product[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [search, setSearch] = useState('');
  const [categoryId, setCategoryId] = useState(0);
  const [availability, setAvailability] = useState<Availability | ''>('');
  const [error, setError] = useState('');
  const [editing, setEditing] = useState<Product | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState('');
  const [newCategory, setNewCategory] = useState('');

  const loadCategories = () => inventoryService.categories().then(res => res.data && setCategories(res.data.categories));

  const load = useCallback(async () => {
    const res = await inventoryService.products({ search: search.trim(), category_id: categoryId || undefined, availability });
    if (res.success && res.data) setProducts(res.data.products);
    else setError(errorMessage(res, 'No se pudo cargar el inventario'));
  }, [search, categoryId, availability]);

  useEffect(() => { loadCategories(); }, []);
  useEffect(() => {
    const t = setTimeout(load, search ? 400 : 0);
    return () => clearTimeout(t);
  }, [load, search]);

  const openForm = (product: Product | null) => {
    setEditing(product);
    setFormErrors({});
    setFormError('');
    setForm(product ? {
      name: product.name, category_id: product.categoryId, unit: product.unit,
      unit_price: String(product.unitPrice), stock_quantity: String(product.stockQuantity), min_stock: String(product.minStock),
    } : { ...EMPTY_FORM, category_id: categories[0]?.id ?? 0 });
    setFormOpen(true);
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    const payload = {
      name: form.name.trim(), category_id: form.category_id, unit: form.unit,
      unit_price: Number(form.unit_price), stock_quantity: Number(form.stock_quantity), min_stock: Number(form.min_stock),
    };
    const res = editing ? await inventoryService.updateProduct(editing.id, payload) : await inventoryService.createProduct(payload);
    if (!res.success) {
      setFormErrors(fieldErrors(res));
      setFormError(errorMessage(res, 'No se pudo guardar el producto'));
      return;
    }
    setFormOpen(false);
    load();
    loadCategories();
  };

  const addCategory = async () => {
    const res = await inventoryService.createCategory(newCategory.trim());
    if (!res.success || !res.data) {
      setFormError(errorMessage(res, 'No se pudo crear la categoría'));
      return;
    }
    setNewCategory('');
    await loadCategories();
    setForm(f => ({ ...f, category_id: res.data!.category.id }));
  };

  const stats = {
    total: products.length,
    available: products.filter(p => p.availability === 'available').length,
    low: products.filter(p => p.availability === 'low_stock').length,
    unavailable: products.filter(p => p.availability === 'unavailable').length,
  };

  return (
    <AdminLayout title="Inventario" subtitle="Repuestos e insumos disponibles para los servicios"
      actions={<button onClick={() => openForm(null)} className="btn-primary flex items-center gap-2"><Plus className="w-4 h-4" /> Nuevo producto</button>}>
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        {[
          { label: 'Productos', value: stats.total, color: 'text-white' },
          { label: 'Disponibles', value: stats.available, color: 'text-green-400' },
          { label: 'Stock bajo', value: stats.low, color: 'text-yellow-400' },
          { label: 'Sin stock', value: stats.unavailable, color: 'text-red-400' },
        ].map(s => (
          <div key={s.label} className="card p-4">
            <p className={`text-2xl font-bold ${s.color}`}>{s.value}</p>
            <p className="text-gray-500 text-xs mt-1">{s.label}</p>
          </div>
        ))}
      </div>

      <div className="flex gap-3 flex-wrap">
        <SearchBox value={search} onChange={setSearch} placeholder="Buscar producto o categoría..." />
        <select value={categoryId} onChange={e => setCategoryId(Number(e.target.value))} className="input-field w-auto text-sm">
          <option value={0}>Todas las categorías</option>
          {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select value={availability} onChange={e => setAvailability(e.target.value as Availability | '')} className="input-field w-auto text-sm">
          <option value="">Todos los estados</option>
          {(Object.keys(STATUS_CFG) as Availability[]).map(a => <option key={a} value={a}>{STATUS_CFG[a].label}</option>)}
        </select>
      </div>

      {error && <Alert type="error">{error}</Alert>}

      {products.length === 0 ? (
        <EmptyState icon={Package}>Sin resultados.</EmptyState>
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-anthracite-800 text-gray-400 text-xs uppercase">
                <th className="px-4 py-3 text-left">Producto</th>
                <th className="px-4 py-3 text-left">Categoría</th>
                <th className="px-4 py-3 text-right">Stock</th>
                <th className="px-4 py-3 text-right">Mín.</th>
                <th className="px-4 py-3 text-right">Precio unit.</th>
                <th className="px-4 py-3 text-center">Disponibilidad</th>
                <th className="px-4 py-3 text-center">Editar</th>
              </tr>
            </thead>
            <tbody>
              {products.map(item => {
                const cfg = STATUS_CFG[item.availability];
                return (
                  <tr key={item.id} className="border-b border-anthracite-800/50 hover:bg-dark-800/40 transition-colors">
                    <td className="px-4 py-3">
                      <p className="text-white font-medium">{item.name}</p>
                      <p className="text-gray-500 text-xs">Actualizado: {formatDate(item.updatedAt)}</p>
                    </td>
                    <td className="px-4 py-3 text-gray-400 text-xs">{item.categoryName}</td>
                    <td className="px-4 py-3 text-right">
                      <span className={`font-bold ${item.availability === 'unavailable' ? 'text-red-400' : item.availability === 'low_stock' ? 'text-yellow-400' : 'text-white'}`}>
                        {item.stockQuantity} {item.unit}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right text-gray-500 text-xs">{item.minStock}</td>
                    <td className="px-4 py-3 text-right text-gold-400 font-semibold">{formatCOP(item.unitPrice)}</td>
                    <td className="px-4 py-3 text-center">
                      <span className={`inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold border ${cfg.color}`}>
                        <cfg.icon className="w-3 h-3" /> {cfg.label}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-center">
                      <button onClick={() => openForm(item)} className="p-1.5 hover:bg-dark-700 rounded-lg text-gray-400 hover:text-gold-400" title="Actualizar disponibilidad">
                        <Edit2 className="w-4 h-4" />
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Actualizar producto' : 'Nuevo producto'}>
        <form onSubmit={save} className="space-y-3">
          <Field label="Nombre" error={formErrors.name}>
            <input value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} className="input-field" required />
          </Field>
          <Field label="Categoría" error={formErrors.categoryId}>
            <select value={form.category_id} onChange={e => setForm({ ...form, category_id: Number(e.target.value) })} className="input-field">
              {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </Field>
          <div className="flex gap-2">
            <input value={newCategory} onChange={e => setNewCategory(e.target.value)} placeholder="Nueva categoría" className="input-field flex-1 text-sm" />
            <button type="button" onClick={addCategory} disabled={!newCategory.trim()} className="px-3 rounded-xl bg-dark-700 text-gray-300 text-sm disabled:opacity-40">Agregar</button>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Unidad">
              <select value={form.unit} onChange={e => setForm({ ...form, unit: e.target.value as ProductUnit })} className="input-field">
                {UNITS.map(u => <option key={u} value={u}>{u}</option>)}
              </select>
            </Field>
            <Field label="Precio unitario (COP)" error={formErrors.unitPrice}>
              <input type="number" min={0} value={form.unit_price} onChange={e => setForm({ ...form, unit_price: e.target.value })} className="input-field" required />
            </Field>
            <Field label="Stock actual" error={formErrors.stockQuantity}>
              <input type="number" min={0} value={form.stock_quantity} onChange={e => setForm({ ...form, stock_quantity: e.target.value })} className="input-field" required />
            </Field>
            <Field label="Stock mínimo" error={formErrors.minStock}>
              <input type="number" min={0} value={form.min_stock} onChange={e => setForm({ ...form, min_stock: e.target.value })} className="input-field" required />
            </Field>
          </div>
          {editing && <p className="text-xs text-gray-500">Cambiar el stock aquí es un ajuste manual (conteo físico). Las entradas de proveedores se registran en "Ingreso de mercancía".</p>}
          {formError && <Alert type="error">{formError}</Alert>}
          <button type="submit" className="w-full btn-primary">Guardar</button>
        </form>
      </Modal>
    </AdminLayout>
  );
}
