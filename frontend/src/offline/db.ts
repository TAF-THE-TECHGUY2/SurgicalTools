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
    | 'transfer.source_to_boot'
    | 'transfer.boot_to_hospital'
    | 'transfer.sign'
    | 'stock_count.submit'
  payload: Record<string, unknown>
  status: 'pending' | 'syncing' | 'synced' | 'error'
  label: string
  error?: string
  created_at: number
}

class SurgicalDB extends Dexie {
  syncQueue!: Table<QueuedOperation, string>

  constructor() {
    super('surgical_erp')
    this.version(1).stores({
      syncQueue: 'client_id, status, type, created_at',
    })
  }
}

export const db = new SurgicalDB()

export function uuid(): string {
  return crypto.randomUUID()
}
