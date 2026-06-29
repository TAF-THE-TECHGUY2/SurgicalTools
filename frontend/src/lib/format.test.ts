import { describe, expect, it } from 'vitest'
import { humanize, formatDate, formatDateTime, formatMoney } from '@/lib/format'

describe('humanize', () => {
  it('converts snake_case to Title Case', () => {
    expect(humanize('jhb_master_warehouse')).toBe('Jhb Master Warehouse')
    expect(humanize('pending_approval')).toBe('Pending Approval')
  })

  it('returns an em-dash for empty values', () => {
    expect(humanize(null)).toBe('—')
    expect(humanize(undefined)).toBe('—')
    expect(humanize('')).toBe('—')
  })
})

describe('formatDate / formatDateTime', () => {
  it('formats an ISO date', () => {
    expect(formatDate('2026-03-15')).toBe('15 Mar 2026')
  })

  it('includes the time for date-time', () => {
    expect(formatDateTime('2026-03-15T09:30:00Z')).toMatch(/15 Mar 2026/)
  })

  it('returns an em-dash for null/invalid input', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatDate('not-a-date')).toBe('—')
  })
})

describe('formatMoney', () => {
  it('returns an em-dash for empty/invalid values', () => {
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney('')).toBe('—')
    expect(formatMoney('abc')).toBe('—')
  })

  it('renders a currency amount for numbers and numeric strings', () => {
    const out = formatMoney(1234.5)
    expect(out).toContain('R')
    expect(out).toMatch(/1\D?234/) // grouping separator varies by locale (may be a non-breaking space)
    expect(formatMoney('850.00')).toContain('850')
  })
})
