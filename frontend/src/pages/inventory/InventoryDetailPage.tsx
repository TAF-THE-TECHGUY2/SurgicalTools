import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import type { ReactNode } from 'react'
import { ArrowLeft, AlertTriangle, Clock } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { formatDate, formatDateTime, formatMoney, humanize } from '@/lib/format'
import type { InventoryItem, StockMovement, Paginated } from '@/types'

export default function InventoryDetailPage() {
  const { id } = useParams<{ id: string }>()

  const { data: item, isLoading, error } = useQuery({
    queryKey: ['inventory', id],
    queryFn: async () => (await api.get<InventoryItem>(`/inventory/${id}`)).data,
    enabled: !!id,
  })

  const { data: movements, isLoading: movementsLoading, error: movementsError } = useQuery({
    queryKey: ['inventory', id, 'movements'],
    queryFn: async () => (await api.get<Paginated<StockMovement>>(`/inventory/${id}/movements`)).data,
    enabled: !!id,
  })

  if (isLoading) return <LoadingState label="Loading item…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!item) return null

  const expiryCritical = item.days_to_expiry != null && item.days_to_expiry <= 30

  const movementColumns: Column<StockMovement>[] = [
    { key: 'qty', header: 'Qty', render: (m) => <span className="font-medium text-slate-800">{m.quantity}</span> },
    {
      key: 'route',
      header: 'From → To',
      render: (m) => `${humanize(m.from_location)} → ${humanize(m.to_location)}`,
    },
    { key: 'type', header: 'Type', render: (m) => <Badge tone="blue">{humanize(m.movement_type)}</Badge> },
    { key: 'by', header: 'Performed by', render: (m) => m.performed_by?.name ?? '—' },
    { key: 'at', header: 'When', render: (m) => formatDateTime(m.moved_at) },
  ]

  return (
    <>
      <Link to="/inventory" className="mb-4 inline-flex items-center gap-1.5 text-sm text-brand-700 hover:underline">
        <ArrowLeft className="h-4 w-4" /> Back to inventory
      </Link>

      <Card className="mb-6">
        <CardBody>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <div className="flex items-center gap-3">
                <h1 className="text-2xl font-bold tracking-tight text-slate-900">{item.ref_code}</h1>
                <StatusBadge status={item.status} />
              </div>
              <p className="mt-1 text-sm text-slate-500">{item.description}</p>
            </div>
            <div className="text-right">
              <p className="text-4xl font-bold leading-none text-slate-900">{item.quantity}</p>
              <p className="mt-1 text-xs font-medium uppercase tracking-wide text-slate-400">on hand</p>
            </div>
          </div>

          {(item.is_low_stock || expiryCritical) && (
            <div className="mt-4 flex flex-wrap gap-2">
              {item.is_low_stock && (
                <span className="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-700">
                  <AlertTriangle className="h-4 w-4" /> Low stock
                  {item.min_threshold != null && ` (min ${item.min_threshold})`}
                </span>
              )}
              {expiryCritical && (
                <span className="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700">
                  <Clock className="h-4 w-4" /> Expires in {item.days_to_expiry} days
                </span>
              )}
            </div>
          )}

          <dl className="mt-6 grid gap-x-6 gap-y-4 border-t border-slate-100 pt-6 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label="Lot number" value={item.lot_number ?? '—'} />
            <Detail label="Stock type" value={humanize(item.stock_type)} />
            <Detail label="Location" value={humanize(item.location)} />
            <Detail
              label="Expiry"
              value={
                <span className={expiryCritical ? 'text-red-600' : undefined}>
                  {formatDate(item.expiry_date)}
                </span>
              }
            />
            <Detail label="Holder" value={item.holder?.name ?? '—'} />
            <Detail label="Hospital" value={item.hospital?.name ?? '—'} />
            <Detail label="Unit price" value={formatMoney(item.unit_price)} />
            <Detail label="Min threshold" value={item.min_threshold != null ? String(item.min_threshold) : '—'} />
            <Detail label="Barcode" value={item.barcode ?? '—'} />
            <Detail label="Unit of measure" value={item.uom ?? '—'} />
          </dl>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Movement history" subtitle="Stock ledger for this line" />
        <CardBody className="p-0">
          {movementsLoading ? (
            <LoadingState label="Loading movements…" />
          ) : movementsError ? (
            <div className="p-5">
              <ErrorState message={apiError(movementsError)} />
            </div>
          ) : (
            <DataTable
              columns={movementColumns}
              rows={movements?.data ?? []}
              rowKey={(m) => m.id}
              empty="No movements recorded for this item."
            />
          )}
        </CardBody>
      </Card>
    </>
  )
}

function Detail({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="mt-0.5 text-sm text-slate-800">{value}</dd>
    </div>
  )
}
