import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, Building2 } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useMeta } from '@/hooks/useMeta'
import { Can } from '@/auth/Can'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Textarea, Select } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { humanize } from '@/lib/format'
import type { Hospital, Paginated } from '@/types'

interface Filters {
  q: string
  category: string
  assigned_to_me: boolean
  page: number
}

export default function HospitalListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const { data: meta } = useMeta()

  const [filters, setFilters] = useState<Filters>({
    q: '',
    category: '',
    assigned_to_me: false,
    page: 1,
  })

  const update = (patch: Partial<Filters>) =>
    setFilters((prev) => ({ ...prev, ...patch, page: 'page' in patch ? (patch.page ?? 1) : 1 }))

  const { data, isLoading, error } = useQuery({
    queryKey: ['hospitals', filters],
    queryFn: async () => {
      const params: Record<string, string | number> = { page: filters.page }
      if (filters.q) params.q = filters.q
      if (filters.category) params.category = filters.category
      if (filters.assigned_to_me) params.assigned_to_me = 1
      return (await api.get<Paginated<Hospital>>('/hospitals', { params })).data
    },
  })

  const columns: Column<Hospital>[] = [
    { key: 'name', header: 'Name', render: (h) => <span className="font-medium text-slate-800">{h.name}</span> },
    { key: 'category', header: 'Category', render: (h) => <Badge tone="teal">{humanize(h.category)}</Badge> },
    { key: 'region', header: 'Region', render: (h) => h.region ?? '—' },
    { key: 'assigned_rep', header: 'Assigned Rep', render: (h) => h.assigned_rep?.name ?? '—' },
    { key: 'inventory', header: 'Inventory', render: (h) => h.inventory_count ?? 0 },
  ]

  const [createOpen, setCreateOpen] = useState(false)

  const pageMeta = data?.meta
  const currentPage = pageMeta?.current_page ?? filters.page
  const lastPage = pageMeta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Hospitals"
        description="Browse facilities, assignments and on-site inventory."
        actions={
          <Can permission="hospital.manage">
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="h-4 w-4" /> Add hospital
            </Button>
          </Can>
        }
      />

      <Card className="mb-6">
        <CardBody className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Search">
            <Input
              placeholder="Name, code, city…"
              value={filters.q}
              onChange={(e) => update({ q: e.target.value })}
            />
          </Field>
          <Field label="Category">
            <Select value={filters.category} onChange={(e) => update({ category: e.target.value })}>
              <option value="">All categories</option>
              {meta?.hospital_categories.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </Field>
          <label className="flex items-center gap-2 text-sm font-medium text-slate-700 sm:col-span-2 lg:col-span-1 lg:self-end lg:pb-2">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
              checked={filters.assigned_to_me}
              onChange={(e) => update({ assigned_to_me: e.target.checked })}
            />
            Assigned to me
          </label>
        </CardBody>
      </Card>

      {isLoading ? (
        <LoadingState label="Loading hospitals…" />
      ) : error ? (
        <ErrorState message={apiError(error)} />
      ) : !data || data.data.length === 0 ? (
        <EmptyState
          icon={<Building2 className="h-10 w-10" />}
          title="No hospitals found"
          description="Try adjusting your filters, or add a new hospital."
        />
      ) : (
        <Card>
          <DataTable
            columns={columns}
            rows={data.data}
            rowKey={(h) => h.id}
            onRowClick={(h) => navigate(`/hospitals/${h.id}`)}
          />
          <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
            <span>Page {currentPage} of {lastPage}{pageMeta ? ` · ${pageMeta.total} hospitals` : ''}</span>
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

      <CreateHospitalModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => {
          queryClient.invalidateQueries({ queryKey: ['hospitals'] })
          toast.success('Hospital created.')
          setCreateOpen(false)
        }}
      />
    </>
  )
}

interface CreateForm {
  name: string
  code: string
  category: string
  region: string
  city: string
  province: string
  address: string
  phone: string
  email: string
}

const emptyForm: CreateForm = {
  name: '',
  code: '',
  category: '',
  region: '',
  city: '',
  province: '',
  address: '',
  phone: '',
  email: '',
}

function CreateHospitalModal({ open, onClose, onCreated }: {
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
        name: form.name,
        code: form.code || null,
        category: form.category,
        region: form.region || null,
        city: form.city || null,
        province: form.province || null,
        address: form.address || null,
        phone: form.phone || null,
        email: form.email || null,
      }
      return (await api.post('/hospitals', payload)).data
    },
    onSuccess: () => {
      setForm(emptyForm)
      onCreated()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Modal open={open} onClose={onClose} title="Add hospital" size="lg">
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          mutation.mutate()
        }}
      >
        <Field label="Name" required>
          <Input value={form.name} onChange={set('name')} required />
        </Field>
        <Field label="Code">
          <Input value={form.code} onChange={set('code')} />
        </Field>
        <Field label="Category" required>
          <Select value={form.category} onChange={set('category')} required>
            <option value="">Select…</option>
            {meta?.hospital_categories.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </Field>
        <Field label="Region">
          <Input value={form.region} onChange={set('region')} />
        </Field>
        <Field label="City">
          <Input value={form.city} onChange={set('city')} />
        </Field>
        <Field label="Province">
          <Input value={form.province} onChange={set('province')} />
        </Field>
        <Field label="Address" required={false}>
          <Textarea value={form.address} onChange={set('address')} />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Phone">
            <Input value={form.phone} onChange={set('phone')} />
          </Field>
          <Field label="Email">
            <Input type="email" value={form.email} onChange={set('email')} />
          </Field>
        </div>
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Create hospital</Button>
        </div>
      </form>
    </Modal>
  )
}
