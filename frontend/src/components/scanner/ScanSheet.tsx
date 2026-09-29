import { useCallback, useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Barcode, CheckCircle2, Loader2, ScanLine } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useToast } from '@/components/ToastProvider'
import { enqueue } from '@/offline/syncQueue'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { cn } from '@/lib/cn'
import { useBarcodeScanner } from '@/components/scanner/useBarcodeScanner'
import {
  ManualLabelEntry, MatchBadge, ScannerEmptyState, ScannerFrame,
} from '@/components/scanner/ScannerFrame'
import { isAdjustmentResult } from '@/lib/scan'
import type {
  ScanExtractResponse, ScanExtraction, ScanResponse, StockCount, StockCountScan, SupplierLabelTemplate,
} from '@/types'

/** A capture and what the server made of it, held in the session list. */
interface ScanRow {
  key: string
  /** Present once the server has recorded it. */
  scan?: StockCountScan
  extraction?: ScanExtraction
  status: 'sending' | 'recorded' | 'review' | 'error' | 'queued'
  message?: string
  /** Bumped when a second barcode fills in fields, so the review form re-reads them. */
  merges?: number
}

const EMPTY_MANUAL = { ref: '', lot: '', expiry: '' }

/**
 * Stock-count scanning. Each capture posts to the count, and the server owns
 * the cross-reference — matched lines are tallied, discrepancies INSERT an
 * orange line.
 */
export function ScanSheet({ count, onClose, onChange }: {
  count: StockCount
  onClose: () => void
  onChange: (updated: StockCount) => void
}) {
  const toast = useToast()
  const [rows, setRows] = useState<ScanRow[]>([])
  const [manualOpen, setManualOpen] = useState(false)
  const [manual, setManual] = useState(EMPTY_MANUAL)
  const [capturing, setCapturing] = useState(false)
  const [savingManual, setSavingManual] = useState(false)
  /** Supplier label template to read with; '' lets the server detect it. */
  const [templateId, setTemplateId] = useState('')
  /** A held capture waiting for the other barcode on the same label. */
  const [mergeInto, setMergeInto] = useState<string | null>(null)

  const { data: templates = [] } = useQuery({
    queryKey: ['label-templates'],
    queryFn: async () => (await api.get<{ data: SupplierLabelTemplate[] }>('/label-templates')).data.data,
    staleTime: 5 * 60_000,
    retry: false,
  })

  // A row awaiting confirmation pauses decoding, so the runner isn't fighting
  // new reads while correcting one — unless it asked for the label's other
  // barcode, which is exactly the next read it wants.
  const reviewing = rows.some((r) => r.status === 'review') && mergeInto === null

  const send = useCallback(
    async (input: Record<string, string>, blob?: { data: Blob; mime: string }) => {
      const body = templateId ? { ...input, template_id: templateId } : input
      const key = crypto.randomUUID()
      setRows((prev) => [{ key, status: 'sending' }, ...prev])

      const settle = (patch: Partial<ScanRow>) =>
        setRows((prev) => prev.map((r) => (r.key === key ? { ...r, ...patch } : r)))

      // Offline: queue it. Barcode text syncs as JSON; a photo rides alongside.
      if (!navigator.onLine) {
        await enqueue(
          'stock_count.scan',
          { stock_count_id: count.id, ...body },
          `Scan — ${count.reference}`,
          blob,
        )
        settle({
          status: 'queued',
          extraction: { lot_number: body.lot_number ?? null, ref: body.ref ?? null },
        })
        return
      }

      try {
        let data: ScanResponse
        if (blob) {
          const form = new FormData()
          form.append('photo', blob.data, 'label.jpg')
          Object.entries(body).forEach(([k, v]) => form.append(k, v))
          data = (await api.post<ScanResponse>(`/stock-counts/${count.id}/scan`, form)).data
        } else {
          data = (await api.post<ScanResponse>(`/stock-counts/${count.id}/scan`, body)).data
        }

        settle({
          scan: data.scan,
          extraction: data.scan.extracted ?? undefined,
          status: data.needs_review ? 'review' : 'recorded',
        })
        onChange(data.stock_count)
      } catch (err) {
        settle({ status: 'error', message: apiError(err) })
      }
    },
    [count.id, count.reference, onChange, templateId],
  )

  /**
   * Some labels split the fields across two barcodes (Waston: the product
   * code on one, lot and expiry on the other). Read the second one without
   * recording it, and fill the held capture's missing fields from it.
   */
  const mergeBarcode = useCallback(async (key: string, raw: string) => {
    setMergeInto(null)
    try {
      const { data } = await api.post<ScanExtractResponse>('/scan/extract', {
        barcode: raw,
        ...(templateId ? { template_id: templateId } : {}),
      })
      setRows((prev) => prev.map((r) => {
        if (r.key !== key) return r
        const current: ScanExtraction = r.extraction ?? r.scan?.extracted ?? {}
        const merged: ScanExtraction = { ...current }
        for (const field of ['ref', 'gtin', 'lot_number', 'expiry_date', 'serial_number'] as const) {
          if (!merged[field] && data.extracted[field]) merged[field] = data.extracted[field]
        }
        return { ...r, extraction: merged, merges: (r.merges ?? 0) + 1 }
      }))
    } catch (err) {
      toast.error(apiError(err))
    }
  }, [templateId, toast])

  const onDecode = useCallback((raw: string) => {
    if (mergeInto) void mergeBarcode(mergeInto, raw)
    else void send({ barcode: raw })
  }, [send, mergeInto, mergeBarcode])

  const scanner = useBarcodeScanner({ onDecode, paused: reviewing || manualOpen })

  // Open the camera as soon as the sheet mounts — the spec's "tap Scan and
  // keep scanning" loop, not tap-per-item.
  const started = useRef(false)
  useEffect(() => {
    if (started.current) return
    started.current = true
    void scanner.start()
  }, [scanner])

  const onCapturePhoto = async () => {
    setCapturing(true)
    try {
      const shot = await scanner.capturePhoto()
      if (!shot) {
        toast.error('Could not grab a frame — hold the camera steady and retry.')
        return
      }
      await send({}, { data: shot.blob, mime: shot.mime })
    } finally {
      setCapturing(false)
    }
  }

  const submitManual = async () => {
    setSavingManual(true)
    try {
      const body: Record<string, string> = { ref: manual.ref.trim() }
      if (manual.lot.trim()) body.lot_number = manual.lot.trim()
      if (manual.expiry) body.expiry_date = manual.expiry
      setManualOpen(false)
      setManual(EMPTY_MANUAL)
      await send(body)
    } finally {
      setSavingManual(false)
    }
  }

  const close = () => {
    scanner.stop()
    onClose()
  }

  const counted = rows.filter((r) => r.status === 'recorded' || r.status === 'queued').length
  const flagged = rows.filter((r) => r.scan && isAdjustmentResult(r.scan.match_result)).length

  return (
    <ScannerFrame
      title={`Scanning · ${count.reference}`}
      scanner={scanner}
      reviewing={reviewing}
      capturing={capturing}
      onCapturePhoto={() => void onCapturePhoto()}
      manualOpen={manualOpen}
      onToggleManual={() => setManualOpen((v) => !v)}
      subtitle={mergeInto ? 'Scan the other barcode on this label' : count.location ?? undefined}
      captureCount={rows.length}
      badges={
        <>
          <Badge tone="teal">{counted} counted</Badge>
          {flagged > 0 && <Badge tone="amber">{flagged} flagged</Badge>}
        </>
      }
      manualForm={
        <ManualLabelEntry
          fields={manual}
          onChange={setManual}
          onSubmit={() => void submitManual()}
          onCancel={() => setManualOpen(false)}
          saving={savingManual}
          submitLabel="Add capture"
        />
      }
      onDone={close}
    >
      {templates.length > 0 && (
        <div className="border-b border-slate-200 bg-slate-50 px-4 py-2">
          <label className="flex items-center gap-2 text-xs text-slate-600">
            <span className="shrink-0 font-medium">Label</span>
            <Select
              value={templateId}
              onChange={(e) => setTemplateId(e.target.value)}
              className="h-8 py-1 text-xs"
              aria-label="Supplier label template"
            >
              <option value="">Detect automatically</option>
              {templates.map((t) => (
                <option key={t.id} value={String(t.id)}>{t.supplier} — {t.name}</option>
              ))}
            </Select>
          </label>
        </div>
      )}

      {rows.length === 0 ? (
        <ScannerEmptyState>Captures appear here as you scan.</ScannerEmptyState>
      ) : (
        <ul className="divide-y divide-slate-200">
          {rows.map((row) => (
            <ScanRowItem
              key={row.key}
              row={row}
              countId={count.id}
              awaitingBarcode={mergeInto === row.key}
              onReadOtherBarcode={() => setMergeInto((cur) => (cur === row.key ? null : row.key))}
              onResolved={(scan, stockCount) => {
                if (mergeInto === row.key) setMergeInto(null)
                setRows((prev) =>
                  prev.map((r) =>
                    r.key === row.key
                      ? { ...r, scan, status: scan.needs_review ? 'review' : 'recorded' }
                      : r,
                  ),
                )
                onChange(stockCount)
              }}
              onError={(message) =>
                setRows((prev) =>
                  prev.map((r) => (r.key === row.key ? { ...r, status: 'error', message } : r)),
                )
              }
            />
          ))}
        </ul>
      )}
    </ScannerFrame>
  )
}

/* -------------------------------------------------------------------- */
/*  One capture in the session list                                      */
/* -------------------------------------------------------------------- */

function ScanRowItem({ row, countId, awaitingBarcode, onReadOtherBarcode, onResolved, onError }: {
  row: ScanRow
  countId: number
  awaitingBarcode: boolean
  onReadOtherBarcode: () => void
  onResolved: (scan: StockCountScan, count: StockCount) => void
  onError: (message: string) => void
}) {
  const extracted = row.extraction ?? row.scan?.extracted ?? {}
  const needsReview = row.status === 'review'

  return (
    <li
      className={cn(
        'px-4 py-3',
        // Spec §6: adjustment rows carry the orange highlight.
        row.scan && isAdjustmentResult(row.scan.match_result)
          ? 'border-l-4 border-orange-400 bg-orange-50'
          : needsReview
            ? 'border-l-4 border-slate-300 bg-white'
            : 'bg-white',
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium text-slate-800">
            {row.scan?.line?.description ?? extracted.ref ?? extracted.gtin ?? 'Unread label'}
          </p>
          <p className="mt-0.5 truncate text-xs text-slate-500">
            {extracted.ref && <span className="font-medium text-slate-600">{extracted.ref}</span>}
            {extracted.lot_number && <> · Lot {extracted.lot_number}</>}
            {extracted.expiry_date && <> · Exp {extracted.expiry_date}</>}
          </p>

          {row.scan?.line?.expected_lot_number && (
            <p className="mt-1 text-xs text-orange-800">
              Expected <span className="line-through">{row.scan.line.expected_lot_number}</span>
              {' · found '}
              <span className="font-semibold">{row.scan.line.lot_number ?? '—'}</span>
            </p>
          )}
        </div>

        <div className="shrink-0 text-right">
          {row.status === 'sending' && <Loader2 className="h-4 w-4 animate-spin text-slate-400" />}
          {row.status === 'queued' && <Badge tone="blue">Queued offline</Badge>}
          {row.status === 'error' && <Badge tone="red">Failed</Badge>}
          {row.scan && row.status !== 'error' && row.status !== 'queued' && (
            <MatchBadge result={row.scan.match_result} />
          )}
          {row.scan?.source === 'vision' && row.scan.confidence != null && (
            <p className="mt-1 text-[11px] text-slate-400">
              {Math.round(row.scan.confidence * 100)}% confident
            </p>
          )}
        </div>
      </div>

      {row.status === 'error' && row.message && (
        <p className="mt-2 text-xs text-red-600">{row.message}</p>
      )}

      {needsReview && row.scan && (
        <ReviewForm
          key={row.merges ?? 0}
          scan={row.scan}
          countId={countId}
          initial={extracted}
          awaitingBarcode={awaitingBarcode}
          onReadOtherBarcode={onReadOtherBarcode}
          onResolved={onResolved}
          onError={onError}
        />
      )}
    </li>
  )
}

/**
 * Nothing commits silently: an unresolved item, or a low-confidence vision
 * read, is held here until the runner confirms or corrects the three fields.
 */
function ReviewForm({ scan, countId, initial, awaitingBarcode, onReadOtherBarcode, onResolved, onError }: {
  scan: StockCountScan
  countId: number
  initial: ScanExtraction
  awaitingBarcode: boolean
  onReadOtherBarcode: () => void
  onResolved: (scan: StockCountScan, count: StockCount) => void
  onError: (message: string) => void
}) {
  const [ref, setRef] = useState(initial.ref ?? '')
  const [lot, setLot] = useState(initial.lot_number ?? '')
  const [expiry, setExpiry] = useState(initial.expiry_date ?? '')
  const [saving, setSaving] = useState(false)

  const confirm = async () => {
    setSaving(true)
    try {
      const { data } = await api.post<{ scan: StockCountScan; stock_count: StockCount }>(
        `/stock-counts/${countId}/scan/${scan.id}/confirm`,
        { ref: ref || null, lot_number: lot || null, expiry_date: expiry || null },
      )
      onResolved(data.scan, data.stock_count)
    } catch (err) {
      onError(apiError(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
      <p className="mb-2 text-xs font-medium text-slate-600">
        {scan.match_result === 'unresolved'
          ? 'This code is not in the catalogue — check the reference.'
          : scan.match_result === 'incomplete'
            ? 'Product found, but no lot was read. Scan the label’s other barcode or type the lot.'
            : 'Low confidence — check the reading before it counts.'}
      </p>
      <div className="grid gap-2 sm:grid-cols-3">
        <Field label="REF">
          <Input value={ref} onChange={(e) => setRef(e.target.value)} placeholder="12012029" />
        </Field>
        <Field label="Lot">
          <Input value={lot} onChange={(e) => setLot(e.target.value)} placeholder="11129D250603" />
        </Field>
        <Field label="Expiry">
          <Input type="date" value={expiry} onChange={(e) => setExpiry(e.target.value)} />
        </Field>
      </div>
      <div className="mt-2 flex flex-wrap gap-2">
        <Button size="sm" loading={saving} disabled={!ref.trim() && !scan.stock_item_id} onClick={() => void confirm()}>
          <CheckCircle2 className="h-4 w-4" /> Confirm
        </Button>
        <Button size="sm" variant={awaitingBarcode ? 'secondary' : 'outline'} onClick={onReadOtherBarcode}>
          <Barcode className="h-4 w-4" /> {awaitingBarcode ? 'Waiting for barcode… (cancel)' : 'Read other barcode'}
        </Button>
      </div>
      <p className="mt-2 flex items-center gap-1 text-[11px] text-slate-400">
        <ScanLine className="h-3 w-3" />
        {awaitingBarcode ? 'Hold the label’s other barcode in the frame.' : 'Scanning is paused until this is confirmed.'}
      </p>
    </div>
  )
}
