/**
 * Dashboard widget grid — per-widget resizing and show/hide.
 *
 * The grid is a 12-column CSS grid of `.dashboard-widget` blocks, each spanning
 * a number of those columns via `grid-column: span N`. Spans and hidden state
 * live in localStorage under the same `adminlte.` prefix the theme and the
 * resizable data-grid widths use, so one naming scheme covers the whole app.
 *
 * A widget switched off takes its own toggle with it, so the module also keeps
 * a restore bar below the grid: one chip per hidden widget, and a reset that
 * drops the stored layout and reloads the server defaults. The bar is the only
 * way back from a resize too, so it appears whenever anything at all has been
 * stored — hidden widgets or spans — and stays hidden for a first visit.
 *
 * Nothing here touches the DOM at import time. The pure helpers below are
 * exported so a plain `node` can exercise them, and every DOM call sits behind
 * `initDashboardWidgets()`, which is self-bootstrapped at the bottom of this
 * file only when `document` exists and the grid is actually on the page. A page
 * without the grid pays nothing for this module.
 */

const WIDGET_GRID_SELECTOR = '[data-dashboard-widget-grid]'
const WIDGET_SELECTOR = '.dashboard-widget[data-widget]'
const TOGGLE_SELECTOR = '[data-widget-toggle]'

const SPANS_STORE = 'adminlte.dashboardWidgetSpans.v1'
const HIDDEN_STORE = 'adminlte.dashboardWidgetHidden.v1'

const GRID_COLUMNS = 12
const MIN_SPAN = 2
const MAX_SPAN = 12
/** Used only when a widget's span can be read neither from the markup nor from layout. */
const FALLBACK_SPAN = 6
const HIDDEN_CLASS = 'is-hidden'
const RESIZING_CLASS = 'widget-is-resizing'
const RESTORE_BAR_CLASS = 'dashboard-widget-restore'
const PERSIST_DEBOUNCE_MS = 150

const SPAN_PATTERN = /span\s+(\d+)/i

const KNOWN_WIDGET_IDS = ['receivables', 'renewals', 'support', 'health', 'capacity']

/** Copy for a hidden widget's chip on the restore bar; unknown ids fall back to the raw id. */
const WIDGET_LABELS = {
  receivables: 'receivables',
  renewals: 'renewals',
  support: 'support queue',
  health: 'system health',
  capacity: 'capacity',
}

/**
 * Clamp a span into the grid's usable range.
 * @param {number} value Raw span — anything numeric, including NaN.
 * @returns {number} An integer between {@link MIN_SPAN} and {@link MAX_SPAN}.
 */
export function clampSpan(value) {
  const span = Math.round(Number(value))
  if (!Number.isFinite(span)) return MIN_SPAN
  return clampTo(span, MIN_SPAN, MAX_SPAN)
}

/**
 * Span implied by a horizontal drag on a widget resize handle.
 *
 * Pixels are converted against the grid's own width, so a column of travel is a
 * column of span at any viewport. The width is read once per drag, so a window
 * resize mid-drag does not make the span jump under the cursor.
 * @param {object} drag
 * @param {number} drag.startSpan Span the widget had when the drag began.
 * @param {number} drag.deltaPx Horizontal pointer travel in CSS pixels.
 * @param {number} drag.gridWidthPx Rendered width of the grid container.
 * @param {number} [drag.columns] Column count of the grid.
 * @param {number} [drag.min] Smallest span a widget may shrink to.
 * @param {number} [drag.max] Largest span a widget may grow to.
 * @returns {number} Integer span, clamped to `[min, max]`.
 */
export function spanFromDrag({ startSpan, deltaPx, gridWidthPx, columns = GRID_COLUMNS, min = MIN_SPAN, max = MAX_SPAN }) {
  const width = Number(gridWidthPx)
  if (!(width > 0)) return clampSpan(startSpan)

  const count = Number(columns) > 0 ? Number(columns) : GRID_COLUMNS
  const raw = (Number(startSpan) || 0) + (Number(deltaPx) || 0) / (width / count)

  if (!Number.isFinite(raw)) return clampSpan(startSpan)
  return clampTo(raw, Math.min(min, max), Math.max(min, max))
}

/**
 * Decode the stored span map. Malformed JSON, non-objects and non-numeric values
 * are dropped rather than thrown, so a corrupt entry cannot block the dashboard;
 * a numeric span outside 2…12 is clamped into range.
 * @param {string|null|object} raw Value read from localStorage, or an already-parsed object.
 * @returns {Object<string, number>} Widget id → integer span, every value in range.
 */
export function decodeSpans(raw) {
  const spans = {}
  const parsed = parseStore(raw)
  if (!isPlainRecord(parsed)) return spans

  for (const [id, value] of Object.entries(parsed)) {
    if (!id) continue
    const span = Number(value)
    if (!Number.isFinite(span)) continue
    spans[id] = clampSpan(span)
  }

  return spans
}

/**
 * Decode the stored list of hidden widget ids.
 * @param {string|null|Array} raw Value read from localStorage, or an already-parsed array.
 * @returns {string[]} Known widget ids only, de-duplicated.
 */
export function decodeHidden(raw) {
  const parsed = parseStore(raw)
  if (!Array.isArray(parsed)) return []
  return sanitizeIds(parsed, KNOWN_WIDGET_IDS)
}

/**
 * Keep only ids that are known, non-empty and unique, in their original order.
 * @param {Array} list Candidate ids, possibly junk.
 * @param {Array<string>} knownIds Ids the caller is willing to accept.
 * @returns {string[]} Filtered ids.
 */
export function sanitizeIds(list, knownIds) {
  if (!Array.isArray(list)) return []
  const known = new Set(Array.isArray(knownIds) ? knownIds : [])
  const result = []

  for (const id of list) {
    if (typeof id !== 'string' || !id) continue
    if (!known.has(id) || result.includes(id)) continue
    result.push(id)
  }

  return result
}

/**
 * Label for a widget id, as shown on its restore-bar chip.
 * @param {string} id Widget id from `data-widget`.
 * @returns {string} The known label, or the raw id for anything unrecognised.
 */
export function widgetLabel(id) {
  const key = typeof id === 'string' ? id : ''
  return Object.prototype.hasOwnProperty.call(WIDGET_LABELS, key) ? WIDGET_LABELS[key] : key
}

/**
 * Summary copy for the restore bar.
 * @param {number} count How many widgets are currently hidden.
 * @returns {string} "1 widget hidden" for one, "N widgets hidden" otherwise.
 */
export function hiddenBarLabel(count) {
  const total = Number.isFinite(Number(count)) ? Math.max(0, Math.round(Number(count))) : 0
  return total === 1 ? '1 widget hidden' : total + ' widgets hidden'
}

/**
 * Summary copy for the restore bar, or `null` when the bar has nothing to show.
 * Hidden widgets win over a span-only customisation, so the bar always describes
 * the reason it is on screen.
 * @param {object} state
 * @param {number} state.hiddenCount How many widgets are currently hidden.
 * @param {boolean} state.customised Whether the stored layout carries a span
 *   for a widget that exists in the DOM — a resize the user may want undone.
 * @returns {string|null} Summary copy, or `null` to keep the bar hidden.
 */
export function barSummary(state) {
  const source = isPlainRecord(state) ? state : {}
  const total = Number.isFinite(Number(source.hiddenCount)) ? Math.max(0, Math.round(Number(source.hiddenCount))) : 0
  if (total > 0) return hiddenBarLabel(total)
  return source.customised ? 'Layout customised' : null
}

/**
 * Wire the dashboard widget grid: restore stored spans and hidden state, inject
 * a resize handle into every card, mount the restore bar, and bind the pointer,
 * keyboard and toggle behaviour. Safe to call before or after
 * DOMContentLoaded, and safe to call with no grid on the page.
 * @param {Document|Element} [root] Scope to search; the document by default.
 * @returns {void}
 */
export function initDashboardWidgets(root = document) {
  const grid = findGrid(root)
  if (!grid) return

  try {
    const controller = createGridController()
    collectWidgets(grid, controller.widgets)
    controller.widgets.forEach((widget) => bindWidget(controller, grid, widget))
    controller.mountRestoreBar(grid)
    controller.restore()
  } catch (e) {
    console.warn('Dashboard widgets: init failed', e)
  }
}

// --- Pure helpers used above ------------------------------------------------

function clampTo(value, min, max) {
  return Math.min(max, Math.max(min, value))
}

function parseStore(raw) {
  if (typeof raw !== 'string') return raw
  try {
    return JSON.parse(raw)
  } catch (e) {
    return null
  }
}

function isPlainRecord(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

// --- Storage -----------------------------------------------------------------

function readStore(key) {
  try {
    return localStorage.getItem(key)
  } catch (e) {
    // Private mode / blocked storage — fall back to the server-rendered layout.
    return null
  }
}

function writeStore(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch (e) {
    // Private mode / quota — resizing still works for this page view.
  }
}

function removeStore(key) {
  try {
    localStorage.removeItem(key)
  } catch (e) {
    // Private mode / blocked storage — nothing to drop; the reload below still
    // renders the server defaults.
  }
}

// --- Grid bootstrap ----------------------------------------------------------

function findGrid(root) {
  if (!root || typeof root.querySelector !== 'function') return null
  if (typeof root.matches === 'function' && root.matches(WIDGET_GRID_SELECTOR)) return root
  return root.querySelector(WIDGET_GRID_SELECTOR)
}

function createGridController() {
  const widgets = []
  let persistTimer = null
  let bar = null
  // Spans read back from the store at init, plus whether one has been changed
  // since — either is a customisation the bar has to offer a way back from.
  let storedSpans = {}
  let spansCustomised = false

  function persistSpans() {
    persistTimer = null
    // Built from the live widgets only, so a widget that has left the page
    // leaves its stale entry behind instead of being written back forever.
    const spans = {}
    widgets.forEach((widget) => {
      spans[widget.id] = clampSpan(widget.span)
    })
    writeStore(SPANS_STORE, spans)
  }

  function schedulePersist() {
    if (persistTimer !== null) window.clearTimeout(persistTimer)
    persistTimer = window.setTimeout(persistSpans, PERSIST_DEBOUNCE_MS)
  }

  function persistHidden() {
    writeStore(HIDDEN_STORE, widgets.filter((widget) => widget.hidden).map((widget) => widget.id))
  }

  // Spans read back from the store at init. A span recorded here for a widget
  // that is still on the page is a customisation even when nothing is hidden.
  function isCustomised() {
    if (spansCustomised) return true
    return widgets.some((widget) => Object.prototype.hasOwnProperty.call(storedSpans, widget.id))
  }

  function syncRestoreBar() {
    if (!bar) return

    const hidden = widgets.filter((widget) => widget.hidden)
    const summary = barSummary({ hiddenCount: hidden.length, customised: isCustomised() })
    bar.textContent = ''

    if (summary === null) {
      bar.hidden = true
      return
    }

    bar.appendChild(buildSummary(summary))
    hidden.forEach((widget) => bar.appendChild(buildChip(widget.id)))
    bar.appendChild(buildReset())
    bar.hidden = false
  }

  function resizeWidget(widget, span) {
    applySpan(widget, span)
    // A resize is a stored customisation in its own right: the bar is the only
    // way back to the server defaults once a span has been changed, so it has
    // to appear even though no widget is hidden.
    spansCustomised = true
    syncRestoreBar()
    schedulePersist()
  }

  function mountRestoreBar(grid) {
    if (bar || !grid.parentNode) return

    // A bar left behind by an earlier init belongs to the controller that built
    // it, so it is adopted untouched rather than rebuilt from a widget list this
    // controller does not own — that is also what keeps re-init idempotent.
    const existing = findRestoreBar(grid)
    if (existing) {
      bar = existing
      return
    }

    bar = createRestoreBar()
    bar.addEventListener('click', (event) => onRestoreBarClick(controller, event))
    grid.insertAdjacentElement('afterend', bar)
    // Widgets are collected before this runs, so their hidden flags already
    // carry the pre-paint pass in the markup — mirror them onto the bar now.
    syncRestoreBar()
  }

  function restore() {
    const storedHidden = readStore(HIDDEN_STORE)
    const hidden = decodeHidden(storedHidden)
    const spans = decodeSpans(readStore(SPANS_STORE))
    storedSpans = spans

    widgets.forEach((widget) => {
      if (Object.prototype.hasOwnProperty.call(spans, widget.id)) {
        widget.span = spans[widget.id]
        applySpan(widget, widget.span)
      } else {
        // No stored span: keep whatever the markup and CSS decided, only mirror
        // it onto the handle so screen readers can announce it.
        announceSpan(widget.handle, widget.span)
      }
      // The stored list is authoritative once it exists — until the operator has
      // said otherwise, a widget hidden in the markup stays hidden.
      if (storedHidden !== null) setHidden(widget, hidden.includes(widget.id), controller)
    })

    // The bar is mounted before any stored state is known, so re-evaluate it now
    // that both stores have been applied.
    syncRestoreBar()
  }

  const controller = { widgets, mountRestoreBar, restore, persistSpans, persistHidden, syncRestoreBar, resizeWidget }
  return controller
}

function collectWidgets(grid, widgets) {
  grid.querySelectorAll(WIDGET_SELECTOR).forEach((element) => {
    const id = element.dataset.widget
    const card = element.querySelector(':scope > .card')
    if (!id || !card || element.dataset.widgetReady === 'true') return

    element.dataset.widgetReady = 'true'
    widgets.push({
      id,
      element,
      card,
      handle: injectHandle(card),
      toggle: null,
      span: currentSpan(element) ?? FALLBACK_SPAN,
      hidden: element.classList.contains(HIDDEN_CLASS),
    })
  })
}

function injectHandle(card) {
  const handle = document.createElement('div')
  handle.className = 'widget-resize-handle'
  handle.setAttribute('data-widget-resize', '')
  handle.setAttribute('role', 'separator')
  handle.setAttribute('aria-orientation', 'vertical')
  handle.setAttribute('tabindex', '0')
  card.appendChild(handle)
  return handle
}

function currentSpan(element) {
  const inline = SPAN_PATTERN.exec(element.style.gridColumn || '')
  if (inline) return clampSpan(Number(inline[1]))

  if (typeof getComputedStyle === 'function') {
    const computed = SPAN_PATTERN.exec(getComputedStyle(element).gridColumn || '')
    if (computed) return clampSpan(Number(computed[1]))
  }

  return null
}

// --- State application -------------------------------------------------------

function applySpan(widget, span) {
  widget.span = clampSpan(span)
  widget.element.style.gridColumn = 'span ' + widget.span
  announceSpan(widget.handle, widget.span)
}

function setHidden(widget, hidden, controller) {
  widget.hidden = hidden
  widget.element.classList.toggle(HIDDEN_CLASS, hidden)
  syncToggle(widget)
  // The one place hidden state changes — toggling, restoring and the bar's own
  // chips all funnel through here, so the bar cannot drift from the cards.
  if (controller) controller.syncRestoreBar()
}

function announceSpan(handle, span) {
  if (!handle) return
  handle.setAttribute('aria-valuenow', String(span))
  handle.setAttribute('aria-valuemin', String(MIN_SPAN))
  handle.setAttribute('aria-valuemax', String(MAX_SPAN))
  handle.setAttribute('aria-valuetext', span + ' of ' + GRID_COLUMNS + ' columns')
}

function syncToggle(widget) {
  const button = widget.toggle
  if (!button) return

  const label = widget.hidden ? 'Show widget' : 'Hide widget'
  button.setAttribute('title', label)
  button.setAttribute('aria-pressed', widget.hidden ? 'true' : 'false')

  const icon = button.querySelector('.bi')
  if (icon) {
    icon.classList.toggle('bi-eye', !widget.hidden)
    icon.classList.toggle('bi-eye-slash', widget.hidden)
  }

  const hiddenText = button.querySelector('.visually-hidden')
  if (hiddenText) hiddenText.textContent = label
}

// --- Restore bar --------------------------------------------------------------
// A hidden widget's toggle lives inside the hidden card, so it can no longer be
// clicked. This bar sits directly under the grid and is the way back: one chip
// per hidden widget, plus a reset that drops both keys and reloads.

function findRestoreBar(grid) {
  const sibling = grid.nextElementSibling
  if (sibling && sibling.classList && sibling.classList.contains(RESTORE_BAR_CLASS)) return sibling
  return null
}

function createRestoreBar() {
  const bar = document.createElement('div')
  bar.className = RESTORE_BAR_CLASS + ' d-flex flex-wrap align-items-center gap-2 mt-3'
  bar.setAttribute('role', 'status')
  bar.setAttribute('aria-live', 'polite')
  bar.hidden = true
  return bar
}

/**
 * Summary line of the restore bar, as passed in by {@link barSummary}.
 * @param {string} label Copy to render.
 * @returns {HTMLSpanElement} The summary element.
 */
function buildSummary(label) {
  const summary = document.createElement('span')
  summary.className = 'text-muted small'
  summary.textContent = label
  return summary
}

function buildChip(id) {
  const chip = document.createElement('button')
  chip.setAttribute('type', 'button')
  chip.className = 'btn btn-sm btn-outline-secondary py-1 px-2'
  chip.setAttribute('data-widget-show', id)
  chip.setAttribute('style', 'border-radius: var(--radius-md); font-size: var(--text-xs);')
  chip.textContent = 'Show ' + widgetLabel(id)
  return chip
}

function buildReset() {
  const reset = document.createElement('button')
  reset.setAttribute('type', 'button')
  reset.className = 'btn btn-sm btn-link py-0 px-1'
  reset.setAttribute('data-widget-reset', '')
  reset.setAttribute('style', 'font-size: var(--text-xs); text-decoration: none;')
  reset.textContent = 'Reset layout'
  return reset
}

function onRestoreBarClick(controller, event) {
  const target = event.target
  if (!target || typeof target.closest !== 'function') return

  const showButton = target.closest('[data-widget-show]')
  if (showButton) {
    const id = showButton.getAttribute('data-widget-show')
    const widget = controller.widgets.find((entry) => entry.id === id)
    // setHidden re-renders the bar, so this chip disappears with its card's
    // hidden class instead of needing a second update path.
    if (widget) setHidden(widget, false, controller)
    controller.persistHidden()
    return
  }

  if (target.closest('[data-widget-reset]')) resetLayout()
}

function resetLayout() {
  removeStore(SPANS_STORE)
  removeStore(HIDDEN_STORE)
  location.reload()
}

// --- Event wiring ------------------------------------------------------------

function bindWidget(controller, grid, widget) {
  bindResize(controller, grid, widget)
  widget.handle.addEventListener('keydown', (event) => onHandleKey(controller, widget, event))
  bindToggle(controller, widget)
}

function bindResize(controller, grid, widget) {
  const handle = widget.handle

  handle.addEventListener('pointerdown', (event) => {
    event.preventDefault()
    event.stopPropagation()

    // Pointer capture keeps the cursor glued to the handle, but it is an
    // enhancement only: the drag listeners live on `document`, so a refused or
    // unsupported capture cannot strand the drag with moves that never arrive.
    try {
      handle.setPointerCapture(event.pointerId)
    } catch (e) {
      // capture unavailable — the document listeners below still drive the drag
    }

    handle.classList.add('is-dragging')
    document.body.classList.add(RESIZING_CLASS)

    const startX = event.clientX
    const startSpan = widget.span
    const gridWidth = grid.getBoundingClientRect().width

    const onMove = (moveEvent) => {
      controller.resizeWidget(widget, spanFromDrag({ startSpan, deltaPx: moveEvent.clientX - startX, gridWidthPx: gridWidth }))
    }

    const onUp = () => {
      document.removeEventListener('pointermove', onMove)
      document.removeEventListener('pointerup', onUp)
      document.removeEventListener('pointercancel', onUp)
      handle.classList.remove('is-dragging')
      document.body.classList.remove(RESIZING_CLASS)
      controller.persistSpans()
    }

    document.addEventListener('pointermove', onMove)
    document.addEventListener('pointerup', onUp)
    document.addEventListener('pointercancel', onUp)
  })
}

function onHandleKey(controller, widget, event) {
  let span = widget.span

  if (event.key === 'ArrowLeft') span -= 1
  else if (event.key === 'ArrowRight') span += 1
  else if (event.key === 'Home') span = MIN_SPAN
  else if (event.key === 'End') span = MAX_SPAN
  else return

  event.preventDefault()
  controller.resizeWidget(widget, span)
}

function bindToggle(controller, widget) {
  const button = widget.card.querySelector(TOGGLE_SELECTOR)
  if (!button) return

  widget.toggle = button
  syncToggle(widget)
  button.addEventListener('click', () => {
    setHidden(widget, !widget.hidden, controller)
    controller.persistHidden()
  })
}

// --- Bootstrap ---------------------------------------------------------------
// Imported by the admin bundle; degrades to a no-op on any page without a
// widget grid, and never runs at all where there is no document.

if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initDashboardWidgets())
  } else {
    initDashboardWidgets()
  }
}
