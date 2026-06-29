import { useQuery } from '@tanstack/react-query'
import { Link, useParams, useNavigate } from 'react-router-dom'
import type { ReactNode } from 'react'
import { ArrowLeft, Star, Boxes, MapPin, Phone, Mail } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { humanize } from '@/lib/format'
import type { Hospital } from '@/types'

export default function HospitalDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const { data: hospital, isLoading, error } = useQuery({
    queryKey: ['hospitals', id],
    queryFn: async () => (await api.get<Hospital>(`/hospitals/${id}`)).data,
    enabled: !!id,
  })

  if (isLoading) return <LoadingState label="Loading hospital…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!hospital) return null

  const users = hospital.users ?? []
  const contacts = hospital.contacts ?? []
  const doctors = hospital.doctors ?? []

  return (
    <>
      <Link to="/hospitals" className="mb-4 inline-flex items-center gap-1.5 text-sm text-brand-700 hover:underline">
        <ArrowLeft className="h-4 w-4" /> Back to hospitals
      </Link>

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
            <Button variant="outline" onClick={() => navigate(`/inventory?hospital_id=${hospital.id}`)}>
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
