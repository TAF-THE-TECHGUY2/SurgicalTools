import { useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useMeta } from '@/hooks/useMeta'
import { Can } from '@/auth/Can'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Select } from '@/components/ui/Field'
import { StatusBadge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { formatDate } from '@/lib/format'
import type { Paginated, Transfer } from '@/types'

export default function TransferListPage() {
  const navigate = useNavigate()
  const { data: meta } = useMeta()
  const [searchParams, setSearchParams] = useSearchParams()

  const type = searchParams.get('type') ?? ''
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
    queryKey: ['transfers', { type, status, page }],
    queryFn: async () =>
      (
        await api.get<Paginated<Transfer>>('/transfers', {
          params: {
            type: type || undefined,
            status: status || undefined,
            page,
          },
        })
      ).data,
  })

  const columns: Column<Transfer>[] = [
    { key: 'reference', header: 'Reference', render: (r) => <span className="font-medium text-slate-800">{r.reference}</span> },
    { key: 'type', header: 'Type', render: (r) => r.type_label ?? r.type },
    { key: 'hospital', header: 'Hospital', render: (r) => r.hospital?.name ?? 'Boot' },
    { key: 'items', header: 'Items', render: (r) => r.items?.length ?? 0 },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'created', header: 'Created', render: (r) => formatDate(r.created_at) },
  ]

  const lastPage = data?.meta?.last_page ?? 1
  const currentPage = data?.meta?.current_page ?? page

  return (
    <>
      <PageHeader
        title="Transfers"
        description="Source-to-boot and boot-to-hospital stock movements."
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
          <Field label="Type">
            <Select value={type} onChange={(e) => setParam('type', e.target.value)}>
              <option value="">All types</option>
              {meta?.transfer_types.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Status">
            <Select value={status} onChange={(e) => setParam('status', e.target.value)}>
              <option value="">All statuses</option>
              {meta?.transfer_statuses.map((o) => (
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
