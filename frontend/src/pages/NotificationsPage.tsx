import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { Bell, CheckCheck } from 'lucide-react'
import { api, apiError } from '@/lib/api'
import { useToast } from '@/components/ToastProvider'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/States'
import { fromNow, humanize } from '@/lib/format'
import type { AppNotification } from '@/types'

interface NotificationsResponse {
  data: AppNotification[]
  unread_count: number
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export default function NotificationsPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const toast = useToast()
  const [page, setPage] = useState(1)

  const { data, isLoading, error } = useQuery({
    queryKey: ['notifications', page],
    queryFn: async () =>
      (await api.get<NotificationsResponse>('/notifications', { params: { page } })).data,
  })

  const markAll = useMutation({
    mutationFn: async () => (await api.post('/notifications/read-all')).data,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      toast.success('All notifications marked as read.')
    },
    onError: (err) => toast.error(apiError(err)),
  })

  const markRead = useMutation({
    mutationFn: async (id: string) => (await api.post(`/notifications/${id}/read`)).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['notifications'] }),
    onError: (err) => toast.error(apiError(err)),
  })

  const openNotification = (n: AppNotification) => {
    if (!n.read_at) markRead.mutate(n.id)
    if (n.data.link) navigate(n.data.link)
  }

  const pageMeta = data?.meta
  const currentPage = pageMeta?.current_page ?? page
  const lastPage = pageMeta?.last_page ?? 1

  return (
    <>
      <PageHeader
        title="Notifications"
        description="Stay on top of approvals, transfers and stock alerts."
        actions={
          <Button
            variant="outline"
            onClick={() => markAll.mutate()}
            loading={markAll.isPending}
            disabled={!data || data.unread_count === 0}
          >
            <CheckCheck className="h-4 w-4" /> Mark all read
          </Button>
        }
      />

      {isLoading ? (
        <LoadingState label="Loading notifications…" />
      ) : error ? (
        <ErrorState message={apiError(error)} />
      ) : !data || data.data.length === 0 ? (
        <EmptyState
          icon={<Bell className="h-10 w-10" />}
          title="No notifications"
          description="You're all caught up — new alerts will appear here."
        />
      ) : (
        <Card>
          <ul className="divide-y divide-slate-100">
            {data.data.map((n) => (
              <li key={n.id}>
                <button
                  type="button"
                  onClick={() => openNotification(n)}
                  className="flex w-full items-start gap-3 px-5 py-4 text-left transition-colors hover:bg-brand-50/40"
                >
                  <span className="mt-1.5 flex h-2.5 w-2.5 shrink-0 items-center justify-center">
                    {!n.read_at && <span className="h-2.5 w-2.5 rounded-full bg-brand-600" aria-label="Unread" />}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className={n.read_at ? 'text-sm text-slate-600' : 'text-sm font-medium text-slate-900'}>
                      {n.data.message ?? 'Notification'}
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                      {n.data.category && <Badge tone="teal">{humanize(n.data.category)}</Badge>}
                      <span className="text-xs text-slate-400">{fromNow(n.created_at)}</span>
                    </div>
                  </div>
                </button>
              </li>
            ))}
          </ul>
          <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm text-slate-500">
            <span>
              Page {currentPage} of {lastPage}
              {pageMeta ? ` · ${pageMeta.total} notifications` : ''}
            </span>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage <= 1}
                onClick={() => setPage(currentPage - 1)}
              >
                Prev
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage >= lastPage}
                onClick={() => setPage(currentPage + 1)}
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
