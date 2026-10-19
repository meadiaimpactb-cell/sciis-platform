/** Small wrapper around a loader with loading/error state and refresh (client-only portal pages). */
export const useAsyncResource = <T>(loader: () => Promise<T>, opts: { immediate?: boolean } = {}) => {
  const data = ref<T | null>(null) as Ref<T | null>
  const loading = ref(false)
  const error = ref<string | null>(null)

  const refresh = async () => {
    loading.value = true
    error.value = null
    try {
      data.value = await loader()
    } catch (e) {
      error.value = (e as Error).message ?? 'error'
    } finally {
      loading.value = false
    }
  }
  // Loads on the client only: portal pages are client-only (routeRules ssr:false). If SSR is ever enabled for them,
  // both sides render the same "loading" state first, so there is no hydration mismatch.
  if (opts.immediate !== false) onMounted(refresh)
  return { data, loading, error, refresh }
}
