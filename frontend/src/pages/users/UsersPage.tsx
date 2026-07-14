import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, ShieldCheck } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
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
import type { Hospital, LocationEntity, Paginated, User } from '@/types'

interface PermissionOption { id: number; name: string }
interface RoleOption { id: number; name: string; permissions: PermissionOption[] }

type AssignmentRole = 'rep' | 'runner'
interface HospitalAssignment { checked: boolean; role: AssignmentRole }

const emptyForm = {
  name: '', email: '', password: '', phone: '', region: '', staff_type: '', role: '', is_active: true,
}

function hospitalRole(h: Hospital): string | null {
  return (h as { pivot?: { role?: string | null } }).pivot?.role ?? null
}

export default function UsersPage() {
  const qc = useQueryClient()
  const toast = useToast()
  const { user: currentUser } = useAuth()
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ ...emptyForm })
  const [createPermissions, setCreatePermissions] = useState<string[]>([])

  const [editing, setEditing] = useState<User | null>(null)
  const [editForm, setEditForm] = useState({ ...emptyForm })
  const [assignments, setAssignments] = useState<Record<number, HospitalAssignment>>({})
  const [editLocationId, setEditLocationId] = useState('')
  const [editPermissions, setEditPermissions] = useState<string[]>([])
  const [roleEditingId, setRoleEditingId] = useState('')
  const [rolePermissions, setRolePermissions] = useState<string[]>([])

  const { data, isLoading, error } = useQuery({
    queryKey: ['users', q],
    queryFn: async () => (await api.get<Paginated<User>>('/users', { params: { q: q || undefined } })).data,
  })

  const { data: roles } = useQuery({
    queryKey: ['users', 'roles'],
    queryFn: async () => (await api.get<RoleOption[]>('/users/roles')).data,
  })

  const { data: permissions = [] } = useQuery({
    queryKey: ['users', 'permissions'],
    queryFn: async () => (await api.get<string[]>('/users/permissions')).data,
  })

  const { data: locationOptions } = useQuery({
    queryKey: ['locations'],
    queryFn: async () => (await api.get<{ data: LocationEntity[] }>('/locations')).data.data,
  })

  const { data: hospitals } = useQuery({
    queryKey: ['hospitals'],
    queryFn: async () => (await api.get<Paginated<Hospital> | Hospital[]>('/hospitals')).data,
  })

  const hospitalList: Hospital[] = Array.isArray(hospitals) ? hospitals : hospitals?.data ?? []

  const create = useMutation({
    mutationFn: async () => (await api.post('/users', { ...form, permissions: createPermissions })).data,
    onSuccess: () => {
      toast.success('User created.')
      setOpen(false)
      setForm({ ...emptyForm })
      setCreatePermissions([])
      void qc.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(apiError(e)),
  })

  function openEdit(u: User) {
    setEditing(u)
    setEditForm({
      name: u.name ?? '',
      email: u.email ?? '',
      password: '',
      phone: u.phone ?? '',
      region: u.region ?? '',
      staff_type: u.staff_type ?? '',
      role: u.roles?.[0] ?? '',
      is_active: u.is_active,
    })
    setEditLocationId(u.location_id ? String(u.location_id) : '')
    setEditPermissions(u.permissions ?? [])
    const next: Record<number, HospitalAssignment> = {}
    for (const h of u.hospitals ?? []) {
      const role = hospitalRole(h)
      next[h.id] = { checked: true, role: role === 'runner' ? 'runner' : 'rep' }
    }
    setAssignments(next)
  }

  function closeEdit() {
    setEditing(null)
  }

  const update = useMutation({
    mutationFn: async () => {
      if (!editing) return
      const payload: Record<string, unknown> = {
        name: editForm.name,
        email: editForm.email,
        phone: editForm.phone,
        region: editForm.region,
        staff_type: editForm.staff_type,
        role: editForm.role,
        is_active: editForm.is_active,
        location_id: editLocationId ? Number(editLocationId) : null,
        permissions: editPermissions,
      }
      if (editForm.password) payload.password = editForm.password
      await api.put(`/users/${editing.id}`, payload)
      const selected = Object.entries(assignments)
        .filter(([, a]) => a.checked)
        .map(([hospitalId, a]) => ({ hospital_id: Number(hospitalId), role: a.role }))
      await api.post(`/users/${editing.id}/hospitals`, { assignments: selected })
    },
    onSuccess: () => {
      toast.success('User updated.')
      closeEdit()
      void qc.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(apiError(e)),
  })

  const deactivate = useMutation({
    mutationFn: async () => {
      if (!editing) return
      await api.delete(`/users/${editing.id}`)
    },
    onSuccess: () => {
      toast.success('User deactivated.')
      closeEdit()
      void qc.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(apiError(e)),
  })

  const saveRolePermissions = useMutation({
    mutationFn: async () => {
      if (!roleEditingId) return
      await api.put(`/users/roles/${roleEditingId}`, { permissions: rolePermissions })
    },
    onSuccess: () => {
      toast.success('Role permissions updated.')
      void qc.invalidateQueries({ queryKey: ['users', 'roles'] })
      void qc.invalidateQueries({ queryKey: ['users'] })
    },
    onError: (e) => toast.error(apiError(e)),
  })

  function permissionsForRole(roleName: string): string[] {
    return roles?.find((r) => r.name === roleName)?.permissions.map((p) => p.name) ?? []
  }

  function selectRoleForManagement(id: string) {
    setRoleEditingId(id)
    const role = roles?.find((r) => String(r.id) === id)
    setRolePermissions(role?.permissions.map((p) => p.name) ?? [])
  }

  function toggleAssignment(id: number, checked: boolean) {
    setAssignments((prev) => ({ ...prev, [id]: { role: prev[id]?.role ?? 'rep', checked } }))
  }

  function setAssignmentRole(id: number, role: AssignmentRole) {
    setAssignments((prev) => ({ ...prev, [id]: { checked: prev[id]?.checked ?? true, role } }))
  }

  const columns: Column<User>[] = [
    { key: 'name', header: 'Name', render: (u) => <span className="font-medium text-slate-800">{u.name}</span> },
    { key: 'email', header: 'Email', render: (u) => u.email },
    { key: 'role', header: 'Role', render: (u) => <Badge tone="teal">{humanize(u.roles?.[0] ?? '—')}</Badge> },
    { key: 'staff', header: 'Staff type', render: (u) => humanize(u.staff_type) },
    { key: 'active', header: 'Active', render: (u) => <Badge tone={u.is_active ? 'green' : 'gray'}>{u.is_active ? 'Active' : 'Inactive'}</Badge> },
    { key: 'hospitals', header: 'Hospitals', render: (u) => u.hospitals?.length ?? 0 },
  ]

  const isSelf = editing != null && currentUser?.id === editing.id

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

      <Card className="mb-4">
        <CardBody>
          <div className="mb-4 flex items-start gap-3">
            <ShieldCheck className="mt-0.5 h-5 w-5 text-brand-600" />
            <div>
              <p className="font-semibold text-slate-800">Role access</p>
              <p className="text-sm text-slate-500">Choose a role, then tick the actions everyone with that role may use.</p>
            </div>
          </div>
          <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <div className="w-full sm:max-w-xs">
              <Field label="Role">
                <Select value={roleEditingId} onChange={(e) => selectRoleForManagement(e.target.value)}>
                  <option value="">Select role…</option>
                  {roles?.map((r) => <option key={r.id} value={r.id}>{humanize(r.name)}</option>)}
                </Select>
              </Field>
            </div>
            <Button
              type="button"
              loading={saveRolePermissions.isPending}
              disabled={!roleEditingId || roles?.find((r) => String(r.id) === roleEditingId)?.name === 'super_admin'}
              onClick={() => saveRolePermissions.mutate()}
            >
              Save role access
            </Button>
          </div>
          {roleEditingId && (
            <PermissionChecklist
              permissions={permissions}
              selected={rolePermissions}
              onChange={setRolePermissions}
              disabled={roles?.find((r) => String(r.id) === roleEditingId)?.name === 'super_admin'}
            />
          )}
        </CardBody>
      </Card>

      <Card>
        <CardBody className="p-0">
          {isLoading ? (
            <LoadingState />
          ) : error ? (
            <div className="p-5"><ErrorState message={apiError(error)} /></div>
          ) : (
            <DataTable columns={columns} rows={data?.data ?? []} rowKey={(u) => u.id} onRowClick={openEdit} empty="No users found." />
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
              <Select value={form.role} onChange={(e) => {
                setForm({ ...form, role: e.target.value })
                setCreatePermissions(permissionsForRole(e.target.value))
              }} required>
                <option value="">Select role…</option>
                {roles?.map((r) => <option key={r.id} value={r.name}>{humanize(r.name)}</option>)}
              </Select>
            </Field>
          </div>
          <div>
            <p className="mb-1 text-sm font-medium text-slate-700">User access</p>
            <PermissionChecklist permissions={permissions} selected={createPermissions} onChange={setCreatePermissions} />
            <p className="mt-1 text-xs text-slate-400">These ticks are this user's exact access, independent of other users in the role.</p>
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

      <Modal open={editing != null} onClose={closeEdit} title="Edit user" size="lg">
        <form
          className="space-y-4"
          onSubmit={(e) => { e.preventDefault(); update.mutate() }}
        >
          <Field label="Name" required><Input value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} required /></Field>
          <Field label="Email" required><Input type="email" value={editForm.email} onChange={(e) => setEditForm({ ...editForm, email: e.target.value })} required /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Phone"><Input value={editForm.phone} onChange={(e) => setEditForm({ ...editForm, phone: e.target.value })} /></Field>
            <Field label="Region"><Input value={editForm.region} onChange={(e) => setEditForm({ ...editForm, region: e.target.value })} /></Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Staff type">
              <Select value={editForm.staff_type} onChange={(e) => setEditForm({ ...editForm, staff_type: e.target.value })}>
                <option value="">—</option>
                <option value="rep">Rep</option>
                <option value="runner">Runner</option>
                <option value="office">Office</option>
                <option value="controller">Controller</option>
              </Select>
            </Field>
            <Field label="Role" required>
              <Select value={editForm.role} onChange={(e) => {
                setEditForm({ ...editForm, role: e.target.value })
                setEditPermissions(permissionsForRole(e.target.value))
              }} required>
                <option value="">Select role…</option>
                {roles?.map((r) => <option key={r.id} value={r.name}>{humanize(r.name)}</option>)}
              </Select>
            </Field>
          </div>
          <div>
            <p className="mb-1 text-sm font-medium text-slate-700">User access</p>
            <PermissionChecklist permissions={permissions} selected={editPermissions} onChange={setEditPermissions} />
            <p className="mt-1 text-xs text-slate-400">Untick anything this user must not see or use. Tick only Inventory View and Transfer actions for an inventory-and-transfer-only user.</p>
          </div>
          <Field label="Linked location (My Inventory)" hint="The boot/office whose stock this user sees and owns.">
            <Select value={editLocationId} onChange={(e) => setEditLocationId(e.target.value)}>
              <option value="">— Not linked —</option>
              {locationOptions?.map((l) => (
                <option key={l.id} value={l.id}>{l.name}{l.owner ? ` — ${l.owner.name}` : ''}</option>
              ))}
            </Select>
          </Field>
          <Field label="Reset password" hint="Leave blank to keep the current password.">
            <Input type="password" value={editForm.password} onChange={(e) => setEditForm({ ...editForm, password: e.target.value })} autoComplete="new-password" />
          </Field>
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" checked={editForm.is_active} onChange={(e) => setEditForm({ ...editForm, is_active: e.target.checked })} />
            Active account
          </label>

          <div className="border-t border-slate-100 pt-4">
            <p className="mb-2 text-sm font-medium text-slate-700">Hospital assignments</p>
            {hospitalList.length === 0 ? (
              <p className="text-sm text-slate-400">No hospitals available.</p>
            ) : (
              <ul className="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                {hospitalList.map((h) => {
                  const a = assignments[h.id]
                  const checked = a?.checked ?? false
                  return (
                    <li key={h.id} className="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 hover:bg-slate-50">
                      <label className="flex flex-1 items-center gap-2 text-sm text-slate-700">
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={(e) => toggleAssignment(h.id, e.target.checked)}
                        />
                        {h.name}
                      </label>
                      {checked && (
                        <Select
                          className="w-32"
                          value={a?.role ?? 'rep'}
                          onChange={(e) => setAssignmentRole(h.id, e.target.value as AssignmentRole)}
                        >
                          <option value="rep">Rep</option>
                          <option value="runner">Runner</option>
                        </Select>
                      )}
                    </li>
                  )
                })}
              </ul>
            )}
          </div>

          <div className="flex items-center justify-between gap-2 pt-2">
            <div>
              {!isSelf && (
                <Button
                  type="button"
                  variant="danger"
                  loading={deactivate.isPending}
                  onClick={() => deactivate.mutate()}
                >
                  Deactivate
                </Button>
              )}
            </div>
            <div className="flex gap-2">
              <Button type="button" variant="secondary" onClick={closeEdit}>Cancel</Button>
              <Button type="submit" loading={update.isPending}>Save changes</Button>
            </div>
          </div>
        </form>
      </Modal>
    </>
  )
}

const permissionGroupLabels: Record<string, string> = {
  inventory: 'Inventory',
  transfer: 'Transfers',
  stock_count: 'Stock counts',
  hospital: 'Hospitals',
  doctor: 'Doctors & preference cards',
  location: 'Locations',
  report: 'Reports',
  pastel: 'Pastel export',
  user: 'Users',
  role: 'Roles',
  config: 'Configuration',
  audit: 'Audit log',
}

const permissionLabels: Record<string, string> = {
  'inventory.view': 'View My Inventory',
  'inventory.manage': 'Manage Stock Catalog',
  'transfer.view': 'View Transfers',
  'transfer.create': 'Create Transfers',
  'transfer.approve': 'Approve Transfers',
  'transfer.override': 'Override Transfers',
  'transfer.review': 'Final Transfer Review',
  'stock_count.capture': 'Capture Counts',
  'stock_count.review': 'Review Counts',
}

function PermissionChecklist({ permissions, selected, onChange, disabled = false }: {
  permissions: string[]
  selected: string[]
  onChange: (next: string[]) => void
  disabled?: boolean
}) {
  const grouped = permissions.reduce<Record<string, string[]>>((acc, permission) => {
    const group = permission.split('.').slice(0, -1).join('.')
    ;(acc[group] ??= []).push(permission)
    return acc
  }, {})

  function toggle(permission: string, checked: boolean) {
    onChange(checked
      ? [...new Set([...selected, permission])]
      : selected.filter((p) => p !== permission))
  }

  return (
    <div className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50/50 p-3 sm:grid-cols-2">
      {Object.entries(grouped).map(([group, items]) => (
        <fieldset key={group} className="rounded-md bg-white p-3 shadow-sm ring-1 ring-slate-100">
          <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
            {permissionGroupLabels[group] ?? humanize(group)}
          </legend>
          <div className="mt-1 space-y-2">
            {items.map((permission) => (
              <label key={permission} className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={selected.includes(permission)}
                  disabled={disabled}
                  onChange={(e) => toggle(permission, e.target.checked)}
                />
                {permissionLabels[permission] ?? humanize(permission.split('.').at(-1))}
              </label>
            ))}
          </div>
        </fieldset>
      ))}
    </div>
  )
}
