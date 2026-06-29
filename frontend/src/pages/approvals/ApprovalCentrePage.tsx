import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api, apiError } from '@/lib/api'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { cn } from '@/lib/cn'
import { formatDate, humanize } from '@/lib/format'
import type { StockCount, Transfer } from '@/types'

interface ApprovalSummary {
  transfers?: number
  counts?: number
}

type Tab = 'transfers' | 'counts'

export default function ApprovalCentrePage() {
  const navigate = useNavigate()
  const [tab, setTab] = useState<Tab>('transfers')

  const { data: summary } = useQuery({
    queryKey: ['approvals', 'summary'],
    queryFn: async () => (await api.get<ApprovalSummary>('/approvals/summary')).data,
  })

  const transfers = useQuery({
    queryKey: ['approvals', 'transfers'],
    queryFn: async () => (await api.get<Transfer[]>('/approvals/transfers')).data,
  })

  const counts = useQuery({
    queryKey: ['approvals', 'counts'],
    queryFn: async () => (await api.get<StockCount[]>('/approvals/counts')).data,
  })

  const transferColumns: Column<Transfer>[] = [
    { key: 'reference', header: 'Reference', render: (r) => <span className="font-medium text-slate-800">{r.reference}</span> },
    { key: 'type', header: 'Type', render: (r) => r.type_label ?? humanize(r.type) },
    { key: 'hospital', header: 'Hospital', render: (r) => r.hospital?.name ?? 'Boot' },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'created', header: 'Created', render: (r) => formatDate(r.created_at) },
  ]

  const countColumns: Column<StockCount>[] = [
    { key: 'reference', header: 'Reference', render: (r) => <span className="font-medium text-slate-800">{r.reference}</span> },
    { key: 'location', header: 'Location', render: (r) => humanize(r.location) },
    { key: 'assignee', header: 'Assignee', render: (r) => r.assignee?.name ?? '—' },
    {
      key: 'total_variance',
      header: 'Total variance',
      render: (r) => {
        const v = r.total_variance ?? 0
        return <span className={v !== 0 ? 'font-medium text-red-600' : 'text-slate-700'}>{v}</span>
      },
    },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ]

  return (
    <>
      <PageHeader title="Approval Centre" description="Transfers and stock counts awaiting your action." />

      <div className="mb-4 flex gap-2">
        <TabButton active={tab === 'transfers'} onClick={() => setTab('transfers')} label="Pending Transfers" badge={summary?.transfers} />
        <TabButton active={tab === 'counts'} onClick={() => setTab('counts')} label="Pending Counts" badge={summary?.counts} />
      </div>

      {tab === 'transfers' ? (
        <Card>
          <CardBody className="p-0">
            {transfers.isLoading ? (
              <LoadingState label="Loading transfers…" />
            ) : transfers.error ? (
              <div className="p-5">
                <ErrorState message={apiError(transfers.error)} />
              </div>
            ) : (transfers.data?.length ?? 0) === 0 ? (
              <div className="p-5">
                <EmptyState title="No pending transfers" description="Nothing is awaiting approval right now." />
              </div>
            ) : (
              <DataTable
                columns={transferColumns}
                rows={transfers.data ?? []}
                rowKey={(r) => r.id}
                onRowClick={(r) => navigate(`/transfers/${r.id}`)}
              />
            )}
          </CardBody>
        </Card>
      ) : (
        <Card>
          <CardBody className="p-0">
            {counts.isLoading ? (
              <LoadingState label="Loading counts…" />
            ) : counts.error ? (
              <div className="p-5">
                <ErrorState message={apiError(counts.error)} />
              </div>
            ) : (counts.data?.length ?? 0) === 0 ? (
              <div className="p-5">
                <EmptyState title="No pending counts" description="Nothing is awaiting review right now." />
              </div>
            ) : (
              <DataTable
                columns={countColumns}
                rows={counts.data ?? []}
                rowKey={(r) => r.id}
                onRowClick={(r) => navigate(`/stock-counts/${r.id}`)}
              />
            )}
          </CardBody>
        </Card>
      )}
    </>
  )
}

function TabButton({ active, onClick, label, badge }: {
  active: boolean
  onClick: () => void
  label: string
  badge?: number
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-medium transition-colors',
        active
          ? 'border-brand-500 bg-brand-50 text-brand-800'
          : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50',
      )}
    >
      {label}
      {badge != null && badge > 0 && <Badge tone={active ? 'teal' : 'gray'}>{badge}</Badge>}
    </button>
  )
}
