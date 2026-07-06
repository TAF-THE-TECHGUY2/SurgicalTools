import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Boxes, ChevronDown, ChevronRight, MapPin, Search, User as UserIcon } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Field, Input, Select } from '@/components/ui/Field'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { formatDate, humanize } from '@/lib/format'
import { cn } from '@/lib/cn'
import type {
  GroupedStockRow, ItemSearchRow, LocationEntity, LocationInventoryResponse, StockItem,
} from '@/types'

type Tab = 'my' | 'location' | 'find'

export default function MyInventoryPage() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const [tab, setTab] = useState<Tab>(() => {
    if (searchParams.get('location')) return 'location'
    if (searchParams.get('item')) return 'find'
    return 'my'
  })
  const [locationId, setLocationId] = useState(searchParams.get('location') ?? '')
  const [q, setQ] = useState('')
  const [findQ, setFindQ] = useState('')

  // Deep link from global search: /inventory?item={id} → resolve the item name.
  const itemParam = searchParams.get('item')
  const { data: linkedItem } = useQuery({
    queryKey: ['stock-items', itemParam],
    queryFn: async () => (await api.get<{ data: StockItem }>(`/stock-items/${itemParam}`)).data.data,
    enabled: !!itemParam,
  })
  useEffect(() => {
    if (linkedItem?.name) setFindQ(linkedItem.name)
  }, [linkedItem])

  const { data: locations } = useQuery({
    queryKey: ['locations'],
    queryFn: async () => (await api.get<{ data: LocationEntity[] }>('/locations')).data.data,
  })

  // Deep link from a hospital page: /inventory?hospital={id} → its location.
  const hospitalParam = searchParams.get('hospital')
  useEffect(() => {
    if (hospitalParam && locations) {
      const match = locations.find((l) => String(l.hospital_id ?? '') === hospitalParam)
      if (match) {
        setLocationId(String(match.id))
        setTab('location')
      }
    }
  }, [hospitalParam, locations])

  const tabs: { key: Tab; label: string; icon: typeof Boxes }[] = [
    { key: 'my', label: 'My Inventory', icon: Boxes },
    { key: 'location', label: 'By Rep / Location', icon: UserIcon },
    { key: 'find', label: 'Find an Item', icon: Search },
  ]

  return (
    <>
      <PageHeader
        title="Inventory"
        description={user?.location ? `Your linked location: ${user.location.name}` : 'Serialised device stock across every location.'}
      />

      {/* Tabs */}
      <div className="mb-4 flex gap-1 rounded-xl border border-slate-200 bg-white p-1">
        {tabs.map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className={cn(
              'flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
              tab === t.key ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-50',
            )}
          >
            <t.icon className="h-4 w-4" /> <span className="hidden sm:inline">{t.label}</span>
          </button>
        ))}
      </div>

      {tab === 'my' && <LocationInventory endpoint="/inventory/my" q={q} onQ={setQ} emptyHint="Your location holds no stock yet. Request a transfer to bring devices in." />}

      {tab === 'location' && (
        <>
          <Card className="mb-4">
            <CardBody className="grid gap-3 sm:grid-cols-2">
              <Field label="Rep / location">
                <Select value={locationId} onChange={(e) => setLocationId(e.target.value)}>
                  <option value="">Select a location…</option>
                  {locations?.map((l) => (
                    <option key={l.id} value={l.id}>
                      {l.name}{l.owner ? ` — ${l.owner.name}` : ''} ({humanize(l.type)})
                    </option>
                  ))}
                </Select>
              </Field>
            </CardBody>
          </Card>
          {locationId ? (
            <LocationInventory endpoint={`/locations/${locationId}/inventory`} q={q} onQ={setQ} emptyHint="No stock at this location." />
          ) : (
            <EmptyState icon={<MapPin className="h-10 w-10" />} title="Pick a rep or location" description="Choose an entity above to see everything it currently holds." />
          )}
        </>
      )}

      {tab === 'find' && (
        <FindItem
          q={findQ}
          onQ={setFindQ}
          onOpenLocation={(id) => {
            setLocationId(String(id))
            setTab('location')
            navigate(`/inventory?location=${id}`, { replace: true })
          }}
        />
      )}
    </>
  )
}

/* ---------------------------------------------------------------------- */
/*  Grouped stock at one location (items → expandable units)              */
/* ---------------------------------------------------------------------- */

function LocationInventory({ endpoint, q, onQ, emptyHint }: {
  endpoint: string
  q: string
  onQ: (v: string) => void
  emptyHint: string
}) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['location-inventory', endpoint, q],
    queryFn: async () =>
      (await api.get<LocationInventoryResponse>(endpoint, { params: { q: q || undefined } })).data,
  })

  const [openRows, setOpenRows] = useState<Record<number, boolean>>({})
  const toggle = (id: number) => setOpenRows((prev) => ({ ...prev, [id]: !prev[id] }))

  if (isLoading) return <LoadingState label="Loading stock…" />
  if (error) return <ErrorState message={apiError(error)} />

  return (
    <>
      {data?.location && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm text-brand-800">
          <span className="inline-flex items-center gap-2 font-medium">
            <MapPin className="h-4 w-4" /> {data.location.name}
            {data.location.owner && <span className="font-normal text-brand-600">· {data.location.owner.name}</span>}
          </span>
          <span>{data.items.reduce((sum, i) => sum + i.quantity, 0)} unit(s) on hand</span>
        </div>
      )}
      {data?.message && !data.location && (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800">{data.message}</div>
      )}

      <Card className="mb-4">
        <CardBody>
          <div className="relative max-w-md">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input className="pl-9" placeholder="Search by name, catalogue number, item code…" value={q} onChange={(e) => onQ(e.target.value)} />
          </div>
        </CardBody>
      </Card>

      {!data || data.items.length === 0 ? (
        <EmptyState icon={<Boxes className="h-10 w-10" />} title="No stock found" description={q ? 'Nothing matches your search at this location.' : emptyHint} />
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] border-collapse text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="w-8 px-2 py-3" />
                  <th className="px-4 py-3 font-semibold">Stock Item</th>
                  <th className="px-4 py-3 font-semibold">Cat No.</th>
                  <th className="px-4 py-3 font-semibold text-right">Quantity</th>
                  <th className="px-4 py-3 font-semibold text-right">Available</th>
                  <th className="px-4 py-3 font-semibold text-right">Pending Out</th>
                </tr>
              </thead>
              <tbody>
                {data.items.map((row: GroupedStockRow) => (
                  <StockGroupRow key={row.stock_item_id} row={row} open={!!openRows[row.stock_item_id]} onToggle={() => toggle(row.stock_item_id)} />
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </>
  )
}

function StockGroupRow({ row, open, onToggle }: { row: GroupedStockRow; open: boolean; onToggle: () => void }) {
  return (
    <>
      <tr onClick={onToggle} className="cursor-pointer border-b border-slate-100 transition-colors hover:bg-brand-50/40">
        <td className="px-2 py-3 text-slate-400">{open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}</td>
        <td className="px-4 py-3 font-medium text-slate-800">{row.name}</td>
        <td className="px-4 py-3 text-slate-600">{row.catalogue_number ?? '—'}</td>
        <td className="px-4 py-3 text-right text-lg font-bold text-slate-900">{row.quantity}</td>
        <td className="px-4 py-3 text-right text-emerald-700">{row.available}</td>
        <td className="px-4 py-3 text-right">
          {row.pending_out > 0 ? <Badge tone="amber">{row.pending_out} reserved</Badge> : <span className="text-slate-300">—</span>}
        </td>
      </tr>
      {open && (
        <tr className="border-b border-slate-100 bg-slate-50/60">
          <td />
          <td colSpan={5} className="px-4 py-3">
            <table className="w-full text-xs">
              <thead>
                <tr className="text-left uppercase tracking-wide text-slate-400">
                  <th className="py-1.5 pr-4 font-semibold">Serial</th>
                  <th className="py-1.5 pr-4 font-semibold">Lot</th>
                  <th className="py-1.5 pr-4 font-semibold">Expiry</th>
                  <th className="py-1.5 font-semibold">Status</th>
                </tr>
              </thead>
              <tbody>
                {row.units.map((u) => (
                  <tr key={u.id} className="border-t border-slate-100">
                    <td className="py-2 pr-4 font-medium text-slate-700">{u.serial_number ?? '—'}</td>
                    <td className="py-2 pr-4 text-slate-600">{u.lot_number ?? '—'}</td>
                    <td className="py-2 pr-4">
                      <span className={u.days_to_expiry != null && u.days_to_expiry <= 30 ? 'font-medium text-red-600' : 'text-slate-600'}>
                        {formatDate(u.expiry_date)}
                        {u.days_to_expiry != null && <span className="ml-1 text-slate-400">({u.days_to_expiry}d)</span>}
                      </span>
                    </td>
                    <td className="py-2"><StatusBadge status={u.status} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </td>
        </tr>
      )}
    </>
  )
}

/* ---------------------------------------------------------------------- */
/*  Find an item across locations                                          */
/* ---------------------------------------------------------------------- */

function FindItem({ q, onQ, onOpenLocation }: {
  q: string
  onQ: (v: string) => void
  onOpenLocation: (locationId: number) => void
}) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['item-search', q],
    queryFn: async () => (await api.get<{ items: ItemSearchRow[] }>('/inventory/item-search', { params: { q } })).data,
    enabled: q.trim().length >= 2,
  })

  return (
    <>
      <Card className="mb-4">
        <CardBody>
          <div className="relative max-w-md">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input className="pl-9" placeholder="Search an item, e.g. Trochar…" value={q} onChange={(e) => onQ(e.target.value)} autoFocus />
          </div>
        </CardBody>
      </Card>

      {q.trim().length < 2 ? (
        <EmptyState icon={<Search className="h-10 w-10" />} title="Search for a stock item" description="Type at least two characters to see where every unit of that item currently sits." />
      ) : isLoading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState message={apiError(error)} />
      ) : !data || data.items.length === 0 ? (
        <EmptyState icon={<Boxes className="h-10 w-10" />} title={`No stock found for “${q}”`} />
      ) : (
        <div className="space-y-3">
          {data.items.map((item) => (
            <Card key={item.stock_item_id}>
              <CardBody className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <p className="font-semibold text-slate-800">{item.name}</p>
                  <p className="text-xs text-slate-500">Cat {item.catalogue_number ?? '—'} · {item.total} unit(s) total</p>
                </div>
                <div className="flex flex-wrap gap-2">
                  {item.locations.map((l) => (
                    <button
                      key={l.location_id}
                      onClick={() => onOpenLocation(l.location_id)}
                      className="inline-flex items-center gap-1.5 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-medium text-brand-800 hover:bg-brand-100"
                    >
                      <MapPin className="h-3 w-3" /> {l.name}
                      <span className="rounded-full bg-white px-1.5 font-bold">{l.quantity}</span>
                    </button>
                  ))}
                </div>
              </CardBody>
            </Card>
          ))}
        </div>
      )}
    </>
  )
}
