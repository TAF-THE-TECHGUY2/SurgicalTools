import { useCallback, useState } from 'react'
import { Lock, Minus, ScanLine } from 'lucide-react'
import { Modal } from '@/components/ui/Modal'
import { Button } from '@/components/ui/Button'
import { Field, Input } from '@/components/ui/Field'
import { SignaturePad } from '@/components/SignaturePad'
import { formatDate, formatMoney } from '@/lib/format'
import type { StockCount, StockCountItem } from '@/types'

export interface SignOff {
  signature: string
  signed_by_name: string
  device: string
}

/**
 * Spec §3.4 / flowchart part B. Step one lists every line never scanned; the
 * counter taps MINUS on each to confirm none were found (or goes back and
 * scans it). Only when nothing is unresolved does step two open: the variance
 * recap and the stock controller's on-screen signature, which locks the count.
 */
export function FinishCountModal({ count, keyed, defaultName, busyLineId, signing, onMinus, onScan, onSign, onClose }: {
  count: StockCount
  /** Quantities keyed in the table but not yet submitted, by line id. */
  keyed: Record<number, number>
  defaultName: string
  busyLineId: number | null
  signing: boolean
  onMinus: (line: StockCountItem) => void
  onScan: () => void
  onSign: (signOff: SignOff) => void
  onClose: () => void
}) {
  const [name, setName] = useState(defaultName)
  const [signature, setSignature] = useState('')
  const onSignature = useCallback((v: string) => setSignature(v), [])

  const items = count.items ?? []
  const unresolved = items.filter((it) => !it.is_adjustment && !it.resolved && keyed[it.id] === undefined)
  const variances = items
    .map((it) => ({ it, v: effectiveVariance(it, keyed[it.id]) }))
    .filter((r): r is { it: StockCountItem; v: number } => r.v !== null && r.v !== 0)
  const net = variances.reduce((sum, { it, v }) => sum + (it.unit_price == null ? 0 : v * Number(it.unit_price)), 0)

  return (
    <Modal open onClose={onClose} size="lg" title={unresolved.length > 0 ? 'Finish count — lines not scanned' : 'Finish count — sign off'}>
      {unresolved.length > 0 ? (
        <div>
          <p className="mb-3 text-sm text-slate-600">
            {unresolved.length} line{unresolved.length === 1 ? ' was' : 's were'} never scanned. Tap
            <span className="mx-1 inline-flex items-center rounded bg-red-50 px-1.5 font-semibold text-red-700">
              <Minus className="h-3 w-3" />
            </span>
            to confirm none were found, or go back and scan them.
          </p>
          <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
            {unresolved.map((it) => (
              <li key={it.id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                <div className="min-w-0 text-sm">
                  <p className="truncate font-medium text-slate-800">
                    {it.item_code ?? it.ref_code} · {it.description ?? '—'}
                  </p>
                  <p className="text-xs text-slate-500">
                    REF {it.ref_code} · Lot {it.lot_number ?? '—'}
                    {it.expiry_date && <> · Exp {formatDate(it.expiry_date, 'd MMM yyyy')}</>}
                    {' · '}Syst {it.expected_quantity}
                  </p>
                </div>
                <Button
                  variant="danger"
                  size="sm"
                  aria-label={`None found: ${it.ref_code} lot ${it.lot_number ?? ''}`}
                  loading={busyLineId === it.id}
                  onClick={() => onMinus(it)}
                >
                  <Minus className="h-4 w-4" /> None found
                </Button>
              </li>
            ))}
          </ul>
          <div className="mt-4 flex justify-between gap-3">
            <Button variant="ghost" onClick={onClose}>Back to count</Button>
            <Button variant="outline" onClick={onScan}>
              <ScanLine className="h-4 w-4" /> Scan instead
            </Button>
          </div>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="rounded-lg bg-slate-50 p-3 text-sm">
            <p className="font-medium text-slate-800">
              {variances.length === 0
                ? 'Every line agrees with the system.'
                : `${variances.length} line${variances.length === 1 ? '' : 's'} with a variance · net ${formatMoney(net)}`}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Signing locks the count. The signed sheet is emailed to you and the variance report to
              the accounts department.
            </p>
          </div>

          <Field label="Stock controller" required>
            <Input value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" />
          </Field>

          <Field label="Signature" required>
            <SignaturePad onChange={onSignature} />
          </Field>

          <div className="flex justify-between gap-3">
            <Button variant="ghost" onClick={onClose}>Back to count</Button>
            <Button
              loading={signing}
              disabled={!signature || !name.trim()}
              onClick={() => onSign({
                signature,
                signed_by_name: name.trim(),
                device: navigator.userAgent,
              })}
            >
              <Lock className="h-4 w-4" /> Sign &amp; lock count
            </Button>
          </div>
        </div>
      )}
    </Modal>
  )
}

/**
 * Variance as it will be once submitted: a keyed quantity wins, then the
 * recorded count, then the scan tally the server folds in.
 */
function effectiveVariance(it: StockCountItem, keyed?: number): number | null {
  const actual = keyed ?? it.counted_quantity ?? (it.scanned_quantity > 0 ? it.scanned_quantity : null)
  return actual === null || actual === undefined ? null : actual - it.expected_quantity
}
