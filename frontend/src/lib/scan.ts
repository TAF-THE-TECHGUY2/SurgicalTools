/**
 * Canonical form of a lot number for comparison: uppercased, with whitespace
 * and hyphens stripped.
 *
 * This must stay identical to `StockCountItem::normalizeLot()` on the server.
 * Stock-count matching happens server-side, but delivery-voucher matching runs
 * in the browser (the transfer does not exist yet), so both sides need the
 * same rule — otherwise the same label would count on one path and flag an
 * orange discrepancy on the other.
 *
 * An OCR pass that drops a hyphen or a leading space must not fabricate a lot
 * mismatch.
 */
export function normalizeLot(lot?: string | null): string | null {
  if (lot === null || lot === undefined) return null

  const normalized = lot.trim().toUpperCase().replace(/[\s-]+/g, '')

  return normalized === '' ? null : normalized
}

/**
 * Whether a scan outcome is one of the spec's discrepancies, and therefore
 * renders with the orange highlight. Shared so the count table, the voucher
 * grid and both scanners agree on what counts as an exception.
 */
export function isAdjustmentResult(result: string): boolean {
  return ['lot_mismatch', 'unlisted_item', 'expiry_mismatch'].includes(result)
}
