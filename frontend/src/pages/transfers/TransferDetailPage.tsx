import { useState } from 'react'
import type { ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Check, Download, FileText, PenLine } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useToast } from '@/components/ToastProvider'
import { enqueue } from '@/offline/syncQueue'
import { SignaturePad } from '@/components/SignaturePad'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/Field'
import { StatusBadge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { cn } from '@/lib/cn'
import { formatDate, formatDateTime, humanize } from '@/lib/format'
import type { Transfer, TransferItem, TransferSignature, TransferStatus, TransferType } from '@/types'

const FLOW: Record<TransferType, TransferStatus[]> = {
  source_to_boot: ['draft', 'pending_approval', 'awaiting_signature', 'completed'],
  boot_to_hospital: ['draft', 'pending_approval', 'awaiting_signature', 'signed', 'awaiting_admin_review', 'completed'],
}

const SIGNER_ROLES = [
  { value: 'hospital_controller', label: 'Hospital controller' },
  { value: 'rep', label: 'Rep' },
  { value: 'runner', label: 'Runner' },
]

export default function TransferDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const toast = useToast()
  const qc = useQueryClient()
  const { user, hasPermission, isAdmin } = useAuth()

  const [rejectOpen, setRejectOpen] = useState(false)
  const [rejectReason, setRejectReason] = useState('')
  const [signOpen, setSignOpen] = useState(false)
  const [signerName, setSignerName] = useState('')
  const [signerRole, setSignerRole] = useState('hospital_controller')
  const [signature, setSignature] = useState('')

  const { data: transfer, isLoading, error } = useQuery({
    queryKey: ['transfers', id],
    queryFn: async () => (await api.get<Transfer>(`/transfers/${id}`)).data,
    enabled: Boolean(id),
  })

  const invalidate = () => qc.invalidateQueries({ queryKey: ['transfers', id] })

  const action = useMutation({
    mutationFn: async ({ path, body }: { path: string; body?: Record<string, unknown> }) =>
      (await api.post(`/transfers/${id}/${path}`, body ?? {})).data,
    onSuccess: (_data, vars) => {
      toast.success(`${humanize(vars.path)} done.`)
      void invalidate()
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

  const submitSignature = async () => {
    if (!signerName.trim() || !signature) {
      toast.error('Provide a signer name and signature.')
      return
    }
    const body = { signer_name: signerName, signer_role: signerRole, signature }

    if (!navigator.onLine) {
      await enqueue('transfer.sign', { transfer_id: Number(id), ...body }, `Signature — ${signerName}`)
      toast.info('Saved offline — will sync when online')
      setSignOpen(false)
      return
    }

    action.mutate(
      { path: 'sign', body },
      {
        onSuccess: () => {
          setSignOpen(false)
          setSignerName('')
          setSignature('')
        },
      },
    )
  }

  if (isLoading) return <LoadingState label="Loading transfer…" />
  if (error) return <ErrorState message={apiError(error)} />
  if (!transfer) return null

  const flow = FLOW[transfer.type]
  const isRequester = transfer.requester?.id === user?.id

  const itemColumns: Column<TransferItem>[] = [
    { key: 'ref_code', header: 'Ref', render: (r) => <span className="font-medium text-slate-800">{r.ref_code}</span> },
    { key: 'description', header: 'Description', render: (r) => r.description ?? '—' },
    { key: 'lot_number', header: 'Lot', render: (r) => r.lot_number ?? '—' },
    { key: 'quantity', header: 'Qty', render: (r) => r.quantity },
    { key: 'expiry_date', header: 'Expiry', render: (r) => formatDate(r.expiry_date) },
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
        description={transfer.type_label ?? humanize(transfer.type)}
        actions={
          <Button variant="ghost" size="sm" onClick={() => navigate('/transfers')}>
            <ArrowLeft className="h-4 w-4" /> Back
          </Button>
        }
      />

      {/* Workflow stepper */}
      <Card className="mb-6">
        <CardBody>
          <ol className="flex flex-wrap items-center gap-y-3">
            {flow.map((step, i) => {
              const currentIndex = flow.indexOf(transfer.status)
              const done = currentIndex > i
              const current = currentIndex === i
              return (
                <li key={step} className="flex items-center">
                  <span
                    className={cn(
                      'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                      done && 'bg-brand-700 text-white',
                      current && 'bg-brand-100 text-brand-800 ring-2 ring-brand-500',
                      !done && !current && 'bg-slate-100 text-slate-400',
                    )}
                  >
                    {done ? <Check className="h-4 w-4" /> : i + 1}
                  </span>
                  <span
                    className={cn(
                      'ml-2 text-xs font-medium',
                      current ? 'text-brand-800' : done ? 'text-slate-700' : 'text-slate-400',
                    )}
                  >
                    {humanize(step)}
                  </span>
                  {i < flow.length - 1 && <span className="mx-3 h-px w-6 bg-slate-200 sm:w-10" />}
                </li>
              )
            })}
          </ol>
        </CardBody>
      </Card>

      {/* Action buttons */}
      <ActionBar
        transfer={transfer}
        isRequester={isRequester}
        isAdmin={isAdmin}
        hasPermission={hasPermission}
        loading={action.isPending}
        onSubmit={() => action.mutate({ path: 'submit' })}
        onApprove={() => action.mutate({ path: 'approve' })}
        onApproveOverride={() => action.mutate({ path: 'approve', body: { override: true } })}
        onReject={() => setRejectOpen(true)}
        onSign={() => setSignOpen(true)}
        onReview={() => action.mutate({ path: 'review' })}
        onDownload={() => void downloadPdf()}
      />

      {transfer.status === 'rejected' && transfer.rejection_reason && (
        <div className="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          <span className="font-semibold">Rejected:</span> {transfer.rejection_reason}
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Routing" />
          <CardBody className="grid gap-2 text-sm">
            <Row label="From" value={humanize(transfer.from_location)} />
            <Row label="To" value={humanize(transfer.to_location)} />
            <Row label="Hospital" value={transfer.hospital?.name ?? 'Boot'} />
            <Row label="Stock type" value={humanize(transfer.hospital_stock_type)} />
            {transfer.doctor && <Row label="Doctor" value={transfer.doctor.name} />}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="People" />
          <CardBody className="grid gap-2 text-sm">
            <Row
              label="Requester"
              value={transfer.requester ? `${transfer.requester.name} · ${formatDateTime(transfer.created_at)}` : '—'}
            />
            <Row
              label="Approver"
              value={transfer.approver ? `${transfer.approver.name} · ${formatDateTime(transfer.approved_at)}` : '—'}
            />
            <Row
              label="Reviewer"
              value={transfer.reviewer ? `${transfer.reviewer.name} · ${formatDateTime(transfer.reviewed_at)}` : '—'}
            />
          </CardBody>
        </Card>
      </div>

      <Card className="mt-6">
        <CardHeader title="Items" subtitle={`${transfer.items?.length ?? 0} line items`} />
        <CardBody className="p-0">
          <DataTable
            columns={itemColumns}
            rows={transfer.items ?? []}
            rowKey={(r) => r.id}
            empty="No items on this transfer."
          />
        </CardBody>
      </Card>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Signatures" />
          <CardBody>
            {(transfer.signatures?.length ?? 0) === 0 ? (
              <p className="text-sm text-slate-400">No signatures captured.</p>
            ) : (
              <ul className="grid gap-4">
                {transfer.signatures?.map((s: TransferSignature) => (
                  <li key={s.id} className="rounded-lg border border-slate-200 p-3">
                    <p className="text-sm font-medium text-slate-800">
                      {s.signer_name}{' '}
                      {s.signer_role && <span className="font-normal text-slate-500">· {humanize(s.signer_role)}</span>}
                    </p>
                    <p className="text-xs text-slate-400">{formatDateTime(s.signed_at)}</p>
                    {s.url && (
                      <img src={s.url} alt={`${s.signer_name} signature`} className="mt-2 max-h-24 rounded border border-slate-100 bg-white" />
                    )}
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Documents" />
          <CardBody>
            {(transfer.documents?.length ?? 0) === 0 ? (
              <button
                type="button"
                onClick={() => void downloadPdf()}
                className="flex items-center gap-2 text-sm text-brand-700 hover:underline"
              >
                <FileText className="h-4 w-4" /> Generate / download PDF
              </button>
            ) : (
              <ul className="grid gap-2">
                {transfer.documents?.map((d) => (
                  <li key={d.id} className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    <span className="flex items-center gap-2 text-slate-700">
                      <FileText className="h-4 w-4 text-slate-400" />
                      {d.original_name ?? humanize(d.type)}
                    </span>
                    <button
                      type="button"
                      onClick={() => void downloadPdf()}
                      className="flex items-center gap-1 text-xs text-brand-700 hover:underline"
                    >
                      <Download className="h-3.5 w-3.5" /> Download
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </CardBody>
        </Card>
      </div>

      {/* Reject modal */}
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
              loading={action.isPending}
              disabled={!rejectReason.trim()}
              onClick={() =>
                action.mutate(
                  { path: 'reject', body: { reason: rejectReason } },
                  {
                    onSuccess: () => {
                      setRejectOpen(false)
                      setRejectReason('')
                    },
                  },
                )
              }
            >
              Reject
            </Button>
          </div>
        </div>
      </Modal>

      {/* Signature modal */}
      <Modal open={signOpen} onClose={() => setSignOpen(false)} title="Capture signature">
        <div className="grid gap-4">
          <Field label="Signer name" required>
            <Input value={signerName} onChange={(e) => setSignerName(e.target.value)} placeholder="Full name" />
          </Field>
          <Field label="Signer role" required>
            <Select value={signerRole} onChange={(e) => setSignerRole(e.target.value)}>
              {SIGNER_ROLES.map((r) => (
                <option key={r.value} value={r.value}>
                  {r.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Signature" required>
            <SignaturePad onChange={setSignature} />
          </Field>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setSignOpen(false)}>
              Cancel
            </Button>
            <Button loading={action.isPending} onClick={() => void submitSignature()}>
              Save signature
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

function ActionBar({
  transfer,
  isRequester,
  isAdmin,
  hasPermission,
  loading,
  onSubmit,
  onApprove,
  onApproveOverride,
  onReject,
  onSign,
  onReview,
  onDownload,
}: {
  transfer: Transfer
  isRequester: boolean
  isAdmin: boolean
  hasPermission: (p: string) => boolean
  loading: boolean
  onSubmit: () => void
  onApprove: () => void
  onApproveOverride: () => void
  onReject: () => void
  onSign: () => void
  onReview: () => void
  onDownload: () => void
}) {
  const buttons: ReactNode[] = []

  if (transfer.status === 'draft' && (isRequester || isAdmin)) {
    buttons.push(
      <Button key="submit" loading={loading} onClick={onSubmit}>
        Submit for approval
      </Button>,
    )
  }

  if (transfer.status === 'pending_approval' && hasPermission('transfer.approve')) {
    buttons.push(
      <Button key="approve" loading={loading} onClick={onApprove}>
        <Check className="h-4 w-4" /> Approve
      </Button>,
      <Button key="reject" variant="danger" onClick={onReject}>
        Reject
      </Button>,
    )
    if (isAdmin) {
      buttons.push(
        <Button key="override" variant="outline" loading={loading} onClick={onApproveOverride}>
          Approve (override)
        </Button>,
      )
    }
  }

  if (transfer.status === 'awaiting_signature') {
    buttons.push(
      <Button key="sign" onClick={onSign}>
        <PenLine className="h-4 w-4" /> Capture signature
      </Button>,
    )
  }

  if (transfer.status === 'awaiting_admin_review' && hasPermission('transfer.review')) {
    buttons.push(
      <Button key="review" loading={loading} onClick={onReview}>
        <Check className="h-4 w-4" /> Approve & post to inventory
      </Button>,
    )
  }

  if (transfer.status === 'completed') {
    buttons.push(
      <Button key="download" variant="outline" onClick={onDownload}>
        <Download className="h-4 w-4" /> Download PDF
      </Button>,
    )
  }

  if (buttons.length === 0) return null

  return <div className="mb-6 flex flex-wrap gap-3">{buttons}</div>
}
