import { useState } from 'react'
import { render, screen } from '@testing-library/react'
import { act } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SignaturePad } from './SignaturePad'

/**
 * A stand-in for the real pad: jsdom has no canvas to draw on, and what is
 * under test here is the component's lifecycle, not the drawing.
 *
 * Built inside vi.hoisted because vi.mock's factory is lifted above ordinary
 * declarations in this file.
 */
const { instances, FakePad } = vi.hoisted(() => {
  const instances: FakePadShape[] = []

  class FakePad {
    listeners: Record<string, () => void> = {}
    empty = true
    cleared = 0

    constructor() { instances.push(this) }
    addEventListener(name: string, cb: () => void) { this.listeners[name] = cb }
    isEmpty() { return this.empty }
    toDataURL() { return 'data:image/png;base64,SIG' }
    fromDataURL() {}
    clear() { this.empty = true; this.cleared++ }
    on() {}
    off() {}

    /** Simulate the recipient drawing. */
    draw() { this.empty = false; this.listeners.endStroke?.() }
  }

  return { instances, FakePad }
})

interface FakePadShape {
  listeners: Record<string, () => void>
  empty: boolean
  cleared: number
  isEmpty(): boolean
  draw(): void
}

vi.mock('signature_pad', () => ({ default: FakePad }))

beforeEach(() => { instances.length = 0 })

describe('SignaturePad', () => {
  /**
   * The bug this guards: onChange was in the mount effect's dependency list.
   * A caller declaring its handler inline gets a new function identity on
   * every render, so reporting a stroke re-rendered the parent, changed the
   * handler, and tore the pad down — erasing the signature that had just been
   * drawn. The recipient signed, and the cell went blank.
   */
  it('survives a parent that re-renders with a fresh handler on every stroke', () => {
    function Parent() {
      const [signature, setSignature] = useState('')
      const [at, setAt] = useState<string | null>(null)

      return (
        <>
          <span data-testid="sig">{signature}</span>
          <span data-testid="at">{at ?? ''}</span>
          {/* Deliberately inline and unmemoised — the shape a caller writes. */}
          <SignaturePad
            onChange={(d) => { setSignature(d); setAt(d ? 'stamped' : null) }}
          />
        </>
      )
    }

    render(<Parent />)
    expect(instances).toHaveLength(1)

    act(() => { instances[0].draw() })

    expect(screen.getByTestId('sig').textContent).toBe('data:image/png;base64,SIG')
    expect(screen.getByTestId('at').textContent).toBe('stamped')

    // The pad must be the same instance, never rebuilt, and never cleared:
    // a rebuild is what blanked the canvas.
    expect(instances).toHaveLength(1)
    expect(instances[0].cleared).toBe(0)
    expect(instances[0].isEmpty()).toBe(false)
  })

  /** DATE DELIVERED is stamped by the stroke, so the stroke must report once. */
  it('reports the signature to the caller', () => {
    const onChange = vi.fn()
    render(<SignaturePad onChange={onChange} />)

    act(() => { instances[0].draw() })

    expect(onChange).toHaveBeenCalledWith('data:image/png;base64,SIG')
  })

  /**
   * dev-spec §4: the pad does not activate until the signatory is named.
   * Disabling has to detach the listeners, not merely dim the canvas — a
   * disabled look that still recorded strokes would take a signature the form
   * is not yet entitled to.
   */
  it('clears anything already drawn when it becomes disabled', () => {
    const onChange = vi.fn()
    const { rerender } = render(<SignaturePad onChange={onChange} />)

    act(() => { instances[0].draw() })
    onChange.mockClear()

    rerender(<SignaturePad onChange={onChange} disabled />)

    expect(instances[0].isEmpty()).toBe(true)
    expect(onChange).toHaveBeenCalledWith('')
    expect(instances).toHaveLength(1)
  })

  it('shows the in-cell prompt on a bare pad until something is drawn', () => {
    render(<SignaturePad onChange={() => {}} variant="bare" />)
    expect(screen.getByText('Sign here')).toBeTruthy()

    act(() => { instances[0].draw() })
    expect(screen.queryByText('Sign here')).toBeNull()
    expect(screen.getByText('Clear')).toBeTruthy()
  })

  it('names the reason it is locked', () => {
    render(
      <SignaturePad onChange={() => {}} disabled disabledReason="Enter the recipient's name first" />,
    )
    expect(screen.getByText("Enter the recipient's name first")).toBeTruthy()
  })
})
