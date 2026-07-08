import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams, useNavigate } from 'react-router-dom'
import type { ReactNode } from 'react'
import { ArrowLeft, Star, Boxes, MapPin, Phone, Mail, Pencil, Archive } from 'lucide-react'
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
import type { Hospital, User, Paginated } from '@/types'

export default function HospitalDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [editOpen, setEditOpen] = useState(false)
  const [archiveOpen, setArchiveOpen] = useState(false)

  const { data: hospital, isLoading, error } = useQuery({
    queryKey: ['hospitals', id],
    queryFn: async () => (await api.get<{ data: Hospital }>(`/hospitals/${id}`)).data.data,
    enabled: !!id,
  })

  const archive = useMutation({
    mutationFn: async () => (await api.delete(`/hospitals/${id}`)).data,
    onSuccess: () => {
      toast.success('Hospital archived.')
      queryClient.invalidateQueries({ queryKey: ['hospitals'] })
      navigate('/hospitals')
    },
    onError: (err) => toast.error(apiError(err)),
  })

  if (isLoading) return <LoadingState label="Loading hospital…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!hospital) return null

  const users = hospital.users ?? []
  const contacts = hospital.contacts ?? []
  const doctors = hospital.doctors ?? []

  return (
    <>
      <div className="mb-4 flex items-center justify-between">
        <Link to="/hospitals" className="inline-flex items-center gap-1.5 text-sm text-brand-700 hover:underline">
          <ArrowLeft className="h-4 w-4" /> Back to hospitals
        </Link>
        <Can permission="hospital.manage">
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
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <div className="flex items-center gap-3">
                <h1 className="text-2xl font-bold tracking-tight text-slate-900">{hospital.name}</h1>
                <Badge tone="teal">{humanize(hospital.category)}</Badge>
              </div>
              <p className="mt-1 text-sm text-slate-500">{hospital.region ? humanize(hospital.region) : '—'}</p>
            </div>
            <Button variant="outline" onClick={() => navigate(`/inventory?hospital=${hospital.id}`)}>
              <Boxes className="h-4 w-4" /> View inventory
            </Button>
          </div>

          <dl className="mt-6 grid gap-x-6 gap-y-4 border-t border-slate-100 pt-6 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label="Code" value={hospital.code ?? '—'} />
            <Detail
              label="Address"
              value={
                <span className="inline-flex items-start gap-1.5">
                  <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                  <span>
                    {[hospital.address, hospital.city, hospital.province].filter(Boolean).join(', ') || '—'}
                  </span>
                </span>
              }
            />
            <Detail label="Inventory lines" value={String(hospital.inventory_count ?? 0)} />
            <Detail
              label="Phone"
              value={
                <span className="inline-flex items-center gap-1.5">
                  <Phone className="h-4 w-4 text-slate-400" /> {hospital.phone ?? '—'}
                </span>
              }
            />
            <Detail
              label="Email"
              value={
                <span className="inline-flex items-center gap-1.5">
                  <Mail className="h-4 w-4 text-slate-400" /> {hospital.email ?? '—'}
                </span>
              }
            />
          </dl>
        </CardBody>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Assigned reps & runners" subtitle="Staff linked to this facility" />
          <CardBody className="p-0">
            {users.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-400">No staff assigned.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {users.map((u) => {
                  const pivotRole = (u as { pivot?: { role?: string | null } }).pivot?.role
                  return (
                    <li key={u.id} className="flex items-center justify-between px-5 py-3">
                      <div>
                        <p className="text-sm font-medium text-slate-800">{u.name}</p>
                        <p className="text-xs text-slate-500">{u.email}</p>
                      </div>
                      {pivotRole && <Badge tone="blue">{humanize(pivotRole)}</Badge>}
                    </li>
                  )
                })}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Key contacts" subtitle="On-site points of contact" />
          <CardBody className="p-0">
            {contacts.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-400">No contacts recorded.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {contacts.map((c) => (
                  <li key={c.id} className="flex items-start justify-between gap-3 px-5 py-3">
                    <div>
                      <p className="flex items-center gap-1.5 text-sm font-medium text-slate-800">
                        {c.name}
                        {c.is_primary && <Star className="h-4 w-4 fill-amber-400 text-amber-400" aria-label="Primary contact" />}
                      </p>
                      {c.role && <p className="text-xs text-slate-500">{c.role}</p>}
                      <p className="mt-0.5 text-xs text-slate-500">
                        {[c.email, c.phone].filter(Boolean).join(' · ') || '—'}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title="Linked doctors" subtitle="Surgeons operating at this hospital" />
          <CardBody className="p-0">
            {doctors.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-400">No doctors linked.</p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {doctors.map((d) => (
                  <li key={d.id}>
                    <Link to={`/doctors/${d.id}`} className="flex items-center justify-between px-5 py-3 hover:bg-slate-50">
                      <div>
                        <p className="text-sm font-medium text-slate-800">{d.name}</p>
                        <p className="text-xs text-slate-500">{d.specialty ? humanize(d.specialty) : '—'}</p>
                      </div>
                      {d.specialty && <Badge tone="purple">{humanize(d.specialty)}</Badge>}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      <EditHospitalModal
        hospital={hospital}
        open={editOpen}
        onClose={() => setEditOpen(false)}
        onSaved={() => {
          queryClient.invalidateQueries({ queryKey: ['hospitals', id] })
          queryClient.invalidateQueries({ queryKey: ['hospitals'] })
          toast.success('Hospital updated.')
          setEditOpen(false)
        }}
      />

      <Modal open={archiveOpen} onClose={() => setArchiveOpen(false)} title="Archive this hospital?" size="sm">
        <p className="text-sm text-slate-600">
          <span className="font-medium text-slate-800">{hospital.name}</span>
          {hospital.code ? ` (${hospital.code})` : ''} will be archived. It can be restored by an administrator.
        </p>
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setArchiveOpen(false)}>Cancel</Button>
          <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
            <Archive className="h-4 w-4" /> Archive hospital
          </Button>
        </div>
      </Modal>
    </>
  )
}

interface EditForm {
  name: string
  code: string
  category: string
  region: string
  city: string
  province: string
  address: string
  phone: string
  email: string
  assigned_rep_id: string
  assigned_runner_id: string
  is_active: boolean
}

function toForm(hospital: Hospital): EditForm {
  return {
    name: hospital.name,
    code: hospital.code ?? '',
    category: hospital.category,
    region: hospital.region ?? '',
    city: hospital.city ?? '',
    province: hospital.province ?? '',
    address: hospital.address ?? '',
    phone: hospital.phone ?? '',
    email: hospital.email ?? '',
    assigned_rep_id: hospital.assigned_rep?.id != null ? String(hospital.assigned_rep.id) : '',
    assigned_runner_id: hospital.assigned_runner?.id != null ? String(hospital.assigned_runner.id) : '',
    is_active: hospital.is_active,
  }
}

function EditHospitalModal({ hospital, open, onClose, onSaved }: {
  hospital: Hospital
  open: boolean
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const { data: meta } = useMeta()
  const [form, setForm] = useState<EditForm>(() => toForm(hospital))

  // Re-sync the form whenever a different hospital is loaded.
  const [syncedId, setSyncedId] = useState(hospital.id)
  if (syncedId !== hospital.id) {
    setSyncedId(hospital.id)
    setForm(toForm(hospital))
  }

  const { data: users } = useQuery({
    queryKey: ['users', 'all'],
    queryFn: async () => {
      try {
        return (await api.get<Paginated<User>>('/users')).data.data
      } catch {
        return [] as User[]
      }
    },
  })

  const set = (key: keyof EditForm) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const mutation = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        name: form.name,
        code: form.code || null,
        category: form.category,
        region: form.region || null,
        city: form.city || null,
        province: form.province || null,
        address: form.address || null,
        phone: form.phone || null,
        email: form.email || null,
        assigned_rep_id: form.assigned_rep_id === '' ? null : Number(form.assigned_rep_id),
        assigned_runner_id: form.assigned_runner_id === '' ? null : Number(form.assigned_runner_id),
        is_active: form.is_active,
      }
      return (await api.put(`/hospitals/${hospital.id}`, payload)).data
    },
    onSuccess: onSaved,
    onError: (err) => toast.error(apiError(err)),
  })

  const userOptions = users ?? []

  return (
    <Modal open={open} onClose={onClose} title="Edit hospital" size="lg">
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
        <Field label="Code">
          <Input value={form.code} onChange={set('code')} />
        </Field>
        <Field label="Category" required>
          <Select value={form.category} onChange={set('category')} required>
            <option value="">Select…</option>
            {meta?.hospital_categories.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </Field>
        <Field label="Region">
          <Input value={form.region} onChange={set('region')} />
        </Field>
        <Field label="City">
          <Input value={form.city} onChange={set('city')} />
        </Field>
        <Field label="Province">
          <Input value={form.province} onChange={set('province')} />
        </Field>
        <div className="sm:col-span-2">
          <Field label="Address">
            <Textarea value={form.address} onChange={set('address')} />
          </Field>
        </div>
        <Field label="Phone">
          <Input value={form.phone} onChange={set('phone')} />
        </Field>
        <Field label="Email">
          <Input type="email" value={form.email} onChange={set('email')} />
        </Field>
        <Field label="Assigned rep">
          <Select value={form.assigned_rep_id} onChange={set('assigned_rep_id')}>
            <option value="">Unassigned</option>
            {userOptions.map((u) => (
              <option key={u.id} value={u.id}>{u.name}</option>
            ))}
          </Select>
        </Field>
        <Field label="Assigned runner">
          <Select value={form.assigned_runner_id} onChange={set('assigned_runner_id')}>
            <option value="">Unassigned</option>
            {userOptions.map((u) => (
              <option key={u.id} value={u.id}>{u.name}</option>
            ))}
          </Select>
        </Field>
        <label className="flex items-center gap-2 sm:col-span-2">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
            checked={form.is_active}
            onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
          />
          <span className="text-sm font-medium text-slate-700">Active</span>
        </label>
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
