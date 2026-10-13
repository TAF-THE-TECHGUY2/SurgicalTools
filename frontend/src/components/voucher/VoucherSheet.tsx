import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

/**
 * An on-screen reproduction of the printed "Stock Movement / Delivery Voucher"
 * pad (form 130233 and its siblings).
 *
 * Reps have filled this form by hand for years, so the digital version keeps
 * the same furniture in the same places: the letterhead block, the black title
 * bar, the red serial, the DELIVER TO / ADDRESS / CONTACT PERSON rows, the
 * four-column ruled grid and the recipient footer. Someone who knows the pad
 * should recognise the screen without being taught it.
 *
 * The same component serves data entry and the finished record — `editable`
 * swaps the written-in values for borderless inputs that sit on the ruled
 * lines rather than in boxes of their own, so the form never stops looking
 * like the form.
 *
 * Deliberately *not* a pixel trace of the paper: ruled lines are taller than
 * 5mm so a fingertip can land on them (dev-spec §3, "optimised touch targets"),
 * and the letterhead reflows below 640px instead of forcing a sideways pan.
 */

export interface VoucherRow {
  key: string | number
  code: string
  description: string
  lot?: string | null
  qty: number
  /** Scanned stock the dispatch list did not authorise — tinted, as in the PDF. */
  flagged?: boolean
  flagLabel?: string | null
}

export interface VoucherSheetFields {
  deliverTo: string
  address: string
  contactPerson: string
  invoiceRef: string
}

export interface VoucherSheetProps {
  voucherNumber?: string | null
  /**
   * The number is the next in sequence but has not been allocated yet. Shown
   * faintly with a caption, because a rep who reads it as final would write it
   * on the paperwork and it could still change.
   */
  provisional?: boolean
  date?: string | null
  rows: VoucherRow[]
  recipientName?: string | null
  dateDelivered?: string | null
  signatureUrl?: string | null
  /** Ruled lines to draw, as the pad has a fixed count. */
  minRows?: number
  editable?: boolean
  fields: VoucherSheetFields
  onFieldChange?: (patch: Partial<VoucherSheetFields>) => void
  /** Marks a field with the required asterisk the spec's matrix calls for. */
  required?: Array<keyof VoucherSheetFields>
  /**
   * Fields that stay read-only on an otherwise editable sheet. Deliver To is
   * the case in practice: it comes from the destination picker, so typing over
   * it here would silently disagree with the location the stock moves to.
   */
  lockedFields?: Array<keyof VoucherSheetFields>
  /** Appears under the grid — used for the "scan more" affordance. */
  children?: ReactNode
  className?: string
}

const PAD_LINES = 16

export function VoucherSheet({
  voucherNumber,
  provisional = false,
  date,
  rows,
  recipientName,
  dateDelivered,
  signatureUrl,
  minRows = PAD_LINES,
  editable = false,
  fields,
  onFieldChange,
  required = [],
  lockedFields = [],
  children,
  className,
}: VoucherSheetProps) {
  const blankLines = Math.max(0, minRows - rows.length)
  const set = (patch: Partial<VoucherSheetFields>) => onFieldChange?.(patch)
  const writable = (field: keyof VoucherSheetFields) => editable && !lockedFields.includes(field)

  return (
    <div className={cn('voucher-sheet', className)}>
      {/* ---------------- Letterhead ---------------- */}
      <div className="flex flex-col gap-3 border-b border-black p-3 sm:flex-row sm:items-start sm:gap-4">
        <div className="order-1 flex-1 sm:order-none">
          <VoucherLogo />
        </div>

        <div className="order-3 text-[7.5px] leading-[1.45] sm:order-none sm:w-[38%] sm:text-right sm:text-[8px]">
          <strong>HEAD OFFICE:</strong>
          <br />1 Santoni House, 7 Sinembe Crescent
          <br />Sinembe Office Park, La Lucia Ridge
          <br />P.O. Box 573, Umhlanga Rocks, 4320
          <br />[Tel] 031 584 8086 [Fax] 0866 524 734
          <br />[Mail] info@surgicaldevices.co.za
          <br />Reg No.: 2024/443234/07
          <br />VAT No.: 4830320380
        </div>

        {/* The boxed serial block, top-right on the pad. */}
        <div className="order-2 w-[46%] shrink-0 border border-black sm:order-none sm:w-[30%]">
          <div className="bg-black px-1 py-[3px] text-center text-[8px] font-bold uppercase leading-[1.3] tracking-wide text-white sm:text-[9px]">
            Stock Movement /<br />Delivery Voucher
          </div>
          <div
            className={cn(
              'border-b border-black py-[2px] text-center text-[22px] font-bold leading-tight tracking-[0.06em] text-[#c1121f]',
              provisional && 'opacity-55',
            )}
          >
            {voucherNumber ?? '—'}
          </div>
          {provisional && (
            <div className="border-b border-black bg-[#fdf6e3] px-1 py-[2px] text-center text-[6.5px] uppercase leading-tight tracking-wide text-[#8a6d1f]">
              Next in sequence · allocated on submit
            </div>
          )}
          <div className="flex text-[7px]">
            <div className="w-1/2 border-r border-black px-1 py-[2px]">
              <span className="voucher-label">Date</span>
              <div className="text-[10px] text-black">{date ?? ''}</div>
            </div>
            <div className="w-1/2 px-1 py-[2px]">
              <span className="voucher-label">Invoice No.</span>
              {writable('invoiceRef') ? (
                <VoucherInput
                  value={fields.invoiceRef}
                  onChange={(v) => set({ invoiceRef: v })}
                  placeholder="—"
                  className="text-[10px]"
                />
              ) : (
                <div className="text-[10px] text-black">{fields.invoiceRef || ''}</div>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* ---------------- Deliver to / Address / Contact ---------------- */}
      <VoucherFieldRow
        label="Deliver To"
        required={required.includes('deliverTo')}
        editable={writable('deliverTo')}
        value={fields.deliverTo}
        onChange={(v) => set({ deliverTo: v })}
        placeholder="Hospital, clinic or doctor"
      />
      <VoucherFieldRow
        label="Address"
        required={required.includes('address')}
        editable={writable('address')}
        value={fields.address}
        onChange={(v) => set({ address: v })}
        placeholder="Street / department"
        multiline
      />
      <VoucherFieldRow
        label="Contact Person"
        required={required.includes('contactPerson')}
        editable={writable('contactPerson')}
        value={fields.contactPerson}
        onChange={(v) => set({ contactPerson: v })}
        placeholder="Person receiving the stock"
      />

      {/* ---------------- Items grid ---------------- */}
      <table className="w-full table-fixed border-collapse">
        <thead>
          <tr>
            <th className="voucher-th w-[22%] sm:w-[16%]">Code</th>
            <th className="voucher-th">Description</th>
            <th className="voucher-th w-[22%] sm:w-[18%]">Lot No.</th>
            <th className="voucher-th w-[11%] text-center sm:w-[8%]">Qty</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key} className={cn(row.flagged && 'bg-[#fff2e4]')}>
              <td className="voucher-td font-medium">{row.code}</td>
              <td className="voucher-td">
                {row.description}
                {row.flagged && row.flagLabel && (
                  <span className="ml-1 text-[8px] font-bold uppercase tracking-wide text-[#9a3412]">
                    · {row.flagLabel}
                  </span>
                )}
              </td>
              <td className="voucher-td">{row.lot ?? ''}</td>
              <td className="voucher-td text-center">{row.qty}</td>
            </tr>
          ))}

          {/* The pad's unused ruled lines, struck through by hand on paper. */}
          {Array.from({ length: blankLines }, (_, i) => (
            <tr key={`blank-${i}`}>
              <td className="voucher-td">&nbsp;</td>
              <td className="voucher-td" />
              <td className="voucher-td" />
              <td className="voucher-td" />
            </tr>
          ))}
        </tbody>
      </table>

      {/* ---------------- Recipient footer ---------------- */}
      <div className="flex border-t border-black">
        <div className="voucher-footer-label">Name of Recipient</div>
        <div className="flex-1 border-r border-black px-2 py-[6px] text-[11px]">
          {recipientName || <span className="voucher-ghost">signed at hand-over</span>}
        </div>
        <div className="voucher-footer-label !w-[22%] sm:!w-[16%]">Date Delivered</div>
        <div className="w-[22%] px-2 py-[6px] text-[10px] sm:w-[16%]">{dateDelivered ?? ''}</div>
      </div>
      <div className="flex border-t border-black">
        <div className="voucher-footer-label">Signature</div>
        <div className="flex min-h-[54px] flex-1 items-center px-2 py-1">
          {signatureUrl && (
            <img src={signatureUrl} alt="Recipient signature" className="max-h-[48px] w-auto" />
          )}
        </div>
      </div>

      {children}
    </div>
  )
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                             */
/* ------------------------------------------------------------------ */

/**
 * The pad's monogram, printed in greyscale rather than the brand blue — the
 * carbon copies are single-colour, so a blue logo on screen beside a grey one
 * on paper reads as a different document.
 */
function VoucherLogo() {
  return (
    <div className="flex shrink-0 items-center gap-2">
      <svg viewBox="0 0 124 100" className="h-9 w-auto shrink-0 sm:h-11" role="img" aria-label="Surgical Devices">
        <path d="M58 8 H76 A42 42 0 0 1 76 92 H58 V76 H74 A26 26 0 0 0 74 24 H58 Z" fill="#4b5563" />
        <path
          d="M82 20 C74 10 50 8 40 20 C30 32 38 44 52 48 C66 52 78 56 80 68 C82 82 66 92 50 88 C42 86 38 80 38 80"
          fill="none"
          stroke="#9ca3af"
          strokeWidth="15"
          strokeLinecap="round"
        />
      </svg>
      <div className="leading-none">
        <div className="whitespace-nowrap text-[15px] font-bold tracking-tight sm:text-[19px]">
          <span className="text-[#6b7280]">SURGICAL</span> <span className="text-black">DEVICES</span>
        </div>
        <div className="mt-[2px] whitespace-nowrap text-[7px] tracking-[0.12em] text-black sm:text-[8.5px]">
          SOUTH AFRICA (PTY) LTD
        </div>
      </div>
    </div>
  )
}

function VoucherFieldRow({
  label, value, onChange, editable, placeholder, multiline = false, required = false,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  editable: boolean
  placeholder?: string
  multiline?: boolean
  required?: boolean
}) {
  return (
    <div className="flex border-t border-black">
      <div className="voucher-footer-label">
        {label}
        {required && <span className="ml-0.5 text-[#c1121f]">*</span>}
      </div>
      <div className={cn('flex-1 px-2 py-[5px]', multiline && 'min-h-[46px]')}>
        {editable ? (
          multiline ? (
            <textarea
              value={value}
              onChange={(e) => onChange(e.target.value)}
              placeholder={placeholder}
              rows={2}
              className="voucher-input resize-none"
            />
          ) : (
            <VoucherInput value={value} onChange={onChange} placeholder={placeholder} />
          )
        ) : (
          <span className="whitespace-pre-line text-[11px]">{value || ''}</span>
        )}
      </div>
    </div>
  )
}

function VoucherInput({
  value, onChange, placeholder, className,
}: {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  className?: string
}) {
  return (
    <input
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className={cn('voucher-input', className)}
    />
  )
}
