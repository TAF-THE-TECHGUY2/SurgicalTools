import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, apiError } from '@/lib/api'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { formatDate, humanize } from '@/lib/format'

interface InventoryReport {
  by_location: { location: string; units: number; lines: number }[]
  by_stock_type: { stock_type: string; units: number }[]
  by_status: { status: string; lines: number }[]
}

interface TransfersReport {
  by_status: { status: string; total: number }[]
  by_type: { type: string; total: number }[]
  completed_by_hospital: { hospital_id: number; total: number; hospital: { name: string } }[]
}

interface VarianceRow {
  reference: string
  status: string
  total_variance: number
  lines: number
  submitted_at: string | null
}

interface ExpiryItem {
  id: number
  ref_code: string
  description: string
  lot_number: string | null
  expiry_date: string | null
  quantity: number
}

interface ExpiryReport {
  critical: ExpiryItem[]
  high: ExpiryItem[]
  warning: ExpiryItem[]
}

interface RepRow {
  id: number
  name: string
  transfers_requested: number
  transfers_completed: number
}

export default function ReportsPage() {
  return (
    <>
      <PageHeader title="Reports" description="Operational snapshots across inventory, transfers and people." />

      <div className="grid gap-6 lg:grid-cols-2">
        <InventoryByLocationCard />
        <InventoryByStockTypeCard />
        <TransfersByStatusCard />
        <VariancesCard />
        <ExpiryCard />
        <RepPerformanceCard />
      </div>
    </>
  )
}

function ReportCard({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return (
    <Card>
      <CardHeader title={title} subtitle={subtitle} />
      <CardBody className="p-0">{children}</CardBody>
    </Card>
  )
}

function InventoryByLocationCard() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['reports', 'inventory'],
    queryFn: async () => (await api.get<InventoryReport>('/reports/inventory')).data,
  })

  return (
    <ReportCard title="Inventory by location">
      {isLoading ? (
        <LoadingState label="Loading…" />
      ) : error ? (
        <div className="p-5"><ErrorState message={apiError(error)} /></div>
      ) : (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-5 py-3 font-semibold">Location</th>
              <th className="px-5 py-3 text-right font-semibold">Units</th>
              <th className="px-5 py-3 text-right font-semibold">Lines</th>
            </tr>
          </thead>
          <tbody>
            {(data?.by_location ?? []).map((r) => (
              <tr key={r.location} className="border-b border-slate-100">
                <td className="px-5 py-3 text-slate-700">{humanize(r.location)}</td>
                <td className="px-5 py-3 text-right font-medium text-slate-800">{r.units}</td>
                <td className="px-5 py-3 text-right text-slate-600">{r.lines}</td>
              </tr>
            ))}
            {(data?.by_location?.length ?? 0) === 0 && (
              <tr><td colSpan={3} className="px-5 py-8 text-center text-slate-400">No data.</td></tr>
            )}
          </tbody>
        </table>
      )}
    </ReportCard>
  )
}

function InventoryByStockTypeCard() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['reports', 'inventory'],
    queryFn: async () => (await api.get<InventoryReport>('/reports/inventory')).data,
  })

  return (
    <ReportCard title="Inventory by stock type">
      {isLoading ? (
        <LoadingState label="Loading…" />
      ) : error ? (
        <div className="p-5"><ErrorState message={apiError(error)} /></div>
      ) : (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-5 py-3 font-semibold">Stock type</th>
              <th className="px-5 py-3 text-right font-semibold">Units</th>
            </tr>
          </thead>
          <tbody>
            {(data?.by_stock_type ?? []).map((r) => (
              <tr key={r.stock_type} className="border-b border-slate-100">
                <td className="px-5 py-3 text-slate-700">{humanize(r.stock_type)}</td>
                <td className="px-5 py-3 text-right font-medium text-slate-800">{r.units}</td>
              </tr>
            ))}
            {(data?.by_stock_type?.length ?? 0) === 0 && (
              <tr><td colSpan={2} className="px-5 py-8 text-center text-slate-400">No data.</td></tr>
            )}
          </tbody>
        </table>
      )}
    </ReportCard>
  )
}

function TransfersByStatusCard() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['reports', 'transfers'],
    queryFn: async () => (await api.get<TransfersReport>('/reports/transfers')).data,
  })

  return (
    <ReportCard title="Transfers by status">
      {isLoading ? (
        <LoadingState label="Loading…" />
      ) : error ? (
        <div className="p-5"><ErrorState message={apiError(error)} /></div>
      ) : (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-5 py-3 font-semibold">Status</th>
              <th className="px-5 py-3 text-right font-semibold">Total</th>
            </tr>
          </thead>
          <tbody>
            {(data?.by_status ?? []).map((r) => (
              <tr key={r.status} className="border-b border-slate-100">
                <td className="px-5 py-3"><StatusBadge status={r.status} /></td>
                <td className="px-5 py-3 text-right font-medium text-slate-800">{r.total}</td>
              </tr>
            ))}
            {(data?.by_status?.length ?? 0) === 0 && (
              <tr><td colSpan={2} className="px-5 py-8 text-center text-slate-400">No data.</td></tr>
            )}
          </tbody>
        </table>
      )}
    </ReportCard>
  )
}

function VariancesCard() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['reports', 'variances'],
    queryFn: async () => (await api.get<VarianceRow[]>('/reports/variances')).data,
  })

  return (
    <ReportCard title="Stock count variances">
      {isLoading ? (
        <LoadingState label="Loading…" />
      ) : error ? (
        <div className="p-5"><ErrorState message={apiError(error)} /></div>
      ) : (data?.length ?? 0) === 0 ? (
        <p className="px-5 py-8 text-center text-sm text-slate-400">No variances recorded.</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {(data ?? []).map((r) => (
            <li key={r.reference} className="flex items-center justify-between px-5 py-3">
              <div className="min-w-0">
                <p className="text-sm font-medium text-slate-800">{r.reference}</p>
                <p className="text-xs text-slate-500">
                  {r.lines} lines · {formatDate(r.submitted_at)}
                </p>
              </div>
              <div className="flex items-center gap-3">
                <span className="text-sm font-semibold text-slate-800">{r.total_variance}</span>
                <StatusBadge status={r.status} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </ReportCard>
  )
}

const EXPIRY_SECTIONS: { key: keyof ExpiryReport; label: string; tone: string }[] = [
  { key: 'critical', label: 'Critical', tone: 'text-red-600' },
  { key: 'high', label: 'High', tone: 'text-amber-600' },
  { key: 'warning', label: 'Warning', tone: 'text-slate-500' },
]

function ExpiryCard() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['reports', 'expiry'],
    queryFn: async () => (await api.get<ExpiryReport>('/reports/expiry')).data,
  })

  return (
    <ReportCard title="Expiry tracking" subtitle="Stock approaching or past expiry">
      {isLoading ? (
        <LoadingState label="Loading…" />
      ) : error ? (
        <div className="p-5"><ErrorState message={apiError(error)} /></div>
      ) : (
        <div className="divide-y divide-slate-100">
          {EXPIRY_SECTIONS.map(({ key, label, tone }) => {
            const items = data?.[key] ?? []
            return (
              <div key={key} className="px-5 py-4">
                <p className={`mb-2 text-xs font-semibold uppercase tracking-wide ${tone}`}>
                  {label} ({items.length})
                </p>
                {items.length === 0 ? (
                  <p className="text-sm text-slate-400">None.</p>
                ) : (
                  <ul className="space-y-2">
                    {items.map((item) => (
                      <li key={item.id} className="flex items-center justify-between gap-3 text-sm">
                        <div className="min-w-0">
                          <p className="truncate font-medium text-slate-800">
                            {item.ref_code} <span className="font-normal text-slate-500">×{item.quantity}</span>
                          </p>
                          <p className="truncate text-xs text-slate-500">
                            {item.description}
                            {item.lot_number ? ` · Lot ${item.lot_number}` : ''}
                          </p>
                        </div>
                        <span className={`shrink-0 text-xs ${tone}`}>{formatDate(item.expiry_date)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )
          })}
        </div>
      )}
    </ReportCard>
  )
}

function RepPerformanceCard() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['reports', 'rep-performance'],
    queryFn: async () => (await api.get<RepRow[]>('/reports/rep-performance')).data,
  })

  return (
    <ReportCard title="Rep performance">
      {isLoading ? (
        <LoadingState label="Loading…" />
      ) : error ? (
        <div className="p-5"><ErrorState message={apiError(error)} /></div>
      ) : (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="px-5 py-3 font-semibold">Name</th>
              <th className="px-5 py-3 text-right font-semibold">Requested</th>
              <th className="px-5 py-3 text-right font-semibold">Completed</th>
            </tr>
          </thead>
          <tbody>
            {(data ?? []).map((r) => (
              <tr key={r.id} className="border-b border-slate-100">
                <td className="px-5 py-3 font-medium text-slate-800">{r.name}</td>
                <td className="px-5 py-3 text-right text-slate-600">{r.transfers_requested}</td>
                <td className="px-5 py-3 text-right">
                  <Badge tone="green">{r.transfers_completed}</Badge>
                </td>
              </tr>
            ))}
            {(data?.length ?? 0) === 0 && (
              <tr><td colSpan={3} className="px-5 py-8 text-center text-slate-400">No data.</td></tr>
            )}
          </tbody>
        </table>
      )}
    </ReportCard>
  )
}
