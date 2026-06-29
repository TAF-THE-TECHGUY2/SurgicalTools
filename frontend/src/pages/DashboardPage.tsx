import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Boxes, AlertTriangle, Clock, ArrowLeftRight, CheckSquare, ClipboardCheck, Building2, TrendingUp,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { api } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { StatusBadge } from '@/components/ui/Badge'
import { apiError } from '@/lib/api'
import { formatDateTime, humanize } from '@/lib/format'
import type { DashboardData } from '@/types'

export default function DashboardPage() {
  const { user } = useAuth()
  const { data, isLoading, error } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<DashboardData>('/dashboard')).data,
  })

  if (isLoading) return <LoadingState label="Loading dashboard…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!data) return null

  return (
    <>
      <PageHeader
        title={`Welcome, ${user?.name?.split(' ')[0]}`}
        description="Here's what's happening across your inventory today."
      />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Stat icon={Boxes} tone="teal" label="Stock lines" value={data.inventory.total_items} sub={`${data.inventory.total_units} units`} to="/inventory" />
        <Stat icon={AlertTriangle} tone="amber" label="Low stock" value={data.inventory.low_stock} sub="below threshold" to="/inventory?low_stock=1" />
        <Stat icon={Clock} tone="red" label="Expiring soon" value={data.inventory.expiring_soon} sub={`${data.inventory.expiring_critical} critical`} to="/reports" />
        <Stat icon={ArrowLeftRight} tone="blue" label="Open transfers" value={data.transfers.open} sub={`${data.transfers.pending_approval} pending`} to="/transfers" />
        <Stat icon={CheckSquare} tone="purple" label="Awaiting review" value={data.transfers.awaiting_admin_review} sub="admin review" to="/approvals" />
        <Stat icon={ClipboardCheck} tone="teal" label="Open counts" value={data.stock_counts.open} sub={`${data.stock_counts.submitted} submitted`} to="/stock-counts" />
        <Stat icon={TrendingUp} tone="green" label="Completed (mo)" value={data.transfers.completed_this_month} sub="transfers" to="/transfers?status=completed" />
        <Stat icon={Building2} tone="blue" label="Hospitals" value={data.hospitals.assigned} sub={`of ${data.hospitals.total}`} to="/hospitals" />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Recent transfers" action={<Link to="/transfers" className="text-sm text-brand-700 hover:underline">View all</Link>} />
          <CardBody className="p-0">
            {data.recent_transfers.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-400">No transfers yet.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {data.recent_transfers.map((t) => (
                  <li key={t.id}>
                    <Link to={`/transfers/${t.id}`} className="flex items-center justify-between px-5 py-3 hover:bg-slate-50">
                      <div>
                        <p className="text-sm font-medium text-slate-800">{t.reference}</p>
                        <p className="text-xs text-slate-500">{t.type_label} · {t.hospital?.name ?? 'Boot'}</p>
                      </div>
                      <StatusBadge status={t.status} />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Recent stock movements" subtitle="Live ledger across all locations" />
          <CardBody className="p-0">
            {data.recent_movements.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-400">No movements yet.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {data.recent_movements.map((m) => (
                  <li key={m.id} className="flex items-center justify-between px-5 py-3">
                    <div>
                      <p className="text-sm font-medium text-slate-800">{m.ref_code} <span className="text-slate-400">×{m.quantity}</span></p>
                      <p className="text-xs text-slate-500">
                        {humanize(m.from_location)} → {humanize(m.to_location)}
                      </p>
                    </div>
                    <span className="text-xs text-slate-400">{formatDateTime(m.moved_at)}</span>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>
    </>
  )
}

const toneClasses: Record<string, string> = {
  teal: 'bg-brand-50 text-brand-700',
  amber: 'bg-amber-50 text-amber-600',
  red: 'bg-red-50 text-red-600',
  blue: 'bg-blue-50 text-blue-600',
  purple: 'bg-purple-50 text-purple-600',
  green: 'bg-emerald-50 text-emerald-600',
}

function Stat({ icon: Icon, tone, label, value, sub, to }: {
  icon: LucideIcon
  tone: keyof typeof toneClasses
  label: string
  value: number
  sub: string
  to: string
}) {
  return (
    <Link to={to}>
      <Card className="transition-shadow hover:shadow-md">
        <CardBody className="flex items-center gap-3">
          <span className={`flex h-11 w-11 items-center justify-center rounded-lg ${toneClasses[tone]}`}>
            <Icon className="h-5 w-5" />
          </span>
          <div className="min-w-0">
            <p className="text-2xl font-bold leading-none text-slate-900">{value}</p>
            <p className="mt-1 truncate text-xs font-medium text-slate-600">{label}</p>
            <p className="truncate text-[11px] text-slate-400">{sub}</p>
          </div>
        </CardBody>
      </Card>
    </Link>
  )
}
