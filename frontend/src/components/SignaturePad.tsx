import { useEffect, useRef } from 'react'
import SignaturePadLib from 'signature_pad'
import { Eraser, Lock } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { cn } from '@/lib/cn'

/**
 * Touch / mouse signature capture. Calls `onChange` with a PNG data-URI as the
 * signer draws (empty string when cleared). Works offline — pure client-side.
 */
export function SignaturePad({
  onChange,
  disabled = false,
  disabledReason,
}: {
  onChange: (dataUrl: string) => void
  /**
   * dev-spec §4 requires the recipient's printed name before the pad becomes
   * active. Disabling the submit button is not the same thing: a recipient who
   * signs first and is named afterwards has attested to a form that did not
   * yet say who they were.
   */
  disabled?: boolean
  disabledReason?: string
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const padRef = useRef<SignaturePadLib | null>(null)

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return

    // Scale for crisp lines on high-DPI screens.
    const ratio = Math.max(window.devicePixelRatio || 1, 1)
    const resize = () => {
      const { width } = canvas.getBoundingClientRect()
      canvas.width = width * ratio
      canvas.height = 180 * ratio
      canvas.getContext('2d')?.scale(ratio, ratio)
      padRef.current?.clear()
    }

    const pad = new SignaturePadLib(canvas, { penColor: '#0f172a', backgroundColor: '#ffffff' })
    padRef.current = pad
    pad.addEventListener('endStroke', () => onChange(pad.isEmpty() ? '' : pad.toDataURL('image/png')))

    resize()
    window.addEventListener('resize', resize)
    return () => {
      window.removeEventListener('resize', resize)
      pad.off()
    }
  }, [onChange])

  /**
   * Detaching the listeners rather than only dimming the canvas: a disabled
   * look that still records strokes would capture a signature the form is not
   * yet entitled to take.
   */
  useEffect(() => {
    const pad = padRef.current
    if (!pad) return

    if (disabled) {
      pad.off()
      if (!pad.isEmpty()) {
        pad.clear()
        onChange('')
      }
    } else {
      pad.on()
    }
  }, [disabled, onChange])

  const clear = () => {
    padRef.current?.clear()
    onChange('')
  }

  return (
    <div>
      <div
        className={cn(
          'relative overflow-hidden rounded-lg border-2 border-dashed bg-white',
          disabled ? 'border-slate-200 bg-slate-50' : 'border-slate-300',
        )}
      >
        <canvas
          ref={canvasRef}
          aria-disabled={disabled}
          className={cn('h-[180px] w-full touch-none', disabled && 'opacity-40')}
        />
        {disabled && (
          <div className="absolute inset-0 flex items-center justify-center bg-slate-50/70 px-4 text-center">
            <span className="flex items-center gap-2 text-xs font-medium text-slate-500">
              <Lock className="h-3.5 w-3.5" />
              {disabledReason ?? 'Complete the required fields first.'}
            </span>
          </div>
        )}
      </div>
      <div className="mt-2 flex items-center justify-between">
        <span className="text-xs text-slate-400">Sign above using your finger or mouse.</span>
        <Button type="button" variant="ghost" size="sm" disabled={disabled} onClick={clear}>
          <Eraser className="h-4 w-4" /> Clear
        </Button>
      </div>
    </div>
  )
}
