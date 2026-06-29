import type { LucideIcon } from 'lucide-react'
import {
  LayoutDashboard, Boxes, ArrowLeftRight, ClipboardCheck, CheckSquare,
  Building2, Stethoscope, IdCard, BarChart3, FileSpreadsheet, Users, ScrollText, Bell,
} from 'lucide-react'

export interface NavItem {
  label: string
  to: string
  icon: LucideIcon
  /** Permission required to see the item (undefined = always visible). */
  permission?: string
}

export const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', to: '/', icon: LayoutDashboard },
  { label: 'Inventory', to: '/inventory', icon: Boxes, permission: 'inventory.view' },
  { label: 'Transfers', to: '/transfers', icon: ArrowLeftRight, permission: 'transfer.view' },
  { label: 'Stock Counts', to: '/stock-counts', icon: ClipboardCheck, permission: 'stock_count.capture' },
  { label: 'Approval Centre', to: '/approvals', icon: CheckSquare, permission: 'transfer.approve' },
  { label: 'Hospitals', to: '/hospitals', icon: Building2, permission: 'hospital.view' },
  { label: 'Doctors', to: '/doctors', icon: Stethoscope, permission: 'doctor.view' },
  { label: 'Preference Cards', to: '/preference-cards', icon: IdCard, permission: 'doctor.view' },
  { label: 'Reports', to: '/reports', icon: BarChart3, permission: 'report.view' },
  { label: 'Pastel Export', to: '/pastel', icon: FileSpreadsheet, permission: 'pastel.export' },
  { label: 'Users', to: '/users', icon: Users, permission: 'user.manage' },
  { label: 'Audit Log', to: '/audit', icon: ScrollText, permission: 'audit.view' },
  { label: 'Notifications', to: '/notifications', icon: Bell },
]
