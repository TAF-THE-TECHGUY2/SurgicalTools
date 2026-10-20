import { useEffect, useRef, useState } from 'react'
import SignaturePadLib from 'signature_pad'
import { Eraser, Lock, PenLine } from 'lucide-react'
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
  height = 180,
  variant = 'boxed',
  value,
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
  /** Shorter inside the voucher's SIGNATURE cell than in a modal of its own. */
  height?: number
  /**
   * 'bare' drops the dashed frame and the hint line so the pad sits directly
   * on the voucher's ruled SIGNATURE line. It also captures on a transparent
   * background rather than white, so the signature composites onto the form
   * instead of printing as a white patch over it.
   */
  variant?: 'boxed' | 'bare'
  /**
   * A signature already captured, as a PNG data URI, re-drawn whenever the
   * canvas is built.
   *
   * The pad is a DOM canvas, so it starts blank every time it mounts, while
   * the signature itself lives in the caller's state. On the voucher that
   * happens whenever the rep steps forward and back: the form still held the
   * signature and was still going to submit it, but the cell was empty, which
   * reads as having lost it.
   */
  value?: string
}) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const padRef = useRef<SignaturePadLib | null>(null)
  // Drives the in-cell prompt and the Clear control. A blank ruled line tells
  // a recipient nothing — on paper the pen is the affordance; here it has to
  // be drawn.
  const [hasInk, setHasInk] = useState(false)

  /**
   * Held in a ref and deliberately kept out of the effect's dependencies.
   *
   * A caller that declares its handler inline hands us a new function on every
   * render. With onChange in the deps, reporting a stroke re-rendered the
   * parent, which changed the handler's identity, which tore down and rebuilt
   * the pad — wiping the stroke that had just been drawn. The signature
   * vanished the instant it was recorded, and only for callers that had not
   * memoised their handler, which is the easy mistake to make.
   */
  const onChangeRef = useRef(onChange)
  useEffect(() => { onChangeRef.current = onChange })

  // Read when the canvas is built rather than tracked as a dependency, so a
  // restore cannot interrupt someone mid-signature.
  const valueRef = useRef(value)
  useEffect(() => { valueRef.current = value })

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return

    // Scale for crisp lines on high-DPI screens.
    const ratio = Math.max(window.devicePixelRatio || 1, 1)

    /**
     * Resizing the backing store blanks it, so anything already signed is
     * re-drawn afterwards. iOS fires resize when the Safari toolbar collapses
     * on scroll — scrolling down the voucher after signing must not erase the
     * signature.
     */
    const resize = () => {
      const { width } = canvas.getBoundingClientRect()
      if (width === 0) return // laid out at zero (hidden tab, unmounting)

      const pad = padRef.current
      const existing = pad && !pad.isEmpty() ? pad.toDataURL('image/png') : null

      canvas.width = width * ratio
      canvas.height = height * ratio
      canvas.getContext('2d')?.scale(ratio, ratio)
      pad?.clear()

      // Prefer what is on the canvas; fall back to what the caller is
      // holding, which is the case on a fresh mount.
      const restore = existing ?? valueRef.current
      if (restore) {
        void pad?.fromDataURL(restore, { ratio, width, height })
        setHasInk(true)
      }
    }

    const pad = new SignaturePadLib(canvas, {
      penColor: '#0f172a',
      // Transparent for a bare pad: the exported PNG is laid over cream on
      // screen and white on paper, and a baked-in background would show as a
      // rectangle on one of them.
      ...(variant === 'bare' ? {} : { backgroundColor: '#ffffff' }),
    })
    padRef.current = pad
    pad.addEventListener('endStroke', () => {
      setHasInk(!pad.isEmpty())
      onChangeRef.current(pad.isEmpty() ? '' : pad.toDataURL('image/png'))
    })

    resize()
    window.addEventListener('resize', resize)
    return () => {
      window.removeEventListener('resize', resize)
      pad.off()
    }
  }, [height, variant])

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
        setHasInk(false)
        onChangeRef.current('')
      }
    } else {
      pad.on()
    }
  }, [disabled])

  const clear = () => {
    padRef.current?.clear()
    setHasInk(false)
    onChangeRef.current('')
  }

  const bare = variant === 'bare'

  return (
    <div className={cn(bare && 'relative')}>
      <div
        className={cn(
          'relative overflow-hidden',
          bare
            ? 'rounded-none border-0 bg-transparent'
            : cn('rounded-lg border-2 border-dashed bg-white',
                disabled ? 'border-slate-200 bg-slate-50' : 'border-slate-300'),
        )}
      >
        <canvas
          ref={canvasRef}
          aria-disabled={disabled}
          style={{ height }}
          className={cn('w-full touch-none', disabled && 'opacity-40')}
        />
        {disabled && (
          <div
            className={cn(
              'absolute inset-0 flex items-center px-2 text-center',
              bare ? 'justify-start' : 'justify-center bg-slate-50/70',
            )}
          >
            <span className="flex items-center gap-1.5 text-[10px] font-medium text-slate-400">
              <Lock className="h-3 w-3" />
              {disabledReason ?? 'Complete the required fields first.'}
            </span>
          </div>
        )}
        {bare && !disabled && !hasInk && (
          <div className="pointer-events-none absolute inset-0 flex items-center">
            <span className="flex items-center gap-1.5 text-[10px] italic text-slate-400">
              <PenLine className="h-3 w-3" />
              Sign here
            </span>
          </div>
        )}
      </div>

      {bare ? (
        // No hint line and no button chrome: anything with a box around it
        // stops the cell reading as part of the form.
        hasInk && (
          <button
            type="button"
            onClick={clear}
            className="absolute right-1 top-1 text-[9px] uppercase tracking-wide text-slate-400 hover:text-slate-600"
          >
            Clear
          </button>
        )
      ) : (
        <div className="mt-2 flex items-center justify-between">
          <span className="text-xs text-slate-400">Sign above using your finger or mouse.</span>
          <Button type="button" variant="ghost" size="sm" disabled={disabled} onClick={clear}>
            <Eraser className="h-4 w-4" /> Clear
          </Button>
        </div>
      )}
    </div>
  )
}
