/**
 * Central notification & confirmation layer — the ONLY place that talks to SweetAlert2.
 * Screens and components import these functions (or `useToast()`, which delegates here); never `Swal` directly.
 *
 * Visual contract (look in assets/css/main.css → "SweetAlert2 skin"):
 *  - always top-centre (`top`): direction-neutral, so it never needs flipping for RTL and never sits on the header edge;
 *  - mounted on <body> in a layer above the fixed header, the sidebar and any open modal (z-index 99999);
 *  - 340–460 px wide (full width minus 24 px on phones), 16 px / 700 title, 30 px icon, a 5 px stripe in the
 *    type colour on the inline-start edge;
 *  - success / info 4 s · warning 6 s · error 8 s, with a progress bar, a close × and the timer paused on hover;
 *  - one toast at a time: a new one replaces the previous one, and waits while a confirmation dialog is open
 *    (SweetAlert shows a single popup, so firing a toast would otherwise close the dialog);
 *  - `role="alert"` with `aria-live` assertive for errors, polite otherwise.
 *
 * Wording: past tense, names the item («تم تحديث الفرصة»), one short line, shown only after the server confirmed.
 * SweetAlert2 (+ its CSS) is loaded lazily on first use, keeping it out of the entry bundle.
 */
import type { SweetAlertIcon } from 'sweetalert2'
import { parseApiError } from '~/utils/apiError'

type ToastKind = Extract<SweetAlertIcon, 'success' | 'error' | 'warning' | 'info'>
const DURATION: Record<ToastKind, number> = { success: 4000, info: 4000, warning: 6000, error: 8000 }

type SwalModule = typeof import('sweetalert2')['default']
let swalPromise: Promise<SwalModule> | null = null
/** Resolves when the open confirmation dialog closes (toasts queue behind it). */
let dialogOpen: Promise<unknown> | null = null

function loadSwal(): Promise<SwalModule> {
  swalPromise ??= Promise.all([import('sweetalert2'), import('sweetalert2/dist/sweetalert2.min.css')]).then(([m]) => m.default)
  return swalPromise
}

function isRtl(): boolean {
  if (typeof document === 'undefined') return true
  return (document.documentElement.getAttribute('dir') ?? 'rtl') === 'rtl'
}

/** Translate with the app's i18n when available (call-time, so the current locale is used). */
function tr(key: string, fallback: string, params?: Record<string, unknown>): string {
  try {
    const i18n = useNuxtApp().$i18n as { t: (k: string, p?: Record<string, unknown>) => string; te: (k: string) => boolean }
    return i18n.te(key) ? i18n.t(key, params ?? {}) : fallback
  } catch {
    return fallback
  }
}

/** Shows the toast and resolves once it is on screen (not when it closes), so callers can `await` it freely. */
async function fire(kind: ToastKind, title: string): Promise<void> {
  if (typeof window === 'undefined' || !title) return
  const Swal = await loadSwal()
  while (dialogOpen) await dialogOpen
  void Swal.fire({
    toast: true,
    icon: kind,
    title,
    position: 'top',
    target: 'body',
    showConfirmButton: false,
    showCloseButton: true,
    closeButtonAriaLabel: tr('notify.close', 'إغلاق'),
    timer: DURATION[kind],
    timerProgressBar: true,
    showClass: { popup: 'sciis-toast-in', icon: 'swal2-icon-show' },
    hideClass: { popup: 'sciis-toast-out' },
    customClass: { container: 'sciis-swal-layer', popup: `sciis-toast sciis-toast--${kind}`, title: 'sciis-toast__title' },
    didOpen: (el) => {
      el.setAttribute('dir', isRtl() ? 'rtl' : 'ltr')
      el.setAttribute('role', 'alert')
      el.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite')
      el.addEventListener('mouseenter', Swal.stopTimer)
      el.addEventListener('mouseleave', Swal.resumeTimer)
    },
  })
}

export const toastSuccess = (title: string) => fire('success', title)
export const toastError = (title: string) => fire('error', title)
export const toastWarning = (title: string) => fire('warning', title)
export const toastInfo = (title: string) => fire('info', title)

/**
 * Error toast for an API failure outside a form (row actions, buttons): 429 → `notify.tooMany`, a 422 → its first
 * field message, anything else → the mapped server / network message (`parseApiError`).
 */
export function toastApiError(e: unknown) {
  const p = parseApiError(e)
  if (p.status === 429) return toastError(tr('notify.tooMany', p.message))
  return toastError(Object.values(p.errors)[0] ?? p.message)
}

/**
 * Confirmation dialog — the only modal: decisions that need explicit consent (delete, cancel, irreversible actions).
 * Always name the item in `text`.
 */
export async function confirmDialog(title: string, text = '', confirmText?: string): Promise<boolean> {
  if (typeof window === 'undefined') return false
  const Swal = await loadSwal()
  const result = Swal.fire({
    icon: 'question',
    title,
    text,
    target: 'body',
    showCancelButton: true,
    confirmButtonText: confirmText ?? tr('notify.confirm', 'تأكيد'),
    cancelButtonText: tr('notify.cancel', 'إلغاء'),
    // swal2 renders [confirm, cancel] in DOM order and the dialog itself is `dir=rtl` in Arabic, so the natural order
    // already puts confirm on the right (reading start); only LTR needs reversing to keep confirm on the right.
    reverseButtons: !isRtl(),
    focusCancel: true,
    // buttons styled from the theme tokens (main.css), not swal2's inline colours
    customClass: { container: 'sciis-swal-layer', popup: 'sciis-dialog' },
    didOpen: (el) => el.setAttribute('dir', isRtl() ? 'rtl' : 'ltr'),
  })
  // swal2 returns a thenable, not a native Promise (no .catch/.finally).
  const pending = Promise.resolve(result).catch(() => undefined).finally(() => {
    if (dialogOpen === pending) dialogOpen = null
  })
  dialogOpen = pending
  return (await result).isConfirmed
}
