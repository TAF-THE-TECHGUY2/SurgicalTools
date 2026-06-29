import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams, useNavigate } from 'react-router-dom'
import type { ReactNode } from 'react'
import { ArrowLeft, AlertTriangle, Clock, Pencil, Archive } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { Can } from '@/auth/Can'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { useToast } from '@/components/ToastProvider'
import { useMeta } from '@/hooks/useMeta'
import { formatDate, formatDateTime, formatMoney, humanize } from '@/lib/format'
import type { InventoryItem, StockMovement, Paginated } from '@/types'

export default function InventoryDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [editOpen, setEditOpen] = useState(false)
  const [archiveOpen, setArchiveOpen] = useState(false)

  const { data: item, isLoading, error } = useQuery({
    queryKey: ['inventory', id],
    queryFn: async () => (await api.get<InventoryItem>(`/inventory/${id}`)).data,
    enabled: !!id,
  })

  const { data: movements, isLoading: movementsLoading, error: movementsError } = useQuery({
    queryKey: ['inventory', id, 'movements'],
    queryFn: async () => (await api.get<Paginated<StockMovement>>(`/inventory/${id}/movements`)).data,
    enabled: !!id,
  })

  const archive = useMutation({
    mutationFn: async () => (await api.delete(`/inventory/${id}`)).data,
    onSuccess: () => {
      toast.success('Item archived.')
      queryClient.invalidateQueries({ queryKey: ['inventory'] })
      navigate('/inventory')
    },
    onError: (err) => toast.error(apiError(err)),
  })

  if (isLoading) return <LoadingState label="Loading item…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!item) return null

  const expiryCritical = item.days_to_expiry != null && item.days_to_expiry <= 30

  const movementColumns: Column<StockMovement>[] = [
    { key: 'qty', header: 'Qty', render: (m) => <span className="font-medium text-slate-800">{m.quantity}</span> },
    {
      key: 'route',
      header: 'From → To',
      render: (m) => `${humanize(m.from_location)} → ${humanize(m.to_location)}`,
    },
    { key: 'type', header: 'Type', render: (m) => <Badge tone="blue">{humanize(m.movement_type)}</Badge> },
    { key: 'by', header: 'Performed by', render: (m) => m.performed_by?.name ?? '—' },
    { key: 'at', header: 'When', render: (m) => formatDateTime(m.moved_at) },
  ]

  return (
    <>
      <div className="mb-4 flex items-center justify-between">
        <Link to="/inventory" className="inline-flex items-center gap-1.5 text-sm text-brand-700 hover:underline">
          <ArrowLeft className="h-4 w-4" /> Back to inventory
        </Link>
        <Can permission="inventory.manage">
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
              <Pencil className="h-4 w-4" /> Edit
            </Button>
            <Button variant="danger" size="sm" onClick={() => setArchiveOpen(true)}>
              <Archive className="h-4 w-4" /> Archive
            </Button>
          </div>
        </Can>
      </div>

      <Card className="mb-6">
        <CardBody>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <div className="flex items-center gap-3">
                <h1 className="text-2xl font-bold tracking-tight text-slate-900">{item.ref_code}</h1>
                <StatusBadge status={item.status} />
              </div>
              <p className="mt-1 text-sm text-slate-500">{item.description}</p>
            </div>
            <div className="text-right">
              <p className="text-4xl font-bold leading-none text-slate-900">{item.quantity}</p>
              <p className="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">on hand</p>
            </div>
          </div>

          {(item.is_low_stock || expiryCritical) && (
            <div className="mt-4 flex flex-wrap gap-2">
              {item.is_low_stock && (
                <span className="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-700">
                  <AlertTriangle className="h-4 w-4" /> Low stock
                  {item.min_threshold != null && ` (min ${item.min_threshold})`}
                </span>
              )}
              {expiryCritical && (
                <span className="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700">
                  <Clock className="h-4 w-4" /> Expires in {item.days_to_expiry} days
                </span>
              )}
            </div>
          )}

          <dl className="mt-6 grid gap-x-6 gap-y-4 border-t border-slate-100 pt-6 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label="Lot number" value={item.lot_number ?? '—'} />
            <Detail label="Stock type" value={humanize(item.stock_type)} />
            <Detail label="Location" value={humanize(item.location)} />
            <Detail
              label="Expiry"
              value={
                <span className={expiryCritical ? 'text-red-600' : undefined}>
                  {formatDate(item.expiry_date)}
                </span>
              }
            />
            <Detail label="Holder" value={item.holder?.name ?? '—'} />
            <Detail label="Hospital" value={item.hospital?.name ?? '—'} />
            <Detail label="Unit price" value={formatMoney(item.unit_price)} />
            <Detail label="Min threshold" value={item.min_threshold != null ? String(item.min_threshold) : '—'} />
            <Detail label="Barcode" value={item.barcode ?? '—'} />
            <Detail label="Unit of measure" value={item.uom ?? '—'} />
          </dl>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Movement history" subtitle="Stock ledger for this line" />
        <CardBody className="p-0">
          {movementsLoading ? (
            <LoadingState label="Loading movements…" />
          ) : movementsError ? (
            <div className="p-5">
              <ErrorState message={apiError(movementsError)} />
            </div>
          ) : (
            <DataTable
              columns={movementColumns}
              rows={movements?.data ?? []}
              rowKey={(m) => m.id}
              empty="No movements recorded for this item."
            />
          )}
        </CardBody>
      </Card>

      <EditItemModal
        item={item}
        open={editOpen}
        onClose={() => setEditOpen(false)}
        onSaved={() => {
          queryClient.invalidateQueries({ queryKey: ['inventory', id] })
          queryClient.invalidateQueries({ queryKey: ['inventory'] })
          toast.success('Item updated.')
          setEditOpen(false)
        }}
      />

      <Modal open={archiveOpen} onClose={() => setArchiveOpen(false)} title="Archive this item?" size="sm">
        <p className="text-sm text-slate-600">
          <span className="font-medium text-slate-800">{item.ref_code}</span> — {item.description}
          {item.lot_number ? ` (lot ${item.lot_number})` : ''} will be archived. Its movement
          history is preserved and it can be restored by an administrator.
        </p>
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setArchiveOpen(false)}>Cancel</Button>
          <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
            <Archive className="h-4 w-4" /> Archive item
          </Button>
        </div>
      </Modal>
    </>
  )
}

interface EditForm {
  ref_code: string
  description: string
  lot_number: string
  quantity: string
  expiry_date: string
  stock_type: string
  location: string
  status: string
  min_threshold: string
  unit_price: string
  barcode: string
  uom: string
}

function toForm(item: InventoryItem): EditForm {
  return {
    ref_code: item.ref_code,
    description: item.description,
    lot_number: item.lot_number ?? '',
    quantity: String(item.quantity),
    expiry_date: item.expiry_date ?? '',
    stock_type: item.stock_type,
    location: item.location,
    status: item.status,
    min_threshold: item.min_threshold != null ? String(item.min_threshold) : '',
    unit_price: item.unit_price != null ? String(item.unit_price) : '',
    barcode: item.barcode ?? '',
    uom: item.uom ?? '',
  }
}

function EditItemModal({ item, open, onClose, onSaved }: {
  item: InventoryItem
  open: boolean
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const { data: meta } = useMeta()
  const [form, setForm] = useState<EditForm>(() => toForm(item))

  // Re-sync the form whenever a different item is loaded.
  const [syncedId, setSyncedId] = useState(item.id)
  if (syncedId !== item.id) {
    setSyncedId(item.id)
    setForm(toForm(item))
  }

  const set = (key: keyof EditForm) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        ref_code: form.ref_code,
        description: form.description,
        lot_number: form.lot_number || null,
        quantity: form.quantity === '' ? 0 : Number(form.quantity),
        expiry_date: form.expiry_date || null,
        stock_type: form.stock_type,
        location: form.location,
        status: form.status,
        min_threshold: form.min_threshold === '' ? null : Number(form.min_threshold),
        unit_price: form.unit_price === '' ? null : Number(form.unit_price),
        barcode: form.barcode || null,
        uom: form.uom || null,
      }
      return (await api.put(`/inventory/${item.id}`, payload)).data
    },
    onSuccess: onSaved,
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Modal open={open} onClose={onClose} title="Edit inventory item" size="lg">
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          mutation.mutate()
        }}
      >
        <Field label="Ref code" required>
          <Input value={form.ref_code} onChange={set('ref_code')} required />
        </Field>
        <Field label="Lot number">
          <Input value={form.lot_number} onChange={set('lot_number')} />
        </Field>
        <Field label="Description" required>
          <Input value={form.description} onChange={set('description')} required />
        </Field>
        <Field label="Quantity" required hint="Manual edits are logged in the audit trail.">
          <Input type="number" min={0} value={form.quantity} onChange={set('quantity')} required />
        </Field>
        <Field label="Expiry date">
          <Input type="date" value={form.expiry_date} onChange={set('expiry_date')} />
        </Field>
        <Field label="Stock type" required>
          <Select value={form.stock_type} onChange={set('stock_type')} required>
            <option value="">Select…</option>
            {meta?.stock_types.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </Field>
        <Field label="Location" required>
          <Select value={form.location} onChange={set('location')} required>
            <option value="">Select…</option>
            {meta?.locations.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </Field>
        <Field label="Status" required>
          <Select value={form.status} onChange={set('status')} required>
            <option value="">Select…</option>
            {meta?.statuses.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </Field>
        <Field label="Min threshold">
          <Input type="number" min={0} value={form.min_threshold} onChange={set('min_threshold')} />
        </Field>
        <Field label="Unit price">
          <Input type="number" min={0} step="0.01" value={form.unit_price} onChange={set('unit_price')} />
        </Field>
        <Field label="Barcode">
          <Input value={form.barcode} onChange={set('barcode')} />
        </Field>
        <Field label="Unit of measure">
          <Input value={form.uom} onChange={set('uom')} />
        </Field>
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Save changes</Button>
        </div>
      </form>
    </Modal>
  )
}

function Detail({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="mt-0.5 text-sm text-slate-800">{value}</dd>
    </div>
  )
}
