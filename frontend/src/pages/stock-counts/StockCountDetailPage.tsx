import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Check, Search } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useToast } from '@/components/ToastProvider'
import { enqueue } from '@/offline/syncQueue'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Field'
import { StatusBadge } from '@/components/ui/Badge'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { cn } from '@/lib/cn'
import { humanize } from '@/lib/format'
import type { StockCount, StockCountItem } from '@/types'

interface SubmitLine {
  id: number
  counted_quantity: number
  notes?: string
}

export default function StockCountDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const qc = useQueryClient()
  const { hasPermission, isAdmin } = useAuth()

  // Local state for counted quantities, keyed by item id (raw string input).
  const [counts, setCounts] = useState<Record<number, string>>({})

  const { data: count, isLoading, error } = useQuery({
    queryKey: ['stock-counts', id],
    queryFn: async () => (await api.get<{ data: StockCount }>(`/stock-counts/${id}`)).data.data,
    enabled: Boolean(id),
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['stock-counts', id] })

  const buildLines = (items: StockCountItem[]): SubmitLine[] =>
    items
      .filter((it) => counts[it.id] !== undefined && counts[it.id] !== '')
      .map((it) => ({ id: it.id, counted_quantity: Number(counts[it.id]) }))

  const submit = useMutation({
    mutationFn: async (lines: SubmitLine[]) =>
      (await api.post(`/stock-counts/${id}/submit`, { lines })).data,
    onSuccess: () => {
      toast.success('Count submitted.')
      void invalidate()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const review = useMutation({
    mutationFn: async (action: 'approve' | 'investigate') =>
      (await api.post(`/stock-counts/${id}/review`, { action })).data,
    onSuccess: (_data, action) => {
      toast.success(action === 'approve' ? 'Variances applied.' : 'Marked for investigation.')
      void invalidate()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const onSubmitCount = async () => {
    if (!count) return
    const lines = buildLines(count.items ?? [])
    if (lines.length === 0) {
      toast.error('Enter at least one counted quantity.')
      return
    }
    if (!navigator.onLine) {
      await enqueue('stock_count.submit', { stock_count_id: Number(id), lines }, `Stock count — ${count.reference}`)
      toast.info('Saved offline — will sync when online')
      return
    }
    submit.mutate(lines)
  }

  if (isLoading) return <LoadingState label="Loading stock count…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!count) return null

  const canCapture = hasPermission('stock_count.capture') || isAdmin
  const canReview = hasPermission('stock_count.review')
  const showSubmit = canCapture && ['requested', 'in_progress', 'submitted'].includes(count.status)
  const showReview = canReview && ['submitted', 'investigating'].includes(count.status)
  const items = count.items ?? []

  return (
    <>
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {count.reference}
            <StatusBadge status={count.status} />
          </span>
        }
        description={humanize(count.location)}
        actions={
          <Button variant="ghost" size="sm" onClick={() => navigate('/stock-counts')}>
            <ArrowLeft className="h-4 w-4" /> Back
          </Button>
        }
      />

      <Card className="mb-6">
        <CardHeader title="Details" />
        <CardBody className="grid gap-2 text-sm sm:grid-cols-2">
          <Row label="Location" value={humanize(count.location)} />
          <Row label="Status" value={humanize(count.status)} />
          <Row label="Requester" value={count.requester?.name ?? '—'} />
          <Row label="Assignee" value={count.assignee?.name ?? '—'} />
          {count.notes && <Row label="Notes" value={count.notes} />}
        </CardBody>
      </Card>

      {(showSubmit || showReview) && (
        <div className="mb-6 flex flex-wrap gap-3">
          {showSubmit && (
            <Button loading={submit.isPending} onClick={() => void onSubmitCount()}>
              <Check className="h-4 w-4" /> Submit count
            </Button>
          )}
          {showReview && (
            <>
              <Button loading={review.isPending} onClick={() => review.mutate('approve')}>
                <Check className="h-4 w-4" /> Approve &amp; apply variances
              </Button>
              <Button variant="outline" loading={review.isPending} onClick={() => review.mutate('investigate')}>
                <Search className="h-4 w-4" /> Mark for investigation
              </Button>
            </>
          )}
        </div>
      )}

      <Card>
        <CardHeader title="Count capture" subtitle={`${items.length} line items`} />
        <CardBody className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-3 font-semibold">Ref</th>
                  <th className="px-4 py-3 font-semibold">Description</th>
                  <th className="px-4 py-3 font-semibold">Lot</th>
                  <th className="px-4 py-3 font-semibold">Expected</th>
                  <th className="px-4 py-3 font-semibold">Counted</th>
                  <th className="px-4 py-3 font-semibold">Variance</th>
                </tr>
              </thead>
              <tbody>
                {items.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-10 text-center text-slate-400">
                      No items on this count.
                    </td>
                  </tr>
                ) : (
                  items.map((it) => {
                    const raw = counts[it.id]
                    const hasCount = raw !== undefined && raw !== ''
                    const variance = hasCount ? Number(raw) - it.expected_quantity : null
                    return (
                      <tr key={it.id} className="border-b border-slate-100">
                        <td className="px-4 py-3 font-medium text-slate-800">{it.ref_code}</td>
                        <td className="px-4 py-3 text-slate-700">{it.description ?? '—'}</td>
                        <td className="px-4 py-3 text-slate-500">{it.lot_number ?? '—'}</td>
                        <td className="px-4 py-3 text-slate-700">{it.expected_quantity}</td>
                        <td className="px-4 py-3">
                          <Input
                            type="number"
                            className="w-28"
                            value={raw ?? ''}
                            disabled={!showSubmit}
                            onChange={(e) =>
                              setCounts((prev) => ({ ...prev, [it.id]: e.target.value }))
                            }
                          />
                        </td>
                        <td className="px-4 py-3">
                          {variance === null ? (
                            <span className="text-slate-400">—</span>
                          ) : (
                            <span
                              className={cn(
                                'font-medium',
                                variance === 0 ? 'text-emerald-600' : 'text-red-600',
                              )}
                            >
                              {variance > 0 ? `+${variance}` : variance}
                            </span>
                          )}
                        </td>
                      </tr>
                    )
                  })
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>
    </>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <span className="text-slate-500">{label}</span>
      <span className="text-right font-medium text-slate-800">{value}</span>
    </div>
  )
}
