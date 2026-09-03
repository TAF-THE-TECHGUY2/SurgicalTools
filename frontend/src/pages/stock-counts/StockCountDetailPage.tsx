import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { AlertTriangle, ArrowLeft, Check, ScanLine, Search, Trash2 } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useToast } from '@/components/ToastProvider'
import { enqueue } from '@/offline/syncQueue'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Field'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { ScanSheet } from '@/components/scanner/ScanSheet'
import { cn } from '@/lib/cn'
import { formatDate, humanize } from '@/lib/format'
import type { StockCount, StockCountItem } from '@/types'

interface SubmitLine {
  id: number
  counted_quantity: number
}

export default function StockCountDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const qc = useQueryClient()
  const { hasPermission, isAdmin } = useAuth()

  // Local state for counted quantities, keyed by item id (raw string input).
  const [counts, setCounts] = useState<Record<number, string>>({})
  const [scanning, setScanning] = useState(false)

  const { data: count, isLoading, error } = useQuery({
    queryKey: ['stock-counts', id],
    queryFn: async () => (await api.get<{ data: StockCount }>(`/stock-counts/${id}`)).data.data,
    enabled: Boolean(id),
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['stock-counts', id] })

  /** Push a fresh count straight into the cache so scanning updates the table live. */
  const applyCount = (updated: StockCount) =>
    qc.setQueryData(['stock-counts', id], updated)

  const buildLines = (items: StockCountItem[]): SubmitLine[] =>
    items
      .filter((it) => counts[it.id] !== undefined && counts[it.id] !== '')
      .map((it) => ({ id: it.id, counted_quantity: Number(counts[it.id]) }))

  const submit = useMutation({
    mutationFn: async (lines: SubmitLine[]) =>
      (await api.post(`/stock-counts/${id}/submit`, { lines })).data,
    onSuccess: () => {
      toast.success('Count submitted — the summary report is on its way.')
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

  const removeLine = useMutation({
    mutationFn: async (lineId: number) =>
      (await api.delete<{ stock_count: StockCount }>(`/stock-counts/${id}/lines/${lineId}`)).data,
    onSuccess: (data) => {
      toast.success('Capture removed.')
      applyCount(data.stock_count)
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const onSubmitCount = async () => {
    if (!count) return
    const lines = buildLines(count.items ?? [])
    const scanned = (count.items ?? []).some((it) => it.scanned_quantity > 0)

    // A count done entirely by scanning has nothing keyed — the server folds
    // the scan tallies in on submit.
    if (lines.length === 0 && !scanned) {
      toast.error('Scan or enter at least one quantity.')
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
  const canScan = hasPermission('stock_count.scan')
  const canReview = hasPermission('stock_count.review')
  const isOpen = ['requested', 'in_progress', 'submitted'].includes(count.status)
  const showSubmit = canCapture && isOpen
  const showScan = canScan && isOpen
  const showReview = canReview && ['submitted', 'investigating'].includes(count.status)

  const items = count.items ?? []
  const adjustments = items.filter((it) => it.is_adjustment)

  return (
    <>
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {count.reference}
            <StatusBadge status={count.status} />
            {adjustments.length > 0 && (
              <Badge tone="amber">
                {adjustments.length} adjustment{adjustments.length === 1 ? '' : 's'}
              </Badge>
            )}
          </span>
        }
        description={humanize(count.location)}
        actions={
          <Button variant="ghost" size="sm" onClick={() => navigate('/stock-counts')}>
            <ArrowLeft className="h-4 w-4" /> Back
          </Button>
        }
      />

      {adjustments.length > 0 && (
        <div className="mb-6 flex items-start gap-3 rounded-lg border-l-4 border-orange-400 bg-orange-50 px-4 py-3">
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-orange-600" />
          <div className="text-sm">
            <p className="font-semibold text-orange-900">
              {adjustments.length} Lot/Stock Adjustment{adjustments.length === 1 ? '' : 's'} flagged
            </p>
            <p className="text-orange-800">
              Scanned items that did not match this location&apos;s expected inventory. Admin staff
              have been alerted. These lines have not been applied to stock.
            </p>
          </div>
        </div>
      )}

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

      {(showSubmit || showReview || showScan) && (
        <div className="mb-6 flex flex-wrap gap-3">
          {showScan && (
            <Button onClick={() => setScanning(true)}>
              <ScanLine className="h-4 w-4" /> Scan
            </Button>
          )}
          {showSubmit && (
            <Button variant="outline" loading={submit.isPending} onClick={() => void onSubmitCount()}>
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
        <CardHeader
          title="Count capture"
          subtitle={`${items.length} line item${items.length === 1 ? '' : 's'}`}
        />
        <CardBody className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-3 font-semibold">Ref</th>
                  <th className="px-4 py-3 font-semibold">Description</th>
                  <th className="px-4 py-3 font-semibold">Lot</th>
                  <th className="px-4 py-3 font-semibold">Expiry</th>
                  <th className="px-4 py-3 text-right font-semibold">Expected</th>
                  <th className="px-4 py-3 text-right font-semibold">Scanned</th>
                  <th className="px-4 py-3 font-semibold">Counted</th>
                  <th className="px-4 py-3 text-right font-semibold">Variance</th>
                  {showScan && <th className="px-4 py-3" />}
                </tr>
              </thead>
              <tbody>
                {items.length === 0 ? (
                  <tr>
                    <td colSpan={showScan ? 9 : 8} className="px-4 py-10 text-center text-slate-400">
                      No items on this count.
                    </td>
                  </tr>
                ) : (
                  items.map((it) => {
                    const raw = counts[it.id]
                    const hasCount = raw !== undefined && raw !== ''
                    const effective = hasCount
                      ? Number(raw)
                      : it.counted_quantity ?? (it.scanned_quantity > 0 ? it.scanned_quantity : null)
                    const variance = effective === null ? null : effective - it.expected_quantity

                    return (
                      <tr
                        key={it.id}
                        className={cn(
                          'border-b',
                          // Spec §6: adjustment rows render with an explicit
                          // orange highlight before the admin confirms them.
                          it.is_adjustment
                            ? 'border-l-4 border-orange-400 border-b-orange-200 bg-orange-50'
                            : 'border-slate-100',
                        )}
                      >
                        <td className="px-4 py-3 font-medium text-slate-800">
                          {it.ref_code}
                          {it.is_adjustment && (
                            <span className="mt-1 block">
                              <Badge tone="amber">{it.adjustment_label ?? 'Adjustment'}</Badge>
                            </span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-slate-700">{it.description ?? '—'}</td>
                        <td className="px-4 py-3 text-slate-500">
                          {it.expected_lot_number && (
                            <span className="mr-1.5 text-orange-700 line-through">
                              {it.expected_lot_number}
                            </span>
                          )}
                          <span className={it.is_adjustment ? 'font-semibold text-orange-900' : ''}>
                            {it.lot_number ?? '—'}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-slate-500">{formatDate(it.expiry_date)}</td>
                        <td className="px-4 py-3 text-right text-slate-700">
                          {it.is_adjustment ? <span className="text-slate-400">—</span> : it.expected_quantity}
                        </td>
                        <td className="px-4 py-3 text-right text-slate-700">
                          {it.scanned_quantity > 0 ? it.scanned_quantity : <span className="text-slate-300">—</span>}
                        </td>
                        <td className="px-4 py-3">
                          <Input
                            type="number"
                            min={0}
                            className="w-24"
                            value={raw ?? ''}
                            placeholder={
                              it.counted_quantity != null
                                ? String(it.counted_quantity)
                                : it.scanned_quantity > 0
                                  ? String(it.scanned_quantity)
                                  : ''
                            }
                            disabled={!showSubmit}
                            onChange={(e) => setCounts((prev) => ({ ...prev, [it.id]: e.target.value }))}
                          />
                        </td>
                        <td className="px-4 py-3 text-right">
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
                        {showScan && (
                          <td className="px-4 py-3 text-right">
                            {it.is_adjustment && (
                              <button
                                aria-label={`Remove ${it.ref_code} adjustment`}
                                title="Remove this mis-scan"
                                disabled={removeLine.isPending}
                                onClick={() => removeLine.mutate(it.id)}
                                className="rounded-md p-1.5 text-orange-700 hover:bg-orange-100 disabled:opacity-50"
                              >
                                <Trash2 className="h-4 w-4" />
                              </button>
                            )}
                          </td>
                        )}
                      </tr>
                    )
                  })
                )}
              </tbody>
            </table>
          </div>
        </CardBody>
      </Card>

      {scanning && (
        <ScanSheet
          count={count}
          onClose={() => { setScanning(false); void invalidate() }}
          onChange={applyCount}
        />
      )}
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
