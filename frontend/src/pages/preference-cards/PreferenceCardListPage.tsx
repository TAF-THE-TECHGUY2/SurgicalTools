import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ClipboardList, Printer, Plus, Pencil, Trash2 } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { Can } from '@/auth/Can'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import type { Doctor, PreferenceCard, Paginated } from '@/types'

export default function PreferenceCardListPage() {
  const navigate = useNavigate()
  const toast = useToast()
  const queryClient = useQueryClient()

  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<PreferenceCard | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const [deleting, setDeleting] = useState<PreferenceCard | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['preference-cards', { q, page }],
    queryFn: async () => {
      const params: Record<string, string | number> = { page }
      if (q) params.q = q
      return (await api.get<Paginated<PreferenceCard>>('/preference-cards', { params })).data
    },
  })

  const remove = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/preference-cards/${id}`)).data,
    onSuccess: () => {
      toast.success('Preference card deleted.')
      queryClient.invalidateQueries({ queryKey: ['preference-cards'] })
      setDeleting(null)
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const printCard = async (cardId: number) => {
    try {
      const res = await api.get(`/preference-cards/${cardId}/print`, { responseType: 'blob' })
      window.open(URL.createObjectURL(res.data))
    } catch (err) {
      toast.error(apiError(err))
    }
  }

  const openCreate = () => { setEditing(null); setFormOpen(true) }
  const openEdit = (card: PreferenceCard) => { setEditing(card); setFormOpen(true) }

  const columns: Column<PreferenceCard>[] = [
    { key: 'procedure_name', header: 'Procedure', render: (c) => <span className="font-medium text-slate-800">{c.procedure_name}</span> },
    { key: 'doctor', header: 'Doctor', render: (c) => c.doctor?.name ?? '—' },
    { key: 'items', header: 'Items', render: (c) => c.items?.length ?? 0 },
    { key: 'is_active', header: 'Active', render: (c) => <Badge tone={c.is_active ? 'green' : 'gray'}>{c.is_active ? 'Active' : 'Inactive'}</Badge> },
    {
      key: 'actions',
      header: '',
      className: 'text-right',
      render: (c) => (
        <div className="flex justify-end gap-2" onClick={(e) => e.stopPropagation()}>
          <Button variant="outline" size="sm" onClick={() => printCard(c.id)}>
            <Printer className="h-4 w-4" /> Print
          </Button>
          <Can permission="doctor.manage">
            <Button variant="ghost" size="sm" onClick={() => openEdit(c)}>
              <Pencil className="h-4 w-4" /> Edit
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setDeleting(c)}>
              <Trash2 className="h-4 w-4 text-red-500" />
            </Button>
          </Can>
        </div>
      ),
    },
  ]

  const pageMeta = data?.meta
  const currentPage = pageMeta?.current_page ?? page
  const lastPage = pageMeta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Doctor Preference Cards"
        description="Procedure-specific item lists used to prepare for surgery."
        actions={
          <Can permission="doctor.manage">
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" /> New preference card
            </Button>
          </Can>
        }
      />

      <Card className="mb-6">
        <CardBody className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Search">
            <Input placeholder="Procedure, doctor…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} />
          </Field>
        </CardBody>
      </Card>

      {isLoading ? (
        <LoadingState label="Loading preference cards…" />
      ) : error ? (
        <ErrorState message={apiError(error)} />
      ) : !data || data.data.length === 0 ? (
        <EmptyState
          icon={<ClipboardList className="h-10 w-10" />}
          title={q ? 'No preference cards match your search' : 'No preference cards yet'}
          description={q ? 'Try a different search term.' : 'Create a card to capture a surgeon’s preferred equipment for a procedure.'}
          action={
            <Can permission="doctor.manage">
              <Button onClick={openCreate}><Plus className="h-4 w-4" /> New preference card</Button>
            </Can>
          }
        />
      ) : (
        <Card>
          <DataTable columns={columns} rows={data.data} rowKey={(c) => c.id} onRowClick={(c) => navigate(`/doctors/${c.doctor_id}`)} />
          <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
            <span>Page {currentPage} of {lastPage}{pageMeta ? ` · ${pageMeta.total} cards` : ''}</span>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" disabled={currentPage <= 1} onClick={() => setPage(currentPage - 1)}>Prev</Button>
              <Button variant="outline" size="sm" disabled={currentPage >= lastPage} onClick={() => setPage(currentPage + 1)}>Next</Button>
            </div>
          </div>
        </Card>
      )}

      <PreferenceCardFormModal
        open={formOpen}
        card={editing}
        onClose={() => setFormOpen(false)}
        onSaved={() => {
          queryClient.invalidateQueries({ queryKey: ['preference-cards'] })
          toast.success(editing ? 'Preference card updated.' : 'Preference card created.')
          setFormOpen(false)
        }}
      />

      <Modal open={!!deleting} onClose={() => setDeleting(null)} title="Delete preference card?" size="sm">
        <p className="text-sm text-slate-600">
          <span className="font-medium text-slate-800">{deleting?.procedure_name}</span> for{' '}
          {deleting?.doctor?.name ?? 'this doctor'} will be permanently deleted.
        </p>
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setDeleting(null)}>Cancel</Button>
          <Button variant="danger" loading={remove.isPending} onClick={() => deleting && remove.mutate(deleting.id)}>
            Delete card
          </Button>
        </div>
      </Modal>
    </>
  )
}

interface ItemRow {
  _key: number
  ref_code: string
  description: string
  preferred_size: string
  quantity: string
  notes: string
}

function PreferenceCardFormModal({ open, card, onClose, onSaved }: {
  open: boolean
  card: PreferenceCard | null
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const keyCounter = useRef(0)
  const nextKey = () => ++keyCounter.current

  const blankRow = (): ItemRow => ({ _key: nextKey(), ref_code: '', description: '', preferred_size: '', quantity: '1', notes: '' })

  const [doctorId, setDoctorId] = useState('')
  const [procedureName, setProcedureName] = useState('')
  const [notes, setNotes] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [items, setItems] = useState<ItemRow[]>([blankRow()])

  // Re-seed the form whenever a different card (or create mode) is opened.
  const [syncKey, setSyncKey] = useState<string>('')
  const currentKey = `${open}-${card?.id ?? 'new'}`
  if (open && syncKey !== currentKey) {
    setSyncKey(currentKey)
    setDoctorId(card ? String(card.doctor_id) : '')
    setProcedureName(card?.procedure_name ?? '')
    setNotes(card?.notes ?? '')
    setIsActive(card?.is_active ?? true)
    setItems(
      card?.items && card.items.length
        ? card.items.map((i) => ({
            _key: nextKey(),
            ref_code: i.ref_code ?? '',
            description: i.description,
            preferred_size: i.preferred_size ?? '',
            quantity: String(i.quantity ?? 1),
            notes: i.notes ?? '',
          }))
        : [blankRow()],
    )
  }

  const { data: doctors } = useQuery({
    queryKey: ['doctors', 'for-prefcard'],
    queryFn: async () => (await api.get<Paginated<Doctor>>('/doctors', { params: { per_page: 200 } })).data.data,
    enabled: open,
  })

  const setRow = (key: number, patch: Partial<ItemRow>) =>
    setItems((prev) => prev.map((r) => (r._key === key ? { ...r, ...patch } : r)))
  const addRow = () => setItems((prev) => [...prev, blankRow()])
  const removeRow = (key: number) => setItems((prev) => prev.filter((r) => r._key !== key))

  const mutation = useMutation({
    mutationFn: async () => {
      const payload = {
        doctor_id: Number(doctorId),
        procedure_name: procedureName,
        notes: notes || null,
        is_active: isActive,
        items: items
          .filter((r) => r.description.trim() !== '')
          .map((r) => ({
            ref_code: r.ref_code || null,
            description: r.description,
            preferred_size: r.preferred_size || null,
            quantity: r.quantity === '' ? 1 : Number(r.quantity),
            notes: r.notes || null,
          })),
      }
      return card
        ? (await api.put(`/preference-cards/${card.id}`, payload)).data
        : (await api.post('/preference-cards', payload)).data
    },
    onSuccess: onSaved,
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Modal open={open} onClose={onClose} title={card ? 'Edit preference card' : 'New preference card'} size="xl">
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); mutation.mutate() }}>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Doctor" required>
            <Select value={doctorId} onChange={(e) => setDoctorId(e.target.value)} required>
              <option value="">Select doctor…</option>
              {doctors?.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </Select>
          </Field>
          <Field label="Procedure" required>
            <Input value={procedureName} onChange={(e) => setProcedureName(e.target.value)} required />
          </Field>
        </div>
        <Field label="Notes">
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Positioning, setup notes…" />
        </Field>

        <div>
          <div className="mb-2 flex items-center justify-between">
            <span className="text-sm font-medium text-slate-700">Required equipment</span>
            <Button type="button" variant="outline" size="sm" onClick={addRow}>
              <Plus className="h-4 w-4" /> Add row
            </Button>
          </div>
          <div className="space-y-2">
            {items.map((row) => (
              <div key={row._key} className="grid grid-cols-12 gap-2">
                <Input className="col-span-2" placeholder="Ref" value={row.ref_code} onChange={(e) => setRow(row._key, { ref_code: e.target.value })} />
                <Input className="col-span-4" placeholder="Description" value={row.description} onChange={(e) => setRow(row._key, { description: e.target.value })} />
                <Input className="col-span-2" placeholder="Size" value={row.preferred_size} onChange={(e) => setRow(row._key, { preferred_size: e.target.value })} />
                <Input className="col-span-2" type="number" min={1} placeholder="Qty" value={row.quantity} onChange={(e) => setRow(row._key, { quantity: e.target.value })} />
                <div className="col-span-2 flex items-center">
                  <Button type="button" variant="ghost" size="sm" onClick={() => removeRow(row._key)} disabled={items.length === 1}>
                    <Trash2 className="h-4 w-4 text-red-500" />
                  </Button>
                </div>
              </div>
            ))}
          </div>
        </div>

        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          Active
        </label>

        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>{card ? 'Save changes' : 'Create card'}</Button>
        </div>
      </form>
    </Modal>
  )
}
