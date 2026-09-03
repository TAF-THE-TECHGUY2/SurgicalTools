import { api } from '@/lib/api'
import { db, uuid } from '@/offline/db'
import type { QueuedOperation } from '@/offline/db'

/**
 * Add an operation to the offline queue.
 *
 * `blob` attaches a label photo, which cannot travel in the JSON payload — it
 * is stored alongside and uploaded after the operation lands. Pass the
 * client_id through in the payload so the server can deduplicate a replay.
 */
export async function enqueue(
  type: QueuedOperation['type'],
  payload: Record<string, unknown>,
  label: string,
  blob?: { data: Blob; mime: string },
): Promise<string> {
  const clientId = uuid()
  const op: QueuedOperation = {
    client_id: clientId,
    type,
    payload: { ...payload, client_id: clientId },
    status: 'pending',
    label,
    created_at: Date.now(),
  }
  await db.syncQueue.add(op)

  if (blob) {
    await db.scanBlobs.put({
      client_id: clientId,
      blob: blob.data,
      mime: blob.mime,
      created_at: Date.now(),
    })
  }

  return clientId
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
        // A queued label photo is uploaded once its operation exists server-side.
        if (result.server_id) await uploadPendingBlob(result.client_id, result.server_id)
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

/**
 * Upload the label photo held for an operation that has now synced. The scan
 * row already exists — this only attaches its evidence image, so a failure is
 * logged and the blob dropped rather than blocking the queue: the count itself
 * is correct without the photo.
 */
async function uploadPendingBlob(clientId: string, scanId: number): Promise<void> {
  const held = await db.scanBlobs.get(clientId)
  if (!held) return

  try {
    const form = new FormData()
    form.append('photo', held.blob, `scan-${clientId}.jpg`)
    await api.post(`/stock-count-scans/${scanId}/image`, form)
  } catch {
    // Evidence photo is best-effort; the scan and its match already synced.
  } finally {
    await db.scanBlobs.delete(clientId)
  }
}

interface SyncResult {
  client_id: string
  status: 'applied' | 'duplicate' | 'error'
  server_id?: number
  message?: string
}
