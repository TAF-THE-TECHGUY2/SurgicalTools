import { useEffect, useState } from 'react'
import { flushQueue, pendingCount } from '@/offline/syncQueue'

/**
 * Tracks connectivity and the offline queue depth. Automatically flushes
 * queued operations when the browser comes back online.
 */
export function useOnlineStatus() {
  const [online, setOnline] = useState(navigator.onLine)
  const [pending, setPending] = useState(0)
  const [syncing, setSyncing] = useState(false)

  const refresh = async () => setPending(await pendingCount())

  useEffect(() => {
    void refresh()
    const interval = setInterval(() => void refresh(), 5000)

    const goOnline = async () => {
      setOnline(true)
      setSyncing(true)
      try {
        await flushQueue()
      } catch {
        // stay queued; will retry
      } finally {
        setSyncing(false)
        void refresh()
      }
    }
    const goOffline = () => setOnline(false)

    window.addEventListener('online', goOnline)
    window.addEventListener('offline', goOffline)
    return () => {
      clearInterval(interval)
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
    }
  }, [])

  return { online, pending, syncing, refresh }
}
