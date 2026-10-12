import type { Router, RouteLocationRaw } from 'vue-router'

/**
 * Open a routed destination in a NEW browser tab instead of navigating the
 * current one. Used by list pages so an operator can open a row (policy,
 * customer, …) to work on it without losing the list they're working through —
 * they close the tab and they're back on the same filtered/scrolled list.
 *
 * `router.resolve` yields the full href (including the app's BASE_URL, e.g.
 * /insurehub), so the new tab lands on the right SPA route. `noopener` keeps
 * the opened tab from getting a reference back to this window.
 */
export function openInNewTab(router: Router, to: RouteLocationRaw): void {
  const href = router.resolve(to).href
  window.open(href, '_blank', 'noopener')
}
