import { cn } from '@/lib/cn'

/**
 * Surgical Devices brand assets — the interlocking S+D monogram recreated as
 * SVG (light blue S #29A9E1 over navy D #1E3C8C) plus the two-tone wordmark.
 */
export function LogoMark({ className }: { className?: string }) {
  return (
    <svg viewBox="0 0 124 100" className={className} aria-label="Surgical Devices logo" role="img">
      {/* D — navy */}
      <path
        d="M58 8 H76 A42 42 0 0 1 76 92 H58 V76 H74 A26 26 0 0 0 74 24 H58 Z"
        fill="#1E3C8C"
      />
      {/* S — light blue, drawn over the D */}
      <path
        d="M82 20 C74 10 50 8 40 20 C30 32 38 44 52 48 C66 52 78 56 80 68 C82 82 66 92 50 88 C42 86 38 80 38 80"
        fill="none"
        stroke="#29A9E1"
        strokeWidth="15"
        strokeLinecap="round"
      />
    </svg>
  )
}

/** Monogram + "SURGICAL DEVICES" wordmark. `dark` for use on dark surfaces. */
export function LogoLockup({ className, dark = false }: { className?: string; dark?: boolean }) {
  return (
    <span className={cn('flex items-center gap-2.5', className)}>
      <LogoMark className="h-9 w-auto shrink-0" />
      <span className="leading-tight">
        <span className="block text-sm font-bold tracking-wide">
          <span className="text-[#29A9E1]">SURGICAL</span>{' '}
          <span className={dark ? 'text-white' : 'text-[#1E3C8C]'}>DEVICES</span>
        </span>
        <span className={cn('block text-[11px]', dark ? 'text-slate-400' : 'text-slate-500')}>
          Inventory ERP
        </span>
      </span>
    </span>
  )
}
