/**
 * AdminLTE 4 + Bootstrap 5 entry point.
 *
 * Published by `php artisan adminlte:install`. Add this file to your
 * vite.config.js input array, then `npm run dev` / `npm run build`.
 */

// Bootstrap (provides dropdowns, modals, tooltips, offcanvas, etc.)
import 'bootstrap'

// OverlayScrollbars — AdminLTE uses it for the sidebar scroller (optional)
import { OverlayScrollbars } from 'overlayscrollbars'

// AdminLTE plugins (PushMenu, Treeview, CardWidget, FullScreen, DirectChat,
// Layout, accessibility). The data-lte-* API is wired on DOMContentLoaded.
import 'admin-lte'

/**
 * Initialise an optional plugin only when its global is present.
 * Plugin libraries (ApexCharts, jsVectorMap, FullCalendar, Sortable) are
 * loaded lazily via the @pluginScripts directive as global <script> tags,
 * so we feature-detect before touching them.
 */
function whenReady(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn)
  } else {
    fn()
  }
}

function parseConfig(el, attr) {
  const raw = el.getAttribute(attr)
  if (!raw) return {}
  try {
    return JSON.parse(raw)
  } catch (e) {
    console.warn('AdminLTE: invalid JSON in', attr, e)
    return {}
  }
}

// --- ApexCharts ------------------------------------------------------------
function initCharts() {
  if (typeof window.ApexCharts === 'undefined') return
  document.querySelectorAll('[data-apexchart]').forEach((el) => {
    if (el.dataset.apexchartReady) return
    const config = parseConfig(el, 'data-apexchart-config')
    try {
      new window.ApexCharts(el, config).render()
      el.dataset.apexchartReady = 'true'
    } catch (e) {
      console.warn('AdminLTE: ApexCharts init failed (check the chart config)', e)
    }
  })
}

// --- jsVectorMap -----------------------------------------------------------
function initVectorMaps() {
  if (typeof window.jsVectorMap === 'undefined') return
  document.querySelectorAll('[data-jsvectormap]').forEach((el) => {
    if (el.dataset.jsvectormapReady || !el.id) return
    const config = parseConfig(el, 'data-jsvectormap-config')
    try {
      new window.jsVectorMap({ selector: '#' + el.id, ...config })
      el.dataset.jsvectormapReady = 'true'
    } catch (e) {
      console.warn('AdminLTE: jsVectorMap init failed (is the map data file loaded?)', e)
    }
  })
}

// --- FullCalendar ----------------------------------------------------------
function initCalendars() {
  if (typeof window.FullCalendar === 'undefined') return
  document.querySelectorAll('[data-fullcalendar]').forEach((el) => {
    if (el.dataset.fullcalendarReady) return
    const config = parseConfig(el, 'data-fullcalendar-config')
    new window.FullCalendar.Calendar(el, config).render()
    el.dataset.fullcalendarReady = 'true'
  })
}

// --- SortableJS (generic lists + kanban boards) ----------------------------
function initSortables() {
  if (typeof window.Sortable === 'undefined') return

  // Generic sortable lists — items in the same group can be dragged between lists.
  document.querySelectorAll('[data-sortable]').forEach((el) => {
    if (el.dataset.sortableReady) return
    const options = parseConfig(el, 'data-sortable-options')
    window.Sortable.create(el, { animation: 150, ...options })
    el.dataset.sortableReady = 'true'
  })

  // Kanban boards — every lane shares one group so cards move between lanes.
  document.querySelectorAll('[data-sortable-kanban]').forEach((board) => {
    board.querySelectorAll('[data-sortable-group]').forEach((lane) => {
      if (lane.dataset.sortableReady) return
      window.Sortable.create(lane, {
        group: 'kanban-' + (board.id || 'board'),
        animation: 150,
      })
      lane.dataset.sortableReady = 'true'
    })
  })
}

// --- Sidebar treeview a11y --------------------------------------------------
// AdminLTE's Treeview toggles .menu-open on the <li>; mirror that state onto
// the toggle link's aria-expanded so screen readers track open/closed submenus.
function initTreeviewA11y() {
  const sidebar = document.querySelector('.app-sidebar')
  if (!sidebar || typeof MutationObserver === 'undefined') return
  const observer = new MutationObserver((mutations) => {
    mutations.forEach((m) => {
      const link = m.target.querySelector(':scope > a.nav-link[aria-expanded]')
      if (link) link.setAttribute('aria-expanded', m.target.classList.contains('menu-open') ? 'true' : 'false')
    })
  })
  sidebar.querySelectorAll('li.nav-item').forEach((li) => {
    if (li.querySelector(':scope > ul.nav-treeview')) {
      observer.observe(li, { attributes: true, attributeFilter: ['class'] })
    }
  })
}

// ---------------------------------------------------------------------------
// Sidebar: keep the active menu item centered in the viewport
// ---------------------------------------------------------------------------
// On a deep treeview page the active link can sit far below the fold. After the
// menu has settled (treeview submenus expanded, OverlayScrollbars wired), scroll
// the sidebar so the active `.nav-link.active` is vertically centered — clamped
// to the scroll bounds so a short menu simply stays at the top.

let _sidebarScrollEl = null

function activeNavLink() {
  // Prefer the deepest active link (a leaf under an open treeview). AdminLTE
  // also marks the parent treeview toggle as `.active`, so take the last one.
  const links = document.querySelectorAll('.app-sidebar a.nav-link.active')
  return links.length ? links[links.length - 1] : null
}

function sidebarScrollElement() {
  if (_sidebarScrollEl && document.contains(_sidebarScrollEl)) return _sidebarScrollEl
  const wrapper = document.querySelector('.sidebar-wrapper')
  if (!wrapper) return null

  // OverlayScrollbars may restructure the sidebar so the real scroller is an
  // internal element (e.g. `.os-viewport`) rather than the wrapper itself.
  const candidates = [wrapper, ...wrapper.querySelectorAll('*')]
  const viewport = wrapper.querySelector('.os-viewport')

  if (viewport && viewport.scrollHeight > viewport.clientHeight) {
    _sidebarScrollEl = viewport
    return _sidebarScrollEl
  }

  for (const el of candidates) {
    const cs = getComputedStyle(el)
    const scrollable =
      cs.overflowY === 'auto' ||
      cs.overflowY === 'scroll' ||
      cs.overflowY === 'overlay'
    if (scrollable && el.scrollHeight > el.clientHeight) {
      _sidebarScrollEl = el
      return _sidebarScrollEl
    }
  }

  _sidebarScrollEl = wrapper
  return _sidebarScrollEl
}

function centerActiveMenuItem() {
  const link = activeNavLink()
  const scroller = sidebarScrollElement()
  if (!link || !scroller || link.offsetParent === null) return

  const linkRect = link.getBoundingClientRect()
  const scrollerRect = scroller.getBoundingClientRect()
  if (linkRect.height === 0 || scrollerRect.height === 0) return

  // Current position of the link relative to the scroller's top edge.
  const offset = linkRect.top - scrollerRect.top + scroller.scrollTop

  // Target scroll so the link sits at the vertical centre of the viewport.
  const target = offset - (scrollerRect.height - linkRect.height) / 2
  const maxScroll = scroller.scrollHeight - scroller.clientHeight
  const clamped = Math.max(0, Math.min(target, maxScroll))

  scroller.scrollTo({ top: clamped, behavior: 'smooth' })
}

whenReady(() => {
  // Wire OverlayScrollbars to the sidebar (matches the AdminLTE demo behaviour)
  const sidebar = document.querySelector('.sidebar-wrapper')
  if (sidebar && window.innerWidth > 992) {
    OverlayScrollbars(sidebar, {
      scrollbars: { theme: 'os-theme-light', autoHide: 'leave', clickScroll: true },
    })
  }

  initCharts()
  initVectorMaps()
  initCalendars()
  initSortables()
  initTreeviewA11y()

  // Center the active menu item once layout, fonts and any treeview transition
  // have settled (and again on window.load as a fallback).
  centerActiveMenuItem()
  if (document.readyState === 'complete') centerActiveMenuItem()
})

window.addEventListener('load', centerActiveMenuItem)
