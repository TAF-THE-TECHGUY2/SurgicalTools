import type { ReactNode } from 'react'
import { AlertTriangle, Camera, Check, Keyboard, Loader2, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { cn } from '@/lib/cn'
import type { UseBarcodeScanner } from '@/components/scanner/useBarcodeScanner'

/**
 * The full-screen camera chrome shared by the stock-count and delivery-voucher
 * scanners: viewfinder, reticle, status messaging, capture controls and the
 * running list of what has been scanned.
 *
 * Only the chrome is shared. What a scan *means* differs — a count scan is
 * resolved by the server against the count, while a voucher scan is matched
 * client-side against the source location's inventory before the transfer
 * exists — so each caller supplies its own rows.
 */
export function ScannerFrame({
  title,
  subtitle,
  scanner,
  reviewing,
  capturing,
  onCapturePhoto,
  onToggleManual,
  manualOpen,
  badges,
  captureCount,
  manualForm,
  children,
  onDone,
  doneLabel = 'Done scanning',
  doneDisabled = false,
}: {
  title: string
  subtitle?: string
  scanner: UseBarcodeScanner
  /** Decoding is paused — a row needs confirming first. */
  reviewing: boolean
  capturing: boolean
  onCapturePhoto: () => void
  onToggleManual: () => void
  manualOpen: boolean
  badges?: ReactNode
  captureCount: number
  /** Rendered above the list when manual entry is open. */
  manualForm?: ReactNode
  children: ReactNode
  onDone: () => void
  doneLabel?: string
  doneDisabled?: boolean
}) {
  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-slate-900">
      <div className="flex items-center justify-between gap-3 px-4 py-3 text-white">
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold">{title}</p>
          {subtitle && <p className="truncate text-xs text-slate-400">{subtitle}</p>}
        </div>
        <div className="flex items-center gap-2">
          {badges}
          <button
            onClick={onDone}
            aria-label="Close scanner"
            className="rounded-lg p-1.5 text-slate-300 hover:bg-white/10 hover:text-white"
          >
            <X className="h-5 w-5" />
          </button>
        </div>
      </div>

      <div className="relative shrink-0 bg-black" style={{ height: '38vh' }}>
        <video ref={scanner.videoRef} className="h-full w-full object-cover" muted playsInline />

        <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
          <div
            className={cn(
              'h-28 w-64 rounded-xl border-2 transition-colors',
              reviewing ? 'border-amber-400' : 'border-white/70',
            )}
          />
        </div>

        {scanner.status === 'starting' && (
          <div className="absolute inset-0 flex items-center justify-center gap-2 bg-black/60 text-sm text-white">
            <Loader2 className="h-4 w-4 animate-spin" /> Starting camera…
          </div>
        )}

        {scanner.status === 'error' && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/80 px-6 text-center">
            <AlertTriangle className="h-6 w-6 text-amber-400" />
            <p className="text-sm text-white">{scanner.error}</p>
            {!manualOpen && (
              <Button size="sm" variant="secondary" onClick={onToggleManual}>
                <Keyboard className="h-4 w-4" /> Enter by hand
              </Button>
            )}
          </div>
        )}

        {scanner.status === 'scanning' && (
          <p className="absolute bottom-2 left-0 right-0 px-4 text-center text-xs text-white/70">
            {reviewing
              ? 'Paused — confirm the highlighted capture below'
              : scanner.barcodeSupported
                ? 'Hold a barcode in the frame — keep going, it stays open'
                : 'No barcode reader on this device — use Photo for each label'}
          </p>
        )}
      </div>

      <div className="flex items-center gap-2 border-y border-white/10 px-4 py-2.5">
        <Button size="sm" variant="secondary" loading={capturing} onClick={onCapturePhoto}>
          <Camera className="h-4 w-4" /> Photo
        </Button>
        <Button size="sm" variant="secondary" onClick={onToggleManual}>
          <Keyboard className="h-4 w-4" /> By hand
        </Button>
        <span className="ml-auto text-xs text-slate-400">
          {captureCount} capture{captureCount === 1 ? '' : 's'}
        </span>
      </div>

      {manualOpen && manualForm}

      <div className="flex-1 overflow-y-auto bg-slate-50">{children}</div>

      <div className="border-t border-slate-200 bg-white p-3">
        <Button className="w-full" size="lg" disabled={doneDisabled} onClick={onDone}>
          <Check className="h-4 w-4" /> {doneLabel}
        </Button>
      </div>
    </div>
  )
}

/** Shared empty state for a scanner's session list. */
export function ScannerEmptyState({ children }: { children: ReactNode }) {
  return <p className="px-4 py-10 text-center text-sm text-slate-400">{children}</p>
}

/** Shared three-field label form, for a label that will not read. */
export function ManualLabelEntry({ fields, onChange, onSubmit, onCancel, saving, submitLabel }: {
  fields: { ref: string; lot: string; expiry: string }
  onChange: (next: { ref: string; lot: string; expiry: string }) => void
  onSubmit: () => void
  onCancel: () => void
  saving: boolean
  submitLabel: string
}) {
  return (
    <div className="border-b border-slate-200 bg-white p-4">
      <div className="grid gap-2 sm:grid-cols-3">
        <LabelField label="REF" required>
          <input
            autoFocus
            className={controlClass}
            value={fields.ref}
            placeholder="12012029"
            onChange={(e) => onChange({ ...fields, ref: e.target.value })}
          />
        </LabelField>
        <LabelField label="Lot">
          <input
            className={controlClass}
            value={fields.lot}
            placeholder="11129D250603"
            onChange={(e) => onChange({ ...fields, lot: e.target.value })}
          />
        </LabelField>
        <LabelField label="Expiry">
          <input
            type="date"
            className={controlClass}
            value={fields.expiry}
            onChange={(e) => onChange({ ...fields, expiry: e.target.value })}
          />
        </LabelField>
      </div>
      <div className="mt-2 flex gap-2">
        <Button size="sm" loading={saving} disabled={!fields.ref.trim()} onClick={onSubmit}>
          {submitLabel}
        </Button>
        <Button size="sm" variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </div>
  )
}

const controlClass =
  'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 shadow-sm ' +
  'placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200'

function LabelField({ label, required, children }: { label: string; required?: boolean; children: ReactNode }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-slate-700">
        {label} {required && <span className="text-red-500">*</span>}
      </span>
      {children}
    </label>
  )
}

/** Badge for a scan outcome, shared so both scanners read the same way. */
export function MatchBadge({ result }: { result: string }) {
  const copy: Record<string, { label: string; tone: 'green' | 'amber' | 'gray' }> = {
    match: { label: 'Counted', tone: 'green' },
    selected: { label: 'Added', tone: 'green' },
    lot_mismatch: { label: 'Lot mismatch', tone: 'amber' },
    unlisted_item: { label: 'Not on list', tone: 'amber' },
    expiry_mismatch: { label: 'Expiry mismatch', tone: 'amber' },
    unresolved: { label: 'Needs details', tone: 'gray' },
    exhausted: { label: 'None left', tone: 'amber' },
  }

  const entry = copy[result]

  return entry ? <Badge tone={entry.tone}>{entry.label}</Badge> : null
}
