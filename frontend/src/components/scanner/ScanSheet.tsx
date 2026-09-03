import { useCallback, useEffect, useRef, useState } from 'react'
import { CheckCircle2, Loader2, ScanLine } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useToast } from '@/components/ToastProvider'
import { enqueue } from '@/offline/syncQueue'
import { Button } from '@/components/ui/Button'
import { Field, Input } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { cn } from '@/lib/cn'
import { useBarcodeScanner } from '@/components/scanner/useBarcodeScanner'
import {
  ManualLabelEntry, MatchBadge, ScannerEmptyState, ScannerFrame,
} from '@/components/scanner/ScannerFrame'
import { isAdjustmentResult } from '@/lib/scan'
import type { ScanExtraction, ScanResponse, StockCount, StockCountScan } from '@/types'

/** A capture and what the server made of it, held in the session list. */
interface ScanRow {
  key: string
  /** Present once the server has recorded it. */
  scan?: StockCountScan
  extraction?: ScanExtraction
  status: 'sending' | 'recorded' | 'review' | 'error' | 'queued'
  message?: string
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

  // A row awaiting confirmation pauses decoding, so the runner isn't fighting
  // new reads while correcting one.
  const reviewing = rows.some((r) => r.status === 'review')

  const send = useCallback(
    async (body: Record<string, string>, blob?: { data: Blob; mime: string }) => {
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
    [count.id, count.reference, onChange],
  )

  const onDecode = useCallback((raw: string) => { void send({ barcode: raw }) }, [send])

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
      subtitle={count.location ?? undefined}
      scanner={scanner}
      reviewing={reviewing}
      capturing={capturing}
      onCapturePhoto={() => void onCapturePhoto()}
      manualOpen={manualOpen}
      onToggleManual={() => setManualOpen((v) => !v)}
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
      {rows.length === 0 ? (
        <ScannerEmptyState>Captures appear here as you scan.</ScannerEmptyState>
      ) : (
        <ul className="divide-y divide-slate-200">
          {rows.map((row) => (
            <ScanRowItem
              key={row.key}
              row={row}
              countId={count.id}
              onResolved={(scan, stockCount) => {
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

function ScanRowItem({ row, countId, onResolved, onError }: {
  row: ScanRow
  countId: number
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
          scan={row.scan}
          countId={countId}
          initial={extracted}
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
function ReviewForm({ scan, countId, initial, onResolved, onError }: {
  scan: StockCountScan
  countId: number
  initial: ScanExtraction
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
      <Button size="sm" className="mt-2" loading={saving} disabled={!ref.trim()} onClick={() => void confirm()}>
        <CheckCircle2 className="h-4 w-4" /> Confirm
      </Button>
      <p className="mt-2 flex items-center gap-1 text-[11px] text-slate-400">
        <ScanLine className="h-3 w-3" /> Scanning is paused until this is confirmed.
      </p>
    </div>
  )
}
