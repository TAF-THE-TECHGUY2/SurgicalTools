import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Bell, CheckCheck } from 'lucide-react'
import { api } from '@/lib/api'
import { fromNow } from '@/lib/format'
import { useState, useRef, useEffect } from 'react'
import type { AppNotification } from '@/types'

export function NotificationBell() {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data } = useQuery({
    queryKey: ['notifications', 'recent'],
    queryFn: async () => {
      const res = await api.get<{ data: AppNotification[]; unread_count: number }>('/notifications', {
        params: { per_page: 8 },
      })
      return res.data
    },
    refetchInterval: 30_000,
  })

  // Refresh both the bell and the full notifications page after any read action.
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['notifications'] })

  const markRead = useMutation({
    mutationFn: async (id: string) => (await api.post(`/notifications/${id}/read`)).data,
    onSuccess: invalidate,
  })

  const markAll = useMutation({
    mutationFn: async () => (await api.post('/notifications/read-all')).data,
    onSuccess: invalidate,
  })

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [])

  const unread = data?.unread_count ?? 0
  const items = data?.data ?? []

  const openNotification = (n: AppNotification) => {
    setOpen(false)
    if (!n.read_at) markRead.mutate(n.id)
    if (n.data.link) navigate(n.data.link)
  }

  return (
    <div ref={ref} className="relative">
      <button
        onClick={() => setOpen((o) => !o)}
        className="relative rounded-lg p-2 text-slate-500 hover:bg-slate-100"
        aria-label="Notifications"
      >
        <Bell className="h-5 w-5" />
        {unread > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
          <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <span className="text-sm font-semibold text-slate-700">Notifications</span>
            <div className="flex items-center gap-3">
              {unread > 0 && (
                <button
                  onClick={() => markAll.mutate()}
                  className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700"
                >
                  <CheckCheck className="h-3.5 w-3.5" /> Mark all
                </button>
              )}
              <button onClick={() => { setOpen(false); navigate('/notifications') }} className="text-xs text-brand-700 hover:underline">
                View all
              </button>
            </div>
          </div>
          <div className="max-h-80 overflow-y-auto">
            {items.length === 0 ? (
              <div className="px-4 py-8 text-center text-sm text-slate-400">You're all caught up.</div>
            ) : (
              items.map((n) => (
                <button
                  key={n.id}
                  onClick={() => openNotification(n)}
                  className="block w-full border-b border-slate-50 px-4 py-3 text-left hover:bg-slate-50"
                >
                  <div className="flex items-start gap-2">
                    <span className="mt-1.5 flex h-2 w-2 shrink-0">
                      {!n.read_at && <span className="h-2 w-2 rounded-full bg-brand-500" aria-label="Unread" />}
                    </span>
                    <div className={n.read_at ? 'opacity-60' : ''}>
                      <p className="text-sm text-slate-700">{n.data.message ?? n.type}</p>
                      <p className="mt-0.5 text-xs text-slate-400">{fromNow(n.created_at)}</p>
                    </div>
                  </div>
                </button>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}
