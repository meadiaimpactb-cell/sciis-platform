/**
 * "I'm a… investor / project owner" join chooser — one modal instance lives in the default layout; any CTA opens it.
 * Focus returns to the element that opened it when it closes. (`opener` is only ever set from a client-side event.)
 */
let opener: HTMLElement | null = null

export function useJoinChooser() {
  const isOpen = useState('join-chooser-open', () => false)
  const open = (e?: Event) => {
    if (import.meta.client) opener = (e?.currentTarget as HTMLElement | null) ?? (document.activeElement as HTMLElement | null)
    isOpen.value = true
  }
  const close = (restoreFocus = true) => {
    isOpen.value = false
    const el = opener
    opener = null
    if (import.meta.client && restoreFocus && el) nextTick(() => el.focus?.())
  }
  return { isOpen, open, close }
}
