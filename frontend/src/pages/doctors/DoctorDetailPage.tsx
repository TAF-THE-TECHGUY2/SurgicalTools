import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams, useNavigate } from 'react-router-dom'
import type { ReactNode } from 'react'
import { ArrowLeft, Phone, Mail, Printer, Pencil, Archive } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { Can } from '@/auth/Can'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/Field'
import { Modal } from '@/components/ui/Modal'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { useToast } from '@/components/ToastProvider'
import { useMeta } from '@/hooks/useMeta'
import { humanize } from '@/lib/format'
import type { Doctor, Hospital, Paginated } from '@/types'

const WEEKDAYS = [
  { value: 'mon', label: 'Mon' },
  { value: 'tue', label: 'Tue' },
  { value: 'wed', label: 'Wed' },
  { value: 'thu', label: 'Thu' },
  { value: 'fri', label: 'Fri' },
  { value: 'sat', label: 'Sat' },
  { value: 'sun', label: 'Sun' },
]

export default function DoctorDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [editOpen, setEditOpen] = useState(false)
  const [archiveOpen, setArchiveOpen] = useState(false)

  const { data: doctor, isLoading, error } = useQuery({
    queryKey: ['doctors', id],
    queryFn: async () => (await api.get<Doctor>(`/doctors/${id}`)).data,
    enabled: !!id,
  })

  const archive = useMutation({
    mutationFn: async () => (await api.delete(`/doctors/${id}`)).data,
    onSuccess: () => {
      toast.success('Doctor archived.')
      queryClient.invalidateQueries({ queryKey: ['doctors'] })
      navigate('/doctors')
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

  if (isLoading) return <LoadingState label="Loading doctor…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!doctor) return null

  const operatingDays = doctor.operating_days ?? []
  const equipment = doctor.equipment_used ?? []
  const hospitals = doctor.hospitals ?? []
  const cards = doctor.preference_cards ?? []

  return (
    <>
      <div className="mb-4 flex items-center justify-between">
        <Link to="/doctors" className="inline-flex items-center gap-1.5 text-sm text-brand-700 hover:underline">
          <ArrowLeft className="h-4 w-4" /> Back to doctors
        </Link>
        <Can permission="doctor.manage">
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
              <Pencil className="h-4 w-4" /> Edit
            </Button>
            <Button variant="danger" size="sm" onClick={() => setArchiveOpen(true)}>
              <Archive className="h-4 w-4" /> Archive
            </Button>
          </div>
        </Can>
      </div>

      <Card className="mb-6">
        <CardBody>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">{doctor.name}</h1>
            {doctor.specialty && <Badge tone="purple">{humanize(doctor.specialty)}</Badge>}
          </div>

          <dl className="mt-6 grid gap-x-6 gap-y-4 border-t border-slate-100 pt-6 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label="Age" value={doctor.age != null ? String(doctor.age) : '—'} />
            <Detail
              label="Phone"
              value={
                <span className="inline-flex items-center gap-1.5">
                  <Phone className="h-4 w-4 text-slate-400" /> {doctor.phone ?? '—'}
                </span>
              }
            />
            <Detail
              label="Email"
              value={
                <span className="inline-flex items-center gap-1.5">
                  <Mail className="h-4 w-4 text-slate-400" /> {doctor.email ?? '—'}
                </span>
              }
            />
          </dl>
        </CardBody>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Operating days" subtitle="Scheduled theatre days" />
          <CardBody>
            {operatingDays.length === 0 ? (
              <p className="text-sm text-slate-400">No operating days recorded.</p>
            ) : (
              <div className="flex flex-wrap gap-2">
                {operatingDays.map((d) => (
                  <Badge key={d} tone="teal">{humanize(d)}</Badge>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Equipment used" subtitle="Preferred instruments & devices" />
          <CardBody>
            {equipment.length === 0 ? (
              <p className="text-sm text-slate-400">No equipment recorded.</p>
            ) : (
              <div className="flex flex-wrap gap-2">
                {equipment.map((e) => (
                  <Badge key={e} tone="blue">{e}</Badge>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Procedure preferences" />
          <CardBody>
            <p className="whitespace-pre-line text-sm text-slate-700">
              {doctor.procedure_preferences || '—'}
            </p>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Notes" />
          <CardBody>
            <p className="whitespace-pre-line text-sm text-slate-700">{doctor.notes || '—'}</p>
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Hospitals" subtitle="Facilities where this surgeon operates" />
          <CardBody className="p-0">
            {hospitals.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-400">No hospitals linked.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {hospitals.map((h) => (
                  <li key={h.id}>
                    <Link to={`/hospitals/${h.id}`} className="flex items-center justify-between px-5 py-3 hover:bg-slate-50">
                      <div>
                        <p className="text-sm font-medium text-slate-800">{h.name}</p>
                        <p className="text-xs text-slate-500">{h.region ? humanize(h.region) : '—'}</p>
                      </div>
                      <Badge tone="teal">{humanize(h.category)}</Badge>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Preference cards" subtitle="Procedure-specific item lists" />
          <CardBody className="p-0">
            {cards.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-400">No preference cards.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {cards.map((c) => (
                  <li key={c.id} className="flex items-center justify-between gap-3 px-5 py-3">
                    <div>
                      <p className="text-sm font-medium text-slate-800">{c.procedure_name}</p>
                      <p className="text-xs text-slate-500">{`${c.items?.length ?? 0} items`}</p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => printCard(c.id)}>
                      <Printer className="h-4 w-4" /> Print
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      <EditDoctorModal
        doctor={doctor}
        open={editOpen}
        onClose={() => setEditOpen(false)}
        onSaved={() => {
          queryClient.invalidateQueries({ queryKey: ['doctors', id] })
          queryClient.invalidateQueries({ queryKey: ['doctors'] })
          toast.success('Doctor updated.')
          setEditOpen(false)
        }}
      />

      <Modal open={archiveOpen} onClose={() => setArchiveOpen(false)} title="Archive this doctor?" size="sm">
        <p className="text-sm text-slate-600">
          <span className="font-medium text-slate-800">{doctor.name}</span>
          {doctor.specialty ? ` (${humanize(doctor.specialty)})` : ''} will be archived. Linked
          preference cards and hospital associations are preserved and the record can be restored by
          an administrator.
        </p>
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setArchiveOpen(false)}>Cancel</Button>
          <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
            <Archive className="h-4 w-4" /> Archive doctor
          </Button>
        </div>
      </Modal>
    </>
  )
}

interface EditForm {
  name: string
  age: string
  specialty: string
  phone: string
  email: string
  procedure_preferences: string
  notes: string
  operating_days: string[]
  equipment_used: string
  hospital_ids: number[]
}

function toForm(doctor: Doctor): EditForm {
  return {
    name: doctor.name,
    age: doctor.age != null ? String(doctor.age) : '',
    specialty: doctor.specialty ?? '',
    phone: doctor.phone ?? '',
    email: doctor.email ?? '',
    procedure_preferences: doctor.procedure_preferences ?? '',
    notes: doctor.notes ?? '',
    operating_days: doctor.operating_days ?? [],
    equipment_used: (doctor.equipment_used ?? []).join(', '),
    hospital_ids: (doctor.hospitals ?? []).map((h) => h.id),
  }
}

function EditDoctorModal({ doctor, open, onClose, onSaved }: {
  doctor: Doctor
  open: boolean
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const { data: meta } = useMeta()
  const [form, setForm] = useState<EditForm>(() => toForm(doctor))

  // Re-sync the form whenever a different doctor is loaded.
  const [syncedId, setSyncedId] = useState(doctor.id)
  if (syncedId !== doctor.id) {
    setSyncedId(doctor.id)
    setForm(toForm(doctor))
  }

  const { data: hospitals } = useQuery({
    queryKey: ['hospitals'],
    queryFn: async (): Promise<Hospital[]> => {
      try {
        const res = (await api.get<Paginated<Hospital> | Hospital[]>('/hospitals')).data
        return Array.isArray(res) ? res : res?.data ?? []
      } catch {
        return []
      }
    },
  })

  const set = (key: keyof EditForm) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const toggleDay = (day: string) =>
    setForm((prev) => ({
      ...prev,
      operating_days: prev.operating_days.includes(day)
        ? prev.operating_days.filter((d) => d !== day)
        : [...prev.operating_days, day],
    }))

  const toggleHospital = (hospitalId: number) =>
    setForm((prev) => ({
      ...prev,
      hospital_ids: prev.hospital_ids.includes(hospitalId)
        ? prev.hospital_ids.filter((h) => h !== hospitalId)
        : [...prev.hospital_ids, hospitalId],
    }))

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
        operating_days: form.operating_days,
        equipment_used: form.equipment_used
          .split(',')
          .map((e) => e.trim())
          .filter(Boolean),
        hospital_ids: form.hospital_ids,
      }
      return (await api.put(`/doctors/${doctor.id}`, payload)).data
    },
    onSuccess: onSaved,
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Modal open={open} onClose={onClose} title="Edit doctor" size="lg">
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
        <Field label="Equipment used" hint="Comma-separated list of instruments & devices.">
          <Input value={form.equipment_used} onChange={set('equipment_used')} />
        </Field>
        <Field label="Procedure preferences">
          <Textarea value={form.procedure_preferences} onChange={set('procedure_preferences')} />
        </Field>
        <Field label="Notes">
          <Textarea value={form.notes} onChange={set('notes')} />
        </Field>
        <Field label="Operating days">
          <div className="flex flex-wrap gap-3 pt-1">
            {WEEKDAYS.map((d) => (
              <label key={d.value} className="inline-flex items-center gap-1.5 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
                  checked={form.operating_days.includes(d.value)}
                  onChange={() => toggleDay(d.value)}
                />
                {d.label}
              </label>
            ))}
          </div>
        </Field>
        <Field label="Hospitals" hint="Facilities where this surgeon operates.">
          {(hospitals ?? []).length === 0 ? (
            <p className="pt-1 text-sm text-slate-400">No hospitals available.</p>
          ) : (
            <div className="max-h-40 space-y-1.5 overflow-y-auto rounded-lg border border-slate-200 p-2">
              {(hospitals ?? []).map((h) => (
                <label key={h.id} className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
                    checked={form.hospital_ids.includes(h.id)}
                    onChange={() => toggleHospital(h.id)}
                  />
                  {h.name}
                </label>
              ))}
            </div>
          )}
        </Field>
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={mutation.isPending}>Save changes</Button>
        </div>
      </form>
    </Modal>
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
