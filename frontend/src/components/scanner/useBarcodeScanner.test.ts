import { act, renderHook } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useBarcodeScanner } from '@/components/scanner/useBarcodeScanner'

/**
 * The continuous capture loop reads the camera several times a second, so the
 * same barcode sits in frame across many decodes. Suppressing those repeats is
 * what keeps one physical item from counting as thirty.
 */

/** Values the stubbed detector returns on each decode attempt. */
let framePayloads: string[] = []
let stopped: number

function stubCamera() {
  stopped = 0
  const track = { stop: () => { stopped += 1 } }

  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: { getUserMedia: vi.fn().mockResolvedValue({ getTracks: () => [track] }) },
  })

  // Native detector: hands back whatever the current frame is set to.
  ;(window as unknown as { BarcodeDetector: unknown }).BarcodeDetector = class {
    async detect() {
      return framePayloads.map((rawValue) => ({ rawValue }))
    }
    static async getSupportedFormats() {
      return ['data_matrix', 'code_128', 'qr_code', 'ean_13']
    }
  }
}

/** A stand-in for the <video> element, which jsdom cannot actually play. */
function fakeVideo() {
  return {
    readyState: 4,
    videoWidth: 1280,
    videoHeight: 720,
    srcObject: null,
    play: vi.fn().mockResolvedValue(undefined),
    setAttribute: vi.fn(),
  } as unknown as HTMLVideoElement
}

async function startScanner(onDecode: (raw: string) => void, paused = false) {
  const hook = renderHook(
    ({ p }: { p: boolean }) => useBarcodeScanner({ onDecode, paused: p }),
    { initialProps: { p: paused } },
  )

  hook.result.current.videoRef.current = fakeVideo()
  // start() awaits the camera and the detector, so the status is settled by
  // the time it returns — no waitFor, which would block on real time here.
  await act(async () => { await hook.result.current.start() })

  return hook
}

/** Run the decode interval forward far enough for `frames` decode attempts. */
async function advanceFrames(frames: number) {
  for (let i = 0; i < frames; i++) {
    // Async advance so the detector's promise inside the interval resolves.
    await act(async () => { await vi.advanceTimersByTimeAsync(140) })
  }
}

describe('useBarcodeScanner', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    framePayloads = []
    stubCamera()
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  it('reports scanning once the camera starts', async () => {
    const hook = await startScanner(vi.fn())

    expect(hook.result.current.status).toBe('scanning')
    expect(hook.result.current.barcodeSupported).toBe(true)
    expect(hook.result.current.error).toBeNull()
  })

  it('emits one decode for a barcode held in frame across many reads', async () => {
    const onDecode = vi.fn()
    await startScanner(onDecode)

    framePayloads = ['(01)03456789012345(10)11129D250603']
    await advanceFrames(8)

    expect(onDecode).toHaveBeenCalledTimes(1)
  })

  it('keeps scanning after a decode, so the next item is picked up', async () => {
    const onDecode = vi.fn()
    await startScanner(onDecode)

    framePayloads = ['(10)LOT-A']
    await advanceFrames(3)

    // Next device presented to the camera.
    framePayloads = ['(10)LOT-B']
    await advanceFrames(3)

    expect(onDecode).toHaveBeenCalledTimes(2)
    expect(onDecode).toHaveBeenNthCalledWith(1, '(10)LOT-A')
    expect(onDecode).toHaveBeenNthCalledWith(2, '(10)LOT-B')
  })

  it('re-reads the same code once the duplicate window has passed', async () => {
    const onDecode = vi.fn()
    await startScanner(onDecode)

    framePayloads = ['(10)LOT-A']
    await advanceFrames(2)
    expect(onDecode).toHaveBeenCalledTimes(1)

    // A second physical unit of the same lot, scanned after the window.
    framePayloads = []
    await act(async () => { await vi.advanceTimersByTimeAsync(2100) })
    framePayloads = ['(10)LOT-A']
    await advanceFrames(2)

    expect(onDecode).toHaveBeenCalledTimes(2)
  })

  it('stops decoding while paused, then resumes', async () => {
    const onDecode = vi.fn()
    const hook = await startScanner(onDecode)

    hook.rerender({ p: true })
    framePayloads = ['(10)LOT-A']
    await advanceFrames(5)
    expect(onDecode).not.toHaveBeenCalled()

    hook.rerender({ p: false })
    await advanceFrames(2)
    expect(onDecode).toHaveBeenCalledTimes(1)
  })

  it('releases the camera on stop', async () => {
    const hook = await startScanner(vi.fn())

    act(() => hook.result.current.stop())

    expect(stopped).toBe(1)
    expect(hook.result.current.status).toBe('idle')
  })

  it('releases the camera when the component unmounts mid-scan', async () => {
    const hook = await startScanner(vi.fn())

    hook.unmount()

    expect(stopped).toBe(1)
  })

  it('explains a blocked camera instead of failing silently', async () => {
    const denied = Object.assign(new Error('denied'), { name: 'NotAllowedError' })
    Object.defineProperty(navigator, 'mediaDevices', {
      configurable: true,
      value: { getUserMedia: vi.fn().mockRejectedValue(denied) },
    })

    const hook = renderHook(() => useBarcodeScanner({ onDecode: vi.fn() }))
    await act(async () => { await hook.result.current.start() })

    expect(hook.result.current.status).toBe('error')
    expect(hook.result.current.error).toMatch(/blocked/i)
  })
})
