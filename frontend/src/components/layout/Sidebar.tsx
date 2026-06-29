import { NavLink } from 'react-router-dom'
import { Activity, X } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { NAV_ITEMS } from '@/components/layout/nav'
import { cn } from '@/lib/cn'

export function Sidebar({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { hasPermission } = useAuth()
  const items = NAV_ITEMS.filter((i) => !i.permission || hasPermission(i.permission))

  return (
    <>
      {/* Mobile backdrop */}
      {open && <div className="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" onClick={onClose} />}

      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 text-slate-300 transition-transform lg:static lg:translate-x-0',
          open ? 'translate-x-0' : '-translate-x-full',
        )}
      >
        <div className="flex items-center justify-between px-5 py-5">
          <div className="flex items-center gap-2.5">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white">
              <Activity className="h-5 w-5" />
            </div>
            <div className="leading-tight">
              <div className="text-sm font-bold text-white">Surgical Devices</div>
              <div className="text-[11px] text-slate-400">Inventory ERP</div>
            </div>
          </div>
          <button onClick={onClose} className="text-slate-400 lg:hidden">
            <X className="h-5 w-5" />
          </button>
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto px-3 pb-6">
          {items.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.to === '/'}
              onClick={onClose}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                  isActive ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white',
                )
              }
            >
              <item.icon className="h-[18px] w-[18px]" />
              {item.label}
            </NavLink>
          ))}
        </nav>

        <div className="border-t border-slate-800 px-5 py-3 text-[11px] text-slate-500">
          v1.0 · Production build
        </div>
      </aside>
    </>
  )
}
