import { api } from '@/lib/api'
import { db, uuid } from '@/offline/db'
import type { QueuedOperation } from '@/offline/db'

/** Add an operation to the offline queue. */
export async function enqueue(
  type: QueuedOperation['type'],
  payload: Record<string, unknown>,
  label: string,
): Promise<string> {
  const op: QueuedOperation = {
    client_id: uuid(),
    type,
    payload,
    status: 'pending',
    label,
    created_at: Date.now(),
  }
  await db.syncQueue.add(op)
  return op.client_id
}

export async function pendingCount(): Promise<number> {
  return db.syncQueue.where('status').anyOf('pending', 'error').count()
}

/**
 * Flush all pending/error operations to the server. Returns the number of
 * operations successfully synced. Safe to call repeatedly (idempotent server-side).
 */
export async function flushQueue(): Promise<number> {
  const pending = await db.syncQueue.where('status').anyOf('pending', 'error').toArray()
  if (pending.length === 0) return 0

  await db.syncQueue.bulkPut(pending.map((p) => ({ ...p, status: 'syncing' as const })))

  try {
    const { data } = await api.post<{ results: SyncResult[] }>('/sync/push', {
      operations: pending.map((p) => ({ client_id: p.client_id, type: p.type, payload: p.payload })),
    })

    let synced = 0
    for (const result of data.results) {
      if (result.status === 'applied' || result.status === 'duplicate') {
        await db.syncQueue.delete(result.client_id)
        synced++
      } else {
        await db.syncQueue.update(result.client_id, { status: 'error', error: result.message })
      }
    }
    return synced
  } catch (e) {
    // Network failed mid-flush — reset to pending so we retry later.
    await db.syncQueue.bulkPut(pending.map((p) => ({ ...p, status: 'pending' as const })))
    throw e
  }
}

interface SyncResult {
  client_id: string
  status: 'applied' | 'duplicate' | 'error'
  server_id?: number
  message?: string
}
