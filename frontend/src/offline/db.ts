import Dexie from 'dexie'
import type { Table } from 'dexie'

/**
 * Operations captured while offline (transfers, signatures, stock counts).
 * Replayed to POST /api/sync/push when connectivity returns. Each row carries
 * a client_id for server-side idempotency.
 */
export interface QueuedOperation {
  client_id: string
  type:
    | 'transfer.request'
    | 'stock_count.submit'
    | 'stock_count.scan'
  payload: Record<string, unknown>
  status: 'pending' | 'syncing' | 'synced' | 'error'
  label: string
  error?: string
  created_at: number
}

/**
 * Label photos captured offline. The sync endpoint takes a JSON payload, so a
 * Blob cannot ride along inside `QueuedOperation.payload` — it is stored here
 * against the same client_id and uploaded separately once the operation lands.
 *
 * Barcode scans are plain text and need no row here, which is the common case:
 * a photo is only taken when no barcode could be decoded.
 */
export interface QueuedScanBlob {
  client_id: string
  blob: Blob
  mime: string
  created_at: number
}

class SurgicalDB extends Dexie {
  syncQueue!: Table<QueuedOperation, string>

  scanBlobs!: Table<QueuedScanBlob, string>

  constructor() {
    super('surgical_erp')
    this.version(1).stores({
      syncQueue: 'client_id, status, type, created_at',
    })
    // v2 adds the offline label-photo store.
    this.version(2).stores({
      syncQueue: 'client_id, status, type, created_at',
      scanBlobs: 'client_id, created_at',
    })
  }
}

export const db = new SurgicalDB()

export function uuid(): string {
  return crypto.randomUUID()
}
