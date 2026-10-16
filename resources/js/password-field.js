/**
 * Password field affordances: generate, copy and show/hide.
 *
 * This is a Vite entry in its own right rather than an import of adminlte.js:
 * the installer runs on a bespoke layout with no Bootstrap and no AdminLTE
 * bundle, and it needs the same three controls. One module, two consumers.
 *
 * Every control is wired through a single delegated listener on the document.
 * Password fields here are re-rendered constantly — the hosting partials
 * render once per provisioning module and again per hosting account, and the
 * Hyper-V and compute action panels live inside modals — so wiring at
 * DOMContentLoaded would miss every field that arrives afterwards.
 */

const PW_LOWERCASE = 'abcdefghjkmnpqrstuvwxyz'
const PW_UPPERCASE = 'ABCDEFGHJKMNPQRSTUVWXYZ'
const PW_DIGITS = '23456789'
const PW_SYMBOLS = '!@#$%^&*-_=+'
const PW_DEFAULT_LENGTH = 16
const PW_COPY_RESET_MS = 1200

function pwRandom(max) {
  const buffer = new Uint32Array(1)
  window.crypto.getRandomValues(buffer)
  return buffer[0] % max
}

const pwPick = (set) => set[pwRandom(set.length)]

function pwShuffle(chars) {
  for (let i = chars.length - 1; i > 0; i--) {
    const j = pwRandom(i + 1)
    const swap = chars[i]
    chars[i] = chars[j]
    chars[j] = swap
  }
  return chars
}

/**
 * One character from each class is guaranteed, the remainder is drawn at
 * random, then the whole string is shuffled. Sampling the alphabet alone can
 * miss a class entirely at short lengths, and every password rule on this app
 * ("Min 8 chars, A-Z, a-z, 0-9") requires all three.
 */
function pwGenerate(length = PW_DEFAULT_LENGTH) {
  const chars = [
    pwPick(PW_LOWERCASE),
    pwPick(PW_UPPERCASE),
    pwPick(PW_DIGITS),
    pwPick(PW_SYMBOLS),
  ]
  const pool = PW_LOWERCASE + PW_UPPERCASE + PW_DIGITS + PW_SYMBOLS

  while (chars.length < length) {
    chars.push(pwPick(pool))
  }

  return pwShuffle(chars).join('')
}

function pwCopyLegacy(value) {
  const area = document.createElement('textarea')
  area.value = value
  area.setAttribute('readonly', '')
  area.style.position = 'fixed'
  area.style.opacity = '0'
  document.body.appendChild(area)
  area.select()
  const copied = document.execCommand('copy')
  document.body.removeChild(area)
  return copied
}

async function pwCopy(value) {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(value)
      return true
    } catch (e) {
      // Clipboard permission refused, or the document lost focus mid-call.
    }
  }
  return pwCopyLegacy(value)
}

function pwFlash(btn) {
  const icon = btn.querySelector('i')
  if (icon) {
    const original = icon.getAttribute('class')
    icon.setAttribute('class', 'bi bi-check-lg')
    window.setTimeout(() => icon.setAttribute('class', original), PW_COPY_RESET_MS)
    return
  }
  // Icon-less control: the installer has no Bootstrap Icons, so its buttons
  // are labelled in text and the label is what swaps.
  const original = btn.textContent
  btn.textContent = 'Copied'
  window.setTimeout(() => { btn.textContent = original }, PW_COPY_RESET_MS)
}

function pwInput(group) {
  return group.querySelector('[data-password-input]')
}

function pwAnnounce(input) {
  input.dispatchEvent(new Event('input', { bubbles: true }))
  input.dispatchEvent(new Event('change', { bubbles: true }))
}

function pwConfirmInput(group, input) {
  const target = group.getAttribute('data-password-confirm')
  if (!target) {
    return null
  }
  const confirm = document.getElementById(target.replace(/^#/, ''))
  // A paired id may resolve to a container rather than the field; writing a
  // password into a non-INPUT is silently lost — the legacy
  // fillGeneratedPassword guards this same id-collision case.
  return confirm && confirm !== input && confirm.tagName === 'INPUT' ? confirm : null
}

function pwToggleView(group) {
  const input = pwInput(group)
  if (!input) {
    return
  }
  const shown = input.type === 'password'
  input.type = shown ? 'text' : 'password'

  const btn = group.querySelector('[data-pw-toggle]')
  if (!btn) {
    return
  }
  const label = shown ? 'Hide password' : 'Show password'
  btn.setAttribute('aria-pressed', shown ? 'true' : 'false')
  btn.setAttribute('aria-label', label)
  btn.setAttribute('title', label)
  const icon = btn.querySelector('i')
  if (icon) {
    icon.setAttribute('class', shown ? 'bi bi-eye-slash' : 'bi bi-eye')
  } else {
    btn.textContent = shown ? 'Hide' : 'View'
  }
}

async function pwCopyValue(group) {
  const input = pwInput(group)
  if (!input) {
    return
  }
  if (await pwCopy(input.value)) {
    const btn = group.querySelector('[data-pw-copy]')
    if (btn) {
      pwFlash(btn)
    }
  }
}

function pwGenerateInto(group) {
  const input = pwInput(group)
  if (!input) {
    return
  }
  const length = Number(group.getAttribute('data-password-length')) || PW_DEFAULT_LENGTH
  const value = pwGenerate(length)

  // Fired before the value is written, so a field that masks itself — the
  // encrypted settings fields swap a mask in and lock the input readOnly —
  // can clear that state first. Listeners must not touch `value`.
  input.dispatchEvent(new CustomEvent('password-field:generate', { bubbles: true }))

  input.value = value
  input.removeAttribute('readonly')
  pwAnnounce(input)

  const confirm = pwConfirmInput(group, input)
  if (confirm) {
    confirm.value = value
    pwAnnounce(confirm)
  }
}

const PW_ACTIONS = {
  'data-pw-toggle': pwToggleView,
  'data-pw-copy': pwCopyValue,
  'data-pw-generate': pwGenerateInto,
}

function initPasswordFields() {
  document.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-pw-toggle], [data-pw-copy], [data-pw-generate]')
    if (!btn || btn.disabled) {
      return
    }
    const group = btn.closest('[data-password-field]')
    if (!group) {
      return
    }
    const action = PW_ACTIONS[Object.keys(PW_ACTIONS).find((key) => btn.hasAttribute(key))]
    if (action) {
      action(group)
    }
  })
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPasswordFields)
} else {
  initPasswordFields()
}
