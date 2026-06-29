import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ClipboardList, Printer } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import type { PreferenceCard, Paginated } from '@/types'

interface Filters {
  q: string
  page: number
}

export default function PreferenceCardListPage() {
  const navigate = useNavigate()
  const toast = useToast()

  const [filters, setFilters] = useState<Filters>({ q: '', page: 1 })

  const update = (patch: Partial<Filters>) =>
    setFilters((prev) => ({ ...prev, ...patch, page: 'page' in patch ? (patch.page ?? 1) : 1 }))

  const { data, isLoading, error } = useQuery({
    queryKey: ['preference-cards', filters],
    queryFn: async () => {
      const params: Record<string, string | number> = { page: filters.page }
      if (filters.q) params.q = filters.q
      return (await api.get<Paginated<PreferenceCard>>('/preference-cards', { params })).data
    },
  })

  const printCard = async (cardId: number) => {
    try {
      const res = await api.get(`/preference-cards/${cardId}/print`, { responseType: 'blob' })
      window.open(URL.createObjectURL(res.data))
    } catch (err) {
      toast.error(apiError(err))
    }
  }

  const columns: Column<PreferenceCard>[] = [
    {
      key: 'procedure_name',
      header: 'Procedure',
      render: (c) => <span className="font-medium text-slate-800">{c.procedure_name}</span>,
    },
    { key: 'doctor', header: 'Doctor', render: (c) => c.doctor?.name ?? '—' },
    { key: 'items', header: 'Items', render: (c) => c.items?.length ?? 0 },
    {
      key: 'is_active',
      header: 'Active',
      render: (c) => <Badge tone={c.is_active ? 'green' : 'gray'}>{c.is_active ? 'Active' : 'Inactive'}</Badge>,
    },
    {
      key: 'actions',
      header: '',
      className: 'text-right',
      render: (c) => (
        <Button
          variant="outline"
          size="sm"
          onClick={(e) => {
            e.stopPropagation()
            printCard(c.id)
          }}
        >
          <Printer className="h-4 w-4" /> Print
        </Button>
      ),
    },
  ]

  const pageMeta = data?.meta
  const currentPage = pageMeta?.current_page ?? filters.page
  const lastPage = pageMeta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Doctor Preference Cards"
        description="Procedure-specific item lists used to prepare for surgery."
      />

      <Card className="mb-6">
        <CardBody className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Search">
            <Input
              placeholder="Procedure, doctor…"
              value={filters.q}
              onChange={(e) => update({ q: e.target.value })}
            />
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
          title="No preference cards found"
          description="Try adjusting your search."
        />
      ) : (
        <Card>
          <DataTable
            columns={columns}
            rows={data.data}
            rowKey={(c) => c.id}
            onRowClick={(c) => navigate(`/doctors/${c.doctor_id}`)}
          />
          <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
            <span>Page {currentPage} of {lastPage}{pageMeta ? ` · ${pageMeta.total} cards` : ''}</span>
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
    </>
  )
}
