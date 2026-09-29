import { Fragment, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import {
  AlertTriangle, ArrowLeft, Check, CheckSquare, ClipboardCheck, FileText, Lock, Mail, Minus,
  Printer, ScanLine, Search, Square, Trash2, Undo2,
} from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { openPdf, printPdf } from '@/lib/pdf'
import { useAuth } from '@/auth/AuthContext'
import { useToast } from '@/components/ToastProvider'
import { enqueue } from '@/offline/syncQueue'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { ScanSheet } from '@/components/scanner/ScanSheet'
import { FinishCountModal } from '@/components/stock-counts/FinishCountModal'
import type { SignOff } from '@/components/stock-counts/FinishCountModal'
import { LotAdjustmentsCard } from '@/components/stock-counts/LotAdjustmentsCard'
import { cn } from '@/lib/cn'
import { formatDate, formatDateTime, formatMoney, humanize } from '@/lib/format'
import type { StockCount, StockCountItem } from '@/types'

interface SubmitLine {
  id: number
  counted_quantity: number
}

type DocumentKind = 'sheet' | 'variance' | 'lot-adjustments'

const FLAG: Record<string, string> = {
  lot_mismatch: 'Lot adjustment needed',
  unlisted_item: 'Not on sheet',
  expiry_mismatch: 'Expiry differs',
}

export default function StockCountDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const qc = useQueryClient()
  const { user, hasPermission, isAdmin } = useAuth()

  // Local state for counted quantities, keyed by item id (raw string input).
  const [counts, setCounts] = useState<Record<number, string>>({})
  const [scanning, setScanning] = useState(false)
  const [finishing, setFinishing] = useState(false)
  const [emailOpen, setEmailOpen] = useState(false)
  const [busyLineId, setBusyLineId] = useState<number | null>(null)

  const { data: count, isLoading, error } = useQuery({
    queryKey: ['stock-counts', id],
    queryFn: async () => (await api.get<{ data: StockCount }>(`/stock-counts/${id}`)).data.data,
    enabled: Boolean(id),
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['stock-counts', id] })

  /** Push a fresh count straight into the cache so scanning updates the table live. */
  const applyCount = (updated: StockCount) =>
    qc.setQueryData<StockCount>(['stock-counts', id], (prev) => ({ ...prev, ...updated }))

  const keyed = useMemo(() => {
    const out: Record<number, number> = {}
    for (const [lineId, raw] of Object.entries(counts)) {
      if (raw !== '' && !Number.isNaN(Number(raw))) out[Number(lineId)] = Number(raw)
    }
    return out
  }, [counts])

  const buildLines = (): SubmitLine[] =>
    Object.entries(keyed).map(([lineId, qty]) => ({ id: Number(lineId), counted_quantity: qty }))

  const submit = useMutation({
    mutationFn: async (body: { lines: SubmitLine[] } & SignOff) =>
      (await api.post<{ data: StockCount }>(`/stock-counts/${id}/submit`, body)).data.data,
    onSuccess: (data) => {
      toast.success('Count signed and locked — the signed sheet and variance report are on their way.')
      setFinishing(false)
      setCounts({})
      applyCount(data)
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

  /** Rule 6: MINUS — confirm none found, or undo it. */
  const notFound = useMutation({
    mutationFn: async ({ line, undo }: { line: StockCountItem; undo?: boolean }) => {
      setBusyLineId(line.id)
      const url = `/stock-counts/${id}/lines/${line.id}/not-found`
      return (await (undo ? api.delete<{ data: StockCount }>(url) : api.post<{ data: StockCount }>(url))).data.data
    },
    onSuccess: (data) => applyCount(data),
    onError: (err) => toast.error(apiError(err)),
    onSettled: () => setBusyLineId(null),
  })

  const adjustLot = useMutation({
    mutationFn: async (line: StockCountItem) => {
      setBusyLineId(line.id)
      return (await api.post<{ stock_count: StockCount }>(`/stock-counts/${id}/lines/${line.id}/adjust-lot`)).data
    },
    onSuccess: (data) => {
      toast.success('Lot adjusted — the stock now sits under the lot that was found.')
      applyCount(data.stock_count)
    },
    onError: (err) => toast.error(apiError(err)),
    onSettled: () => setBusyLineId(null),
  })

  const onSign = async (signOff: SignOff) => {
    if (!count) return
    const lines = buildLines()

    if (!navigator.onLine) {
      await enqueue(
        'stock_count.submit',
        { stock_count_id: Number(id), lines, ...signOff },
        `Stock count sign-off — ${count.reference}`,
      )
      toast.info('Signed offline — the count will lock and send when you are back online.')
      setFinishing(false)
      return
    }
    submit.mutate({ lines, ...signOff })
  }

  const showDocument = async (kind: DocumentKind, print = false) => {
    try {
      const path = `/stock-counts/${id}/documents/${kind}`
      if (print) {
        if (await printPdf(path)) toast.info('Tap Share → Print to send it to AirPrint.')
      } else {
        await openPdf(path)
      }
    } catch (err) {
      toast.error(apiError(err))
    }
  }

  if (isLoading) return <LoadingState label="Loading stock count…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!count) return null

  const canCapture = hasPermission('stock_count.capture') || isAdmin
  const canScan = hasPermission('stock_count.scan')
  const canReview = hasPermission('stock_count.review')
  const editable = !count.locked && ['requested', 'in_progress', 'submitted'].includes(count.status)
  const showFinish = canCapture && editable
  const showScan = canScan && editable
  // Counts submitted before sign-off existed are unlocked but still reviewable.
  const showReview = canReview && ['submitted', 'investigating'].includes(count.status)

  const items = count.items ?? []
  const adjustments = items.filter((it) => it.is_adjustment)
  const unresolved = items.filter((it) => !it.is_adjustment && !it.resolved && keyed[it.id] === undefined).length
  const groups = groupBySupplier(items)
  const columns = 11

  return (
    <>
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {count.reference}
            <StatusBadge status={count.status} />
            {count.locked && <Badge tone="blue"><Lock className="mr-1 inline h-3 w-3" />Signed</Badge>}
            {adjustments.length > 0 && (
              <Badge tone="amber">
                {adjustments.length} flagged line{adjustments.length === 1 ? '' : 's'}
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

      {adjustments.length > 0 && !count.locked && (
        <div className="mb-6 flex items-start gap-3 rounded-lg border-l-4 border-orange-400 bg-orange-50 px-4 py-3">
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-orange-600" />
          <div className="text-sm">
            <p className="font-semibold text-orange-900">
              {adjustments.length} new line{adjustments.length === 1 ? '' : 's'} added at the bottom of the sheet
            </p>
            <p className="text-orange-800">
              Scanned items that did not match this location&apos;s count sheet. Admin staff have been
              alerted; a reviewer adjusts lots once the count is signed.
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
          {count.locked && (
            <>
              <Row label="Signed by" value={count.signed_by_name ?? '—'} />
              <Row label="Signed" value={formatDateTime(count.signed_at)} />
              {count.signed_device && <Row label="Device" value={shortDevice(count.signed_device)} />}
            </>
          )}
          {count.notes && <Row label="Notes" value={count.notes} />}
        </CardBody>
      </Card>

      <div className="mb-6 flex flex-wrap gap-3">
        {showScan && (
          <Button onClick={() => setScanning(true)}>
            <ScanLine className="h-4 w-4" /> Scan
          </Button>
        )}
        {showFinish && (
          <Button variant="outline" onClick={() => setFinishing(true)}>
            <ClipboardCheck className="h-4 w-4" /> Finish count
            {unresolved > 0 && <Badge tone="amber" className="ml-1">{unresolved} to resolve</Badge>}
          </Button>
        )}
        <Button variant="outline" onClick={() => void showDocument('sheet', true)}>
          <Printer className="h-4 w-4" /> {count.locked ? 'Print signed sheet' : 'Print draft sheet'}
        </Button>
        {count.locked && (
          <>
            <Button variant="outline" onClick={() => setEmailOpen(true)}>
              <Mail className="h-4 w-4" /> Email sheet
            </Button>
            <Button variant="ghost" onClick={() => void showDocument('variance')}>
              <FileText className="h-4 w-4" /> Variance report
            </Button>
          </>
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

      <LotAdjustmentsCard
        count={count}
        canAdjust={canReview}
        busyLineId={adjustLot.isPending ? busyLineId : null}
        onAdjust={(line) => adjustLot.mutate(line)}
        onOpenReport={() => void showDocument('lot-adjustments')}
      />

      <Card>
        <CardHeader
          title="Count sheet"
          subtitle={`${items.length} line${items.length === 1 ? '' : 's'}${unresolved > 0 && editable ? ` · ${unresolved} not yet scanned` : ''}`}
        />
        <CardBody className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[980px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="w-8 px-3 py-3" aria-label="Ticked" />
                  <th className="px-3 py-3 font-semibold">Item code</th>
                  <th className="px-3 py-3 font-semibold">Description</th>
                  <th className="px-3 py-3 font-semibold">Group</th>
                  <th className="px-3 py-3 font-semibold">Lot / Exp</th>
                  <th className="px-3 py-3 text-right font-semibold">List/Unit</th>
                  <th className="px-3 py-3 text-right font-semibold">Syst</th>
                  <th className="px-3 py-3 text-right font-semibold">Scanned</th>
                  <th className="px-3 py-3 font-semibold">Act</th>
                  <th className="px-3 py-3 text-right font-semibold">Variance</th>
                  <th className="px-3 py-3" />
                </tr>
              </thead>
              <tbody>
                {items.length === 0 ? (
                  <tr>
                    <td colSpan={columns} className="px-4 py-10 text-center text-slate-400">
                      No items on this count.
                    </td>
                  </tr>
                ) : (
                  groups.map(([supplier, lines]) => (
                    <Fragment key={supplier}>
                      <tr className="bg-brand-50/50">
                        <td colSpan={columns} className="px-3 py-2 text-xs font-bold uppercase tracking-wide text-brand-800">
                          {supplier}
                        </td>
                      </tr>
                      {lines.map((it) => {
                        const raw = counts[it.id]
                        const hasCount = raw !== undefined && raw !== ''
                        const effective = hasCount
                          ? Number(raw)
                          : it.counted_quantity ?? (it.scanned_quantity > 0 ? it.scanned_quantity : null)
                        const variance = effective === null ? null : effective - it.expected_quantity
                        const ticked = it.ticked || (hasCount && Number(raw) > 0)
                        const unscanned = !it.is_adjustment && it.scanned_quantity === 0

                        return (
                          <tr
                            key={it.id}
                            className={cn(
                              'border-b',
                              it.is_adjustment
                                ? 'border-l-4 border-orange-400 border-b-orange-200 bg-orange-50'
                                : it.not_found_at
                                  ? 'border-slate-100 bg-red-50/40'
                                  : 'border-slate-100',
                            )}
                          >
                            <td className="px-3 py-3">
                              {ticked
                                ? <CheckSquare className="h-4 w-4 text-emerald-600" aria-label="Ticked" />
                                : <Square className="h-4 w-4 text-slate-300" aria-label="Not ticked" />}
                            </td>
                            <td className="px-3 py-3 font-medium text-slate-800">
                              {it.item_code ?? it.ref_code}
                              <span className="block text-xs font-normal text-slate-500">REF {it.ref_code}</span>
                            </td>
                            <td className="px-3 py-3 text-slate-700">
                              {it.description ?? '—'}
                              {it.adjustment_type && (
                                <span className="mt-1 block"><Badge tone="amber">{FLAG[it.adjustment_type]}</Badge></span>
                              )}
                              {it.not_found_at && (
                                <span className="mt-1 block"><Badge tone="red">None found</Badge></span>
                              )}
                            </td>
                            <td className="px-3 py-3 text-slate-500">{it.product_group ?? '—'}</td>
                            <td className="px-3 py-3 text-slate-500">
                              {it.expected_lot_number && (
                                <span className="mr-1.5 text-orange-700 line-through">{it.expected_lot_number}</span>
                              )}
                              <span className={it.is_adjustment ? 'font-semibold text-orange-900' : 'text-slate-700'}>
                                {it.lot_number ?? '—'}
                              </span>
                              <span className="block text-xs">{formatDate(it.expiry_date, 'd MMM yyyy')}</span>
                            </td>
                            <td className="px-3 py-3 text-right text-slate-600">
                              {it.unit_price != null ? formatMoney(it.unit_price) : '—'}
                            </td>
                            <td className="px-3 py-3 text-right text-slate-700">{it.expected_quantity}</td>
                            <td className="px-3 py-3 text-right text-slate-700">
                              {it.scanned_quantity > 0 ? it.scanned_quantity : <span className="text-slate-300">—</span>}
                            </td>
                            <td className="px-3 py-3">
                              <Input
                                type="number"
                                min={0}
                                className="w-20"
                                aria-label={`Actual quantity for ${it.ref_code} lot ${it.lot_number ?? ''}`}
                                value={raw ?? ''}
                                placeholder={effective != null ? String(effective) : ''}
                                disabled={!showFinish}
                                onChange={(e) => setCounts((prev) => ({ ...prev, [it.id]: e.target.value }))}
                              />
                            </td>
                            <td className="px-3 py-3 text-right">
                              {variance === null ? (
                                <span className="text-slate-400">—</span>
                              ) : (
                                <span className={cn('font-medium', variance === 0 ? 'text-emerald-600' : 'text-red-600')}>
                                  {variance > 0 ? `+${variance}` : variance}
                                </span>
                              )}
                            </td>
                            <td className="px-3 py-3 text-right">
                              {showFinish && unscanned && !it.not_found_at && !hasCount && (
                                <button
                                  aria-label={`None found: ${it.ref_code} lot ${it.lot_number ?? ''}`}
                                  title="None found"
                                  disabled={notFound.isPending}
                                  onClick={() => notFound.mutate({ line: it })}
                                  className="rounded-md p-1.5 text-red-600 hover:bg-red-50 disabled:opacity-50"
                                >
                                  <Minus className="h-4 w-4" />
                                </button>
                              )}
                              {showFinish && it.not_found_at && (
                                <button
                                  aria-label={`Undo none found: ${it.ref_code}`}
                                  title="Undo none found"
                                  disabled={notFound.isPending}
                                  onClick={() => notFound.mutate({ line: it, undo: true })}
                                  className="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-50"
                                >
                                  <Undo2 className="h-4 w-4" />
                                </button>
                              )}
                              {showScan && it.is_adjustment && (
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
                          </tr>
                        )
                      })}
                    </Fragment>
                  ))
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

      {finishing && (
        <FinishCountModal
          count={count}
          keyed={keyed}
          defaultName={user?.name ?? ''}
          busyLineId={notFound.isPending ? busyLineId : null}
          signing={submit.isPending}
          onMinus={(line) => notFound.mutate({ line })}
          onScan={() => { setFinishing(false); setScanning(true) }}
          onSign={(signOff) => void onSign(signOff)}
          onClose={() => setFinishing(false)}
        />
      )}

      {emailOpen && (
        <EmailSheetModal
          countId={count.id}
          defaultEmail={count.hospital?.email ?? ''}
          onClose={() => setEmailOpen(false)}
        />
      )}
    </>
  )
}

/**
 * The paper sheet's order: grouped by supplier, sheet lines first, then the
 * lines the count added at the bottom of their group.
 */
function groupBySupplier(items: StockCountItem[]): [string, StockCountItem[]][] {
  const groups = new Map<string, StockCountItem[]>()
  for (const it of items) {
    const key = it.supplier || 'Unassigned supplier'
    groups.set(key, [...(groups.get(key) ?? []), it])
  }
  return [...groups.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([k, lines]) => [k, [...lines].sort((a, b) =>
      Number(a.is_adjustment) - Number(b.is_adjustment)
      || (a.item_code ?? a.ref_code).localeCompare(b.item_code ?? b.ref_code)
      || (a.lot_number ?? '').localeCompare(b.lot_number ?? ''))])
}

/** "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 …)" → "iPhone; CPU iPhone OS 17_5". */
function shortDevice(ua: string): string {
  const inner = /\(([^)]+)\)/.exec(ua)?.[1]
  return (inner ?? ua).split(';').slice(0, 2).join(';').trim()
}

function EmailSheetModal({ countId, defaultEmail, onClose }: {
  countId: number
  defaultEmail: string
  onClose: () => void
}) {
  const toast = useToast()
  const [emails, setEmails] = useState(defaultEmail)

  const send = useMutation({
    mutationFn: async (list: string[]) =>
      (await api.post(`/stock-counts/${countId}/email-sheet`, { emails: list })).data,
    onSuccess: () => {
      toast.success('Signed sheet sent.')
      onClose()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const list = emails.split(/[\s,;]+/).map((e) => e.trim()).filter(Boolean)

  return (
    <Modal open onClose={onClose} title="Email signed sheet" size="sm">
      <Field label="Send to" hint="Separate several addresses with commas.">
        <Input
          type="text"
          inputMode="email"
          value={emails}
          placeholder="theatre@hospital.co.za"
          onChange={(e) => setEmails(e.target.value)}
        />
      </Field>
      <div className="mt-4 flex justify-end gap-3">
        <Button variant="ghost" onClick={onClose}>Cancel</Button>
        <Button loading={send.isPending} disabled={list.length === 0} onClick={() => send.mutate(list)}>
          <Mail className="h-4 w-4" /> Send
        </Button>
      </div>
    </Modal>
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
