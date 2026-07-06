import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  ArrowLeft, ArrowRight, Building2, Briefcase, Check, ChevronDown, ChevronRight,
  MapPin, Search, Send, Warehouse,
} from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useToast } from '@/components/ToastProvider'
import { enqueue } from '@/offline/syncQueue'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Textarea } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { SignaturePad } from '@/components/SignaturePad'
import { formatDate, formatDateTime, humanize } from '@/lib/format'
import { cn } from '@/lib/cn'
import type { DeviceUnit, GroupedStockRow, LocationEntity, LocationInventoryResponse, Transfer } from '@/types'

const STEPS = ['From', 'Stock', 'To', 'Sign & Request'] as const

export default function TransferCreatePage() {
  const navigate = useNavigate()
  const toast = useToast()
  const { user } = useAuth()

  const [step, setStep] = useState(0)
  const [fromId, setFromId] = useState<number | null>(null)
  const [toId, setToId] = useState<number | null>(null)
  const [selected, setSelected] = useState<Map<number, DeviceUnit & { itemName: string }>>(new Map())
  const [signature, setSignature] = useState('')
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const { data: locations, isLoading: locationsLoading } = useQuery({
    queryKey: ['locations'],
    queryFn: async () => (await api.get<{ data: LocationEntity[] }>('/locations')).data.data,
  })

  const from = locations?.find((l) => l.id === fromId) ?? null
  const to = locations?.find((l) => l.id === toId) ?? null

  const toggleUnit = (unit: DeviceUnit, itemName: string) => {
    setSelected((prev) => {
      const next = new Map(prev)
      if (next.has(unit.id)) next.delete(unit.id)
      else next.set(unit.id, { ...unit, itemName })
      return next
    })
  }

  const selectFrom = (id: number) => {
    if (id !== fromId) setSelected(new Map()) // changing source clears the picks
    setFromId(id)
    if (toId === id) setToId(null)
    setStep(1)
  }

  const canNext = [
    fromId !== null,
    selected.size > 0,
    toId !== null,
    signature !== '',
  ][step]

  const submit = async () => {
    if (!fromId || !toId || selected.size === 0 || !signature) return
    setSubmitting(true)

    const payload = {
      from_location_id: fromId,
      to_location_id: toId,
      unit_ids: [...selected.keys()],
      signature,
      notes: notes || null,
    }

    try {
      if (!navigator.onLine) {
        await enqueue('transfer.request', { ...payload, signer_name: user?.name }, `Transfer ${from?.name} → ${to?.name}`)
        toast.info('Saved offline — the request will sync when you reconnect.')
        navigate('/transfers')
        return
      }
      const { data } = await api.post<{ data: Transfer }>('/transfers', payload)
      toast.success(`Transfer ${data.data.reference} requested — sent to the Approval Centre.`)
      navigate(`/transfers/${data.data.id}`)
    } catch (err) {
      toast.error(apiError(err))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <>
      <PageHeader title="New Transfer" description="Move devices between hospitals, boots and the office." />

      {/* Stepper */}
      <div className="mb-6 flex items-center gap-2 overflow-x-auto">
        {STEPS.map((label, i) => (
          <div key={label} className="flex items-center gap-2">
            <button
              onClick={() => i < step && setStep(i)}
              className={cn(
                'flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold',
                i === step ? 'bg-brand-700 text-white'
                  : i < step ? 'bg-brand-100 text-brand-800'
                  : 'bg-slate-100 text-slate-400',
              )}
            >
              <span className={cn('flex h-5 w-5 items-center justify-center rounded-full text-[10px]',
                i < step ? 'bg-brand-600 text-white' : i === step ? 'bg-white/20' : 'bg-slate-200')}>
                {i < step ? <Check className="h-3 w-3" /> : i + 1}
              </span>
              {label}
            </button>
            {i < STEPS.length - 1 && <ChevronRight className="h-4 w-4 shrink-0 text-slate-300" />}
          </div>
        ))}
      </div>

      {step === 0 && (
        locationsLoading ? <LoadingState label="Loading locations…" /> : (
          <LocationGrid locations={locations ?? []} selectedId={fromId} onSelect={selectFrom} title="Where is the stock now?" />
        )
      )}

      {step === 1 && fromId && (
        <StockPicker fromId={fromId} fromName={from?.name ?? ''} selected={selected} onToggle={toggleUnit} />
      )}

      {step === 2 && (
        <LocationGrid
          locations={(locations ?? []).filter((l) => l.id !== fromId)}
          selectedId={toId}
          onSelect={(id) => { setToId(id); setStep(3) }}
          title="Where is it going?"
        />
      )}

      {step === 3 && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardBody>
              <h3 className="mb-3 font-semibold text-slate-800">Transfer summary</h3>
              <div className="mb-4 flex items-center gap-2 text-sm">
                <Badge tone="teal">{from?.name}</Badge>
                <ArrowRight className="h-4 w-4 text-slate-400" />
                <Badge tone="blue">{to?.name}</Badge>
              </div>
              <div className="overflow-x-auto rounded-lg border border-slate-100">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="bg-slate-50 text-left uppercase tracking-wide text-slate-400">
                      <th className="px-3 py-2 font-semibold">Item</th>
                      <th className="px-3 py-2 font-semibold">Serial</th>
                      <th className="px-3 py-2 font-semibold">Lot</th>
                      <th className="px-3 py-2 font-semibold">Expiry</th>
                    </tr>
                  </thead>
                  <tbody>
                    {[...selected.values()].map((u) => (
                      <tr key={u.id} className="border-t border-slate-100">
                        <td className="px-3 py-2 font-medium text-slate-700">{u.itemName}</td>
                        <td className="px-3 py-2">{u.serial_number ?? '—'}</td>
                        <td className="px-3 py-2">{u.lot_number ?? '—'}</td>
                        <td className="px-3 py-2">{formatDate(u.expiry_date)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="mb-3 mt-2 text-right text-sm font-semibold text-slate-700">{selected.size} device(s)</p>
              <Field label="Notes">
                <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional context for the approver…" />
              </Field>
            </CardBody>
          </Card>

          <Card>
            <CardBody>
              <h3 className="mb-1 font-semibold text-slate-800">Digital signature</h3>
              <p className="mb-3 text-xs text-slate-500">
                Signed by <span className="font-medium text-slate-700">{user?.name}</span> · {formatDateTime(new Date().toISOString())} (captured automatically)
              </p>
              <SignaturePad onChange={setSignature} />
              <Button className="mt-4 w-full" size="lg" disabled={!signature} loading={submitting} onClick={() => void submit()}>
                <Send className="h-4 w-4" /> Request Transfer
              </Button>
              <p className="mt-2 text-center text-xs text-slate-400">
                The request goes to the Approval Centre. Stock only moves once it's approved.
              </p>
            </CardBody>
          </Card>
        </div>
      )}

      {/* Prev/Next */}
      <div className="mt-6 flex items-center justify-between">
        <Button variant="outline" disabled={step === 0} onClick={() => setStep((s) => s - 1)}>
          <ArrowLeft className="h-4 w-4" /> Back
        </Button>
        {step < 3 && (
          <Button disabled={!canNext} onClick={() => setStep((s) => s + 1)}>
            Next <ArrowRight className="h-4 w-4" />
          </Button>
        )}
      </div>
    </>
  )
}

/* ---------------------------------------------------------------------- */
/*  Steps 1 & 3 — location cards                                           */
/* ---------------------------------------------------------------------- */

const TYPE_ICON = { hospital: Building2, boot: Briefcase, office: Warehouse, warehouse: Warehouse, other: MapPin }

function LocationGrid({ locations, selectedId, onSelect, title }: {
  locations: LocationEntity[]
  selectedId: number | null
  onSelect: (id: number) => void
  title: string
}) {
  return (
    <>
      <h3 className="mb-3 font-semibold text-slate-800">{title}</h3>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {locations.map((l) => {
          const Icon = TYPE_ICON[l.type] ?? MapPin
          const active = selectedId === l.id
          return (
            <button
              key={l.id}
              onClick={() => onSelect(l.id)}
              className={cn(
                'flex items-center gap-3 rounded-xl border bg-white p-4 text-left shadow-sm transition-all',
                active ? 'border-brand-500 ring-2 ring-brand-200' : 'border-slate-200 hover:border-brand-300 hover:shadow',
              )}
            >
              <span className={cn('flex h-10 w-10 items-center justify-center rounded-lg',
                active ? 'bg-brand-600 text-white' : 'bg-brand-50 text-brand-700')}>
                <Icon className="h-5 w-5" />
              </span>
              <span>
                <span className="block text-sm font-semibold text-slate-800">{l.name}</span>
                <span className="block text-xs text-slate-500">
                  {humanize(l.type)}{l.owner ? ` · ${l.owner.name}` : ''} · {l.units_count ?? 0} unit(s)
                </span>
              </span>
            </button>
          )
        })}
      </div>
    </>
  )
}

/* ---------------------------------------------------------------------- */
/*  Step 2 — stock at the source, grouped, expandable, selectable          */
/* ---------------------------------------------------------------------- */

function StockPicker({ fromId, fromName, selected, onToggle }: {
  fromId: number
  fromName: string
  selected: Map<number, DeviceUnit & { itemName: string }>
  onToggle: (unit: DeviceUnit, itemName: string) => void
}) {
  const [q, setQ] = useState('')
  const [open, setOpen] = useState<Record<number, boolean>>({})

  const { data, isLoading, error } = useQuery({
    queryKey: ['location-inventory', fromId, q],
    queryFn: async () =>
      (await api.get<LocationInventoryResponse>(`/locations/${fromId}/inventory`, { params: { q: q || undefined } })).data,
  })

  const rows = useMemo(() => data?.items ?? [], [data])

  if (isLoading) return <LoadingState label={`Loading stock at ${fromName}…`} />
  if (error) return <ErrorState message={apiError(error)} />

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="relative w-full max-w-md">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <Input className="pl-9" placeholder="Search stock name, catalogue number, item code…" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <Badge tone={selected.size ? 'teal' : 'gray'}>{selected.size} device(s) selected</Badge>
      </div>

      {rows.length === 0 ? (
        <EmptyState title={`No stock at ${fromName}`} description={q ? 'Nothing matches your search.' : 'This location holds no available devices.'} />
      ) : (
        <div className="space-y-2">
          {rows.map((row: GroupedStockRow) => {
            const isOpen = !!open[row.stock_item_id]
            const pickedInRow = row.units.filter((u) => selected.has(u.id)).length
            return (
              <Card key={row.stock_item_id}>
                <button
                  onClick={() => setOpen((p) => ({ ...p, [row.stock_item_id]: !isOpen }))}
                  className="flex w-full items-center justify-between px-4 py-3 text-left"
                >
                  <span className="flex items-center gap-2">
                    {isOpen ? <ChevronDown className="h-4 w-4 text-slate-400" /> : <ChevronRight className="h-4 w-4 text-slate-400" />}
                    <span className="font-medium text-slate-800">{row.name}</span>
                    <span className="text-xs text-slate-400">Cat {row.catalogue_number ?? '—'}</span>
                  </span>
                  <span className="flex items-center gap-2 text-sm">
                    {pickedInRow > 0 && <Badge tone="teal">{pickedInRow} selected</Badge>}
                    {row.pending_out > 0 && <Badge tone="amber">{row.pending_out} reserved</Badge>}
                    <span className="font-bold text-slate-900">{row.quantity}</span>
                  </span>
                </button>

                {isOpen && (
                  <div className="border-t border-slate-100 px-4 py-2">
                    {row.units.map((u) => {
                      const selectable = u.status === 'available'
                      const checked = selected.has(u.id)
                      return (
                        <label
                          key={u.id}
                          className={cn(
                            'flex items-center gap-3 rounded-lg px-2 py-2 text-sm',
                            selectable ? 'cursor-pointer hover:bg-brand-50/50' : 'opacity-50',
                          )}
                        >
                          <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
                            disabled={!selectable}
                            checked={checked}
                            onChange={() => onToggle(u, row.name)}
                          />
                          <span className="w-28 font-medium text-slate-700">{u.serial_number ?? '—'}</span>
                          <span className="w-28 text-slate-500">Lot {u.lot_number ?? '—'}</span>
                          <span className={cn('w-36', u.days_to_expiry != null && u.days_to_expiry <= 30 ? 'font-medium text-red-600' : 'text-slate-500')}>
                            Exp {formatDate(u.expiry_date)}
                          </span>
                          {!selectable && <Badge tone="amber">{humanize(u.status)}</Badge>}
                        </label>
                      )
                    })}
                  </div>
                )}
              </Card>
            )
          })}
        </div>
      )}
    </>
  )
}
