import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MapPin, Plus } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useAuth } from '@/auth/AuthContext'
import { useMeta } from '@/hooks/useMeta'
import { Can } from '@/auth/Can'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Field, Input, Select } from '@/components/ui/Field'
import { Badge } from '@/components/ui/Badge'
import { Modal } from '@/components/ui/Modal'
import { DataTable } from '@/components/ui/Table'
import type { Column } from '@/components/ui/Table'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { humanize } from '@/lib/format'
import type { Hospital, LocationEntity, Paginated, User } from '@/types'

export default function LocationsPage() {
  const { hasPermission } = useAuth()
  const canManage = hasPermission('location.manage')

  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<LocationEntity | null>(null)

  const { data, isLoading, error } = useQuery({
    queryKey: ['locations', 'all'],
    queryFn: async () =>
      (await api.get<{ data: LocationEntity[] }>('/locations', { params: { include_inactive: 1 } })).data.data,
  })

  const columns: Column<LocationEntity>[] = [
    { key: 'name', header: 'Name', render: (l) => <span className="font-medium text-slate-800">{l.name}</span> },
    { key: 'type', header: 'Type', render: (l) => <Badge tone="teal">{humanize(l.type)}</Badge> },
    { key: 'owner', header: 'Owner', render: (l) => l.owner?.name ?? '—' },
    { key: 'hospital', header: 'Linked hospital', render: (l) => l.hospital?.name ?? '—' },
    { key: 'units', header: 'Units', render: (l) => l.units_count ?? 0 },
    {
      key: 'active',
      header: 'Active',
      render: (l) => <Badge tone={l.is_active ? 'green' : 'gray'}>{l.is_active ? 'Active' : 'Inactive'}</Badge>,
    },
  ]

  return (
    <>
      <PageHeader
        title="Locations"
        description="The entities stock can sit at — hospitals, rep boots and offices."
        actions={
          <Can permission="location.manage">
            <Button onClick={() => { setEditing(null); setModalOpen(true) }}>
              <Plus className="h-4 w-4" /> Add location
            </Button>
          </Can>
        }
      />

      {isLoading ? (
        <LoadingState label="Loading locations…" />
      ) : error ? (
        <ErrorState message={apiError(error)} />
      ) : !data || data.length === 0 ? (
        <EmptyState
          icon={<MapPin className="h-10 w-10" />}
          title="No locations yet"
          description="Add a hospital, boot or office to start tracking stock."
        />
      ) : (
        <Card>
          <DataTable
            columns={columns}
            rows={data}
            rowKey={(l) => l.id}
            onRowClick={canManage ? (l) => { setEditing(l); setModalOpen(true) } : undefined}
          />
        </Card>
      )}

      <LocationFormModal
        open={modalOpen}
        location={editing}
        onClose={() => setModalOpen(false)}
      />
    </>
  )
}

/* ---------------------------------------------------------------------- */
/*  Create / edit modal                                                    */
/* ---------------------------------------------------------------------- */

interface LocationForm {
  name: string
  code: string
  type: string
  owner_user_id: string
  hospital_id: string
  is_active: boolean
}

const emptyForm: LocationForm = {
  name: '',
  code: '',
  type: '',
  owner_user_id: '',
  hospital_id: '',
  is_active: true,
}

function formFrom(location: LocationEntity | null): LocationForm {
  if (!location) return emptyForm
  return {
    name: location.name,
    code: location.code ?? '',
    type: location.type,
    owner_user_id: location.owner ? String(location.owner.id) : '',
    hospital_id: location.hospital_id != null ? String(location.hospital_id) : '',
    is_active: location.is_active,
  }
}

function LocationFormModal({ open, location, onClose }: {
  open: boolean
  location: LocationEntity | null
  onClose: () => void
}) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const { data: meta } = useMeta()

  const [form, setForm] = useState<LocationForm>(emptyForm)

  // Re-seed the form whenever the modal opens (create or a different record).
  useEffect(() => {
    if (open) setForm(formFrom(location))
  }, [open, location])

  const { data: users } = useQuery({
    queryKey: ['users', 'options'],
    queryFn: async (): Promise<User[]> => {
      try {
        return (await api.get<Paginated<User>>('/users')).data.data
      } catch {
        return []
      }
    },
    enabled: open,
  })

  const { data: hospitals } = useQuery({
    queryKey: ['hospitals', 'options'],
    queryFn: async (): Promise<Hospital[]> => {
      try {
        return (await api.get<Paginated<Hospital>>('/hospitals')).data.data
      } catch {
        return []
      }
    },
    enabled: open,
  })

  const set = (key: keyof LocationForm) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }))

  const save = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = {
        name: form.name,
        code: form.code || null,
        type: form.type,
        owner_user_id: form.owner_user_id ? Number(form.owner_user_id) : null,
        hospital_id: form.type === 'hospital' && form.hospital_id ? Number(form.hospital_id) : null,
        is_active: form.is_active,
      }
      if (location) return (await api.put(`/locations/${location.id}`, payload)).data
      return (await api.post('/locations', payload)).data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['locations'] })
      toast.success(location ? 'Location updated.' : 'Location created.')
      onClose()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const archive = useMutation({
    mutationFn: async () => {
      if (!location) return
      await api.delete(`/locations/${location.id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['locations'] })
      toast.success('Location archived.')
      onClose()
    },
    onError: (err) => toast.error(apiError(err)),
  })

  return (
    <Modal open={open} onClose={onClose} title={location ? 'Edit location' : 'Add location'} size="lg">
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Name" required>
          <Input value={form.name} onChange={set('name')} required />
        </Field>
        <Field label="Code">
          <Input value={form.code} onChange={set('code')} />
        </Field>
        <Field label="Type" required>
          <Select value={form.type} onChange={set('type')} required>
            <option value="">Select…</option>
            {meta?.location_types.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>
        </Field>
        <Field label="Owner" hint="The rep or staff member responsible for this stock.">
          <Select value={form.owner_user_id} onChange={set('owner_user_id')}>
            <option value="">No owner</option>
            {users?.map((u) => (
              <option key={u.id} value={u.id}>{u.name}</option>
            ))}
          </Select>
        </Field>
        {form.type === 'hospital' && (
          <Field label="Linked hospital">
            <Select value={form.hospital_id} onChange={set('hospital_id')}>
              <option value="">None</option>
              {hospitals?.map((h) => (
                <option key={h.id} value={h.id}>{h.name}</option>
              ))}
            </Select>
          </Field>
        )}
        <label className="flex items-center gap-2 self-end pb-2 text-sm font-medium text-slate-700">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
            checked={form.is_active}
            onChange={(e) => setForm((prev) => ({ ...prev, is_active: e.target.checked }))}
          />
          Active
        </label>
        <div className="flex items-center justify-between gap-2 sm:col-span-2">
          <div>
            {location && (
              <Button
                type="button"
                variant="danger"
                loading={archive.isPending}
                onClick={() => archive.mutate()}
              >
                Archive
              </Button>
            )}
          </div>
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            <Button type="submit" loading={save.isPending}>
              {location ? 'Save changes' : 'Create location'}
            </Button>
          </div>
        </div>
      </form>
    </Modal>
  )
}
