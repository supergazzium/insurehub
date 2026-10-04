import { computed, ref } from 'vue'

export interface DraftChange {
  path: string
  from: unknown
  to: unknown
}

function readPath(source: unknown, path: string): unknown {
  let cursor: unknown = source
  for (const part of path.split('.')) {
    if (cursor === null || cursor === undefined || typeof cursor !== 'object') return undefined
    cursor = (cursor as Record<string, unknown>)[part]
  }
  return cursor
}

function normalizeValue(v: unknown): unknown {
  if (v === undefined || v === null) return null
  if (typeof v === 'string') return v.trim() === '' ? null : v.trim()
  return v
}

export function sameValue(a: unknown, b: unknown): boolean {
  const x = normalizeValue(a)
  const y = normalizeValue(b)
  if (x === y) return true
  if (x === null || y === null) return false
  if (typeof x === 'object' || typeof y === 'object') return JSON.stringify(x) === JSON.stringify(y)
  return String(x) === String(y)
}

export function nestPayload(entries: Array<[string, unknown]>): Record<string, unknown> {
  const root: Record<string, unknown> = {}
  for (const [path, value] of entries) {
    const parts = path.split('.')
    let cursor = root
    for (let i = 0; i < parts.length - 1; i++) {
      const next = cursor[parts[i]]
      if (next === null || typeof next !== 'object') cursor[parts[i]] = {}
      cursor = cursor[parts[i]] as Record<string, unknown>
    }
    cursor[parts[parts.length - 1]] = value
  }
  return root
}

export function useDraftEdit<T>(source: () => T | null | undefined) {
  const editing = ref(false)
  const draft = ref<Record<string, unknown>>({})

  function original(path: string): unknown {
    return readPath(source(), path)
  }

  function get<V = unknown>(path: string): V {
    if (editing.value && Object.prototype.hasOwnProperty.call(draft.value, path)) {
      return draft.value[path] as V
    }
    return original(path) as V
  }

  function set(path: string, value: unknown): void {
    draft.value = { ...draft.value, [path]: value }
  }

  const changes = computed<DraftChange[]>(() =>
    Object.entries(draft.value)
      .filter(([path, value]) => !sameValue(value, original(path)))
      .map(([path, value]) => ({ path, from: original(path), to: value })),
  )

  const dirty = computed(() => editing.value && changes.value.length > 0)

  function begin(): void {
    draft.value = {}
    editing.value = true
  }

  function cancel(): void {
    draft.value = {}
    editing.value = false
  }

  function payload(extra: Record<string, unknown> = {}): Record<string, unknown> {
    const entries: Array<[string, unknown]> = changes.value.map((c) => [c.path, normalizeValue(c.to)])
    return { ...nestPayload(entries), ...extra }
  }

  return { editing, draft, get, set, original, changes, dirty, begin, cancel, payload }
}
