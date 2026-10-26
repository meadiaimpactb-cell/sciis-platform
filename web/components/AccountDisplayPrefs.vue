<script setup lang="ts">
/**
 * My account → display: the calendar every date in the app uses (UX-05: Gregorian, Hijri Umm al-Qura or both, always
 * Latin digits) and, while FEATURE_DARK_MODE is on, the portal theme (BRD-04: device / light / dark).
 * Saved on the account (PATCH /me/preferences) and in this browser, so the public site follows it too.
 */
import type { CalendarPref, DisplayPreferences, ThemePref } from '~/types/platform'
import { storeCalendarChoice } from '~/utils/displayPrefs'
import { toastApiError, toastSuccess } from '~/utils/notify'

const { t, locale } = useI18n()
const api = useApi()
const auth = useAuthStore()
const mode = useCalendarMode()
const theme = usePortalTheme()

const calendar = ref<CalendarPref>(auth.me?.preferences?.calendar ?? mode.value)
const themePref = ref<ThemePref>(theme.pref.value)
const saving = ref(false)
watch(() => auth.me?.preferences, (p) => { if (p && !saving.value) { calendar.value = p.calendar; themePref.value = p.theme } })

/** Today in each calendar, as the option hint (Latin digits). */
const sample = (ca: 'gregory' | 'islamic-umalqura') => new Intl.DateTimeFormat(`${locale.value === 'ar' ? 'ar-SA' : 'en-GB'}-u-ca-${ca}-nu-latn`, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date())
const calendarOpts = computed(() => [
  { value: 'gregorian', label: t('platform.calendar.gregorian'), hint: sample('gregory') },
  { value: 'hijri', label: t('platform.calendar.hijri'), hint: sample('islamic-umalqura') },
  { value: 'both', label: t('platform.calendar.both'), hint: `${sample('gregory')} · ${sample('islamic-umalqura')}` },
])
const themeOpts = computed(() => (['system', 'light', 'dark'] as ThemePref[]).map((v) => ({ value: v, label: t(`platform.theme.${v}`) })))

const save = async () => {
  saving.value = true
  try {
    const body: Partial<DisplayPreferences> = { calendar: calendar.value, ...(theme.enabled.value ? { theme: themePref.value || 'system' } : {}) }
    const r = await api.patch<{ data: { preferences: DisplayPreferences } }>('/me/preferences', body)
    if (auth.me) auth.me.preferences = r.data.preferences
    mode.value = r.data.preferences.calendar
    storeCalendarChoice(r.data.preferences.calendar)
    if (theme.enabled.value) await theme.setTheme(r.data.preferences.theme)
    toastSuccess(t('notify.updated', { item: t('platform.display.title') }))
  } catch (e) {
    toastApiError(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SectionCard :title="$t('platform.display.title')" :sub="$t('platform.display.sub')">
    <form class="space-y-4" novalidate @submit.prevent="save">
      <FormField v-model="calendar" name="calendar" type="radio-cards" :label="$t('platform.calendar.label')" :options="calendarOpts" />
      <FormField v-if="theme.enabled.value" v-model="themePref" name="theme" type="select" :label="$t('platform.theme.label')" :help="$t('platform.theme.help')" :options="themeOpts" :placeholder="$t('platform.theme.system')" class="sm:max-w-xs" />
      <div class="flex justify-end">
        <AppButton type="submit" :loading="saving">{{ $t('common.save') }}</AppButton>
      </div>
    </form>
  </SectionCard>
</template>
