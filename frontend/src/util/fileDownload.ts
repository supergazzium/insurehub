import { api, getToken, API_BASE_URL } from '../api/client'

export function base64ToBlob(content: string, mime: string): Blob {
  const bin = atob(content)
  const bytes = new Uint8Array(bin.length)
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i)
  return new Blob([bytes], { type: mime })
}

function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 30000)
}

export async function openPdf(path: string, mode: 'open' | 'download' = 'open'): Promise<void> {
  const sep = path.includes('?') ? '&' : '?'
  const res = await api.get<{ data: { filename: string; mime: string; base64: string } }>(`${path}${sep}as=base64`)
  const blob = base64ToBlob(res.data.base64, res.data.mime)
  if (mode === 'download') {
    saveBlob(blob, res.data.filename)
    return
  }
  const url = URL.createObjectURL(blob)
  const win = window.open(url, '_blank')
  if (!win) saveBlob(blob, res.data.filename)
  window.setTimeout(() => URL.revokeObjectURL(url), 60000)
}

export async function downloadAuthed(path: string, filename: string): Promise<void> {
  const res = await fetch(`${API_BASE_URL}/${path}`, { headers: { Authorization: `Bearer ${getToken() ?? ''}` } })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error((body as { message?: string }).message ?? `HTTP ${res.status}`)
  }
  saveBlob(await res.blob(), filename)
}
