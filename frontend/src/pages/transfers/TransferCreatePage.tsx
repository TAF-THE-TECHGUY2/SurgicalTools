import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, Trash2 } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useMeta } from '@/hooks/useMeta'
import { useToast } from '@/components/ToastProvider'
import { enqueue } from '@/offline/syncQueue'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/Field'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import type { Doctor, Hospital, InventoryItem, Paginated, Transfer, TransferType, User } from '@/types'

interface LineItem {
  key: string
  inventory_item_id?: number
  ref_code: string
  description: string
  lot_number: string
  quantity: number
}

const DEFAULT_LOCATION = 'jhb_master_warehouse'

export default function TransferCreatePage() {
  const navigate = useNavigate()
  const toast = useToast()
  const { user } = useAuth()
  const { data: meta } = useMeta()

  const [transferType, setTransferType] = useState<TransferType>('source_to_boot')
  const [items, setItems] = useState<LineItem[]>([])
  const [search, setSearch] = useState('')
  const [notes, setNotes] = useState('')

  // source_to_boot fields
  const [toHolderId, setToHolderId] = useState('')
  const [fromLocation, setFromLocation] = useState(DEFAULT_LOCATION)

  // boot_to_hospital fields
  const [hospitalId, setHospitalId] = useState('')
  const [hospitalStockType, setHospitalStockType] = useState('')
  const [doctorId, setDoctorId] = useState('')

  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState<'draft' | 'submit' | null>(null)

  // ---- Inventory search ----
  const { data: inventory, isFetching: searching } = useQuery({
    queryKey: ['inventory', 'search', search],
    queryFn: async () =>
      (await api.get<Paginated<InventoryItem>>('/inventory', { params: { q: search, status: 'available' } })).data,
    enabled: search.trim().length > 0,
  })

  // ---- Receiving reps (users) for source_to_boot, with fallback ----
  const { data: users } = useQuery({
    queryKey: ['users', 'transfer-rep'],
    queryFn: async () => {
      try {
        return (await api.get<Paginated<User>>('/users')).data.data
      } catch {
        return user ? [user] : []
      }
    },
    enabled: transferType === 'source_to_boot',
  })

  // ---- Hospitals for boot_to_hospital ----
  const { data: hospitals } = useQuery({
    queryKey: ['hospitals', 'assigned'],
    queryFn: async () => (await api.get<Paginated<Hospital>>('/hospitals', { params: { assigned_to_me: 1 } })).data.data,
    enabled: transferType === 'boot_to_hospital',
  })

  // ---- Doctors for the chosen hospital ----
  const { data: doctors } = useQuery({
    queryKey: ['doctors', 'hospital', hospitalId],
    queryFn: async () => (await api.get<Paginated<Doctor>>('/doctors', { params: { hospital_id: hospitalId } })).data.data,
    enabled: transferType === 'boot_to_hospital' && hospitalId !== '',
  })

  const addItem = (inv: InventoryItem) => {
    setItems((prev) => {
      if (prev.some((p) => p.inventory_item_id === inv.id)) return prev
      return [
        ...prev,
        {
          key: `${inv.id}`,
          inventory_item_id: inv.id,
          ref_code: inv.ref_code,
          description: inv.description,
          lot_number: inv.lot_number ?? '',
          quantity: 1,
        },
      ]
    })
  }

  const removeItem = (key: string) => setItems((prev) => prev.filter((p) => p.key !== key))

  const updateQuantity = (key: string, quantity: number) =>
    setItems((prev) => prev.map((p) => (p.key === key ? { ...p, quantity } : p)))

  const itemColumns: Column<LineItem>[] = [
    { key: 'ref_code', header: 'Ref', render: (r) => <span className="font-medium text-slate-800">{r.ref_code}</span> },
    { key: 'description', header: 'Description', render: (r) => r.description },
    { key: 'lot_number', header: 'Lot', render: (r) => r.lot_number || '—' },
    {
      key: 'quantity',
      header: 'Qty',
      className: 'w-28',
      render: (r) => (
        <Input
          type="number"
          min={1}
          value={r.quantity}
          onChange={(e) => updateQuantity(r.key, Number(e.target.value))}
          className="w-20 px-2 py-1"
        />
      ),
    },
    {
      key: 'remove',
      header: '',
      className: 'w-12',
      render: (r) => (
        <Button type="button" variant="ghost" size="sm" onClick={() => removeItem(r.key)}>
          <Trash2 className="h-4 w-4 text-red-500" />
        </Button>
      ),
    },
  ]

  const validate = (): boolean => {
    const next: Record<string, string> = {}
    if (items.length === 0) next.items = 'Add at least one item.'
    if (items.some((i) => !i.quantity || i.quantity < 1)) next.items = 'Every item needs a quantity of at least 1.'
    if (transferType === 'source_to_boot') {
      if (!toHolderId) next.to_holder = 'Select a receiving rep.'
      if (!fromLocation) next.from_location = 'Select a source location.'
    } else {
      if (!hospitalId) next.hospital = 'Select a hospital.'
      if (!hospitalStockType) next.hospital_stock_type = 'Select a stock type.'
    }
    setErrors(next)
    return Object.keys(next).length === 0
  }

  const payload = useMemo(
    () => ({
      items: items.map((i) => ({
        inventory_item_id: i.inventory_item_id,
        ref_code: i.ref_code,
        lot_number: i.lot_number || null,
        quantity: i.quantity,
      })),
      notes: notes || null,
      ...(transferType === 'source_to_boot'
        ? { to_holder_user_id: toHolderId ? Number(toHolderId) : null, from_location: fromLocation }
        : {
            hospital_id: hospitalId ? Number(hospitalId) : null,
            hospital_stock_type: hospitalStockType,
            doctor_id: doctorId ? Number(doctorId) : null,
          }),
    }),
    [items, notes, transferType, toHolderId, fromLocation, hospitalId, hospitalStockType, doctorId],
  )

  const mutation = useMutation({
    mutationFn: async (submit: boolean) => {
      const body = { ...payload, submit }
      const endpoint = transferType === 'source_to_boot' ? '/transfers/source-to-boot' : '/transfers/boot-to-hospital'
      return (await api.post<Transfer>(endpoint, body)).data
    },
    onSuccess: (transfer) => {
      toast.success('Transfer saved.')
      navigate(`/transfers/${transfer.id}`)
    },
    onError: (err) => toast.error(apiError(err)),
    onSettled: () => setSubmitting(null),
  })

  const save = async (submit: boolean) => {
    if (!validate()) return
    setSubmitting(submit ? 'submit' : 'draft')

    if (!navigator.onLine) {
      const opType = transferType === 'source_to_boot' ? 'transfer.source_to_boot' : 'transfer.boot_to_hospital'
      const label =
        transferType === 'source_to_boot' ? 'Transfer 1 — Source → Boot' : 'Transfer 2 — Boot → Hospital'
      await enqueue(opType, { ...payload, submit }, label)
      toast.info('Saved offline — will sync when online')
      setSubmitting(null)
      navigate('/transfers')
      return
    }

    mutation.mutate(submit)
  }

  return (
    <>
      <PageHeader title="New transfer" description="Create a stock transfer and submit it for approval." />

      <div className="grid gap-6">
        <Card>
          <CardHeader title="Transfer type" />
          <CardBody>
            <div className="grid gap-3 sm:grid-cols-2">
              <TypeOption
                active={transferType === 'source_to_boot'}
                onClick={() => setTransferType('source_to_boot')}
                title="Transfer 1 — Source → Boot"
                description="Move stock from a warehouse into a rep's boot."
              />
              <TypeOption
                active={transferType === 'boot_to_hospital'}
                onClick={() => setTransferType('boot_to_hospital')}
                title="Transfer 2 — Boot → Hospital"
                description="Deliver stock from a rep's boot to a hospital."
              />
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Routing" />
          <CardBody className="grid gap-4 sm:grid-cols-2">
            {transferType === 'source_to_boot' ? (
              <>
                <Field label="From location" required error={errors.from_location}>
                  <Select value={fromLocation} onChange={(e) => setFromLocation(e.target.value)}>
                    <option value="">Select location</option>
                    {meta?.locations.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Receiving rep (boot)" required error={errors.to_holder}>
                  <Select value={toHolderId} onChange={(e) => setToHolderId(e.target.value)}>
                    <option value="">Select rep</option>
                    {users?.map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name}
                      </option>
                    ))}
                  </Select>
                </Field>
              </>
            ) : (
              <>
                <Field label="Hospital" required error={errors.hospital}>
                  <Select
                    value={hospitalId}
                    onChange={(e) => {
                      setHospitalId(e.target.value)
                      setDoctorId('')
                    }}
                  >
                    <option value="">Select hospital</option>
                    {hospitals?.map((h) => (
                      <option key={h.id} value={h.id}>
                        {h.name}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Hospital stock type" required error={errors.hospital_stock_type}>
                  <Select value={hospitalStockType} onChange={(e) => setHospitalStockType(e.target.value)}>
                    <option value="">Select stock type</option>
                    {meta?.hospital_stock_types.map((o) => (
                      <option key={o.value} value={o.value}>
                        {o.label}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field label="Doctor" hint="Optional">
                  <Select value={doctorId} onChange={(e) => setDoctorId(e.target.value)} disabled={!hospitalId}>
                    <option value="">No doctor</option>
                    {doctors?.map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name}
                      </option>
                    ))}
                  </Select>
                </Field>
              </>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Items" subtitle="Search available stock and add line items." />
          <CardBody className="grid gap-4">
            <Field label="Search inventory" error={errors.items}>
              <Input
                placeholder="Search by ref code or description…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </Field>

            {search.trim().length > 0 && (
              <div className="max-h-60 overflow-y-auto rounded-lg border border-slate-200">
                {searching ? (
                  <p className="px-4 py-3 text-sm text-slate-400">Searching…</p>
                ) : (inventory?.data?.length ?? 0) === 0 ? (
                  <p className="px-4 py-3 text-sm text-slate-400">No available stock matches.</p>
                ) : (
                  <ul className="divide-y divide-slate-100">
                    {inventory?.data.map((inv) => (
                      <li key={inv.id} className="flex items-center justify-between gap-3 px-4 py-2">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium text-slate-800">
                            {inv.ref_code} <span className="font-normal text-slate-500">— {inv.description}</span>
                          </p>
                          <p className="text-xs text-slate-400">
                            Lot {inv.lot_number ?? '—'} · {inv.quantity} available
                          </p>
                        </div>
                        <Button type="button" variant="outline" size="sm" onClick={() => addItem(inv)}>
                          <Plus className="h-4 w-4" /> Add
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}

            <div className="rounded-lg border border-slate-200">
              <DataTable
                columns={itemColumns}
                rows={items}
                rowKey={(r) => r.key}
                empty="No items added yet."
              />
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Notes" />
          <CardBody>
            <Field label="Notes">
              <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Optional notes…" />
            </Field>
          </CardBody>
        </Card>

        <div className="flex flex-wrap items-center justify-end gap-3">
          <Button
            type="button"
            variant="outline"
            loading={submitting === 'draft'}
            disabled={submitting !== null}
            onClick={() => void save(false)}
          >
            Save draft
          </Button>
          <Button
            type="button"
            loading={submitting === 'submit'}
            disabled={submitting !== null}
            onClick={() => void save(true)}
          >
            Submit for approval
          </Button>
        </div>
      </div>
    </>
  )
}

function TypeOption({ active, onClick, title, description }: {
  active: boolean
  onClick: () => void
  title: string
  description: string
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        'rounded-lg border p-4 text-left transition-colors ' +
        (active
          ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-200'
          : 'border-slate-200 bg-white hover:border-slate-300')
      }
    >
      <p className="text-sm font-semibold text-slate-800">{title}</p>
      <p className="mt-1 text-xs text-slate-500">{description}</p>
    </button>
  )
}
