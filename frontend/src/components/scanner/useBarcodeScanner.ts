import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * Continuous barcode reading from the rear camera.
 *
 * Medical device labels carry GS1 DataMatrix or Code 128, which decode exactly
 * — no confidence score, no misread characters. That makes this the primary
 * capture path, with photo/OCR only as a fallback for damaged labels.
 *
 * Chrome and Android WebView expose `BarcodeDetector` natively. Safari does
 * not, so `zxing-wasm` is loaded on demand there and kept out of the bundle
 * everywhere else.
 */

/** Formats worth scanning for. DataMatrix first — it's what UDI labels use. */
const FORMATS = ['data_matrix', 'code_128', 'qr_code', 'ean_13'] as const

/** How long an identical read is ignored, so one barcode held in frame
 *  registers once rather than thirty times. */
const DUPLICATE_WINDOW_MS = 2000

/** Gap between decode attempts. ~7/sec is responsive without pinning the CPU. */
const DECODE_INTERVAL_MS = 140

type DetectedBarcode = { rawValue: string; format?: string }

interface BarcodeDetectorLike {
  detect: (source: CanvasImageSource) => Promise<DetectedBarcode[]>
}

declare global {
  interface Window {
    BarcodeDetector?: {
      new (options?: { formats?: readonly string[] }): BarcodeDetectorLike
      getSupportedFormats?: () => Promise<string[]>
    }
  }
}

export type ScannerStatus = 'idle' | 'starting' | 'scanning' | 'error'

export interface UseBarcodeScannerOptions {
  /** Called once per distinct decode, outside the duplicate window. */
  onDecode: (raw: string) => void
  /** Stop decoding without tearing the camera down (e.g. while reviewing a row). */
  paused?: boolean
}

export interface UseBarcodeScanner {
  videoRef: React.RefObject<HTMLVideoElement | null>
  status: ScannerStatus
  error: string | null
  /** False when neither a native detector nor the wasm fallback is usable. */
  barcodeSupported: boolean
  start: () => Promise<void>
  stop: () => void
  /** Grab the current frame as a JPEG, downscaled for the vision fallback. */
  capturePhoto: (maxEdge?: number) => Promise<{ blob: Blob; mime: string } | null>
}

export function useBarcodeScanner({ onDecode, paused = false }: UseBarcodeScannerOptions): UseBarcodeScanner {
  const videoRef = useRef<HTMLVideoElement | null>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const detectorRef = useRef<BarcodeDetectorLike | null>(null)
  const loopRef = useRef<number | null>(null)
  const recentRef = useRef<Map<string, number>>(new Map())
  const pausedRef = useRef(paused)
  const onDecodeRef = useRef(onDecode)

  const [status, setStatus] = useState<ScannerStatus>('idle')
  const [error, setError] = useState<string | null>(null)
  const [barcodeSupported, setBarcodeSupported] = useState(true)

  // Keep the loop reading current values without restarting the camera.
  useEffect(() => { pausedRef.current = paused }, [paused])
  useEffect(() => { onDecodeRef.current = onDecode }, [onDecode])

  const emit = useCallback((raw: string) => {
    const value = raw.trim()
    if (!value) return

    const now = Date.now()
    const seen = recentRef.current.get(value)
    if (seen && now - seen < DUPLICATE_WINDOW_MS) return

    recentRef.current.set(value, now)
    // Keep the dedupe map from growing across a long count.
    if (recentRef.current.size > 50) {
      for (const [key, at] of recentRef.current) {
        if (now - at > DUPLICATE_WINDOW_MS) recentRef.current.delete(key)
      }
    }

    onDecodeRef.current(value)
  }, [])

  const stop = useCallback(() => {
    if (loopRef.current !== null) {
      window.clearInterval(loopRef.current)
      loopRef.current = null
    }
    streamRef.current?.getTracks().forEach((t) => t.stop())
    streamRef.current = null
    detectorRef.current = null
    recentRef.current.clear()
    if (videoRef.current) videoRef.current.srcObject = null
    setStatus('idle')
  }, [])

  const tick = useCallback(async () => {
    const video = videoRef.current
    const detector = detectorRef.current
    if (!video || !detector || pausedRef.current) return
    if (video.readyState < 2 || video.videoWidth === 0) return

    try {
      const results = await detector.detect(video)
      for (const result of results) {
        if (result.rawValue) emit(result.rawValue)
      }
    } catch {
      // A single failed frame is normal (mid-resize, backgrounded tab).
    }
  }, [emit])

  const start = useCallback(async () => {
    setError(null)
    setStatus('starting')

    if (!navigator.mediaDevices?.getUserMedia) {
      // On an insecure origin the browser does not expose mediaDevices at
      // all, so this is indistinguishable from "no camera" unless we check
      // for the real cause. Naming it saves someone hunting for a hardware
      // fault that isn't there.
      setError(
        window.isSecureContext === false
          ? 'The camera needs a secure connection. This page is served over '
            + 'plain HTTP, and browsers only allow camera access over HTTPS. '
            + 'Ask IT to enable HTTPS on this address.'
          : 'This device has no camera available to the browser.',
      )
      setStatus('error')
      return
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: {
          facingMode: { ideal: 'environment' },
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
        audio: false,
      })
      streamRef.current = stream

      const video = videoRef.current
      if (video) {
        video.srcObject = stream
        video.setAttribute('playsinline', 'true')
        await video.play().catch(() => undefined)
      }

      detectorRef.current = await resolveDetector()
      setBarcodeSupported(detectorRef.current !== null)
      setStatus('scanning')

      loopRef.current = window.setInterval(() => {
        void tick()
      }, DECODE_INTERVAL_MS)
    } catch (e) {
      setError(cameraErrorMessage(e))
      setStatus('error')
      streamRef.current?.getTracks().forEach((t) => t.stop())
      streamRef.current = null
    }
  }, [tick])

  const capturePhoto = useCallback(
    async (maxEdge = 1600): Promise<{ blob: Blob; mime: string } | null> => {
      const video = videoRef.current
      if (!video || video.videoWidth === 0) return null

      // Downscale before upload: keeps the label legible while holding the
      // request well under the API's size limit and the token cost down.
      const scale = Math.min(1, maxEdge / Math.max(video.videoWidth, video.videoHeight))
      const canvas = document.createElement('canvas')
      canvas.width = Math.round(video.videoWidth * scale)
      canvas.height = Math.round(video.videoHeight * scale)

      const ctx = canvas.getContext('2d')
      if (!ctx) return null
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

      const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob(resolve, 'image/jpeg', 0.9),
      )

      return blob ? { blob, mime: 'image/jpeg' } : null
    },
    [],
  )

  // Release the camera if the component unmounts mid-scan.
  useEffect(() => stop, [stop])

  return { videoRef, status, error, barcodeSupported, start, stop, capturePhoto }
}

/**
 * Native detector where available, else the wasm fallback. Returns null when
 * neither works — the photo path still functions, so scanning degrades rather
 * than failing.
 */
async function resolveDetector(): Promise<BarcodeDetectorLike | null> {
  if (window.BarcodeDetector) {
    try {
      const supported = (await window.BarcodeDetector.getSupportedFormats?.()) ?? []
      const formats = supported.length
        ? FORMATS.filter((f) => supported.includes(f))
        : [...FORMATS]

      if (formats.length > 0) return new window.BarcodeDetector({ formats })
    } catch {
      // Fall through to wasm.
    }
  }

  return loadZxingDetector()
}

/** iOS Safari has no BarcodeDetector; zxing-wasm is loaded only there. */
async function loadZxingDetector(): Promise<BarcodeDetectorLike | null> {
  try {
    const zxing = await import('zxing-wasm/reader')

    return {
      async detect(source: CanvasImageSource) {
        const video = source as HTMLVideoElement
        if (!video.videoWidth) return []

        const canvas = document.createElement('canvas')
        canvas.width = video.videoWidth
        canvas.height = video.videoHeight
        const ctx = canvas.getContext('2d')
        if (!ctx) return []
        ctx.drawImage(video, 0, 0)

        const results = await zxing.readBarcodesFromImageData(
          ctx.getImageData(0, 0, canvas.width, canvas.height),
          { tryHarder: false, formats: ['DataMatrix', 'Code128', 'QRCode', 'EAN-13'] },
        )

        return results
          .filter((r) => r.text)
          .map((r) => ({ rawValue: r.text, format: r.format }))
      },
    }
  } catch {
    return null
  }
}

function cameraErrorMessage(e: unknown): string {
  const name = (e as { name?: string })?.name

  if (name === 'NotAllowedError') {
    return 'Camera access was blocked. Allow it in your browser settings, then try again.'
  }
  if (name === 'NotFoundError' || name === 'OverconstrainedError') {
    return 'No rear camera was found on this device.'
  }
  if (name === 'NotReadableError') {
    return 'The camera is already in use by another app. Close it and try again.'
  }
  return 'The camera could not be started. Enter the reference by hand instead.'
}
