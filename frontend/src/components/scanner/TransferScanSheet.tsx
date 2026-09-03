import { useCallback, useEffect, useRef, useState } from 'react'
import { Loader2, Trash2 } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useToast } from '@/components/ToastProvider'
import { Badge } from '@/components/ui/Badge'
import { cn } from '@/lib/cn'
import { useBarcodeScanner } from '@/components/scanner/useBarcodeScanner'
import {
  ManualLabelEntry, MatchBadge, ScannerEmptyState, ScannerFrame,
} from '@/components/scanner/ScannerFrame'
import { isAdjustmentResult, normalizeLot } from '@/lib/scan'
import type {
  DeviceUnit, GroupedStockRow, ScanExtractResponse, ScanExtraction, TransferAdjustmentDraft,
} from '@/types'

/**
 * Delivery-voucher scanning.
 *
 * Unlike a stock count, the transfer does not exist yet — the rep is building
 * the voucher grid before anything is submitted. So each label is extracted by
 * the server (one GS1 parser, shared) and then matched **client-side** against
 * the source location's inventory, which this page has already loaded:
 *
 *   item + lot found, a free unit available  → select that unit
 *   item found, that lot not at the source   → orange line, lot_mismatch
 *   item not at the source at all            → orange line, unlisted_item
 *   every unit of that lot already picked    → flagged, nothing selected
 *
 * Off-list rows travel to the server as `scanned_adjustments` on submit, where
 * they become flagged voucher lines and alert admin operations.
 */
interface ScanRow {
  key: string
  status: 'sending' | 'done' | 'error'
  result?: string
  extraction?: ScanExtraction
  /** The unit this scan picked, if it matched. */
  unitId?: number
  itemName?: string
  message?: string
}

const EMPTY_MANUAL = { ref: '', lot: '', expiry: '' }

export function TransferScanSheet({
  sourceName,
  inventory,
  selected,
  adjustments,
  onSelectUnit,
  onAddAdjustment,
  onRemoveAdjustment,
  onClose,
}: {
  sourceName: string
  inventory: GroupedStockRow[]
  /** Units already picked, so a second scan of a lot takes a different one. */
  selected: Map<number, DeviceUnit & { itemName: string }>
  adjustments: TransferAdjustmentDraft[]
  onSelectUnit: (unit: DeviceUnit, itemName: string) => void
  onAddAdjustment: (row: TransferAdjustmentDraft) => void
  onRemoveAdjustment: (key: string) => void
  onClose: () => void
}) {
  const toast = useToast()
  const [rows, setRows] = useState<ScanRow[]>([])
  const [manualOpen, setManualOpen] = useState(false)
  const [manual, setManual] = useState(EMPTY_MANUAL)
  const [capturing, setCapturing] = useState(false)
  const [savingManual, setSavingManual] = useState(false)

  // Read from a ref so the decode loop always sees the current selection
  // without restarting the camera.
  const selectedRef = useRef(selected)
  useEffect(() => { selectedRef.current = selected }, [selected])
  const inventoryRef = useRef(inventory)
  useEffect(() => { inventoryRef.current = inventory }, [inventory])

  /** Match an extraction against the source inventory. */
  const resolve = useCallback((extracted: ScanExtraction): {
    result: string
    unit?: DeviceUnit
    itemName?: string
    expectedLot?: string | null
  } => {
    const ref = extracted.ref?.trim()
    const gtin = extracted.gtin?.trim()

    const row = inventoryRef.current.find((r) =>
      (ref && (r.catalogue_number === ref || r.item_code === ref))
      || (gtin && r.gtin === gtin))

    if (!row) return { result: 'unlisted_item' }

    const scannedLot = normalizeLot(extracted.lot_number)
    const ofLot = row.units.filter((u) => normalizeLot(u.lot_number) === scannedLot)

    if (ofLot.length === 0) {
      // Known product at this location, but not under this lot.
      return {
        result: 'lot_mismatch',
        itemName: row.name,
        expectedLot: row.units.find((u) => u.lot_number)?.lot_number ?? null,
      }
    }

    const free = ofLot.find((u) => u.status === 'available' && !selectedRef.current.has(u.id))

    // Right item, right lot, but every unit of it is already on the voucher.
    if (!free) return { result: 'exhausted', itemName: row.name }

    return { result: 'selected', unit: free, itemName: row.name }
  }, [])

  const send = useCallback(
    async (body: Record<string, string>, blob?: { data: Blob; mime: string }) => {
      const key = crypto.randomUUID()
      setRows((prev) => [{ key, status: 'sending' }, ...prev])

      const settle = (patch: Partial<ScanRow>) =>
        setRows((prev) => prev.map((r) => (r.key === key ? { ...r, ...patch } : r)))

      try {
        let data: ScanExtractResponse
        if (blob) {
          const form = new FormData()
          form.append('photo', blob.data, 'label.jpg')
          Object.entries(body).forEach(([k, v]) => form.append(k, v))
          data = (await api.post<ScanExtractResponse>('/scan/extract', form)).data
        } else {
          data = (await api.post<ScanExtractResponse>('/scan/extract', body)).data
        }

        const extracted = data.extracted
        const match = resolve(extracted)

        if (match.result === 'selected' && match.unit) {
          onSelectUnit(match.unit, match.itemName ?? 'Device')
        } else if (isAdjustmentResult(match.result)) {
          onAddAdjustment({
            key,
            ref_code: extracted.ref ?? extracted.gtin ?? '',
            description: match.itemName ?? data.stock_item?.name ?? null,
            lot_number: extracted.lot_number ?? null,
            expiry_date: extracted.expiry_date ?? null,
            expected_lot_number: match.result === 'lot_mismatch' ? (match.expectedLot ?? null) : null,
            adjustment_type: match.result,
          })
        }

        settle({
          status: 'done',
          result: match.result,
          extraction: extracted,
          unitId: match.unit?.id,
          itemName: match.itemName ?? data.stock_item?.name ?? undefined,
        })
      } catch (err) {
        settle({ status: 'error', message: apiError(err) })
      }
    },
    [onAddAdjustment, onSelectUnit, resolve],
  )

  const onDecode = useCallback((raw: string) => { void send({ barcode: raw }) }, [send])

  const scanner = useBarcodeScanner({ onDecode, paused: manualOpen })

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

  const added = rows.filter((r) => r.result === 'selected').length
  const flagged = adjustments.length

  return (
    <ScannerFrame
      title="Scanning onto the voucher"
      subtitle={`Stock at ${sourceName}`}
      scanner={scanner}
      reviewing={false}
      capturing={capturing}
      onCapturePhoto={() => void onCapturePhoto()}
      manualOpen={manualOpen}
      onToggleManual={() => setManualOpen((v) => !v)}
      captureCount={rows.length}
      badges={
        <>
          <Badge tone="teal">{added} added</Badge>
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
          submitLabel="Add to voucher"
        />
      }
      onDone={close}
      doneLabel={`Done — ${selected.size} device(s), ${flagged} flagged`}
    >
      {rows.length === 0 ? (
        <ScannerEmptyState>
          Scan the label on each item going into the box.
        </ScannerEmptyState>
      ) : (
        <ul className="divide-y divide-slate-200">
          {rows.map((row) => (
            <li
              key={row.key}
              className={cn(
                'px-4 py-3',
                row.result && isAdjustmentResult(row.result)
                  ? 'border-l-4 border-orange-400 bg-orange-50'
                  : 'bg-white',
              )}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-slate-800">
                    {row.itemName ?? row.extraction?.ref ?? 'Unread label'}
                  </p>
                  <p className="mt-0.5 truncate text-xs text-slate-500">
                    {row.extraction?.ref && (
                      <span className="font-medium text-slate-600">{row.extraction.ref}</span>
                    )}
                    {row.extraction?.lot_number && <> · Lot {row.extraction.lot_number}</>}
                    {row.extraction?.expiry_date && <> · Exp {row.extraction.expiry_date}</>}
                  </p>

                  {row.result === 'lot_mismatch' && (
                    <p className="mt-1 text-xs text-orange-800">
                      Not on the dispatch list for {sourceName} under this lot.
                    </p>
                  )}
                  {row.result === 'unlisted_item' && (
                    <p className="mt-1 text-xs text-orange-800">
                      This product is not held at {sourceName}.
                    </p>
                  )}
                  {row.result === 'exhausted' && (
                    <p className="mt-1 text-xs text-amber-700">
                      Every unit of this lot at {sourceName} is already on the voucher.
                    </p>
                  )}
                </div>

                <div className="flex shrink-0 items-center gap-2">
                  {row.status === 'sending' && <Loader2 className="h-4 w-4 animate-spin text-slate-400" />}
                  {row.status === 'error' && <Badge tone="red">Failed</Badge>}
                  {row.result && <MatchBadge result={row.result} />}
                  {row.result && isAdjustmentResult(row.result) && (
                    <button
                      aria-label="Remove this flagged line"
                      title="Remove this flagged line"
                      onClick={() => {
                        onRemoveAdjustment(row.key)
                        setRows((prev) => prev.filter((r) => r.key !== row.key))
                      }}
                      className="rounded-md p-1 text-orange-700 hover:bg-orange-100"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  )}
                </div>
              </div>

              {row.status === 'error' && row.message && (
                <p className="mt-2 text-xs text-red-600">{row.message}</p>
              )}
            </li>
          ))}
        </ul>
      )}
    </ScannerFrame>
  )
}
