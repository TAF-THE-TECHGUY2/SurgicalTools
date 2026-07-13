import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, ArrowRight, Check, Download, FileText, PenLine } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Textarea } from '@/components/ui/Field'
import { StatusBadge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { formatDate, formatDateTime, humanize } from '@/lib/format'
import type { Transfer, TransferItem } from '@/types'

export default function TransferDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const qc = useQueryClient()
  const { hasPermission } = useAuth()

  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectReason, setRejectReason] = useState('')

  const { data: transfer, isLoading, error } = useQuery({
    queryKey: ['transfers', id],
    queryFn: async () => (await api.get<{ data: Transfer }>(`/transfers/${id}`)).data.data,
    enabled: Boolean(id),
  })

  const approve = useMutation({
    mutationFn: async () => (await api.post(`/transfers/${id}/approve`)).data,
    onSuccess: () => {
      toast.success('Transfer approved — stock moved.')
      void qc.invalidateQueries({ queryKey: ['transfers'] })
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const reject = useMutation({
    mutationFn: async (reason: string) => (await api.post(`/transfers/${id}/reject`, { reason })).data,
    onSuccess: () => {
      toast.success('Transfer rejected.')
      setRejectOpen(false)
      setRejectReason('')
      void qc.invalidateQueries({ queryKey: ['transfers'] })
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const downloadPdf = async () => {
    try {
      const res = await api.get(`/transfers/${id}/pdf`, { responseType: 'blob' })
      const url = URL.createObjectURL(res.data as Blob)
      window.open(url)
    } catch (err) {
      toast.error(apiError(err))
    }
  }

  if (isLoading) return <LoadingState label="Loading transfer…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!transfer) return null

  const fromName = transfer.from_location_entity?.name ?? humanize(transfer.from_location)
  const toName = transfer.to_location_entity?.name ?? humanize(transfer.to_location)
  const signature = transfer.signatures?.[0]
  const canDecide = transfer.status === 'pending_approval' && hasPermission('transfer.approve')

  const itemColumns: Column<TransferItem>[] = [
    { key: 'item', header: 'Item', render: (r) => <span className="font-medium text-slate-800">{r.description ?? '—'}</span> },
    { key: 'ref_code', header: 'Cat No', render: (r) => r.ref_code },
    { key: 'serial', header: 'Serial', render: (r) => r.serial_number ?? '—' },
    { key: 'lot', header: 'Lot', render: (r) => r.lot_number ?? '—' },
    { key: 'expiry', header: 'Expiry', render: (r) => formatDate(r.expiry_date) },
  ]

  return (
    <>
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {transfer.reference}
            <StatusBadge status={transfer.status} />
          </span>
        }
        description={
          <span className="inline-flex items-center gap-2">
            {fromName} <ArrowRight className="h-3.5 w-3.5 text-slate-400" /> {toName}
          </span>
        }
        actions={
          <Button variant="ghost" size="sm" onClick={() => navigate('/transfers')}>
            <ArrowLeft className="h-4 w-4" /> Back
          </Button>
        }
      />

      {canDecide && (
        <div className="mb-6 flex flex-wrap gap-3">
          <Button loading={approve.isPending} onClick={() => approve.mutate()}>
            <Check className="h-4 w-4" /> Approve
          </Button>
          <Button variant="danger" onClick={() => setRejectOpen(true)}>
            Reject
          </Button>
        </div>
      )}

      <Card className="mb-6">
        <CardHeader title="Devices" subtitle={`${transfer.items?.length ?? 0} unit(s) on this transfer`} />
        <CardBody className="p-0">
          <DataTable
            columns={itemColumns}
            rows={transfer.items ?? []}
            rowKey={(r) => r.id}
            empty="No devices on this transfer."
          />
        </CardBody>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="People & timeline" />
          <CardBody className="grid gap-2 text-sm">
            <Row
              label="Requested by"
              value={transfer.requester ? `${transfer.requester.name} · ${formatDateTime(transfer.created_at)}` : '—'}
            />
            {transfer.status === 'rejected' ? (
              <>
                <Row
                  label="Rejected by"
                  value={transfer.approver ? `${transfer.approver.name} · ${formatDateTime(transfer.rejected_at)}` : formatDateTime(transfer.rejected_at)}
                />
                {transfer.rejection_reason && (
                  <div className="mt-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <span className="font-semibold">Rejected:</span> {transfer.rejection_reason}
                  </div>
                )}
              </>
            ) : (
              <Row
                label="Approved by"
                value={transfer.approver ? `${transfer.approver.name} · ${formatDateTime(transfer.approved_at)}` : '—'}
              />
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Signature" />
          <CardBody>
            {signature ? (
              <div className="rounded-lg border border-slate-200 p-3">
                <p className="flex items-center gap-2 text-sm font-medium text-slate-800">
                  <PenLine className="h-4 w-4 text-slate-400" />
                  {signature.signer_name}
                  {signature.signer_role && (
                    <span className="font-normal text-slate-500">· {humanize(signature.signer_role)}</span>
                  )}
                </p>
                <p className="mt-1 text-xs text-slate-400">{formatDateTime(signature.signed_at)}</p>
              </div>
            ) : (
              <p className="text-sm text-slate-400">No signature captured.</p>
            )}
          </CardBody>
        </Card>
      </div>

      <Card className="mt-6">
        <CardHeader title="Documents" />
        <CardBody className="grid gap-3">
          {(transfer.documents?.length ?? 0) > 0 && (
            <ul className="grid gap-2">
              {transfer.documents?.map((d) => (
                <li key={d.id} className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
                  <FileText className="h-4 w-4 text-slate-400" />
                  {d.original_name ?? humanize(d.type)}
                </li>
              ))}
            </ul>
          )}
          {transfer.status === 'completed' ? (
            <div>
              <Button variant="outline" onClick={() => void downloadPdf()}>
                <Download className="h-4 w-4" /> Download PDF
              </Button>
            </div>
          ) : (
            (transfer.documents?.length ?? 0) === 0 && (
              <p className="text-sm text-slate-400">The transfer document becomes available once the transfer is completed.</p>
            )
          )}
        </CardBody>
      </Card>

      <Modal open={rejectOpen} onClose={() => setRejectOpen(false)} title="Reject transfer">
        <div className="grid gap-4">
          <Field label="Reason" required>
            <Textarea
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              placeholder="Explain why this transfer is being rejected…"
            />
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setRejectOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              loading={reject.isPending}
              disabled={!rejectReason.trim()}
              onClick={() => reject.mutate(rejectReason)}
            >
              Reject
            </Button>
          </div>
        </div>
      </Modal>
    </>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-4">
      <span className="text-slate-500">{label}</span>
      <span className="text-right font-medium text-slate-800">{value}</span>
    </div>
  )
}
