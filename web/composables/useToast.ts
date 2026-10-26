/**
 * Back-compat facade: `const toast = useToast(); toast.success('…')` — every call is routed through the central
 * notification layer (`utils/notify.ts`), so the look of all notifications is changed in one place.
 */
import { toastError, toastInfo, toastSuccess, toastWarning } from '~/utils/notify'

export const useToast = () => ({
  success: (m: string) => void toastSuccess(m),
  error: (m: string) => void toastError(m),
  warning: (m: string) => void toastWarning(m),
  info: (m: string) => void toastInfo(m),
})
