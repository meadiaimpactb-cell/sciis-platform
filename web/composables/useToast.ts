/** Tiny toast queue (no external lib). */
export interface Toast {
  id: number
  kind: 'success' | 'error' | 'info'
  message: string
}
const toasts = ref<Toast[]>([])
let seq = 1

export const useToast = () => {
  const push = (kind: Toast['kind'], message: string, ttl = 3500) => {
    const id = seq++
    toasts.value.push({ id, kind, message })
    if (import.meta.client) setTimeout(() => dismiss(id), ttl)
    return id
  }
  const dismiss = (id: number) => {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }
  return {
    toasts,
    success: (m: string) => push('success', m),
    error: (m: string) => push('error', m, 5000),
    info: (m: string) => push('info', m),
    dismiss,
  }
}
