import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import {
  AlertTriangle, ArrowLeft, ArrowRight, Check, Download, FileText, PenLine, ShieldCheck,
} from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Textarea } from '@/components/ui/Field'
import { Badge, StatusBadge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { SignaturePad } from '@/components/SignaturePad'
import { cn } from '@/lib/cn'
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

  // Recipient hand-over: the voucher's NAME OF RECIPIENT / SIGNATURE block.
  const [signOpen, setSignOpen] = useState(false)
  const [recipientName, setRecipientName] = useState('')
  const [recipientSignature, setRecipientSignature] = useState('')
  const [invoiceRef, setInvoiceRef] = useState('')

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

  const signDelivery = useMutation({
    mutationFn: async () =>
      (await api.post(`/transfers/${id}/sign-delivery`, {
        recipient_name: recipientName,
        signature: recipientSignature,
        invoice_reference: invoiceRef || null,
      })).data,
    onSuccess: () => {
      toast.success('Delivery signed — the voucher can now be approved.')
      setSignOpen(false)
      setRecipientName('')
      setRecipientSignature('')
      void qc.invalidateQueries({ queryKey: ['transfers', id] })
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
  const signatures = transfer.signatures ?? []
  const requesterSignature = signatures.find((s) => s.signer_role === 'requester') ?? signatures[0]
  const recipientSig = signatures.find((s) => s.signer_role === 'recipient')

  const isHospitalDelivery = transfer.to_location_entity?.type === 'hospital'
  const adjustments = (transfer.items ?? []).filter((i) => i.is_transfer_adjustment)

  // A hospital delivery cannot be approved until the recipient has signed.
  const awaitingRecipient = isHospitalDelivery
    && !recipientSig
    && transfer.status === 'pending_approval'
  const canSignDelivery = awaitingRecipient
    && (hasPermission('transfer.create') || hasPermission('transfer.override'))
  const canDecide = transfer.status === 'pending_approval' && hasPermission('transfer.approve')

  const itemColumns: Column<TransferItem>[] = [
    { key: 'item', header: 'Item', render: (r) => <span className="font-medium text-slate-800">{r.description ?? '—'}</span> },
    { key: 'ref_code', header: 'Cat No', render: (r) => r.ref_code },
    { key: 'serial', header: 'Serial', render: (r) => r.serial_number ?? '—' },
    {
      key: 'lot',
      header: 'Lot',
      render: (r) => (
        <span>
          {r.expected_lot_number && (
            <span className="mr-1.5 text-orange-700 line-through">{r.expected_lot_number}</span>
          )}
          <span className={r.is_transfer_adjustment ? 'font-semibold text-orange-900' : ''}>
            {r.lot_number ?? '—'}
          </span>
        </span>
      ),
    },
    { key: 'expiry', header: 'Expiry', render: (r) => formatDate(r.expiry_date) },
  ]

  return (
    <>
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-3">
            {transfer.voucher_number
              ? <>Voucher {transfer.voucher_number}</>
              : transfer.reference}
            <StatusBadge status={transfer.status} />
            {adjustments.length > 0 && (
              <Badge tone="amber">
                {adjustments.length} flagged line{adjustments.length === 1 ? '' : 's'}
              </Badge>
            )}
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

      {awaitingRecipient && (
        <div className="mb-6 flex flex-wrap items-start justify-between gap-3 rounded-lg border-l-4 border-amber-400 bg-amber-50 px-4 py-3">
          <div className="flex items-start gap-3 text-sm">
            <PenLine className="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
            <div>
              <p className="font-semibold text-amber-900">Awaiting the recipient's signature</p>
              <p className="text-amber-800">
                Capture the signature at hand-over. This voucher cannot be approved
                until someone at {toName} signs for it.
              </p>
            </div>
          </div>
          {canSignDelivery && (
            <Button onClick={() => setSignOpen(true)}>
              <PenLine className="h-4 w-4" /> Sign for delivery
            </Button>
          )}
        </div>
      )}

      {adjustments.length > 0 && (
        <div className="mb-6 flex items-start gap-3 rounded-lg border-l-4 border-orange-400 bg-orange-50 px-4 py-3">
          <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-orange-600" />
          <div className="text-sm">
            <p className="font-semibold text-orange-900">
              {adjustments.length} line{adjustments.length === 1 ? '' : 's'} not on the dispatch list
            </p>
            <p className="text-orange-800">
              Scanned items the source location was not authorised to send. Admin
              operations were alerted. These carry no reserved device, so approval
              moves no stock for them.
            </p>
          </div>
        </div>
      )}

      {canDecide && (
        <div className="mb-6 flex flex-wrap gap-3">
          <Button
            loading={approve.isPending}
            disabled={awaitingRecipient && !hasPermission('transfer.override')}
            title={awaitingRecipient ? 'The recipient has not signed for this delivery yet.' : undefined}
            onClick={() => approve.mutate()}
          >
            <Check className="h-4 w-4" /> Approve
          </Button>
          {awaitingRecipient && hasPermission('transfer.override') && (
            <span className="flex items-center gap-1.5 self-center text-xs text-slate-500">
              <ShieldCheck className="h-3.5 w-3.5" />
              Approving unsigned records an admin override.
            </span>
          )}
          <Button variant="danger" onClick={() => setRejectOpen(true)}>
            Reject
          </Button>
        </div>
      )}

      <Card className="mb-6">
        <CardHeader title="Delivery voucher" />
        <CardBody className="grid gap-2 text-sm sm:grid-cols-2">
          <Row label="Voucher no." value={transfer.voucher_number ?? '—'} />
          <Row label="Reference" value={transfer.reference} />
          <Row label="Date" value={formatDate(transfer.transfer_date ?? transfer.created_at)} />
          <Row label="Invoice no." value={transfer.invoice_reference ?? '—'} />
          <Row label="Deliver to" value={toName} />
          <Row label="Contact person" value={transfer.contact_person_name ?? '—'} />
          {transfer.delivery_address && (
            <div className="sm:col-span-2">
              <Row label="Address" value={transfer.delivery_address} />
            </div>
          )}
          <Row label="Name of recipient" value={transfer.recipient_name ?? '—'} />
          <Row
            label="Date delivered"
            value={transfer.delivery_timestamp ? formatDateTime(transfer.delivery_timestamp) : '—'}
          />
        </CardBody>
      </Card>

      <Card className="mb-6">
        <CardHeader title="Devices" subtitle={`${transfer.items?.length ?? 0} unit(s) on this transfer`} />
        <CardBody className="p-0">
          <DataTable
            columns={itemColumns}
            rows={transfer.items ?? []}
            rowKey={(r) => r.id}
            rowClassName={(r) =>
              cn(r.is_transfer_adjustment && 'border-l-4 border-orange-400 bg-orange-50')}
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
            {signatures.length === 0 ? (
              <p className="text-sm text-slate-400">No signature captured.</p>
            ) : (
              <div className="grid gap-2">
                {[requesterSignature, recipientSig].filter(Boolean).map((sig) => (
                  <div key={sig!.id} className="rounded-lg border border-slate-200 p-3">
                    <p className="flex items-center gap-2 text-sm font-medium text-slate-800">
                      <PenLine className="h-4 w-4 text-slate-400" />
                      {sig!.signer_name}
                      {sig!.signer_role && (
                        <span className="font-normal text-slate-500">· {humanize(sig!.signer_role)}</span>
                      )}
                    </p>
                    <p className="mt-1 text-xs text-slate-400">{formatDateTime(sig!.signed_at)}</p>
                  </div>
                ))}
                {isHospitalDelivery && !recipientSig && (
                  <p className="text-xs text-amber-700">The recipient has not signed yet.</p>
                )}
              </div>
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

      {/*
        Hand-over capture — the paper voucher's NAME OF RECIPIENT / SIGNATURE /
        DATE DELIVERED block. Submission is locked until both the name and a
        drawn signature exist, which is the voucher spec's own condition.
      */}
      <Modal open={signOpen} onClose={() => setSignOpen(false)} title="Sign for delivery" size="lg">
        <div className="grid gap-4">
          <p className="text-sm text-slate-500">
            Hand the device to the person receiving the stock at {toName} and have
            them sign. The timestamp is recorded automatically.
          </p>

          <Field label="Name of recipient" required>
            <Input
              value={recipientName}
              onChange={(e) => setRecipientName(e.target.value)}
              placeholder="Sister Dlamini"
            />
          </Field>

          <Field label="Invoice no." hint="Only if the invoice number is known now.">
            <Input
              value={invoiceRef}
              onChange={(e) => setInvoiceRef(e.target.value)}
              placeholder={transfer.invoice_reference ?? 'INV-4471'}
            />
          </Field>

          <Field label="Signature" required>
            <SignaturePad onChange={setRecipientSignature} />
          </Field>

          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setSignOpen(false)}>
              Cancel
            </Button>
            <Button
              loading={signDelivery.isPending}
              disabled={!recipientName.trim() || !recipientSignature}
              onClick={() => signDelivery.mutate()}
            >
              <PenLine className="h-4 w-4" /> Confirm delivery
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
