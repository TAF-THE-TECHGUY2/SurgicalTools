import { useEffect, useRef } from 'react'
import SignaturePadLib from 'signature_pad'
import { Eraser } from 'lucide-react'
import { Button } from '@/components/ui/Button'

/**
 * Touch / mouse signature capture. Calls `onChange` with a PNG data-URI as the
 * signer draws (empty string when cleared). Works offline — pure client-side.
 */
export function SignaturePad({ onChange }: { onChange: (dataUrl: string) => void }) {
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

  const clear = () => {
    padRef.current?.clear()
    onChange('')
  }

  return (
    <div>
      <div className="overflow-hidden rounded-lg border-2 border-dashed border-slate-300 bg-white">
        <canvas ref={canvasRef} className="h-[180px] w-full touch-none" />
      </div>
      <div className="mt-2 flex items-center justify-between">
        <span className="text-xs text-slate-400">Sign above using your finger or mouse.</span>
        <Button type="button" variant="ghost" size="sm" onClick={clear}>
          <Eraser className="h-4 w-4" /> Clear
        </Button>
      </div>
    </div>
  )
}
