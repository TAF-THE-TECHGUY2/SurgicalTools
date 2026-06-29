import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { api, getToken, setToken } from '@/lib/api'
import type { Role, User } from '@/types'

interface AuthState {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  hasPermission: (permission: string) => boolean
  hasRole: (role: Role) => boolean
  isAdmin: boolean
}

const AuthContext = createContext<AuthState | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  const loadMe = useCallback(async () => {
    if (!getToken()) {
      setLoading(false)
      return
    }
    try {
      const { data } = await api.get<User>('/auth/me')
      setUser(data)
    } catch {
      setToken(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadMe()
  }, [loadMe])

  const login = useCallback(async (email: string, password: string) => {
    const { data } = await api.post<{ token: string; user: User }>('/auth/login', {
      email,
      password,
      device_name: 'web',
    })
    setToken(data.token)
    setUser(data.user)
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // ignore — clear locally regardless
    }
    setToken(null)
    setUser(null)
  }, [])

  const hasPermission = useCallback(
    (permission: string) => Boolean(user?.permissions?.includes(permission)) || hasRoleInternal(user, 'super_admin'),
    [user],
  )
  const hasRole = useCallback((role: Role) => hasRoleInternal(user, role), [user])
  const isAdmin = hasRoleInternal(user, 'admin') || hasRoleInternal(user, 'super_admin')

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, hasPermission, hasRole, isAdmin }}>
      {children}
    </AuthContext.Provider>
  )
}

function hasRoleInternal(user: User | null, role: Role): boolean {
  return Boolean(user?.roles?.includes(role))
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
