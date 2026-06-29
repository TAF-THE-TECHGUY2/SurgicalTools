import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'
import { humanize } from '@/lib/format'

type Tone = 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'teal' | 'purple'

const tones: Record<Tone, string> = {
  gray: 'bg-slate-100 text-slate-700 ring-slate-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  amber: 'bg-amber-50 text-amber-700 ring-amber-200',
  red: 'bg-red-50 text-red-700 ring-red-200',
  blue: 'bg-blue-50 text-blue-700 ring-blue-200',
  teal: 'bg-brand-50 text-brand-700 ring-brand-200',
  purple: 'bg-purple-50 text-purple-700 ring-purple-200',
}

export function Badge({ tone = 'gray', children, className }: {
  tone?: Tone
  children: ReactNode
  className?: string
}) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone], className)}>
      {children}
    </span>
  )
}

// Maps any domain status string to a sensible colour.
const STATUS_TONE: Record<string, Tone> = {
  // transfer / count statuses
  draft: 'gray',
  pending_approval: 'amber',
  approved: 'blue',
  awaiting_signature: 'amber',
  signed: 'blue',
  awaiting_admin_review: 'purple',
  completed: 'green',
  rejected: 'red',
  requested: 'gray',
  in_progress: 'blue',
  submitted: 'amber',
  under_review: 'purple',
  investigating: 'red',
  // stock statuses
  available: 'green',
  reserved: 'amber',
  ordered: 'blue',
  in_transit: 'blue',
  delivered: 'green',
  expired: 'red',
  damaged: 'red',
  quarantined: 'red',
}

export function StatusBadge({ status }: { status: string }) {
  return <Badge tone={STATUS_TONE[status] ?? 'gray'}>{humanize(status)}</Badge>
}
