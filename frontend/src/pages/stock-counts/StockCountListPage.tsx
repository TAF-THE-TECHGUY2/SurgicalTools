import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useMeta } from '@/hooks/useMeta'
import { Can } from '@/auth/Can'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Select, Textarea } from '@/components/ui/Field'
import { StatusBadge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { Modal } from '@/components/ui/Modal'
import { formatDate, humanize } from '@/lib/format'
import type { Paginated, StockCount, User } from '@/types'

export default function StockCountListPage() {
  const navigate = useNavigate()
  const { data: meta } = useMeta()
  const [searchParams, setSearchParams] = useSearchParams()
  const [createOpen, setCreateOpen] = useState(false)

  const status = searchParams.get('status') ?? ''
  const page = Number(searchParams.get('page') ?? '1')

  const setParam = (key: string, value: string) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      if (value) next.set(key, value)
      else next.delete(key)
      if (key !== 'page') next.delete('page')
      return next
    })
  }

  const { data, isLoading, error } = useQuery({
    queryKey: ['stock-counts', { status, page }],
    queryFn: async () =>
      (
        await api.get<Paginated<StockCount>>('/stock-counts', {
          params: {
            status: status || undefined,
            page,
          },
        })
      ).data,
  })

  const columns: Column<StockCount>[] = [
    { key: 'reference', header: 'Reference', render: (r) => <span className="font-medium text-slate-800">{r.reference}</span> },
    { key: 'location', header: 'Location', render: (r) => humanize(r.location) },
    { key: 'assignee', header: 'Assignee', render: (r) => r.assignee?.name ?? '—' },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    {
      key: 'total_variance',
      header: 'Total variance',
      render: (r) => {
        const v = r.total_variance ?? 0
        return <span className={v !== 0 ? 'font-medium text-red-600' : 'text-slate-700'}>{v}</span>
      },
    },
    { key: 'created', header: 'Created', render: (r) => formatDate(r.created_at) },
  ]

  const lastPage = data?.meta?.last_page ?? 1
  const currentPage = data?.meta?.current_page ?? page

  return (
    <>
      <PageHeader
        title="Stock Counts"
        description="Cycle counts and variance reviews across locations."
        actions={
          <Can permission="stock_count.review">
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="h-4 w-4" /> New count request
            </Button>
          </Can>
        }
      />

      <Card className="mb-4">
        <CardBody className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Status">
            <Select value={status} onChange={(e) => setParam('status', e.target.value)}>
              <option value="">All statuses</option>
              {meta?.stock_count_statuses.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </Field>
        </CardBody>
      </Card>

      <Card>
        <CardBody className="p-0">
          {isLoading ? (
            <LoadingState label="Loading stock counts…" />
          ) : error ? (
            <div className="p-5">
              <ErrorState message={apiError(error)} />
            </div>
          ) : (
            <>
              <DataTable
                columns={columns}
                rows={data?.data ?? []}
                rowKey={(r) => r.id}
                onRowClick={(r) => navigate(`/stock-counts/${r.id}`)}
                empty="No stock counts found."
              />
              {lastPage > 1 && (
                <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-600">
                  <span>
                    Page {currentPage} of {lastPage}
                  </span>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={currentPage <= 1}
                      onClick={() => setParam('page', String(currentPage - 1))}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={currentPage >= lastPage}
                      onClick={() => setParam('page', String(currentPage + 1))}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardBody>
      </Card>

      <CreateCountModal open={createOpen} onClose={() => setCreateOpen(false)} />
    </>
  )
}

interface CreateForm {
  location: string
  assigned_to: string
  notes: string
}

const emptyForm: CreateForm = { location: '', assigned_to: '', notes: '' }

function CreateCountModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast()
  const qc = useQueryClient()
  const { data: meta } = useMeta()
  const [form, setForm] = useState<CreateForm>(emptyForm)

  const { data: users } = useQuery({
    queryKey: ['users', 'for-stock-count'],
    queryFn: async () => {
      try {
        return (await api.get<Paginated<User>>('/users')).data.data
      } catch {
        return [] as User[]
      }
    },
  })

  const set = (key: keyof CreateForm) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        location: form.location,
        assigned_to: form.assigned_to === '' ? null : Number(form.assigned_to),
        notes: form.notes || null,
      }
      return (await api.post('/stock-counts', payload)).data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['stock-counts'] })
      toast.success('Stock count request created.')
      setForm(emptyForm)
      onClose()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Modal open={open} onClose={onClose} title="New count request">
      <form
        className="grid gap-4"
        onSubmit={(e) => {
          e.preventDefault()
          mutation.mutate()
        }}
      >
        <Field label="Location" required>
          <Select value={form.location} onChange={set('location')} required>
            <option value="">Select…</option>
            {meta?.locations.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Assigned to">
          <Select value={form.assigned_to} onChange={set('assigned_to')}>
            <option value="">Unassigned</option>
            {(users ?? []).map((u) => (
              <option key={u.id} value={String(u.id)}>
                {u.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Notes">
          <Textarea value={form.notes} onChange={set('notes')} placeholder="Any context for the counter…" />
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={mutation.isPending}>
            Create request
          </Button>
        </div>
      </form>
    </Modal>
  )
}
