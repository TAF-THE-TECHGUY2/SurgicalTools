import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ChevronDown, ChevronRight, PackagePlus, PackageSearch, Pencil, Plus, Search, Trash2,
} from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { Can } from '@/auth/Can'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/Field'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { formatDate } from '@/lib/format'
import type { DeviceUnit, LocationEntity, Paginated, StockItem } from '@/types'

export default function StockItemsPage() {
  const toast = useToast()
  const queryClient = useQueryClient()

  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<StockItem | null>(null)
  const [receiving, setReceiving] = useState<StockItem | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['stock-items', { q, page }],
    queryFn: async () =>
      (await api.get<Paginated<StockItem>>('/stock-items', { params: { q: q || undefined, page } })).data,
  })

  const pageMeta = data?.meta
  const currentPage = pageMeta?.current_page ?? page
  const lastPage = pageMeta?.last_page ?? 1

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['stock-items'] })
    void queryClient.invalidateQueries({ queryKey: ['location-inventory'] })
  }

  return (
    <>
      <PageHeader
        title="Stock Catalog"
        description="Product lines and their serialised devices."
        actions={
          <Can permission="inventory.manage">
            <Button onClick={() => { setEditing(null); setFormOpen(true) }}>
              <Plus className="h-4 w-4" /> New item
            </Button>
          </Can>
        }
      />

      <Card className="mb-4">
        <CardBody>
          <div className="relative max-w-md">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input className="pl-9" placeholder="Search name, catalogue number, item code…" value={q}
              onChange={(e) => { setQ(e.target.value); setPage(1) }} />
          </div>
        </CardBody>
      </Card>

      {isLoading ? (
        <LoadingState label="Loading catalog…" />
      ) : error ? (
        <ErrorState message={apiError(error)} />
      ) : !data || data.data.length === 0 ? (
        <EmptyState
          icon={<PackageSearch className="h-10 w-10" />}
          title={q ? 'No items match your search' : 'No stock items yet'}
          description={q ? 'Try a different term.' : 'Create your first catalog item, then receive its devices into a location.'}
          action={
            <Can permission="inventory.manage">
              <Button onClick={() => { setEditing(null); setFormOpen(true) }}><Plus className="h-4 w-4" /> New item</Button>
            </Can>
          }
        />
      ) : (
        <>
          <div className="space-y-2">
            {data.data.map((item) => (
              <StockItemCard
                key={item.id}
                item={item}
                onEdit={() => { setEditing(item); setFormOpen(true) }}
                onReceive={() => setReceiving(item)}
                onChanged={invalidate}
              />
            ))}
          </div>
          <div className="mt-4 flex items-center justify-between text-sm text-slate-500">
            <span>Page {currentPage} of {lastPage}{pageMeta ? ` · ${pageMeta.total} items` : ''}</span>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" disabled={currentPage <= 1} onClick={() => setPage(currentPage - 1)}>Prev</Button>
              <Button variant="outline" size="sm" disabled={currentPage >= lastPage} onClick={() => setPage(currentPage + 1)}>Next</Button>
            </div>
          </div>
        </>
      )}

      <ItemFormModal
        open={formOpen}
        item={editing}
        onClose={() => setFormOpen(false)}
        onSaved={() => {
          toast.success(editing ? 'Item updated.' : 'Item created.')
          setFormOpen(false)
          invalidate()
        }}
      />

      {receiving && (
        <ReceiveStockModal
          item={receiving}
          onClose={() => setReceiving(null)}
          onReceived={(count) => {
            toast.success(`${count} device(s) received into stock.`)
            setReceiving(null)
            invalidate()
          }}
        />
      )}
    </>
  )
}

/* ---------------------------------------------------------------------- */
/*  Expandable item card with its units                                    */
/* ---------------------------------------------------------------------- */

function StockItemCard({ item, onEdit, onReceive, onChanged }: {
  item: StockItem
  onEdit: () => void
  onReceive: () => void
  onChanged: () => void
}) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [archiving, setArchiving] = useState<DeviceUnit | null>(null)
  const [reason, setReason] = useState('')

  const { data: detail, isLoading, refetch } = useQuery({
    queryKey: ['stock-items', item.id, 'detail'],
    queryFn: async () => (await api.get<{ data: StockItem }>(`/stock-items/${item.id}`)).data.data,
    enabled: open,
  })

  const archive = useMutation({
    mutationFn: async (unit: DeviceUnit) =>
      (await api.delete(`/device-units/${unit.id}`, { data: { reason: reason || 'Removed from stock' } })).data,
    onSuccess: () => {
      toast.success('Device archived.')
      setArchiving(null)
      setReason('')
      void refetch()
      onChanged()
    },
    onError: (e) => toast.error(apiError(e)),
  })

  return (
    <Card>
      <div className="flex items-center justify-between gap-2 px-4 py-3">
        <button onClick={() => setOpen((o) => !o)} className="flex flex-1 items-center gap-2 text-left">
          {open ? <ChevronDown className="h-4 w-4 text-slate-400" /> : <ChevronRight className="h-4 w-4 text-slate-400" />}
          <span className="font-medium text-slate-800">{item.name}</span>
          <span className="hidden text-xs text-slate-400 sm:inline">
            Cat {item.catalogue_number ?? '—'} · Code {item.item_code ?? '—'}
          </span>
          {!item.is_active && <Badge tone="gray">Inactive</Badge>}
        </button>
        <div className="flex items-center gap-2">
          <Badge tone="teal">{item.units_count ?? 0} unit(s)</Badge>
          <Can permission="inventory.manage">
            <Button variant="outline" size="sm" onClick={onReceive}>
              <PackagePlus className="h-4 w-4" /> Receive
            </Button>
            <Button variant="ghost" size="sm" onClick={onEdit}>
              <Pencil className="h-4 w-4" />
            </Button>
          </Can>
        </div>
      </div>

      {open && (
        <div className="border-t border-slate-100 px-4 py-3">
          {isLoading ? (
            <LoadingState label="Loading devices…" />
          ) : !detail?.units || detail.units.length === 0 ? (
            <p className="py-4 text-center text-sm text-slate-400">No devices captured for this item yet.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[560px] text-xs">
                <thead>
                  <tr className="text-left uppercase tracking-wide text-slate-400">
                    <th className="py-1.5 pr-4 font-semibold">Serial</th>
                    <th className="py-1.5 pr-4 font-semibold">Lot</th>
                    <th className="py-1.5 pr-4 font-semibold">Expiry</th>
                    <th className="py-1.5 pr-4 font-semibold">Location</th>
                    <th className="py-1.5 pr-4 font-semibold">Status</th>
                    <th className="py-1.5 font-semibold" />
                  </tr>
                </thead>
                <tbody>
                  {detail.units.map((u) => (
                    <tr key={u.id} className="border-t border-slate-100">
                      <td className="py-2 pr-4 font-medium text-slate-700">{u.serial_number ?? '—'}</td>
                      <td className="py-2 pr-4 text-slate-600">{u.lot_number ?? '—'}</td>
                      <td className="py-2 pr-4">
                        <span className={u.days_to_expiry != null && u.days_to_expiry <= 30 ? 'font-medium text-red-600' : 'text-slate-600'}>
                          {formatDate(u.expiry_date)}
                        </span>
                      </td>
                      <td className="py-2 pr-4 text-slate-600">{u.location?.name ?? '—'}</td>
                      <td className="py-2 pr-4"><StatusBadge status={u.status} /></td>
                      <td className="py-2 text-right">
                        <Can permission="inventory.manage">
                          <button
                            onClick={() => setArchiving(u)}
                            className="rounded p-1 text-slate-300 hover:bg-red-50 hover:text-red-500"
                            aria-label="Archive device"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </Can>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      <Modal open={!!archiving} onClose={() => setArchiving(null)} title="Archive this device?" size="sm">
        <p className="text-sm text-slate-600">
          {item.name} · SN <span className="font-medium">{archiving?.serial_number ?? '—'}</span> will be written
          off with a ledger entry.
        </p>
        <Field label="Reason">
          <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Damaged during procedure" />
        </Field>
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setArchiving(null)}>Cancel</Button>
          <Button variant="danger" loading={archive.isPending} onClick={() => archiving && archive.mutate(archiving)}>
            Archive device
          </Button>
        </div>
      </Modal>
    </Card>
  )
}

/* ---------------------------------------------------------------------- */
/*  Create / edit item                                                     */
/* ---------------------------------------------------------------------- */

function ItemFormModal({ open, item, onClose, onSaved }: {
  open: boolean
  item: StockItem | null
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()

  const blank = { name: '', catalogue_number: '', item_code: '', description: '', uom: '', unit_price: '', min_threshold: '' }
  const [form, setForm] = useState(blank)

  const [syncKey, setSyncKey] = useState('')
  const currentKey = `${open}-${item?.id ?? 'new'}`
  if (open && syncKey !== currentKey) {
    setSyncKey(currentKey)
    setForm(item ? {
      name: item.name,
      catalogue_number: item.catalogue_number ?? '',
      item_code: item.item_code ?? '',
      description: item.description ?? '',
      uom: item.uom ?? '',
      unit_price: item.unit_price != null ? String(item.unit_price) : '',
      min_threshold: item.min_threshold != null ? String(item.min_threshold) : '',
    } : blank)
  }

  const set = (key: keyof typeof blank) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const mutation = useMutation({
    mutationFn: async () => {
      const payload = {
        name: form.name,
        catalogue_number: form.catalogue_number || null,
        item_code: form.item_code || null,
        description: form.description || null,
        uom: form.uom || null,
        unit_price: form.unit_price === '' ? null : Number(form.unit_price),
        min_threshold: form.min_threshold === '' ? null : Number(form.min_threshold),
      }
      return item
        ? (await api.put(`/stock-items/${item.id}`, payload)).data
        : (await api.post('/stock-items', payload)).data
    },
    onSuccess: onSaved,
    onError: (e) => toast.error(apiError(e)),
  })

  return (
    <Modal open={open} onClose={onClose} title={item ? 'Edit stock item' : 'New stock item'} size="lg">
      <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); mutation.mutate() }}>
        <Field label="Name" required><Input value={form.name} onChange={set('name')} required /></Field>
        <Field label="Catalogue number"><Input value={form.catalogue_number} onChange={set('catalogue_number')} /></Field>
        <Field label="Item code"><Input value={form.item_code} onChange={set('item_code')} /></Field>
        <Field label="Unit of measure"><Input value={form.uom} onChange={set('uom')} /></Field>
        <Field label="Unit price"><Input type="number" min={0} step="0.01" value={form.unit_price} onChange={set('unit_price')} /></Field>
        <Field label="Min threshold" hint="Low-stock alerts fire at or below this level.">
          <Input type="number" min={0} value={form.min_threshold} onChange={set('min_threshold')} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="Description"><Textarea value={form.description} onChange={set('description')} /></Field>
        </div>
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>{item ? 'Save changes' : 'Create item'}</Button>
        </div>
      </form>
    </Modal>
  )
}

/* ---------------------------------------------------------------------- */
/*  Receive stock (bulk units with serial/lot/expiry)                      */
/* ---------------------------------------------------------------------- */

interface UnitRow { _key: number; serial_number: string; lot_number: string; expiry_date: string }

let unitRowKey = 0

function ReceiveStockModal({ item, onClose, onReceived }: {
  item: StockItem
  onClose: () => void
  onReceived: (count: number) => void
}) {
  const toast = useToast()
  const [locationId, setLocationId] = useState('')
  const blankRow = (): UnitRow => ({ _key: ++unitRowKey, serial_number: '', lot_number: '', expiry_date: '' })
  const [rows, setRows] = useState<UnitRow[]>([blankRow()])

  const { data: locations } = useQuery({
    queryKey: ['locations'],
    queryFn: async () => (await api.get<{ data: LocationEntity[] }>('/locations')).data.data,
  })

  const setRow = (key: number, patch: Partial<UnitRow>) =>
    setRows((prev) => prev.map((r) => (r._key === key ? { ...r, ...patch } : r)))

  const mutation = useMutation({
    mutationFn: async () => {
      const units = rows.map((r) => ({
        serial_number: r.serial_number || null,
        lot_number: r.lot_number || null,
        expiry_date: r.expiry_date || null,
      }))
      return (await api.post(`/stock-items/${item.id}/units`, { location_id: Number(locationId), units })).data
    },
    onSuccess: () => onReceived(rows.length),
    onError: (e) => toast.error(apiError(e)),
  })

  return (
    <Modal open onClose={onClose} title={`Receive stock — ${item.name}`} size="xl">
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); mutation.mutate() }}>
        <Field label="Into location" required>
          <Select value={locationId} onChange={(e) => setLocationId(e.target.value)} required>
            <option value="">Select location…</option>
            {locations?.map((l) => (
              <option key={l.id} value={l.id}>{l.name}{l.owner ? ` — ${l.owner.name}` : ''}</option>
            ))}
          </Select>
        </Field>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-sm font-medium text-slate-700">Devices ({rows.length})</span>
            <Button type="button" variant="outline" size="sm" onClick={() => setRows((p) => [...p, blankRow()])}>
              <Plus className="h-4 w-4" /> Add device
            </Button>
          </div>
          <div className="space-y-2">
            {rows.map((row) => (
              <div key={row._key} className="grid grid-cols-12 gap-2">
                <Input className="col-span-4" placeholder="Serial number" value={row.serial_number}
                  onChange={(e) => setRow(row._key, { serial_number: e.target.value })} />
                <Input className="col-span-3" placeholder="Lot" value={row.lot_number}
                  onChange={(e) => setRow(row._key, { lot_number: e.target.value })} />
                <Input className="col-span-4" type="date" value={row.expiry_date}
                  onChange={(e) => setRow(row._key, { expiry_date: e.target.value })} />
                <div className="col-span-1 flex items-center">
                  <Button type="button" variant="ghost" size="sm" disabled={rows.length === 1}
                    onClick={() => setRows((p) => p.filter((r) => r._key !== row._key))}>
                    <Trash2 className="h-4 w-4 text-red-500" />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending} disabled={!locationId}>
            <PackagePlus className="h-4 w-4" /> Receive {rows.length} device(s)
          </Button>
        </div>
      </form>
    </Modal>
  )
}
