import { useState } from 'react'
import { useNavigate, useLocation, Navigate } from 'react-router-dom'
import { Activity } from 'lucide-react'
import { useAuth } from '@/auth/AuthContext'
import { Button } from '@/components/ui/Button'
import { Field, Input } from '@/components/ui/Field'
import { apiError } from '@/lib/api'

export default function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [email, setEmail] = useState('admin@surgical.test')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  if (user) return <Navigate to="/" replace />

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      await login(email, password)
      const to = (location.state as { from?: string })?.from ?? '/'
      navigate(to, { replace: true })
    } catch (err) {
      setError(apiError(err, 'Unable to sign in.'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-800 via-brand-700 to-slate-900 p-4">
      <div className="grid w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl md:grid-cols-2">
        {/* Brand panel */}
        <div className="hidden flex-col justify-between bg-brand-700 p-10 text-white md:flex">
          <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-white/15">
              <Activity className="h-6 w-6" />
            </div>
            <div>
              <div className="text-lg font-bold">Surgical Devices</div>
              <div className="text-sm text-brand-100">Inventory ERP</div>
            </div>
          </div>
          <div>
            <h2 className="text-2xl font-bold leading-snug">Digitising medical-device inventory, end to end.</h2>
            <p className="mt-3 text-sm text-brand-100">
              Real-time stock, two-stage transfers, digital signatures, stock counts and audit trails —
              on desktop and mobile, online or offline.
            </p>
          </div>
          <div className="text-xs text-brand-200">© {new Date().getFullYear()} Surgical Devices</div>
        </div>

        {/* Form */}
        <div className="p-8 sm:p-10">
          <h1 className="text-xl font-bold text-slate-900">Welcome back</h1>
          <p className="mt-1 text-sm text-slate-500">Sign in to your ERP account.</p>

          <form onSubmit={submit} className="mt-6 space-y-4">
            <Field label="Email">
              <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoComplete="username" />
            </Field>
            <Field label="Password">
              <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoComplete="current-password" />
            </Field>

            {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}

            <Button type="submit" loading={loading} className="w-full" size="lg">
              Sign in
            </Button>
          </form>

          <div className="mt-6 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
            <p className="font-medium text-slate-600">Demo accounts (password: <code>password</code>)</p>
            <p className="mt-1">super@surgical.test · admin@surgical.test · mike@surgical.test</p>
          </div>
        </div>
      </div>
    </div>
  )
}
