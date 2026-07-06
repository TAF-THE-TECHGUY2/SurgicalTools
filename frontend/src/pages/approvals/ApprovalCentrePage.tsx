import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { api, apiError } from '@/lib/api'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Textarea } from '@/components/ui/Field'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { cn } from '@/lib/cn'
import { formatDate, humanize } from '@/lib/format'
import type { Paginated, StockCount, Transfer } from '@/types'

interface ApprovalSummary {
  pending_transfers?: number
  pending_counts?: number
  unread_notifications?: number
}

type Tab = 'transfers' | 'counts'

export default function ApprovalCentrePage() {
  const navigate = useNavigate()
  const toast = useToast()
  const qc = useQueryClient()
  const [tab, setTab] = useState<Tab>('transfers')

  const [rejectId, setRejectId] = useState<number | null>(null)
  const [rejectReason, setRejectReason] = useState('')

  const { data: summary } = useQuery({
    queryKey: ['approvals', 'summary'],
    queryFn: async () => (await api.get<ApprovalSummary>('/approvals/summary')).data,
  })

  const transfers = useQuery({
    queryKey: ['approvals', 'transfers'],
    queryFn: async () => (await api.get<Paginated<Transfer>>('/approvals/transfers')).data.data,
  })

  const counts = useQuery({
    queryKey: ['approvals', 'counts'],
    queryFn: async () => (await api.get<Paginated<StockCount>>('/approvals/counts')).data.data,
  })

  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: ['approvals'] })
    void qc.invalidateQueries({ queryKey: ['transfers'] })
  }

  const approve = useMutation({
    mutationFn: async (id: number) => (await api.post(`/transfers/${id}/approve`)).data,
    onSuccess: () => {
      toast.success('Transfer approved — stock moved.')
      invalidate()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const reject = useMutation({
    mutationFn: async ({ id, reason }: { id: number; reason: string }) =>
      (await api.post(`/transfers/${id}/reject`, { reason })).data,
    onSuccess: () => {
      toast.success('Transfer rejected.')
      setRejectId(null)
      setRejectReason('')
      invalidate()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const transferColumns: Column<Transfer>[] = [
    { key: 'reference', header: 'Reference', render: (r) => <span className="font-medium text-slate-800">{r.reference}</span> },
    { key: 'from', header: 'From', render: (r) => r.from_location_entity?.name ?? humanize(r.from_location) },
    { key: 'to', header: 'To', render: (r) => r.to_location_entity?.name ?? humanize(r.to_location) },
    {
      key: 'devices',
      header: 'Devices',
      render: (r) => {
        const items = r.items ?? []
        const serials = items
          .map((i) => i.serial_number)
          .filter((s): s is string => Boolean(s))
        return (
          <div>
            <span className="font-medium text-slate-800">{items.length}</span>
            {serials.length > 0 && (
              <p className="text-xs text-slate-400">
                {serials.slice(0, 3).join(', ')}
                {serials.length > 3 && ` +${serials.length - 3} more`}
              </p>
            )}
          </div>
        )
      },
    },
    { key: 'requester', header: 'Requested by', render: (r) => r.requester?.name ?? '—' },
    {
      key: 'signed',
      header: 'Signed',
      render: (r) => {
        const sig = r.signatures?.[0]
        return sig ? `✓ ${formatDate(sig.signed_at)}` : '—'
      },
    },
    { key: 'created', header: 'Created', render: (r) => formatDate(r.created_at) },
    {
      key: 'actions',
      header: '',
      className: 'text-right',
      render: (r) => (
        <div className="flex justify-end gap-2" onClick={(e) => e.stopPropagation()}>
          <Button
            size="sm"
            loading={approve.isPending && approve.variables === r.id}
            onClick={() => approve.mutate(r.id)}
          >
            Approve
          </Button>
          <Button size="sm" variant="danger" onClick={() => setRejectId(r.id)}>
            Reject
          </Button>
        </div>
      ),
    },
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
        <TabButton active={tab === 'transfers'} onClick={() => setTab('transfers')} label="Pending Transfers" badge={summary?.pending_transfers} />
        <TabButton active={tab === 'counts'} onClick={() => setTab('counts')} label="Pending Counts" badge={summary?.pending_counts} />
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

      <Modal open={rejectId !== null} onClose={() => setRejectId(null)} title="Reject transfer">
        <div className="grid gap-4">
          <Field label="Reason" required>
            <Textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              placeholder="Explain why this transfer is being rejected…"
            />
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setRejectId(null)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={reject.isPending}
              disabled={!rejectReason.trim()}
              onClick={() => {
                if (rejectId !== null) reject.mutate({ id: rejectId, reason: rejectReason })
              }}
            >
              Reject
            </Button>
          </div>
        </div>
      </Modal>
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
