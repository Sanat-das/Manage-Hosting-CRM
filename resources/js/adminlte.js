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

// --- Color mode (Light / Dark / Auto) ---------------------------------------
// color-mode.blade.php ships markup only — this is the wiring it expects.
const COLOR_MODE_STORE = 'adminlte.colorMode'

function getStoredTheme() {
  try {
    return localStorage.getItem(COLOR_MODE_STORE)
  } catch (e) {
    return null
  }
}

function setStoredTheme(theme) {
  try {
    localStorage.setItem(COLOR_MODE_STORE, theme)
  } catch (e) {
    // Private mode / quota — same tolerance as writeGridWidths() above.
  }
}

function resolveTheme(preference) {
  if (preference !== 'auto') return preference
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function showActiveTheme(preference) {
  document.querySelectorAll('[data-bs-theme-value]').forEach((btn) => {
    const isActive = btn.getAttribute('data-bs-theme-value') === preference
    btn.classList.toggle('active', isActive)
    btn.setAttribute('aria-pressed', isActive ? 'true' : 'false')
    const check = btn.querySelector('.bi-check-lg')
    if (check) check.classList.toggle('d-none', !isActive)
  })
  document.querySelectorAll('[data-lte-theme-icon]').forEach((icon) => {
    icon.classList.toggle('d-none', icon.dataset.lteThemeIcon !== preference)
  })
}

function applyTheme(preference) {
  const resolved = resolveTheme(preference)
  document.documentElement.setAttribute('data-bs-theme', resolved)
  showActiveTheme(preference)
  document.dispatchEvent(new CustomEvent('adminlte:theme-changed', { detail: { theme: resolved, preference } }))
}

function initColorMode() {
  applyTheme(getStoredTheme() || 'auto')

  document.querySelectorAll('[data-bs-theme-value]').forEach((btn) => {
    if (btn.dataset.themeToggleReady) return
    btn.dataset.themeToggleReady = 'true'
    btn.addEventListener('click', () => {
      const preference = btn.getAttribute('data-bs-theme-value')
      setStoredTheme(preference)
      applyTheme(preference)
    })
  })

  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if ((getStoredTheme() || 'auto') === 'auto') applyTheme('auto')
    })
  }
}

// --- ApexCharts ------------------------------------------------------------
// Instances are kept in `chartInstances` (also stamped on `el.apexChart`) so
// theme changes can call `chart.updateOptions()` — mutating the JSON in
// `data-apexchart-config` after render does nothing, since ApexCharts never
// re-reads that attribute. `chartCategories` remembers each chart's original
// `xaxis.categories` from its own config — `chart.w.config` isn't reliably
// populated yet right as `render()` resolves, and a partial `xaxis` update
// with no `categories` makes ApexCharts fall back to auto-numbered ticks.
const chartInstances = new Map()
const chartCategories = new Map()

function initCharts() {
  if (typeof window.ApexCharts === 'undefined') return Promise.resolve()
  const pending = []
  document.querySelectorAll('[data-apexchart]').forEach((el) => {
    if (el.dataset.apexchartReady) return
    const config = parseConfig(el, 'data-apexchart-config')
    el.dataset.apexchartReady = 'true'
    try {
      const chart = new window.ApexCharts(el, config)
      if (config.xaxis && Array.isArray(config.xaxis.categories) && config.xaxis.categories.length) {
        chartCategories.set(el, config.xaxis.categories)
      }
      // render() resolves once ApexCharts' internal state is ready; calling
      // updateOptions() any earlier throws inside its own render pipeline.
      pending.push(
        chart.render().then(() => {
          el.apexChart = chart
          chartInstances.set(el, chart)
        }).catch((e) => console.warn('AdminLTE: ApexCharts render failed', e))
      )
    } catch (e) {
      console.warn('AdminLTE: ApexCharts init failed (check the chart config)', e)
    }
  })
  return Promise.all(pending)
}

function refreshChartTheme() {
  if (!chartInstances.size) return
  const cs = getComputedStyle(document.documentElement)
  const tok = (name, fallback) => (cs.getPropertyValue(name) || fallback).trim()
  const muted = tok('--bs-secondary-color', tok('--color-text-muted', '#64748b'))
  const border = tok('--color-border', '#e2e8f0')
  chartInstances.forEach((chart, el) => {
    const categories = chartCategories.get(el)
    chart.updateOptions({
      chart: { foreColor: muted },
      grid: { borderColor: border },
      xaxis: Object.assign(
        { labels: { style: { colors: muted } } },
        categories ? { categories } : {}
      ),
      yaxis: { labels: { style: { colors: muted } } },
    }, false, false)
  })
}

document.addEventListener('adminlte:theme-changed', refreshChartTheme)

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

// --- Fitted, resizable data-grid columns -----------------------------------
// Applies to any `table[data-grid-resizable]`; <x-adminlte.partials.datatable>
// sets it, so every grid built on that component behaves this way.
//
// Two rules remove the horizontal scrollbar by construction rather than by
// clamping something after the fact:
//
//   1. Column widths live in a <colgroup> as *percentages of the table*, never
//      pixels, and the table itself is always exactly 100% of its container.
//      No combination of columns, content or window size can make it wider.
//   2. A drag is a zero-sum trade with the next column: whatever one column
//      gains, its neighbour gives up. The percentages therefore always sum to
//      100 and the grid cannot grow past its container mid-drag either.
//
// The browser's own auto-layout widths are measured first and frozen into the
// colgroup, so the grid looks the same before and after the switch to
// `table-layout: fixed`. With JS disabled the table simply stays on auto
// layout at width:100% and still never overflows, because grid cells are
// allowed to break long tokens (see `overflow-wrap` in adminlte.css).

const GRID_WIDTH_STORE = 'adminlte.gridColumnWidths.v2'
// v1 stored pixel widths. Reading those as percentages would blow the table up
// to several times its container, so the old key is dropped on sight.
const GRID_LEGACY_WIDTH_STORE = 'adminlte.gridColumnWidths.v1'
const GRID_MIN_COL_WIDTH = 56
// Mirrors the `.table thead th:last-child.text-end` clamp in adminlte.css. Auto
// layout honours that clamp as a hint only — leftover space is handed to every
// column, Actions included — so the same icon-button column measures 96px on a
// grid whose content fills the table and 124px on one whose content does not.
// Pinning it keeps the Actions edge on the same line in every grid.
const GRID_ACTIONS_WIDTH = 96

function readGridWidths() {
  try {
    localStorage.removeItem(GRID_LEGACY_WIDTH_STORE)
    return JSON.parse(localStorage.getItem(GRID_WIDTH_STORE) || '{}')
  } catch (e) {
    return {}
  }
}

function writeGridWidths(key, widths) {
  try {
    const all = readGridWidths()
    if (widths === null) {
      delete all[key]
    } else {
      all[key] = widths
    }
    localStorage.setItem(GRID_WIDTH_STORE, JSON.stringify(all))
  } catch (e) {
    // Private mode / quota — resizing still works for this page view.
  }
}

function gridHeaderCells(table) {
  const row = table.tHead && table.tHead.rows[0]
  return row ? Array.from(row.cells) : []
}

function gridCols(table) {
  return Array.from(table.querySelectorAll(':scope > colgroup > col'))
}

function gridTableWidth(table) {
  const own = table.getBoundingClientRect().width
  if (own > 0) return own
  return table.parentElement ? table.parentElement.getBoundingClientRect().width : 0
}

// The 56px floor is expressed as a share of the live table so it keeps its
// meaning at any window size, and is capped so it can never exceed the space a
// pair of columns actually has to trade.
function gridMinPercent(table) {
  const width = gridTableWidth(table)
  return width > 0 ? Math.min(25, (GRID_MIN_COL_WIDTH / width) * 100) : 5
}

function normalisedPercents(values) {
  const total = values.reduce((sum, value) => sum + value, 0)
  if (!(total > 0)) return values.map(() => 100 / values.length)
  return values.map((value) => (value / total) * 100)
}

function isUsableGridWidths(widths, count) {
  return (
    Array.isArray(widths) &&
    widths.length === count &&
    widths.every((value) => typeof value === 'number' && isFinite(value) && value > 0)
  )
}

function applyGridPercents(table, percents) {
  const cols = gridCols(table)
  normalisedPercents(percents).forEach((pct, i) => {
    if (cols[i]) cols[i].style.width = pct.toFixed(4) + '%'
  })
}

function pinnedActionsPercents(headers, percents, tableWidth) {
  const last = headers.length - 1
  if (last < 1 || !(tableWidth > 0)) return percents
  if (!headers[last].classList.contains('text-end')) return percents

  const pinned = Math.min((GRID_ACTIONS_WIDTH / tableWidth) * 100, 100 - GRID_MIN_COL_WIDTH)
  const rest = percents.slice(0, last)
  const restTotal = rest.reduce((sum, value) => sum + value, 0)
  const share = restTotal > 0 ? (100 - pinned) / restTotal : 0

  return rest.map((value) => (restTotal > 0 ? value * share : (100 - pinned) / rest.length)).concat(pinned)
}

function currentGridPercents(table) {
  return gridCols(table).map((col) => Math.round(parseFloat(col.style.width || 0) * 1000) / 1000)
}

function initResizableGrids() {
  document.querySelectorAll('table[data-grid-resizable]').forEach((table) => {
    if (table.dataset.gridReady) return

    const headers = gridHeaderCells(table)
    if (!headers.length) return

    const key = table.dataset.gridKey || location.pathname
    const stored = readGridWidths()[key]

    const tableWidth = gridTableWidth(table)
    const measured = headers.map((th) => th.getBoundingClientRect().width)

    let colgroup = table.querySelector(':scope > colgroup')
    if (!colgroup) {
      colgroup = document.createElement('colgroup')
      headers.forEach(() => colgroup.appendChild(document.createElement('col')))
      table.insertBefore(colgroup, table.firstChild)
    }

    const cols = Array.from(colgroup.children)

    table.classList.add('grid-resizable')
    table.style.width = '100%'

    if (isUsableGridWidths(stored, cols.length)) {
      applyGridPercents(table, stored)
      table.dataset.gridCustomised = 'true'
    } else if (tableWidth > 0) {
      const measuredPercents = measured.map((width) => (width / tableWidth) * 100)
      applyGridPercents(table, pinnedActionsPercents(headers, measuredPercents, tableWidth))
    } else {
      applyGridPercents(table, cols.map(() => 1))
    }

    headers.forEach((th, index) => {
      // The last column has no neighbour to trade width with, and its right
      // edge is the container edge — there is nothing to drag it into.
      if (index >= headers.length - 1) return

      const handle = document.createElement('span')
      handle.className = 'grid-col-resizer'
      handle.setAttribute('aria-hidden', 'true')
      th.appendChild(handle)

      handle.addEventListener('pointerdown', (event) => {
        event.preventDefault()
        event.stopPropagation()

        // Pointer capture keeps the cursor glued to the handle, but it is an
        // enhancement only: the drag listeners live on `document` so a refused
        // or unsupported capture (synthetic events, some pen/touch stacks)
        // cannot strand the drag with moves that never reach the handle.
        try {
          handle.setPointerCapture(event.pointerId)
        } catch (e) {
          /* capture unavailable — document listeners below still drive the drag */
        }

        handle.classList.add('is-dragging')
        document.body.classList.add('grid-is-resizing')

        const startX = event.clientX
        const width = gridTableWidth(table)
        const minPercent = gridMinPercent(table)
        const startPercent = parseFloat(cols[index].style.width)
        const pairPercent = startPercent + parseFloat(cols[index + 1].style.width)

        const onMove = (moveEvent) => {
          const delta = width > 0 ? ((moveEvent.clientX - startX) / width) * 100 : 0
          const dragged = Math.min(
            Math.max(startPercent + delta, minPercent),
            Math.max(minPercent, pairPercent - minPercent)
          )
          cols[index].style.width = dragged.toFixed(4) + '%'
          cols[index + 1].style.width = (pairPercent - dragged).toFixed(4) + '%'
        }

        const onUp = () => {
          document.removeEventListener('pointermove', onMove)
          document.removeEventListener('pointerup', onUp)
          document.removeEventListener('pointercancel', onUp)
          handle.classList.remove('is-dragging')
          document.body.classList.remove('grid-is-resizing')
          table.dataset.gridCustomised = 'true'
          writeGridWidths(key, currentGridPercents(table))
        }

        document.addEventListener('pointermove', onMove)
        document.addEventListener('pointerup', onUp)
        document.addEventListener('pointercancel', onUp)
      })

      handle.addEventListener('dblclick', (event) => {
        event.preventDefault()
        event.stopPropagation()
        resetGridWidths(table)
      })
    })

    table.dataset.gridReady = 'true'
  })
}

function resetGridWidths(table) {
  const key = table.dataset.gridKey || location.pathname
  writeGridWidths(key, null)
  delete table.dataset.gridCustomised
  const colgroup = table.querySelector(':scope > colgroup')
  if (colgroup) colgroup.remove()
  table.classList.remove('grid-resizable')
  table.style.width = ''
  delete table.dataset.gridReady
  table.querySelectorAll('.grid-col-resizer').forEach((el) => el.remove())
  initResizableGrids()
}

function initGridResetControls() {
  document.querySelectorAll('[data-grid-reset]').forEach((button) => {
    if (button.dataset.gridResetReady) return
    button.dataset.gridResetReady = 'true'
    button.addEventListener('click', () => {
      const table = document.querySelector('table[data-grid-key="' + button.dataset.gridReset + '"]')
      if (table) resetGridWidths(table)
    })
  })
}

function initGridScrollableHint() {
  document.querySelectorAll('.table-responsive').forEach((el) => {
    function update() {
      el.classList.toggle('is-scrollable', el.scrollWidth > el.clientWidth + 1)
    }
    update()
    el.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update)
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

function initFormValidation() {
  const forms = document.querySelectorAll('.mh-form')
  forms.forEach((form) => {
    const requiredFields = form.querySelectorAll('[required]')
    requiredFields.forEach((el) => {
      el.addEventListener('blur', () => {
        const empty = el.type === 'checkbox' ? !el.checked : !el.value.trim()
        if (empty && el.value === '' && el.hasAttribute('required')) {
          el.classList.add('is-invalid')
          el.setAttribute('aria-invalid', 'true')
        } else if (!empty) {
          if (el.dataset.serverInvalid !== 'true') {
            el.classList.remove('is-invalid')
            el.removeAttribute('aria-invalid')
          }
        }
      })
      el.addEventListener('input', () => {
        if (el.value.trim() !== '' && el.dataset.serverInvalid !== 'true') {
          el.classList.remove('is-invalid')
          el.removeAttribute('aria-invalid')
        }
      })
    })
    form.querySelectorAll('.is-invalid').forEach((el) => { el.dataset.serverInvalid = 'true' })
    form.addEventListener('submit', () => {
      const btn = form.querySelector('button[type="submit"]')
      if (btn) {
        btn.setAttribute('aria-busy', 'true')
        btn.classList.add('is-loading')
      }
      form.setAttribute('aria-busy', 'true')
      form.classList.add('is-loading')
    })
  })
}

whenReady(() => {
  const sidebar = document.querySelector('.sidebar-wrapper')
  if (sidebar && window.innerWidth > 992) {
    OverlayScrollbars(sidebar, {
      scrollbars: { theme: 'os-theme-light', autoHide: 'leave', clickScroll: true },
    })
  }

  initColorMode()
  initCharts().then(() => {
    refreshChartTheme()
    document.dispatchEvent(new CustomEvent('adminlte:charts-ready'))
  })
  initVectorMaps()
  initCalendars()
  initSortables()
  initTreeviewA11y()
  initResizableGrids()
  initGridResetControls()
  initGridScrollableHint()
  initFormValidation()

  centerActiveMenuItem()
  if (document.readyState === 'complete') centerActiveMenuItem()
})

window.addEventListener('load', centerActiveMenuItem)
