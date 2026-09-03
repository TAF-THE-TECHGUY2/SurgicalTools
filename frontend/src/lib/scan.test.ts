import { describe, expect, it } from 'vitest'
import { isAdjustmentResult, normalizeLot } from '@/lib/scan'

/**
 * Delivery-voucher matching happens in the browser (the transfer does not
 * exist while the rep is building it), while stock-count matching happens on
 * the server. Both sides must normalise a lot identically — otherwise the same
 * label would count on one path and raise an orange discrepancy on the other.
 *
 * These cases mirror `StockCountItem::normalizeLot()` in PHP exactly; if one
 * side changes, this file should fail.
 */
describe('normalizeLot', () => {
  it('uppercases and strips whitespace and hyphens', () => {
    expect(normalizeLot(' hq45-d250902 ')).toBe('HQ45D250902')
    expect(normalizeLot('abc\t123')).toBe('ABC123')
  })

  it('leaves an already-canonical lot untouched', () => {
    expect(normalizeLot('11129D250603')).toBe('11129D250603')
  })

  it('treats nothing-but-separators as no lot at all', () => {
    expect(normalizeLot(null)).toBeNull()
    expect(normalizeLot(undefined)).toBeNull()
    expect(normalizeLot('   ')).toBeNull()
    expect(normalizeLot('--')).toBeNull()
  })

  /**
   * The failure this exists to prevent: an OCR pass that drops a leading digit
   * produces a genuinely different lot, and must NOT be normalised into a
   * match. Only formatting noise is forgiven.
   */
  it('does not collapse a genuinely different lot', () => {
    expect(normalizeLot('11129D250603')).not.toBe(normalizeLot('1129D250603'))
  })
})

describe('isAdjustmentResult', () => {
  it('flags the three spec exceptions', () => {
    expect(isAdjustmentResult('lot_mismatch')).toBe(true)
    expect(isAdjustmentResult('unlisted_item')).toBe(true)
    expect(isAdjustmentResult('expiry_mismatch')).toBe(true)
  })

  it('does not flag a clean match, or a scan still awaiting details', () => {
    expect(isAdjustmentResult('match')).toBe(false)
    expect(isAdjustmentResult('selected')).toBe(false)
    expect(isAdjustmentResult('unresolved')).toBe(false)
    // Every unit of that lot is already on the voucher — not a discrepancy.
    expect(isAdjustmentResult('exhausted')).toBe(false)
  })
})
