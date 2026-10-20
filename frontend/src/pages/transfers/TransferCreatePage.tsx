import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  AlertTriangle, ArrowLeft, ArrowRight, Building2, Briefcase, Check, ChevronDown, ChevronRight,
  MapPin, ScanLine, Search, Send, Trash2, Warehouse,
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
import { TransferScanSheet } from '@/components/scanner/TransferScanSheet'
import { VoucherSheet } from '@/components/voucher/VoucherSheet'
import type { VoucherRow } from '@/components/voucher/VoucherSheet'
import { formatDate, formatDateTime, humanize } from '@/lib/format'
import { cn } from '@/lib/cn'
import type {
  DeviceUnit, GroupedStockRow, LocationEntity, LocationInventoryResponse, SelectedUnit,
  Transfer, TransferAdjustmentDraft,
} from '@/types'

const STEPS = ['From', 'Stock', 'To', 'Voucher', 'Sign & Request'] as const

export default function TransferCreatePage() {
  const navigate = useNavigate()
  const toast = useToast()
  const { user, hasPermission } = useAuth()

  const [step, setStep] = useState(0)
  const [fromId, setFromId] = useState<number | null>(null)
  const [toId, setToId] = useState<number | null>(null)
  // Destination typed in because the clinic isn't on the hospitals master.
  const [newDestination, setNewDestination] = useState('')
  const [selected, setSelected] = useState<Map<number, SelectedUnit>>(new Map())
  const [adjustments, setAdjustments] = useState<TransferAdjustmentDraft[]>([])
  const [signature, setSignature] = useState('')
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [scanning, setScanning] = useState(false)

  // Voucher header — the paper form's own fields.
  const [invoiceRef, setInvoiceRef] = useState('')
  const [contactPerson, setContactPerson] = useState('')
  const [address, setAddress] = useState('')
  const [addressTouched, setAddressTouched] = useState(false)
  const [contactTouched, setContactTouched] = useState(false)

  // The voucher's NAME OF RECIPIENT / SIGNATURE / DATE DELIVERED block. The
  // rep is usually standing at the counter, so dev-spec §2 takes these before
  // submission; leaving them blank keeps the hand-over for a later visit.
  const [recipientName, setRecipientName] = useState('')
  const [recipientSignature, setRecipientSignature] = useState('')
  const [deliveredAt, setDeliveredAt] = useState<string | null>(null)
  const [recipientEmail, setRecipientEmail] = useState('')
  const [copyToRep, setCopyToRep] = useState(true)

  /** DATE DELIVERED stamps itself on signature, and clears if they re-sign. */
  const captureRecipientSignature = (dataUrl: string) => {
    setRecipientSignature(dataUrl)
    setDeliveredAt(dataUrl ? new Date().toISOString() : null)
  }

  const { data: locations, isLoading: locationsLoading } = useQuery({
    queryKey: ['locations'],
    queryFn: async () => (await api.get<{ data: LocationEntity[] }>('/locations')).data.data,
  })

  const from = locations?.find((l) => l.id === fromId) ?? null
  const to = locations?.find((l) => l.id === toId) ?? null
  const destinationName = to?.name ?? newDestination.trim()

  // Source inventory, loaded once for the picker and reused by the scanner so
  // a scan can be matched without a round trip per label.
  const { data: sourceInventory } = useQuery({
    queryKey: ['location-inventory', fromId, ''],
    queryFn: async () =>
      (await api.get<LocationInventoryResponse>(`/locations/${fromId}/inventory`)).data,
    enabled: fromId !== null,
  })

  // The /locations list is lean (id + name only for the hospital), so the
  // voucher header is prefilled from the destination's own record.
  const { data: destination } = useQuery({
    queryKey: ['locations', toId],
    queryFn: async () => (await api.get<{ data: LocationEntity }>(`/locations/${toId}`)).data.data,
    enabled: toId !== null,
  })

  /**
   * dev-spec §2 step 1: the number is on the form while the rep fills it in,
   * as it is pre-printed on the pad. Advisory only — the binding number is
   * allocated under lock on submit, so this is labelled as provisional.
   */
  const { data: nextVoucher } = useQuery({
    queryKey: ['transfers', 'next-voucher'],
    queryFn: async () =>
      (await api.get<{ voucher_number: string; date: string }>('/transfers/next-voucher')).data,
    staleTime: 60_000,
  })

  useEffect(() => {
    if (!destination?.hospital) return
    if (!addressTouched) setAddress(destination.hospital.address ?? '')
    if (!contactTouched) {
      const contacts = destination.hospital.contacts ?? []
      const contact = contacts.find((c) => c.is_primary)
        ?? contacts.find((c) => (c.role ?? '').toLowerCase().includes('stock'))
        ?? contacts[0]
      setContactPerson(contact?.name ?? '')
    }
  }, [destination, addressTouched, contactTouched])

  const toggleUnit = (unit: DeviceUnit, itemName: string, itemCode: string) => {
    setSelected((prev) => {
      const next = new Map(prev)
      if (next.has(unit.id)) next.delete(unit.id)
      else next.set(unit.id, { ...unit, itemName, itemCode })
      return next
    })
  }

  /** The scanner only ever adds — a scanned item is in the box. */
  const selectUnit = (unit: DeviceUnit, itemName: string, itemCode: string) => {
    setSelected((prev) => {
      if (prev.has(unit.id)) return prev
      const next = new Map(prev)
      next.set(unit.id, { ...unit, itemName, itemCode })
      return next
    })
  }

  const selectFrom = (id: number) => {
    if (id !== fromId) {
      // Changing source invalidates both the picks and the scanned exceptions.
      setSelected(new Map())
      setAdjustments([])
    }
    setFromId(id)
    if (toId === id) setToId(null)
    setStep(1)
  }

  const selectTo = (id: number) => {
    setToId(id)
    setNewDestination('')
    setStep(3)
  }

  /**
   * A hand-over to a hospital, clinic or doctor — as opposed to an internal
   * move between our own warehouses and boots. Only a delivery has an address
   * to give and a recipient to sign, so only a delivery enforces those.
   */
  const isDelivery = to ? to.type === 'hospital' : newDestination.trim() !== ''

  const canNext = [
    fromId !== null,
    selected.size > 0 || adjustments.length > 0,
    toId !== null || newDestination.trim() !== '',
    // dev-spec §4 makes Address a required field on a delivery voucher.
    !isDelivery || address.trim() !== '',
    signature !== '',
  ][step]

  /** The picked units and scanned exceptions as the voucher's ruled lines. */
  const voucherRows = useMemo<VoucherRow[]>(() => [
    ...[...selected.values()].map((u) => ({
      key: `u-${u.id}`,
      code: u.itemCode,
      description: u.itemName,
      lot: u.lot_number,
      qty: 1,
    })),
    ...adjustments.map((a) => ({
      key: a.key,
      code: a.ref_code,
      description: a.description ?? a.ref_code,
      lot: a.lot_number,
      // One scan, one unit — the draft carries no count, and the server
      // defaults a scanned adjustment to 1.
      qty: 1,
      flagged: true,
      flagLabel: humanize(a.adjustment_type),
    })),
  ], [selected, adjustments])

  const submit = async () => {
    const typed = newDestination.trim()
    if (!fromId || (!toId && !typed) || (selected.size === 0 && adjustments.length === 0) || !signature) return
    setSubmitting(true)

    const payload = {
      from_location_id: fromId,
      to_location_id: toId,
      // Only one of the two travels: a known location, or a typed name the
      // server turns into an ad-hoc destination.
      new_destination: toId ? null : { name: typed },
      unit_ids: [...selected.keys()],
      signature,
      notes: notes || null,
      invoice_reference: invoiceRef || null,
      contact_person_name: contactPerson || null,
      recipient_name: recipientName || null,
      recipient_signature: recipientSignature || null,
      recipient_email: recipientSignature ? (recipientEmail || null) : null,
      copy_to_rep: recipientSignature ? copyToRep : null,
      delivery_address: address || null,
      // Local keys are for the UI only; the server assigns its own line ids.
      scanned_adjustments: adjustments.map(({ key: _key, ...row }) => row),
    }

    try {
      if (!navigator.onLine) {
        await enqueue('transfer.request', { ...payload, signer_name: user?.name }, `Transfer ${from?.name} → ${destinationName}`)
        toast.info('Saved offline — the request will sync when you reconnect.')
        navigate('/transfers')
        return
      }
      const { data } = await api.post<{ data: Transfer }>('/transfers', payload)
      toast.success(
        `Voucher ${data.data.voucher_number ?? data.data.reference} created — sent to the Approval Centre.`,
      )
      navigate(`/transfers/${data.data.id}`)
    } catch (err) {
      toast.error(apiError(err))
    } finally {
      setSubmitting(false)
    }
  }

  const canScan = hasPermission('transfer.create')

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
                'flex items-center gap-2 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold',
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
        <>
          {canScan && (
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3">
              <div className="text-sm">
                <p className="font-semibold text-brand-900">Scan the box</p>
                <p className="text-brand-800">
                  Read each label instead of ticking boxes. Anything not on the dispatch
                  list is flagged rather than blocked.
                </p>
              </div>
              <Button onClick={() => setScanning(true)}>
                <ScanLine className="h-4 w-4" /> Scan items
              </Button>
            </div>
          )}

          {adjustments.length > 0 && <AdjustmentList rows={adjustments} onRemove={removeAdjustment} />}

          <StockPicker fromId={fromId} fromName={from?.name ?? ''} selected={selected} onToggle={toggleUnit} />
        </>
      )}

      {step === 2 && (
        <>
          <LocationGrid
            locations={(locations ?? []).filter((l) => l.id !== fromId)}
            selectedId={toId}
            onSelect={selectTo}
            title="Where is it going?"
          />

          <Card className="mt-4">
            <CardBody>
              <h4 className="font-semibold text-slate-800">Not listed? Enter details</h4>
              <p className="mb-3 text-sm text-slate-500">
                For a clinic or doctor that isn&apos;t on the hospitals list yet. The
                delivery still needs the recipient&apos;s signature, and an admin can
                link it to a proper hospital record afterwards.
              </p>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                <div className="flex-1">
                  <Field label="Destination name">
                    <Input
                      value={newDestination}
                      onChange={(e) => { setNewDestination(e.target.value); setToId(null) }}
                      placeholder="Kempton Day Clinic"
                    />
                  </Field>
                </div>
                <Button
                  variant="outline"
                  disabled={newDestination.trim() === ''}
                  onClick={() => setStep(3)}
                >
                  Use this destination <ArrowRight className="h-4 w-4" />
                </Button>
              </div>
              <p className="mt-2 text-xs text-slate-400">
                You&apos;ll enter the address and contact person on the next step.
              </p>
            </CardBody>
          </Card>
        </>
      )}

      {step === 3 && (
        <>
          {!to && newDestination.trim() !== '' && (
            <div className="mb-4 flex items-start gap-2 rounded-lg border-l-4 border-amber-400 bg-amber-50 px-3 py-2 text-sm">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
              <span className="text-amber-900">
                <strong>{newDestination.trim()}</strong> isn&apos;t on the hospitals
                list, so there&apos;s nothing to prefill — please enter the address
                and contact person yourself.
              </span>
            </div>
          )}

          {/* The pad, on screen. Reps fill the same boxes in the same places. */}
          <VoucherSheet
            editable
            voucherNumber={nextVoucher?.voucher_number}
            provisional
            date={nextVoucher?.date ?? new Date().toISOString().slice(0, 10)}
            rows={voucherRows}
            required={isDelivery ? ['deliverTo', 'address'] : []}
            lockedFields={['deliverTo']}
            // Every pad has these rows, whatever the destination — stock
            // handed to another rep's boot still has someone receiving it.
            // Whether a signature is *required* is a separate question, and
            // one the approval gate answers, not this form.
            recipientEditable
            recipientName={recipientName}
            onRecipientNameChange={setRecipientName}
            dateDelivered={deliveredAt ? formatDateTime(deliveredAt) : null}
            signatureSlot={(
              <div className="w-full py-1">
                <SignaturePad
                  variant="bare"
                  height={52}
                  // Stepping forward and back rebuilds the canvas; the form
                  // still holds the signature, so hand it back.
                  value={recipientSignature}
                  onChange={captureRecipientSignature}
                  disabled={!recipientName.trim()}
                  disabledReason="Enter the recipient's name first"
                />
              </div>
            )}
            fields={{
              // The pads write the whole movement on this line for an internal
              // transfer, and name the hospital directly for a delivery. The
              // PDF does the same, so the screen must not disagree with it.
              deliverTo: isDelivery ? destinationName : `From ${from?.name ?? ''} to ${destinationName}`,
              address,
              contactPerson,
              invoiceRef,
            }}
            onFieldChange={(patch) => {
              if (patch.address !== undefined) { setAddressTouched(true); setAddress(patch.address) }
              if (patch.contactPerson !== undefined) { setContactTouched(true); setContactPerson(patch.contactPerson) }
              if (patch.invoiceRef !== undefined) setInvoiceRef(patch.invoiceRef)
            }}
          />

          <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p className="text-xs text-slate-500">
              Deliver To comes from the destination you picked.{' '}
              <button
                type="button"
                className="font-semibold text-brand-700 underline underline-offset-2"
                onClick={() => setStep(2)}
              >
                Change destination
              </button>
            </p>
            {isDelivery && address.trim() === '' && (
              <p className="text-xs font-semibold text-[#c1121f]">
                An address is required before this voucher can be signed.
              </p>
            )}
          </div>

          {/* dev-spec §5: asked while the recipient is still standing there,
              which is the only moment anyone can give their email. */}
          {recipientSignature !== '' && (
            <Card className="mt-4">
              <CardBody>
                <p className="mb-2 text-sm font-medium text-slate-700">Send a copy of the voucher</p>
                <Field
                  label="Recipient's email"
                  hint="Optional — they receive the signed PDF once it's approved."
                >
                  <Input
                    type="email"
                    value={recipientEmail}
                    onChange={(e) => setRecipientEmail(e.target.value)}
                    placeholder="theatre@hospital.co.za"
                  />
                </Field>
                <label className="mt-3 flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
                    checked={copyToRep}
                    onChange={(e) => setCopyToRep(e.target.checked)}
                  />
                  Send a copy to me ({user?.name})
                </label>
                <p className="mt-2 text-xs text-slate-400">
                  A copy always goes to the transfers mailbox for the records.
                </p>
              </CardBody>
            </Card>
          )}

          {/* Not on the paper form — internal context for the approver. */}
          <Card className="mt-4">
            <CardBody>
              <Field label="Notes" hint="Internal only — these do not print on the voucher.">
                <Textarea
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="Optional context for the approver…"
                />
              </Field>
            </CardBody>
          </Card>
        </>
      )}

      {step === 4 && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardBody>
              <h3 className="mb-3 font-semibold text-slate-800">Voucher summary</h3>
              <div className="mb-4 flex items-center gap-2 text-sm">
                <Badge tone="teal">{from?.name}</Badge>
                <ArrowRight className="h-4 w-4 text-slate-400" />
                <Badge tone={to ? 'blue' : 'amber'}>{destinationName}</Badge>
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
                    {adjustments.map((a) => (
                      <tr key={a.key} className="border-t border-orange-200 bg-orange-50">
                        <td className="px-3 py-2 font-medium text-orange-900">
                          {a.description ?? a.ref_code}
                          <span className="ml-1.5 text-[10px] font-semibold uppercase text-orange-700">
                            {humanize(a.adjustment_type)}
                          </span>
                        </td>
                        <td className="px-3 py-2 text-orange-800">—</td>
                        <td className="px-3 py-2 text-orange-900">
                          {a.expected_lot_number && (
                            <span className="mr-1 line-through opacity-70">{a.expected_lot_number}</span>
                          )}
                          {a.lot_number ?? '—'}
                        </td>
                        <td className="px-3 py-2 text-orange-800">{formatDate(a.expiry_date)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="mb-3 mt-2 text-right text-sm font-semibold text-slate-700">
                {selected.size} device(s)
                {adjustments.length > 0 && (
                  <span className="text-orange-700"> · {adjustments.length} flagged</span>
                )}
              </p>

              {adjustments.length > 0 && (
                <div className="flex items-start gap-2 rounded-lg border-l-4 border-orange-400 bg-orange-50 px-3 py-2 text-xs text-orange-900">
                  <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-orange-600" />
                  <span>
                    Flagged lines will alert admin operations on submit. They carry no
                    reserved device, so approval moves no stock for them.
                  </span>
                </div>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardBody>
              <h3 className="mb-1 font-semibold text-slate-800">Your signature</h3>
              <p className="mb-2 text-xs text-slate-500">
                Yours as the rep dispatching the stock — separate from the
                recipient's on the voucher itself.
              </p>
              <p className="mb-3 text-xs text-slate-500">
                Signed by <span className="font-medium text-slate-700">{user?.name}</span> · {formatDateTime(new Date().toISOString())} (captured automatically)
              </p>
              <SignaturePad onChange={setSignature} />
              <Button className="mt-4 w-full" size="lg" disabled={!signature} loading={submitting} onClick={() => void submit()}>
                <Send className="h-4 w-4" /> Request Transfer
              </Button>
              {recipientSignature !== '' ? (
                <p className="mt-2 text-center text-xs text-emerald-700">
                  {recipientName} has signed for this. It goes straight to the
                  Approval Centre.
                </p>
              ) : isDelivery ? (
                // A hospital delivery is the one the approval gate blocks.
                <p className="mt-2 text-center text-xs text-amber-700">
                  Nobody has signed for this delivery yet. Take the signature on
                  the voucher step now, or on the voucher page when you hand the
                  stock over — it cannot be approved until someone does.
                </p>
              ) : (
                <p className="mt-2 text-center text-xs text-slate-400">
                  The request goes to the Approval Centre. Stock only moves once
                  it's approved.
                </p>
              )}
            </CardBody>
          </Card>
        </div>
      )}

      {/* Prev/Next */}
      <div className="mt-6 flex items-center justify-between">
        <Button variant="outline" disabled={step === 0} onClick={() => setStep((s) => s - 1)}>
          <ArrowLeft className="h-4 w-4" /> Back
        </Button>
        {step < STEPS.length - 1 && (
          <Button disabled={!canNext} onClick={() => setStep((s) => s + 1)}>
            Next <ArrowRight className="h-4 w-4" />
          </Button>
        )}
      </div>

      {scanning && fromId && (
        <TransferScanSheet
          sourceName={from?.name ?? 'the source'}
          inventory={sourceInventory?.items ?? []}
          selected={selected}
          adjustments={adjustments}
          onSelectUnit={selectUnit}
          onAddAdjustment={(row) => setAdjustments((prev) => [...prev, row])}
          onRemoveAdjustment={removeAdjustment}
          onClose={() => setScanning(false)}
        />
      )}
    </>
  )

  function removeAdjustment(key: string) {
    setAdjustments((prev) => prev.filter((a) => a.key !== key))
  }
}

/* ---------------------------------------------------------------------- */
/*  Scanned exceptions, shown on the picker step                           */
/* ---------------------------------------------------------------------- */

function AdjustmentList({ rows, onRemove }: {
  rows: TransferAdjustmentDraft[]
  onRemove: (key: string) => void
}) {
  return (
    <Card className="mb-4">
      <CardBody className="p-0">
        <div className="flex items-center gap-2 border-b border-orange-200 bg-orange-50 px-4 py-2.5">
          <AlertTriangle className="h-4 w-4 text-orange-600" />
          <span className="text-sm font-semibold text-orange-900">
            {rows.length} flagged line{rows.length === 1 ? '' : 's'}
          </span>
          <span className="text-xs text-orange-800">
            Scanned, but not on this location's dispatch list
          </span>
        </div>
        <ul className="divide-y divide-orange-100">
          {rows.map((a) => (
            <li key={a.key} className="flex items-center justify-between gap-3 border-l-4 border-orange-400 bg-orange-50/60 px-4 py-2.5">
              <div className="min-w-0 text-sm">
                <p className="truncate font-medium text-orange-900">
                  {a.ref_code}
                  {a.description && <span className="ml-1.5 font-normal">{a.description}</span>}
                </p>
                <p className="truncate text-xs text-orange-800">
                  {humanize(a.adjustment_type)}
                  {a.lot_number && <> · Lot {a.lot_number}</>}
                  {a.expected_lot_number && <> · expected <span className="line-through">{a.expected_lot_number}</span></>}
                </p>
              </div>
              <button
                aria-label={`Remove flagged line ${a.ref_code}`}
                onClick={() => onRemove(a.key)}
                className="shrink-0 rounded-md p-1.5 text-orange-700 hover:bg-orange-100"
              >
                <Trash2 className="h-4 w-4" />
              </button>
            </li>
          ))}
        </ul>
      </CardBody>
    </Card>
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
  selected: Map<number, SelectedUnit>
  onToggle: (unit: DeviceUnit, itemName: string, itemCode: string) => void
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
      <div className="mb-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-2.5 text-sm font-medium text-brand-800">
        Stock at: {fromName}
      </div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="relative w-full max-w-md">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <Input className="pl-9" placeholder="Search name, catalogue number, REF, lot number…" value={q} onChange={(e) => setQ(e.target.value)} />
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
                    <span className="text-xs text-slate-400">
                      Cat No {row.catalogue_number ?? '—'} · REF {row.item_code ?? '—'}
                    </span>
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
                            onChange={() => onToggle(u, row.name, row.catalogue_number ?? row.item_code ?? '')}
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
