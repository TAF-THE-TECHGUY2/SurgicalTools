import { ArrowRight, Check, FileText, Shuffle } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { formatDate } from '@/lib/format'
import type { StockCount, StockCountItem } from '@/types'

/**
 * The Lot Adjustment report in the app (spec §3.5): each new-lot line beside
 * the lot it displaced, with "Adjust lot" to move the stock across, and the
 * items that were not on the sheet at all.
 */
export function LotAdjustmentsCard({ count, canAdjust, busyLineId, onAdjust, onOpenReport }: {
  count: StockCount
  canAdjust: boolean
  busyLineId: number | null
  onAdjust: (line: StockCountItem) => void
  onOpenReport: () => void
}) {
  const items = count.items ?? []
  const byId = new Map(items.map((it) => [it.id, it]))
  const newLots = items.filter((it) => it.adjustment_type === 'lot_mismatch')
  const unlisted = items.filter((it) => it.adjustment_type === 'unlisted_item')

  if (newLots.length === 0 && unlisted.length === 0) return null

  const approved = count.status === 'approved'

  return (
    <Card className="mb-6 border-orange-200">
      <CardHeader
        title="Lot adjustments"
        subtitle={
          !count.locked
            ? 'Lots can be adjusted once the count is signed.'
            : approved
              ? 'This count is approved — correct any remaining lots from the stock catalog.'
              : 'Adjust lot moves the stock from the old lot to the lot actually found.'
        }
        action={
          <Button variant="outline" size="sm" onClick={onOpenReport}>
            <FileText className="h-4 w-4" /> Report
          </Button>
        }
      />
      <CardBody className="space-y-3">
        {newLots.map((line) => {
          const old = line.parent_item_id ? byId.get(line.parent_item_id) : undefined
          const found = line.counted_quantity ?? line.scanned_quantity
          const done = line.lot_adjusted_quantity >= found

          return (
            <div key={line.id} className="rounded-lg border border-orange-200 bg-orange-50/60 p-3">
              <p className="text-sm font-medium text-slate-800">
                {line.item_code ?? line.ref_code} · {line.description ?? '—'}
              </p>
              <div className="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <div className="rounded-md bg-white px-2.5 py-1.5 ring-1 ring-slate-200">
                  <p className="text-[11px] uppercase text-slate-400">Old lot</p>
                  <p className="font-medium text-slate-500 line-through">{old?.lot_number ?? line.expected_lot_number ?? '—'}</p>
                  {old && <p className="text-xs text-slate-500">Syst {old.expected_quantity} · Act {old.counted_quantity ?? '—'}</p>}
                </div>
                <ArrowRight className="h-4 w-4 shrink-0 text-orange-500" />
                <div className="rounded-md bg-white px-2.5 py-1.5 ring-1 ring-orange-300">
                  <p className="text-[11px] uppercase text-orange-600">New lot</p>
                  <p className="font-semibold text-orange-900">{line.lot_number ?? '—'}</p>
                  <p className="text-xs text-slate-500">
                    Act {found}
                    {line.expiry_date && <> · Exp {formatDate(line.expiry_date, 'd MMM yyyy')}</>}
                  </p>
                </div>
                <div className="ml-auto">
                  {done ? (
                    <Badge tone="green"><Check className="mr-1 inline h-3 w-3" />Adjusted {line.lot_adjusted_quantity}</Badge>
                  ) : canAdjust && count.locked && !approved ? (
                    <Button size="sm" loading={busyLineId === line.id} onClick={() => onAdjust(line)}>
                      <Shuffle className="h-4 w-4" /> Adjust lot
                    </Button>
                  ) : (
                    <Badge tone="amber">Pending</Badge>
                  )}
                </div>
              </div>
            </div>
          )
        })}

        {unlisted.length > 0 && (
          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-orange-700">
              Not on sheet ({unlisted.length})
            </p>
            <ul className="divide-y divide-orange-100 rounded-lg border border-orange-200 text-sm">
              {unlisted.map((line) => (
                <li key={line.id} className="flex justify-between gap-3 px-3 py-2">
                  <span className="text-slate-800">
                    {line.item_code ?? line.ref_code} · {line.description ?? '—'}
                    <span className="block text-xs text-slate-500">Lot {line.lot_number ?? '—'}</span>
                  </span>
                  <span className="shrink-0 text-slate-600">Act {line.counted_quantity ?? line.scanned_quantity}</span>
                </li>
              ))}
            </ul>
            <p className="mt-1 text-xs text-slate-500">Receive these via the stock catalog.</p>
          </div>
        )}
      </CardBody>
    </Card>
  )
}
