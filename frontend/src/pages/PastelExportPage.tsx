import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, FileSpreadsheet, Check } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { formatDate } from '@/lib/format'

interface PastelExport {
  id: number
  reference: string
  type: string
  period_from?: string | null
  period_to?: string | null
  row_count: number
  status: 'generated' | 'imported'
  exporter?: { name: string } | null
  created_at: string
}

interface Paginator<T> { data: T[] }

export default function PastelExportPage() {
  const qc = useQueryClient()
  const toast = useToast()
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const { data, isLoading, error } = useQuery({
    queryKey: ['pastel-exports'],
    queryFn: async () => (await api.get<Paginator<PastelExport>>('/pastel-exports')).data,
  })

  const generate = useMutation({
    mutationFn: async () => (await api.post('/pastel-exports', { from: from || null, to: to || null })).data,
    onSuccess: () => {
      toast.success('Export generated.')
      void qc.invalidateQueries({ queryKey: ['pastel-exports'] })
    },
    onError: (e) => toast.error(apiError(e)),
  })

  const markImported = useMutation({
    mutationFn: async (id: number) => (await api.post(`/pastel-exports/${id}/imported`)).data,
    onSuccess: () => {
      toast.success('Marked as imported.')
      void qc.invalidateQueries({ queryKey: ['pastel-exports'] })
    },
    onError: (e) => toast.error(apiError(e)),
  })

  const download = async (row: PastelExport) => {
    try {
      const res = await api.get(`/pastel-exports/${row.id}/download`, { responseType: 'blob' })
      const a = document.createElement('a')
      a.href = URL.createObjectURL(res.data)
      a.download = `${row.reference}.csv`
      a.click()
    } catch (e) {
      toast.error(apiError(e))
    }
  }

  const columns: Column<PastelExport>[] = [
    { key: 'reference', header: 'Reference', render: (r) => <span className="font-medium text-slate-800">{r.reference}</span> },
    { key: 'type', header: 'Type', render: (r) => r.type },
    { key: 'period', header: 'Period', render: (r) => `${formatDate(r.period_from)} – ${formatDate(r.period_to)}` },
    { key: 'rows', header: 'Rows', render: (r) => r.row_count },
    { key: 'status', header: 'Status', render: (r) => <Badge tone={r.status === 'imported' ? 'green' : 'blue'}>{r.status}</Badge> },
    { key: 'by', header: 'Exported by', render: (r) => r.exporter?.name ?? '—' },
    { key: 'created', header: 'Created', render: (r) => formatDate(r.created_at) },
    {
      key: 'actions',
      header: '',
      render: (r) => (
        <div className="flex justify-end gap-2">
          <Button size="sm" variant="outline" onClick={() => void download(r)}>
            <Download className="h-4 w-4" /> CSV
          </Button>
          {r.status === 'generated' && (
            <Button size="sm" variant="ghost" onClick={() => markImported.mutate(r.id)}>
              <Check className="h-4 w-4" /> Imported
            </Button>
          )}
        </div>
      ),
      className: 'text-right',
    },
  ]

  return (
    <>
      <PageHeader
        title="Pastel Export"
        description="Export completed ERP transactions to CSV for import into Pastel accounting."
      />

      <Card className="mb-6">
        <CardHeader title="Generate a new export" subtitle="Optionally restrict to a date range; leave blank for all un-exported transfers." />
        <CardBody>
          <div className="flex flex-wrap items-end gap-3">
            <Field label="From"><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
            <Field label="To"><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></Field>
            <Button loading={generate.isPending} onClick={() => generate.mutate()}>
              <FileSpreadsheet className="h-4 w-4" /> Generate export
            </Button>
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Export history" />
        <CardBody className="p-0">
          {isLoading ? (
            <LoadingState />
          ) : error ? (
            <div className="p-5"><ErrorState message={apiError(error)} /></div>
          ) : (
            <DataTable columns={columns} rows={data?.data ?? []} rowKey={(r) => r.id} empty="No exports yet." />
          )}
        </CardBody>
      </Card>
    </>
  )
}
