import { format, formatDistanceToNow, parseISO } from 'date-fns'

export function formatDate(value?: string | null, pattern = 'dd MMM yyyy'): string {
  if (!value) return '—'
  try {
    return format(parseISO(value), pattern)
  } catch {
    return '—'
  }
}

export function formatDateTime(value?: string | null): string {
  return formatDate(value, 'dd MMM yyyy HH:mm')
}

export function fromNow(value?: string | null): string {
  if (!value) return ''
  try {
    return formatDistanceToNow(parseISO(value), { addSuffix: true })
  } catch {
    return ''
  }
}

export function formatMoney(value?: string | number | null): string {
  if (value === null || value === undefined || value === '') return '—'
  const n = typeof value === 'string' ? parseFloat(value) : value
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format(n)
}

/** "jhb_master_warehouse" -> "Jhb Master Warehouse". */
export function humanize(value?: string | null): string {
  if (!value) return '—'
  return value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}
