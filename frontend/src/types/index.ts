// Domain types mirroring the Laravel API resources.

export type Role = 'super_admin' | 'admin' | 'general_user'

export interface User {
  id: number
  name: string
  email: string
  phone?: string | null
  region?: string | null
  staff_type?: string | null
  location_id?: number | null
  location?: LocationEntity | null
  is_active: boolean
  roles?: Role[]
  permissions?: string[]
  hospitals?: Hospital[]
  created_at?: string
}

/** A stock "entity": hospital, rep boot, or office — the From/To of transfers. */
export interface LocationEntity {
  id: number
  name: string
  code?: string | null
  type: 'hospital' | 'boot' | 'office' | 'warehouse' | 'other'
  hospital_id?: number | null
  hospital?: Hospital | null
  owner?: User | null
  is_active: boolean
  units_count?: number
}

/** Catalog entry (Trochar, Guide Wire…). Physical stock = DeviceUnit rows. */
export interface StockItem {
  id: number
  name: string
  catalogue_number?: string | null
  item_code?: string | null
  description?: string | null
  uom?: string | null
  unit_price?: string | number | null
  min_threshold?: number | null
  is_active: boolean
  units_count?: number
  units?: DeviceUnit[]
  deleted_at?: string | null
}

export type DeviceUnitStatus =
  | 'available' | 'pending_transfer' | 'missing' | 'used' | 'expired' | 'archived'

/** One physical device: serial / lot / expiry at a location. */
export interface DeviceUnit {
  id: number
  stock_item_id: number
  serial_number?: string | null
  lot_number?: string | null
  expiry_date?: string | null
  days_to_expiry?: number | null
  status: DeviceUnitStatus
  location_id: number
  location?: LocationEntity | null
  stock_item?: StockItem | null
}

/** Grouped inventory row at a location: an item with its expandable units. */
export interface GroupedStockRow {
  stock_item_id: number
  name: string
  catalogue_number?: string | null
  item_code?: string | null
  quantity: number
  available: number
  pending_out: number
  units: DeviceUnit[]
}

export interface LocationInventoryResponse {
  location: LocationEntity | null
  items: GroupedStockRow[]
  message?: string
}

/** Item search across locations: where are all the Trochars? */
export interface ItemSearchRow {
  stock_item_id: number
  name: string
  catalogue_number?: string | null
  item_code?: string | null
  total: number
  locations: { location_id: number; name: string; type: string; quantity: number }[]
}

export interface Hospital {
  id: number
  name: string
  code?: string | null
  category: string
  region?: string | null
  address?: string | null
  city?: string | null
  province?: string | null
  phone?: string | null
  email?: string | null
  is_active: boolean
  assigned_rep?: User | null
  assigned_runner?: User | null
  contacts?: HospitalContact[]
  doctors?: Doctor[]
  users?: User[]
  inventory_count?: number
}

export interface HospitalContact {
  id: number
  name: string
  role?: string | null
  email?: string | null
  phone?: string | null
  is_primary: boolean
}

export interface Doctor {
  id: number
  name: string
  age?: number | null
  specialty?: string | null
  operating_days?: string[] | null
  equipment_used?: string[] | null
  procedure_preferences?: string | null
  notes?: string | null
  phone?: string | null
  email?: string | null
  is_active: boolean
  hospitals?: Hospital[]
  preference_cards?: PreferenceCard[]
}

export interface PreferenceCard {
  id: number
  doctor_id: number
  procedure_name: string
  notes?: string | null
  preferred_sizes?: Record<string, string> | null
  is_active: boolean
  doctor?: Doctor
  items?: PreferenceCardItem[]
}

export interface PreferenceCardItem {
  id: number
  ref_code?: string | null
  description: string
  preferred_size?: string | null
  quantity: number
  notes?: string | null
}

export interface InventoryItem {
  id: number
  ref_code: string
  description: string
  lot_number?: string | null
  quantity: number
  expiry_date?: string | null
  days_to_expiry?: number | null
  stock_type: string
  location: string
  status: string
  is_low_stock: boolean
  min_threshold?: number | null
  unit_price?: string | number | null
  barcode?: string | null
  uom?: string | null
  hospital?: Hospital | null
  holder?: User | null
  movements?: StockMovement[]
}

export interface StockMovement {
  id: number
  ref_code: string
  lot_number?: string | null
  quantity: number
  movement_type: string
  from_location?: string | null
  to_location?: string | null
  notes?: string | null
  performed_by?: User | null
  moved_at?: string | null
}

export type TransferType = 'source_to_boot' | 'boot_to_hospital'
export type TransferStatus =
  | 'draft' | 'pending_approval' | 'approved' | 'awaiting_signature'
  | 'signed' | 'awaiting_admin_review' | 'completed' | 'rejected'

export interface TransferItem {
  id: number
  inventory_item_id?: number | null
  device_unit_id?: number | null
  serial_number?: string | null
  ref_code: string
  description?: string | null
  lot_number?: string | null
  quantity: number
  expiry_date?: string | null
  unit_price?: string | number | null
}

export interface TransferSignature {
  id: number
  signer_name: string
  signer_role?: string | null
  signed_at: string
  url?: string | null
}

export interface TransferDocument {
  id: number
  type: string
  original_name?: string | null
  url?: string | null
}

export interface Transfer {
  id: number
  reference: string
  type: TransferType
  type_label?: string
  status: TransferStatus
  from_location?: string | null
  to_location?: string | null
  from_location_id?: number | null
  to_location_id?: number | null
  from_location_entity?: LocationEntity | null
  to_location_entity?: LocationEntity | null
  hospital_stock_type?: string | null
  admin_override: boolean
  notes?: string | null
  hospital?: Hospital | null
  doctor?: Doctor | null
  requester?: User | null
  approver?: User | null
  reviewer?: User | null
  from_holder?: User | null
  to_holder?: User | null
  items?: TransferItem[]
  signatures?: TransferSignature[]
  documents?: TransferDocument[]
  approved_at?: string | null
  reviewed_at?: string | null
  rejected_at?: string | null
  rejection_reason?: string | null
  completed_at?: string | null
  created_at?: string
}

export type StockCountStatus =
  | 'requested' | 'in_progress' | 'submitted' | 'under_review' | 'approved' | 'investigating'

export interface StockCountItem {
  id: number
  ref_code: string
  description?: string | null
  lot_number?: string | null
  expected_quantity: number
  counted_quantity?: number | null
  variance?: number | null
  photo_url?: string | null
  notes?: string | null
}

export interface StockCount {
  id: number
  reference: string
  status: StockCountStatus
  location?: string | null
  notes?: string | null
  total_variance?: number
  hospital?: Hospital | null
  requester?: User | null
  assignee?: User | null
  items?: StockCountItem[]
  submitted_at?: string | null
  reviewed_at?: string | null
  created_at?: string
}

export interface AppNotification {
  id: string
  type: string
  read_at: string | null
  created_at: string
  data: {
    category?: string
    event?: string
    message?: string
    link?: string
    severity?: string
    [k: string]: unknown
  }
}

export interface Option {
  value: string
  label: string
}

export interface MetaOptions {
  device_unit_statuses: Option[]
  location_types: Option[]
  stock_types: Option[]
  hospital_stock_types: Option[]
  locations: Option[]
  statuses: Option[]
  transfer_types: Option[]
  transfer_statuses: Option[]
  stock_count_statuses: Option[]
  hospital_categories: Option[]
  doctor_specialties: Option[]
  expiry_windows: { warning: number; high: number; critical: number }
}

// Standard Laravel paginated response.
export interface Paginated<T> {
  data: T[]
  links?: unknown
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface DashboardData {
  inventory: {
    total_items: number
    total_units: number
    low_stock: number
    expiring_soon: number
    expiring_critical: number
  }
  transfers: {
    open: number
    pending_approval: number
    awaiting_admin_review: number
    completed_this_month: number
  }
  stock_counts: { open: number; submitted: number }
  hospitals: { total: number; assigned: number }
  my_inventory_units?: number | null
  stock_by_location?: { name: string; units: number }[]
  recent_transfers: Transfer[]
  recent_movements: StockMovement[]
}
