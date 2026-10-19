/** Taxonomies + public settings cache (FRM-04). */
import { defineStore } from 'pinia'
import type { PublicSettings, Taxonomies, TaxonomyTerm } from '~/types/api'
import type { TaxonomyGroup } from '~/types/enums'

export const useTaxonomyStore = defineStore('taxonomies', () => {
  const groups = ref<Partial<Taxonomies>>({})
  const settings = ref<PublicSettings | null>(null)
  const loaded = ref(false)

  async function load(force = false) {
    if (loaded.value && !force) return
    const api = useApi()
    const [t, s] = await Promise.all([api.get<{ data: Taxonomies }>('/taxonomies'), api.get<{ data: PublicSettings }>('/settings/public').catch(() => null)])
    groups.value = t.data
    if (s) settings.value = s.data
    loaded.value = true
  }

  const list = (group: TaxonomyGroup): TaxonomyTerm[] => (groups.value[group] ?? []).filter((t) => t.is_active)
  const byId = (group: TaxonomyGroup, id: number | null | undefined) => (groups.value[group] ?? []).find((t) => t.id === id)
  const byKey = (group: TaxonomyGroup, key: string | null | undefined) => (groups.value[group] ?? []).find((t) => t.key === key)

  return { groups, settings, loaded, load, list, byId, byKey }
})
