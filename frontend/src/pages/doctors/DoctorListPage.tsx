import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Plus, Stethoscope } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useMeta } from '@/hooks/useMeta'
import { Can } from '@/auth/Can'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Textarea, Select } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { humanize } from '@/lib/format'
import type { Doctor, Paginated } from '@/types'

interface Filters {
  q: string
  specialty: string
  page: number
}

export default function DoctorListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const { data: meta } = useMeta()

  const [filters, setFilters] = useState<Filters>({
    q: '',
    specialty: '',
    page: 1,
  })

  const update = (patch: Partial<Filters>) =>
    setFilters((prev) => ({ ...prev, ...patch, page: 'page' in patch ? (patch.page ?? 1) : 1 }))

  const { data, isLoading, error } = useQuery({
    queryKey: ['doctors', filters],
    queryFn: async () => {
      const params: Record<string, string | number> = { page: filters.page }
      if (filters.q) params.q = filters.q
      if (filters.specialty) params.specialty = filters.specialty
      return (await api.get<Paginated<Doctor>>('/doctors', { params })).data
    },
  })

  const columns: Column<Doctor>[] = [
    { key: 'name', header: 'Name', render: (d) => <span className="font-medium text-slate-800">{d.name}</span> },
    { key: 'specialty', header: 'Specialty', render: (d) => (d.specialty ? humanize(d.specialty) : '—') },
    { key: 'hospitals', header: 'Hospitals', render: (d) => d.hospitals?.length ?? 0 },
    { key: 'phone', header: 'Phone', render: (d) => d.phone ?? '—' },
  ]

  const [createOpen, setCreateOpen] = useState(false)

  const pageMeta = data?.meta
  const currentPage = pageMeta?.current_page ?? filters.page
  const lastPage = pageMeta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Doctors"
        description="Surgeons, specialties and their preference cards."
        actions={
          <Can permission="doctor.manage">
            <Button onClick={() => setCreateOpen(true)}>
              <Plus className="h-4 w-4" /> Add doctor
            </Button>
          </Can>
        }
      />

      <Card className="mb-6">
        <CardBody className="grid gap-3 sm:grid-cols-2">
          <Field label="Search">
            <Input
              placeholder="Name, email…"
              value={filters.q}
              onChange={(e) => update({ q: e.target.value })}
            />
          </Field>
          <Field label="Specialty">
            <Select value={filters.specialty} onChange={(e) => update({ specialty: e.target.value })}>
              <option value="">All specialties</option>
              {meta?.doctor_specialties.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </Field>
        </CardBody>
      </Card>

      {isLoading ? (
        <LoadingState label="Loading doctors…" />
      ) : error ? (
        <ErrorState message={apiError(error)} />
      ) : !data || data.data.length === 0 ? (
        <EmptyState
          icon={<Stethoscope className="h-10 w-10" />}
          title="No doctors found"
          description="Try adjusting your filters, or add a new doctor."
        />
      ) : (
        <Card>
          <DataTable
            columns={columns}
            rows={data.data}
            rowKey={(d) => d.id}
            onRowClick={(d) => navigate(`/doctors/${d.id}`)}
          />
          <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
            <span>Page {currentPage} of {lastPage}{pageMeta ? ` · ${pageMeta.total} doctors` : ''}</span>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage <= 1}
                onClick={() => update({ page: currentPage - 1 })}
              >
                Prev
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage >= lastPage}
                onClick={() => update({ page: currentPage + 1 })}
              >
                Next
              </Button>
            </div>
          </div>
        </Card>
      )}

      <CreateDoctorModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={() => {
          queryClient.invalidateQueries({ queryKey: ['doctors'] })
          toast.success('Doctor created.')
          setCreateOpen(false)
        }}
      />
    </>
  )
}

interface CreateForm {
  name: string
  age: string
  specialty: string
  phone: string
  email: string
  procedure_preferences: string
  notes: string
}

const emptyForm: CreateForm = {
  name: '',
  age: '',
  specialty: '',
  phone: '',
  email: '',
  procedure_preferences: '',
  notes: '',
}

function CreateDoctorModal({ open, onClose, onCreated }: {
  open: boolean
  onClose: () => void
  onCreated: () => void
}) {
  const toast = useToast()
  const { data: meta } = useMeta()
  const [form, setForm] = useState<CreateForm>(emptyForm)

  const set = (key: keyof CreateForm) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        name: form.name,
        age: form.age === '' ? null : Number(form.age),
        specialty: form.specialty || null,
        phone: form.phone || null,
        email: form.email || null,
        procedure_preferences: form.procedure_preferences || null,
        notes: form.notes || null,
      }
      return (await api.post('/doctors', payload)).data
    },
    onSuccess: () => {
      setForm(emptyForm)
      onCreated()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Modal open={open} onClose={onClose} title="Add doctor" size="lg">
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          mutation.mutate()
        }}
      >
        <Field label="Name" required>
          <Input value={form.name} onChange={set('name')} required />
        </Field>
        <Field label="Age">
          <Input type="number" min={0} value={form.age} onChange={set('age')} />
        </Field>
        <Field label="Specialty">
          <Select value={form.specialty} onChange={set('specialty')}>
            <option value="">Select…</option>
            {meta?.doctor_specialties.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </Field>
        <Field label="Phone">
          <Input value={form.phone} onChange={set('phone')} />
        </Field>
        <Field label="Email">
          <Input type="email" value={form.email} onChange={set('email')} />
        </Field>
        <Field label="Procedure preferences" required={false}>
          <Textarea value={form.procedure_preferences} onChange={set('procedure_preferences')} />
        </Field>
        <Field label="Notes" required={false}>
          <Textarea value={form.notes} onChange={set('notes')} />
        </Field>
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Create doctor</Button>
        </div>
      </form>
    </Modal>
  )
}
