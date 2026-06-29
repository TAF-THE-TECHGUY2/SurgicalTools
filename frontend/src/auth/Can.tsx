import type { ReactNode } from 'react'
import { useAuth } from '@/auth/AuthContext'

/** Renders children only when the user holds the given permission. */
export function Can({ permission, children, fallback = null }: {
  permission: string
  children: ReactNode
  fallback?: ReactNode
}) {
  const { hasPermission } = useAuth()
  return <>{hasPermission(permission) ? children : fallback}</>
}
