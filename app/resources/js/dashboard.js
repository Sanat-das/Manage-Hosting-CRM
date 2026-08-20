/**
 * Admin dashboard widget grid.
 *
 * Drag-and-drop reordering (SortableJS) persisted via AJAX, widget add/remove
 * actions, and ApexCharts rendering for dashboard widgets. Loaded via Vite on
 * the dashboard page only — adminlte.js handles everything else.
 */
import Sortable from 'sortablejs'
import ApexCharts from 'apexcharts'

// Expose the libraries on window so the existing initCharts()/initSortables()
// helpers in resources/js/adminlte.js can feature-detect them. adminlte.js
// evaluates before this module, so assigning the globals here (before any
// DOMContentLoaded work) is safe: its handlers either already ran or run
// after these are in place.
window.Sortable = Sortable
window.ApexCharts = ApexCharts

const GRID_SELECTOR = '[data-dashboard-grid]'
const ENDPOINT = '/admin/dashboard/widgets'

let grid = null
let sortable = null
let saving = false

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]')
  return meta ? meta.content : ''
}

/**
 * Persist the current widget order. `exclude` drops one widget (remove),
 * `add` appends one (add from the picker). Resolves to true when the server
 * accepted the snapshot. Interactions are disabled while the request is in
 * flight so overlapping saves can't race each other.
 */
async function saveSnapshot({ exclude = null, add = null } = {}) {
  if (saving) return false
  saving = true
  if (grid) {
    grid.classList.add('dashboard-saving')
    grid.style.pointerEvents = 'none'
    if (sortable) sortable.option('disabled', true)
  }

  const keys = []
  if (grid) {
    grid.querySelectorAll('[data-widget-key]').forEach((el) => {
      const key = el.dataset.widgetKey
      if (key && key !== exclude) keys.push(key)
    })
  }
  if (add) keys.push(add)

  try {
    const response = await fetch(ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        widgets: keys.map((key, order) => ({ key, order, enabled: true })),
      }),
    })
    if (!response.ok) {
      alert('Could not save dashboard layout. Please try again.')
      return false
    }
    return true
  } catch (error) {
    alert('Could not save dashboard layout. Please try again.')
    return false
  } finally {
    saving = false
    if (grid) {
      grid.classList.remove('dashboard-saving')
      grid.style.pointerEvents = ''
      if (sortable) sortable.option('disabled', false)
    }
  }
}

document.addEventListener('DOMContentLoaded', () => {
  // Render any dashboard chart adminlte.js could not. It feature-detects
  // window.ApexCharts at module evaluation time — before this module assigns
  // the globals above — so its init may have already been a no-op. Charts it
  // did initialise carry data-apexchart-ready and are skipped here.
  if (window.ApexCharts) {
    document.querySelectorAll('[data-apexchart]:not([data-apexchart-ready])').forEach((el) => {
      try {
        const config = JSON.parse(el.getAttribute('data-apexchart-config') || '{}')
        new window.ApexCharts(el, config).render()
        el.dataset.apexchartReady = 'true'
      } catch (error) {
        // Invalid config — leave the widget's fallback message visible.
      }
    })
  }

  grid = document.querySelector(GRID_SELECTOR)
  if (!grid) return

  if (window.Sortable) {
    sortable = window.Sortable.create(grid, {
      handle: '.dashboard-widget-drag',
      animation: 150,
      onEnd: () => { saveSnapshot() },
    })
  }

  // Remove a widget: confirm, persist the snapshot without it, reload.
  grid.addEventListener('click', (event) => {
    const button = event.target.closest('[data-dashboard-remove]')
    if (!button) return
    const column = button.closest('[data-widget-key]')
    if (!column) return
    if (!window.confirm('Remove this widget from your dashboard?')) return
    saveSnapshot({ exclude: column.dataset.widgetKey }).then((ok) => {
      if (ok) window.location.reload()
    })
  })

  // Add a widget from the picker modal: persist the snapshot with it, reload.
  document.querySelectorAll('[data-dashboard-add]').forEach((button) => {
    button.addEventListener('click', () => {
      saveSnapshot({ add: button.dataset.key }).then((ok) => {
        if (ok) window.location.reload()
      })
    })
  })
})
