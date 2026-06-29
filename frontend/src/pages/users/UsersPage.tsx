import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card, CardBody } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { Modal } from '@/components/ui/Modal'
import { LoadingState, ErrorState } from '@/components/ui/States'
import { humanize } from '@/lib/format'
import type { Paginated, User } from '@/types'

interface RoleOption { id: number; name: string }

const emptyForm = {
  name: '', email: '', password: '', phone: '', region: '', staff_type: '', role: '', is_active: true,
}

export default function UsersPage() {
  const qc = useQueryClient()
  const toast = useToast()
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ ...emptyForm })

  const { data, isLoading, error } = useQuery({
    queryKey: ['users', q],
    queryFn: async () => (await api.get<Paginated<User>>('/users', { params: { q: q || undefined } })).data,
  })

  const { data: roles } = useQuery({
    queryKey: ['users', 'roles'],
    queryFn: async () => (await api.get<RoleOption[]>('/users/roles')).data,
  })

  const create = useMutation({
    mutationFn: async () => (await api.post('/users', form)).data,
    onSuccess: () => {
      toast.success('User created.')
      setOpen(false)
      setForm({ ...emptyForm })
      void qc.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(apiError(e)),
  })

  const columns: Column<User>[] = [
    { key: 'name', header: 'Name', render: (u) => <span className="font-medium text-slate-800">{u.name}</span> },
    { key: 'email', header: 'Email', render: (u) => u.email },
    { key: 'role', header: 'Role', render: (u) => <Badge tone="teal">{humanize(u.roles?.[0] ?? '—')}</Badge> },
    { key: 'staff', header: 'Staff type', render: (u) => humanize(u.staff_type) },
    { key: 'active', header: 'Active', render: (u) => <Badge tone={u.is_active ? 'green' : 'gray'}>{u.is_active ? 'Active' : 'Inactive'}</Badge> },
    { key: 'hospitals', header: 'Hospitals', render: (u) => u.hospitals?.length ?? 0 },
  ]

  return (
    <>
      <PageHeader
        title="Users & Roles"
        description="Manage login accounts, roles and hospital assignments."
        actions={<Button onClick={() => setOpen(true)}><Plus className="h-4 w-4" /> Add user</Button>}
      />

      <Card className="mb-4">
        <CardBody>
          <Input placeholder="Search by name or email…" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-sm" />
        </CardBody>
      </Card>

      <Card>
        <CardBody className="p-0">
          {isLoading ? (
            <LoadingState />
          ) : error ? (
            <div className="p-5"><ErrorState message={apiError(error)} /></div>
          ) : (
            <DataTable columns={columns} rows={data?.data ?? []} rowKey={(u) => u.id} empty="No users found." />
          )}
        </CardBody>
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="Add user">
        <form
          className="space-y-4"
          onSubmit={(e) => { e.preventDefault(); create.mutate() }}
        >
          <Field label="Name" required><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></Field>
          <Field label="Email" required><Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /></Field>
          <Field label="Password" required hint="Minimum 8 characters."><Input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Phone"><Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
            <Field label="Region"><Input value={form.region} onChange={(e) => setForm({ ...form, region: e.target.value })} /></Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Staff type">
              <Select value={form.staff_type} onChange={(e) => setForm({ ...form, staff_type: e.target.value })}>
                <option value="">—</option>
                <option value="rep">Rep</option>
                <option value="runner">Runner</option>
                <option value="office">Office</option>
                <option value="controller">Controller</option>
              </Select>
            </Field>
            <Field label="Role" required>
              <Select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} required>
                <option value="">Select role…</option>
                {roles?.map((r) => <option key={r.id} value={r.name}>{humanize(r.name)}</option>)}
              </Select>
            </Field>
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
            Active account
          </label>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button>
            <Button type="submit" loading={create.isPending}>Create user</Button>
          </div>
        </form>
      </Modal>
    </>
  )
}
