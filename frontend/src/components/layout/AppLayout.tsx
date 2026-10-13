import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import { Sidebar } from '@/components/layout/Sidebar'
import { Topbar } from '@/components/layout/Topbar'

export function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    /* `print-root` lets @media print drop the chrome and reflow the page
       content onto paper, so Ctrl-P on a voucher prints the voucher rather
       than a screenshot of the application. */
    <div className="print-root flex h-screen overflow-hidden bg-surface">
      <div className="no-print contents">
        <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      </div>
      <div className="flex flex-1 flex-col overflow-hidden">
        <div className="no-print contents">
          <Topbar onMenu={() => setSidebarOpen(true)} />
        </div>
        <main className="flex-1 overflow-y-auto px-4 py-6 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-7xl">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
