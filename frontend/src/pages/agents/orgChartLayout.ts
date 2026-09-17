// A minimal tidy-tree layout for the agent org chart.
//
// Given a forest of nodes (each with an id, a parentId, and a list of
// children), it assigns each node an (x, y) so siblings spread horizontally
// and depth increases vertically. Subtree widths are summed bottom-up so
// parents center over their children (Reingold–Tilford-ish, good enough for
// a management view without the full contour algorithm).

export interface LayoutNode {
  id: string
  parentId: string | null
  children: string[]
}

export interface Positioned {
  id: string
  x: number
  y: number
  depth: number
}

export interface LayoutResult {
  positions: Record<string, Positioned>
  width: number
  height: number
}

export function layoutForest(
  nodes: Record<string, LayoutNode>,
  roots: string[],
  opts: { nodeW?: number; nodeH?: number; hGap?: number; vGap?: number } = {},
): LayoutResult {
  const nodeW = opts.nodeW ?? 180
  const nodeH = opts.nodeH ?? 64
  const hGap = opts.hGap ?? 24
  const vGap = opts.vGap ?? 72
  const stepX = nodeW + hGap
  const stepY = nodeH + vGap

  const positions: Record<string, Positioned> = {}
  let cursorX = 0
  let maxDepth = 0

  // Post-order: place leaves at the running cursor, parents centered over kids.
  const place = (id: string, depth: number, seen: Set<string>): number => {
    if (seen.has(id)) return cursorX  // cycle guard
    seen.add(id)
    maxDepth = Math.max(maxDepth, depth)
    const node = nodes[id]
    const kids = node?.children ?? []
    if (kids.length === 0) {
      const x = cursorX
      cursorX += stepX
      positions[id] = { id, x, y: depth * stepY, depth }
      return x
    }
    const childXs = kids.map((c) => place(c, depth + 1, seen))
    const x = (childXs[0] + childXs[childXs.length - 1]) / 2
    positions[id] = { id, x, y: depth * stepY, depth }
    return x
  }

  const seen = new Set<string>()
  for (const r of roots) {
    place(r, 0, seen)
    cursorX += stepX  // gap between separate root trees
  }

  const xs = Object.values(positions).map((p) => p.x)
  const width = (xs.length ? Math.max(...xs) : 0) + nodeW
  const height = (maxDepth + 1) * stepY

  return { positions, width, height }
}
