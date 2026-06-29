import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Plus, AlertTriangle } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useMeta } from '@/hooks/useMeta'
import { Can } from '@/auth/Can'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/Field'
import { StatusBadge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { formatDate, humanize } from '@/lib/format'
import type { InventoryItem, Paginated } from '@/types'

interface Filters {
  q: string
  status: string
  location: string
  stock_type: string
  low_stock: boolean
  page: number
}

export default function InventoryListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const { data: meta } = useMeta()
  const [searchParams] = useSearchParams()

  const [filters, setFilters] = useState<Filters>({
    q: searchParams.get('q') ?? '',
    status: '',
    location: '',
    stock_type: '',
    low_stock: searchParams.get('low_stock') === '1',
    page: 1,
  })

  const update = (patch: Partial<Filters>) =>
    setFilters((prev) => ({ ...prev, ...patch, page: 'page' in patch ? (patch.page ?? 1) : 1 }))

  const { data, isLoading, error } = useQuery({
    queryKey: ['inventory', filters],
    queryFn: async () => {
      const params: Record<string, string | number> = { page: filters.page }
      if (filters.q) params.q = filters.q
      if (filters.status) params.status = filters.status
      if (filters.location) params.location = filters.location
      if (filters.stock_type) params.stock_type = filters.stock_type
      if (filters.low_stock) params.low_stock = 1
      return (await api.get<Paginated<InventoryItem>>('/inventory', { params })).data
    },
  })

  const columns: Column<InventoryItem>[] = [
    { key: 'ref_code', header: 'Ref Code', render: (r) => <span className="font-medium text-slate-800">{r.ref_code}</span> },
    { key: 'description', header: 'Description', render: (r) => r.description },
    { key: 'lot', header: 'Lot', render: (r) => r.lot_number ?? '—' },
    {
      key: 'quantity',
      header: 'Qty',
      render: (r) => (
        <span className="inline-flex items-center gap-1.5 font-medium text-slate-800">
          {r.quantity}
          {r.is_low_stock && <AlertTriangle className="h-4 w-4 text-amber-500" aria-label="Low stock" />}
        </span>
      ),
    },
    {
      key: 'expiry',
      header: 'Expiry',
      render: (r) => {
        if (!r.expiry_date) return '—'
        const critical = r.days_to_expiry != null && r.days_to_expiry <= 30
        return (
          <div>
            <p className="text-slate-700">{formatDate(r.expiry_date)}</p>
            {r.days_to_expiry != null && (
              <p className={critical ? 'text-xs text-red-600' : 'text-xs text-slate-400'}>
                {r.days_to_expiry} days
              </p>
            )}
          </div>
        )
      },
    },
    { key: 'location', header: 'Location', render: (r) => humanize(r.location) },
    { key: 'stock_type', header: 'Stock Type', render: (r) => humanize(r.stock_type) },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ]

  const [createOpen, setCreateOpen] = useState(false)

  const pageMeta = data?.meta
  const currentPage = pageMeta?.current_page ?? filters.page
  const lastPage = pageMeta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Inventory"
        description="Browse stock lines across all locations and holders."
        actions={
          <Can permission="inventory.manage">
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="h-4 w-4" /> New item
            </Button>
          </Can>
        }
      />

      <Card className="mb-6">
        <CardBody className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Search">
            <Input
              placeholder="Ref code, description, lot…"
              value={filters.q}
              onChange={(e) => update({ q: e.target.value })}
            />
          </Field>
          <Field label="Status">
            <Select value={filters.status} onChange={(e) => update({ status: e.target.value })}>
              <option value="">All statuses</option>
              {meta?.statuses.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </Field>
          <Field label="Location">
            <Select value={filters.location} onChange={(e) => update({ location: e.target.value })}>
              <option value="">All locations</option>
              {meta?.locations.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </Field>
          <Field label="Stock type">
            <Select value={filters.stock_type} onChange={(e) => update({ stock_type: e.target.value })}>
              <option value="">All stock types</option>
              {meta?.stock_types.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </Field>
          <label className="flex items-center gap-2 text-sm font-medium text-slate-700 sm:col-span-2 lg:col-span-4">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
              checked={filters.low_stock}
              onChange={(e) => update({ low_stock: e.target.checked })}
            />
            Low stock only
          </label>
        </CardBody>
      </Card>

      {isLoading ? (
        <LoadingState label="Loading inventory…" />
      ) : error ? (
        <ErrorState message={apiError(error)} />
      ) : !data || data.data.length === 0 ? (
        <EmptyState
          icon={<AlertTriangle className="h-10 w-10" />}
          title="No inventory found"
          description="Try adjusting your filters, or add a new stock line."
        />
      ) : (
        <Card>
          <DataTable
            columns={columns}
            rows={data.data}
            rowKey={(r) => r.id}
            onRowClick={(r) => navigate(`/inventory/${r.id}`)}
          />
          <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
            <span>Page {currentPage} of {lastPage}{pageMeta ? ` · ${pageMeta.total} items` : ''}</span>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage <= 1}
                onClick={() => update({ page: currentPage - 1 })}
              >
                Prev
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage >= lastPage}
                onClick={() => update({ page: currentPage + 1 })}
              >
                Next
              </Button>
            </div>
          </div>
        </Card>
      )}

      <CreateItemModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => {
          queryClient.invalidateQueries({ queryKey: ['inventory'] })
          toast.success('Inventory item created.')
          setCreateOpen(false)
        }}
      />
    </>
  )
}

interface CreateForm {
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
}

const emptyForm: CreateForm = {
  ref_code: '',
  description: '',
  lot_number: '',
  quantity: '',
  expiry_date: '',
  stock_type: '',
  location: '',
  status: '',
  min_threshold: '',
  unit_price: '',
}

function CreateItemModal({ open, onClose, onCreated }: {
  open: boolean
  onClose: () => void
  onCreated: () => void
}) {
  const toast = useToast()
  const { data: meta } = useMeta()
  const [form, setForm] = useState<CreateForm>(emptyForm)

  const set = (key: keyof CreateForm) => (e: { target: { value: string } }) =>
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
      }
      return (await api.post('/inventory', payload)).data
    },
    onSuccess: () => {
      setForm(emptyForm)
      onCreated()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Modal open={open} onClose={onClose} title="New inventory item" size="lg">
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
        <Field label="Quantity" required>
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
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Create item</Button>
        </div>
      </form>
    </Modal>
  )
}
