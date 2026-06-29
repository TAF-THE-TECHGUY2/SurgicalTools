import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Search } from 'lucide-react'
import { api } from '@/lib/api'
import { humanize } from '@/lib/format'

interface Hit { id: number; title: string; subtitle: string; link: string }
type Groups = Record<string, Hit[]>

export function GlobalSearch() {
  const [q, setQ] = useState('')
  const [groups, setGroups] = useState<Groups>({})
  const [open, setOpen] = useState(false)
  const boxRef = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()

  useEffect(() => {
    if (q.trim().length < 2) {
      setGroups({})
      return
    }
    const t = setTimeout(async () => {
      try {
        const { data } = await api.get<{ groups: Groups }>('/search', { params: { q } })
        setGroups(data.groups ?? {})
        setOpen(true)
      } catch {
        setGroups({})
      }
    }, 250)
    return () => clearTimeout(t)
  }, [q])

  useEffect(() => {
    const onClick = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [])

  const go = (link: string) => {
    setOpen(false)
    setQ('')
    navigate(link)
  }

  const hasResults = Object.values(groups).some((g) => g.length)

  return (
    <div ref={boxRef} className="relative w-full max-w-md">
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      <input
        value={q}
        onChange={(e) => setQ(e.target.value)}
        onFocus={() => q.length >= 2 && setOpen(true)}
        placeholder="Search doctors, hospitals, ref codes, lots…"
        className="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm focus:border-brand-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-100"
      />

      {open && q.length >= 2 && (
        <div className="absolute z-50 mt-2 max-h-96 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">
          {!hasResults ? (
            <div className="px-4 py-6 text-center text-sm text-slate-400">No matches for “{q}”.</div>
          ) : (
            Object.entries(groups).map(([group, hits]) =>
              hits.length ? (
                <div key={group} className="border-b border-slate-50 last:border-0">
                  <div className="px-4 pt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {humanize(group)}
                  </div>
                  {hits.map((hit) => (
                    <button
                      key={`${group}-${hit.id}`}
                      onClick={() => go(hit.link)}
                      className="block w-full px-4 py-2 text-left hover:bg-brand-50"
                    >
                      <div className="text-sm font-medium text-slate-800">{hit.title}</div>
                      <div className="text-xs text-slate-500">{hit.subtitle}</div>
                    </button>
                  ))}
                </div>
              ) : null,
            )
          )}
        </div>
      )}
    </div>
  )
}
