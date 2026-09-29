import { api } from '@/lib/api'

/**
 * Print a PDF served by the API. It is fetched as a blob (the request needs
 * the bearer token) and printed from a hidden same-origin iframe, which opens
 * the native print dialog directly. iOS has no iframe print, so there it
 * opens in a new tab where Share → Print reaches AirPrint.
 *
 * Returns true when it fell back to a tab, so the caller can say how to print.
 */
export async function printPdf(path: string): Promise<boolean> {
  const res = await api.get(path, { responseType: 'blob' })
  const url = URL.createObjectURL(res.data as Blob)

  if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
    window.open(url, '_blank')
    return true
  }

  const frame = document.createElement('iframe')
  frame.style.display = 'none'
  frame.src = url
  frame.onload = () => {
    frame.contentWindow?.focus()
    frame.contentWindow?.print()
  }
  document.body.appendChild(frame)

  // Left in place while the dialog is open; the blob is released after.
  window.setTimeout(() => { URL.revokeObjectURL(url); frame.remove() }, 60_000)
  return false
}

/** Open a PDF served by the API in a new tab. */
export async function openPdf(path: string): Promise<void> {
  const res = await api.get(path, { responseType: 'blob' })
  window.open(URL.createObjectURL(res.data as Blob))
}
