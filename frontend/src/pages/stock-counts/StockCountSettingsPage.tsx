import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, FlaskConical, Pencil, Plus, Save, Trash2 } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select, Textarea } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { ErrorState, LoadingState } from '@/components/ui/States'
import type { LabelFieldMapping, ScanExtraction, StockItem, SupplierLabelTemplate } from '@/types'

const FIELDS = [
  { key: 'ref', label: 'REF / code' },
  { key: 'gtin', label: 'GTIN' },
  { key: 'lot_number', label: 'Lot' },
  { key: 'expiry_date', label: 'Expiry' },
  { key: 'serial_number', label: 'Serial' },
] as const

const DATE_FORMATS = ['YYMMDD', 'YYYYMMDD', 'YYYY-MM-DD', 'DD/MM/YYYY', 'MM/YYYY']

interface StockCountSettings {
  accounts_emails: string[]
  fallback_email?: string | null
}

/**
 * Admin screen for spec §5: the accounts department address the variance
 * report goes to, and the supplier label templates that tell the scanner
 * where each supplier keeps its lot, expiry and REF.
 */
export default function StockCountSettingsPage() {
  const navigate = useNavigate()
  const { isAdmin } = useAuth()

  if (!isAdmin) return <ErrorState message="Only admins manage stock-count settings." />

  return (
    <>
      <PageHeader
        title="Stock count settings"
        description="Where reports go, and how each supplier's labels are read."
        actions={
          <Button variant="ghost" size="sm" onClick={() => navigate('/stock-counts')}>
            <ArrowLeft className="h-4 w-4" /> Back
          </Button>
        }
      />
      <AccountsCard />
      <TemplatesCard />
    </>
  )
}

function AccountsCard() {
  const toast = useToast()
  const qc = useQueryClient()
  const [draft, setDraft] = useState<string | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['settings', 'stock-counts'],
    queryFn: async () => (await api.get<{ data: StockCountSettings }>('/settings/stock-counts')).data.data,
  })

  const save = useMutation({
    mutationFn: async (emails: string[]) =>
      (await api.put<{ data: StockCountSettings }>('/settings/stock-counts', { accounts_emails: emails })).data.data,
    onSuccess: (saved) => {
      qc.setQueryData(['settings', 'stock-counts'], saved)
      setDraft(null)
      toast.success('Accounts address saved.')
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const value = draft ?? data?.accounts_emails.join(', ') ?? ''
  const emails = value.split(/[\s,;]+/).map((e) => e.trim()).filter(Boolean)

  return (
    <Card className="mb-6">
      <CardHeader
        title="Accounts department"
        subtitle="The variance report and the signed sheet are emailed here when a count is signed off."
      />
      <CardBody>
        {isLoading ? <LoadingState /> : error ? <ErrorState message={apiError(error)} /> : (
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div className="flex-1">
              <Field
                label="Email address(es)"
                hint={emails.length === 0 && data?.fallback_email
                  ? `Not set — reports go to ${data.fallback_email} until it is.`
                  : 'Separate several addresses with commas.'}
              >
                <Input
                  inputMode="email"
                  value={value}
                  placeholder="accounts@surgicaldevices.co.za"
                  onChange={(e) => setDraft(e.target.value)}
                />
              </Field>
            </div>
            <Button loading={save.isPending} disabled={draft === null} onClick={() => save.mutate(emails)}>
              <Save className="h-4 w-4" /> Save
            </Button>
          </div>
        )}
      </CardBody>
    </Card>
  )
}

function TemplatesCard() {
  const toast = useToast()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<SupplierLabelTemplate | 'new' | null>(null)

  const { data: templates = [], isLoading, error } = useQuery({
    queryKey: ['label-templates', 'all'],
    queryFn: async () =>
      (await api.get<{ data: SupplierLabelTemplate[] }>('/label-templates', { params: { include_inactive: 1 } })).data.data,
  })

  const remove = useMutation({
    mutationFn: async (id: number) => api.delete(`/label-templates/${id}`),
    onSuccess: () => {
      toast.success('Template deleted.')
      void qc.invalidateQueries({ queryKey: ['label-templates'] })
    },
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Card>
      <CardHeader
        title="Supplier label templates"
        subtitle="Each supplier prints its labels differently. A template says which barcode field holds which value, and gives the photo reader hints."
        action={
          <Button size="sm" onClick={() => setEditing('new')}>
            <Plus className="h-4 w-4" /> New template
          </Button>
        }
      />
      <CardBody className="p-0">
        {isLoading ? <LoadingState /> : error ? <ErrorState message={apiError(error)} /> : templates.length === 0 ? (
          <p className="px-5 py-8 text-center text-sm text-slate-400">
            No templates yet — standard GS1 barcodes are read without one.
          </p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {templates.map((t) => (
              <li key={t.id} className="flex items-start justify-between gap-3 px-5 py-3">
                <div className="min-w-0 text-sm">
                  <p className="font-medium text-slate-800">
                    {t.supplier} <span className="font-normal text-slate-500">· {t.name}</span>
                    {!t.is_active && <Badge className="ml-2">Inactive</Badge>}
                  </p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {describeMappings(t.field_mappings)}
                    {t.ocr_hints && ' · photo hints'}
                  </p>
                </div>
                <div className="flex shrink-0 gap-1">
                  <Button variant="ghost" size="sm" aria-label={`Edit ${t.name}`} onClick={() => setEditing(t)}>
                    <Pencil className="h-4 w-4" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Delete ${t.name}`}
                    onClick={() => { if (window.confirm(`Delete the ${t.supplier} template “${t.name}”?`)) remove.mutate(t.id) }}
                  >
                    <Trash2 className="h-4 w-4 text-red-600" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardBody>

      {editing && (
        <TemplateModal
          template={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            void qc.invalidateQueries({ queryKey: ['label-templates'] })
          }}
        />
      )}
    </Card>
  )
}

function describeMappings(m?: Record<string, LabelFieldMapping> | null): string {
  const entries = Object.entries(m ?? {})
  if (entries.length === 0) return 'Standard GS1 reading'
  return entries
    .map(([field, map]) => `${FIELDS.find((f) => f.key === field)?.label ?? field} ← ${map.source === 'raw' ? 'text' : `(${map.ai})`}`)
    .join(', ')
}

type MappingDraft = { source: '' | 'ai' | 'raw'; ai: string; pattern: string; date_format: string }

function toDraft(m?: LabelFieldMapping): MappingDraft {
  if (!m) return { source: '', ai: '', pattern: '', date_format: '' }
  return { source: m.source === 'raw' ? 'raw' : 'ai', ai: m.ai ?? '', pattern: m.pattern ?? '', date_format: m.date_format ?? '' }
}

function TemplateModal({ template, onClose, onSaved }: {
  template: SupplierLabelTemplate | null
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const [form, setForm] = useState({
    supplier: template?.supplier ?? '',
    name: template?.name ?? '',
    barcode_type: template?.barcode_type ?? '',
    match_pattern: template?.match_pattern ?? '',
    ocr_hints: template?.ocr_hints ?? '',
    is_active: template?.is_active ?? true,
  })
  const [mappings, setMappings] = useState<Record<string, MappingDraft>>(() =>
    Object.fromEntries(FIELDS.map((f) => [f.key, toDraft(template?.field_mappings?.[f.key])])))
  const [testBarcode, setTestBarcode] = useState('')
  const [testResult, setTestResult] = useState<{ extracted: ScanExtraction; pattern_matches: boolean | null; stock_item: StockItem | null } | null>(null)

  const payload = () => {
    const field_mappings: Record<string, LabelFieldMapping> = {}
    for (const [field, m] of Object.entries(mappings)) {
      if (!m.source) continue
      field_mappings[field] = {
        ...(m.source === 'raw' ? { source: 'raw' as const } : { ai: m.ai.trim() }),
        ...(m.pattern.trim() ? { pattern: m.pattern.trim() } : {}),
        ...(field === 'expiry_date' && m.date_format ? { date_format: m.date_format } : {}),
      }
    }
    return {
      supplier: form.supplier.trim().toUpperCase(),
      name: form.name.trim(),
      barcode_type: form.barcode_type || null,
      match_pattern: form.match_pattern.trim() || null,
      field_mappings,
      ocr_hints: form.ocr_hints.trim() || null,
      is_active: form.is_active,
    }
  }

  const save = useMutation({
    mutationFn: async () => template
      ? api.put(`/label-templates/${template.id}`, payload())
      : api.post('/label-templates', payload()),
    onSuccess: () => {
      toast.success('Template saved.')
      onSaved()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const test = useMutation({
    mutationFn: async () =>
      (await api.post('/label-templates/test', { barcode: testBarcode, template: payload() })).data,
    onSuccess: (data) => setTestResult(data),
    onError: (err) => { setTestResult(null); toast.error(apiError(err)) },
  })

  const setMap = (field: string, patch: Partial<MappingDraft>) =>
    setMappings((prev) => ({ ...prev, [field]: { ...prev[field], ...patch } }))

  return (
    <Modal open onClose={onClose} title={template ? 'Edit label template' : 'New label template'} size="xl">
      <form className="space-y-5" onSubmit={(e) => { e.preventDefault(); save.mutate() }}>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field label="Supplier" required hint="Matches the supplier on stock items.">
            <Input value={form.supplier} onChange={(e) => setForm({ ...form, supplier: e.target.value })} required />
          </Field>
          <Field label="Name" required>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </Field>
          <Field label="Barcode type">
            <Select value={form.barcode_type} onChange={(e) => setForm({ ...form, barcode_type: e.target.value as typeof form.barcode_type })}>
              <option value="">—</option>
              <option value="gs1">GS1 (DataMatrix / GS1-128)</option>
              <option value="code128">Code 128</option>
              <option value="datamatrix">DataMatrix</option>
              <option value="none">No barcode (photo only)</option>
            </Select>
          </Field>
        </div>

        <Field
          label="Recognise by (pattern)"
          hint="A regular expression tested against the barcode text. Leave blank to use this template only when chosen in the scanner."
        >
          <Input
            className="font-mono text-xs"
            value={form.match_pattern}
            placeholder="^\(00\)6936594"
            onChange={(e) => setForm({ ...form, match_pattern: e.target.value })}
          />
        </Field>

        <div>
          <p className="mb-1 text-sm font-medium text-slate-700">Field mappings</p>
          <p className="mb-2 text-xs text-slate-500">
            Fields left on “Standard” use the normal GS1 reading. A pattern keeps its first bracketed group.
          </p>
          <div className="overflow-x-auto rounded-lg border border-slate-200">
            <table className="w-full min-w-[640px] text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-3 py-2">Field</th>
                  <th className="px-3 py-2">Read from</th>
                  <th className="px-3 py-2">GS1 AI</th>
                  <th className="px-3 py-2">Pattern</th>
                  <th className="px-3 py-2">Date format</th>
                </tr>
              </thead>
              <tbody>
                {FIELDS.map((f) => {
                  const m = mappings[f.key]
                  return (
                    <tr key={f.key} className="border-t border-slate-100">
                      <td className="px-3 py-2 font-medium text-slate-700">{f.label}</td>
                      <td className="px-3 py-2">
                        <Select
                          aria-label={`${f.label} source`}
                          value={m.source}
                          onChange={(e) => setMap(f.key, { source: e.target.value as MappingDraft['source'] })}
                        >
                          <option value="">Standard</option>
                          <option value="ai">GS1 element</option>
                          <option value="raw">Whole barcode text</option>
                        </Select>
                      </td>
                      <td className="px-3 py-2">
                        <Input
                          aria-label={`${f.label} AI`}
                          className="w-20 font-mono text-xs"
                          disabled={m.source !== 'ai'}
                          value={m.ai}
                          placeholder="10"
                          onChange={(e) => setMap(f.key, { ai: e.target.value })}
                        />
                      </td>
                      <td className="px-3 py-2">
                        <Input
                          aria-label={`${f.label} pattern`}
                          className="font-mono text-xs"
                          disabled={!m.source}
                          value={m.pattern}
                          placeholder="^(.+?)\d{4}$"
                          onChange={(e) => setMap(f.key, { pattern: e.target.value })}
                        />
                      </td>
                      <td className="px-3 py-2">
                        {f.key === 'expiry_date' ? (
                          <Select
                            aria-label="Expiry date format"
                            disabled={!m.source}
                            value={m.date_format}
                            onChange={(e) => setMap(f.key, { date_format: e.target.value })}
                          >
                            <option value="">Default</option>
                            {DATE_FORMATS.map((d) => <option key={d} value={d}>{d}</option>)}
                          </Select>
                        ) : <span className="text-slate-300">—</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>

        <Field label="Photo reader hints" hint="Plain words for labels read from a photo, e.g. which date is the expiry.">
          <Textarea rows={3} value={form.ocr_hints} onChange={(e) => setForm({ ...form, ocr_hints: e.target.value })} />
        </Field>

        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
          />
          Active
        </label>

        <div className="rounded-lg border border-dashed border-slate-300 p-3">
          <p className="mb-2 text-sm font-medium text-slate-700">Try it on a barcode</p>
          <div className="flex flex-col gap-2 sm:flex-row">
            <Input
              className="font-mono text-xs"
              value={testBarcode}
              placeholder="(11)260206(21)HSDS2302020062"
              onChange={(e) => setTestBarcode(e.target.value)}
            />
            <Button type="button" variant="outline" loading={test.isPending} disabled={!testBarcode.trim()} onClick={() => test.mutate()}>
              <FlaskConical className="h-4 w-4" /> Test
            </Button>
          </div>
          {testResult && (
            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
              {FIELDS.map((f) => (
                <div key={f.key}>
                  <dt className="text-slate-500">{f.label}</dt>
                  <dd className="font-mono text-slate-800">{testResult.extracted[f.key] ?? '—'}</dd>
                </div>
              ))}
              <div>
                <dt className="text-slate-500">Pattern recognises it</dt>
                <dd className="text-slate-800">{testResult.pattern_matches === null ? '—' : testResult.pattern_matches ? 'Yes' : 'No'}</dd>
              </div>
              <div className="col-span-2 sm:col-span-3">
                <dt className="text-slate-500">Catalogue match</dt>
                <dd className="text-slate-800">{testResult.stock_item ? testResult.stock_item.name : 'No stock item found'}</dd>
              </div>
            </dl>
          )}
        </div>

        <div className="flex justify-end gap-3">
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="submit" loading={save.isPending}>
            <Save className="h-4 w-4" /> Save template
          </Button>
        </div>
      </form>
    </Modal>
  )
}
