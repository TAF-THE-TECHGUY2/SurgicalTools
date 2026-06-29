import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import type { ReactNode } from 'react'
import { ArrowLeft, Phone, Mail, Printer } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { useToast } from '@/components/ToastProvider'
import { humanize } from '@/lib/format'
import type { Doctor } from '@/types'

export default function DoctorDetailPage() {
  const { id } = useParams<{ id: string }>()
  const toast = useToast()

  const { data: doctor, isLoading, error } = useQuery({
    queryKey: ['doctors', id],
    queryFn: async () => (await api.get<Doctor>(`/doctors/${id}`)).data,
    enabled: !!id,
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
      <Link to="/doctors" className="mb-4 inline-flex items-center gap-1.5 text-sm text-brand-700 hover:underline">
        <ArrowLeft className="h-4 w-4" /> Back to doctors
      </Link>

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
