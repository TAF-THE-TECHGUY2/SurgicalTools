import { useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { Can } from '@/auth/Can'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Select } from '@/components/ui/Field'
import { StatusBadge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { formatDate, humanize } from '@/lib/format'
import type { Paginated, Transfer } from '@/types'

const STATUS_OPTIONS = [
  { value: 'pending_approval', label: 'Pending approval' },
  { value: 'completed', label: 'Completed' },
  { value: 'rejected', label: 'Rejected' },
]

export default function TransferListPage() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()

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
    queryKey: ['transfers', { status, page }],
    queryFn: async () =>
      (
        await api.get<Paginated<Transfer>>('/transfers', {
          params: {
            status: status || undefined,
            page,
          },
        })
      ).data,
  })

  const columns: Column<Transfer>[] = [
    { key: 'reference', header: 'Reference', render: (r) => <span className="font-medium text-slate-800">{r.reference}</span> },
    { key: 'from', header: 'From', render: (r) => r.from_location_entity?.name ?? humanize(r.from_location) },
    { key: 'to', header: 'To', render: (r) => r.to_location_entity?.name ?? humanize(r.to_location) },
    { key: 'devices', header: 'Devices', render: (r) => r.items?.length ?? 0 },
    { key: 'requester', header: 'Requested by', render: (r) => r.requester?.name ?? '—' },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'created', header: 'Created', render: (r) => formatDate(r.created_at) },
  ]

  const lastPage = data?.meta?.last_page ?? 1
  const currentPage = data?.meta?.current_page ?? page

  return (
    <>
      <PageHeader
        title="Transfers"
        description="Device movements between locations — signed at request, stock moves on approval."
        actions={
          <Can permission="transfer.create">
            <Button onClick={() => navigate('/transfers/new')}>
              <Plus className="h-4 w-4" /> New transfer
            </Button>
          </Can>
        }
      />

      <Card className="mb-4">
        <CardBody className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Status">
            <Select value={status} onChange={(e) => setParam('status', e.target.value)}>
              <option value="">All statuses</option>
              {STATUS_OPTIONS.map((o) => (
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
            <LoadingState label="Loading transfers…" />
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
                onRowClick={(r) => navigate(`/transfers/${r.id}`)}
                empty="No transfers found."
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
    </>
  )
}
