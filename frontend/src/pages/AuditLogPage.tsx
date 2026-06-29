import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, apiError } from '@/lib/api'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { formatDateTime, humanize } from '@/lib/format'

interface Activity {
  id: number
  description: string
  event?: string | null
  subject_type?: string | null
  created_at: string
  causer?: { name: string; email: string } | null
}

interface Paginator<T> {
  data: T[]
  current_page: number
  last_page: number
}

const eventTone: Record<string, 'green' | 'blue' | 'red' | 'gray'> = {
  created: 'green', updated: 'blue', deleted: 'red',
}

export default function AuditLogPage() {
  const [page, setPage] = useState(1)

  const { data, isLoading, error } = useQuery({
    queryKey: ['audit-logs', page],
    queryFn: async () => (await api.get<Paginator<Activity>>('/audit-logs', { params: { page } })).data,
  })

  const columns: Column<Activity>[] = [
    { key: 'description', header: 'Action', render: (a) => <span className="font-medium text-slate-800">{humanize(a.description)}</span> },
    { key: 'event', header: 'Event', render: (a) => a.event ? <Badge tone={eventTone[a.event] ?? 'gray'}>{a.event}</Badge> : '—' },
    { key: 'subject', header: 'Subject', render: (a) => a.subject_type ? a.subject_type.split('\\').pop() : '—' },
    { key: 'causer', header: 'By', render: (a) => a.causer?.name ?? 'system' },
    { key: 'when', header: 'When', render: (a) => formatDateTime(a.created_at) },
  ]

  return (
    <>
      <PageHeader title="Audit Log" description="Immutable trail of every change across the ERP." />

      <Card>
        <CardBody className="p-0">
          {isLoading ? (
            <LoadingState />
          ) : error ? (
            <div className="p-5"><ErrorState message={apiError(error)} /></div>
          ) : (
            <>
              <DataTable columns={columns} rows={data?.data ?? []} rowKey={(a) => a.id} empty="No activity recorded yet." />
              <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
                <span>Page {data?.current_page ?? 1} of {data?.last_page ?? 1}</span>
                <div className="flex gap-2">
                  <Button size="sm" variant="outline" disabled={(data?.current_page ?? 1) <= 1} onClick={() => setPage((p) => p - 1)}>Prev</Button>
                  <Button size="sm" variant="outline" disabled={(data?.current_page ?? 1) >= (data?.last_page ?? 1)} onClick={() => setPage((p) => p + 1)}>Next</Button>
                </div>
              </div>
            </>
          )}
        </CardBody>
      </Card>
    </>
  )
}
