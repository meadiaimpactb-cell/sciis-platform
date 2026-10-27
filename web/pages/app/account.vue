<script setup lang="ts">
/**
 * My account — every role: profile picture, display name, preferred contact, interface language, password change,
 * and notification channel preferences. Email/phone are identity channels verified by OTP, so they are read-only here
 * (with their verification state).
 */
import { z } from 'zod'
import { currentPasswordField, imageFileError, nameField, parseApiError, requiredChoice, withPasswordConfirmation } from '~/utils/validation'
import { confirmDialog, toastApiError, toastError, toastSuccess } from '~/utils/notify'
import type { Me } from '~/types/api'
definePageMeta({ layout: 'portal', middleware: ['auth'] })
const { t, setLocale } = useI18n()
useHead({ title: t('account.metaTitle') })
const api = useApi()
const auth = useAuthStore()
const router = useRouter()
const localePath = useLocalePath()
const { label: enumLabel } = useEnumLabel()

// ---------- profile ----------
const profile = useZodForm(
  z.object({ name: nameField, preferred_contact: requiredChoice('account.preferredContact'), locale: z.enum(['ar', 'en']) }) as never,
  { name: auth.me?.name ?? '', preferred_contact: auth.me?.preferred_contact ?? 'email', locale: (auth.me?.locale ?? 'ar') as 'ar' | 'en' },
)
watch(() => auth.me, (me) => { if (me && !profile.submitting.value) profile.reset({ name: me.name, preferred_contact: me.preferred_contact ?? 'email', locale: me.locale }) })
const saveProfile = () => profile.submit(async (v) => {
  await api.patch<{ data: Me }>('/me', { ...v, name: v.name.trim() })
  await auth.fetchMe(true)
  if (v.locale) await setLocale(v.locale)
  toastSuccess(t('notify.updated', { item: t('notify.entities.profile') }))
})
const contactOptions = computed(() => [
  { value: 'email', label: t('account.contact.email') },
  { value: 'phone', label: t('account.contact.phone') },
  { value: 'whatsapp', label: t('account.contact.whatsapp') },
])
const localeOptions = [{ value: 'ar', label: 'العربية' }, { value: 'en', label: 'English' }]

// ---------- avatar ----------
const fileInput = ref<HTMLInputElement | null>(null)
const avatarBusy = ref(false)
const onAvatar = async (ev: Event) => {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file) return
  const problem = imageFileError(file)
  if (problem) {
    if (fileInput.value) fileInput.value.value = ''
    return void toastError(problem)
  }
  avatarBusy.value = true
  try {
    const fd = new FormData()
    fd.append('file', file)
    await api.post('/me/avatar', fd)
    await auth.fetchMe(true)
    toastSuccess(t('notify.uploaded', { item: t('notify.entities.avatar') }))
  } catch (e) {
    const p = parseApiError(e); if (p.status === 422 || (p.status ?? 0) >= 500) toastError(Object.values(p.errors)[0] ?? t('notify.uploadFailed')); else toastApiError(e)
  } finally {
    avatarBusy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}
const removeAvatar = async () => {
  const name = t('notify.entities.avatar')
  if (!(await confirmDialog(t('notify.deleteTitle'), t('notify.deleteText', { name }), t('notify.deleteConfirm')))) return
  avatarBusy.value = true
  try {
    await api.del('/me/avatar')
    await auth.fetchMe(true)
    toastSuccess(t('notify.deleted', { name }))
  } catch {
    toastError(t('notify.deleteFailed', { name }))
  } finally {
    avatarBusy.value = false
  }
}

// ---------- password ----------
const pwd = useZodForm(withPasswordConfirmation({ current_password: currentPasswordField }), { current_password: '', password: '', password_confirmation: '' })
const savePassword = () => pwd.submit(async (v) => {
  await api.put('/me/password', v)
  pwd.reset()
  toastSuccess(t('notify.passwordChanged'))
})

// ---------- notification preferences ----------
interface PrefRow { category: string; database: boolean; mail: boolean; sms: boolean; whatsapp?: boolean }
// NTF-04 — WhatsApp alerts apply only while the preferred contact is WhatsApp.
const whatsappOn = computed(() => auth.me?.preferred_contact === 'whatsapp')
const prefs = ref<PrefRow[]>([])
const prefsBusy = ref(false)
onMounted(async () => {
  try {
    prefs.value = (await api.get<{ data: { preferences: PrefRow[] } }>('/me/notification-preferences')).data.preferences ?? []
  } catch {
    prefs.value = []
  }
})
const savePrefs = async () => {
  prefsBusy.value = true
  try {
    const r = await api.put<{ data: { preferences: PrefRow[] } }>('/me/notification-preferences', { preferences: prefs.value.map(({ category, mail, sms, whatsapp }) => ({ category, mail, sms, whatsapp: !!whatsapp })) })
    prefs.value = r.data.preferences ?? prefs.value
    toastSuccess(t('notify.updated', { item: t('notify.entities.notificationPrefs') }))
  } catch (e) {
    toastApiError(e)
  } finally {
    prefsBusy.value = false
  }
}
/** OPP-07 new-opportunity alerts and MSG-03 weekly digest go in-app + by e-mail only (no SMS / WhatsApp). */
const EMAIL_ONLY = ['alerts', 'digest']
const prefLabel = (c: string) => (t(`account.notifications.categories.${c}`) !== `account.notifications.categories.${c}` ? t(`account.notifications.categories.${c}`) : c)
const roleLabel = computed(() => (auth.isStaff ? auth.roles.map((r) => enumLabel('role', r)).join('، ') : enumLabel('userType', auth.me?.type)))
const goVerify = () => router.push(localePath('/auth/verify'))
</script>

<template>
  <div>
    <PageHeader :title="$t('account.title')" :sub="$t('account.sub')" :eyebrow="$t('topbar.profile')" />

    <div class="grid gap-6 lg:grid-cols-3">
      <!-- identity card -->
      <SectionCard class="lg:col-span-1">
        <div class="flex flex-col items-center text-center">
          <UserAvatar :name="auth.me?.name" :src="auth.me?.avatar_url" size="xl" />
          <p class="mt-4 card-title">{{ auth.me?.name }}</p>
          <p class="text-sm text-muted">{{ roleLabel }}</p>
          <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" :aria-label="$t('account.avatar.change')" @change="onAvatar">
          <div class="mt-4 flex flex-wrap justify-center gap-2">
            <AppButton size="sm" :loading="avatarBusy" @click="fileInput?.click()">{{ auth.me?.avatar_url ? $t('account.avatar.change') : $t('account.avatar.upload') }}</AppButton>
            <AppButton v-if="auth.me?.avatar_url" size="sm" variant="secondary" :disabled="avatarBusy" @click="removeAvatar">{{ $t('account.avatar.remove') }}</AppButton>
          </div>
          <p class="mt-2 text-xs text-muted">{{ $t('account.avatar.hint') }}</p>
        </div>
        <dl class="mt-6 pt-5 border-t border-border space-y-3 text-sm">
          <div class="flex items-start justify-between gap-3">
            <dt class="text-muted">{{ $t('common.email') }}</dt>
            <dd class="min-w-0 text-end">
              <span class="block ltr text-ink font-medium truncate" dir="ltr">{{ auth.me?.email }}</span>
              <span v-if="auth.emailVerified" class="mt-1 inline-flex items-center gap-1 rounded-full border border-success/30 bg-success/10 text-success text-xs font-medium px-2.5 py-0.5"><svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>{{ $t('auth.verify.verified') }}</span>
              <span v-else class="mt-1 inline-flex items-center gap-2 text-xs"><span class="rounded-full border border-warning/30 bg-warning/10 text-warning font-medium px-2.5 py-0.5">{{ $t('phoneField.unverified') }}</span><button type="button" class="link text-xs" @click="goVerify">{{ $t('phoneField.verifyNow') }}</button></span>
            </dd>
          </div>
          <div class="flex items-start justify-between gap-3">
            <dt class="text-muted">{{ $t('common.phone') }}</dt>
            <dd class="min-w-0 text-end">
              <span class="block ltr num text-ink font-medium" dir="ltr">{{ auth.me?.phone || '—' }}</span>
              <span v-if="auth.phoneVerified" class="mt-1 inline-flex items-center gap-1 rounded-full border border-success/30 bg-success/10 text-success text-xs font-medium px-2.5 py-0.5"><svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>{{ $t('phoneField.verified') }}</span>
              <span v-else-if="auth.me?.phone" class="mt-1 inline-flex items-center gap-2 text-xs"><span class="rounded-full border border-warning/30 bg-warning/10 text-warning font-medium px-2.5 py-0.5">{{ $t('phoneField.unverified') }}</span><button type="button" class="link text-xs" @click="goVerify">{{ $t('phoneField.verifyNow') }}</button></span>
            </dd>
          </div>
          <div class="flex items-center justify-between gap-3">
            <dt class="text-muted">{{ $t('topbar.security') }}</dt>
            <dd><NuxtLink :to="$localePath('/app/security')" class="link text-sm">{{ auth.me?.two_factor_enabled ? $t('account.twoFactorOn') : $t('account.twoFactorOff') }}</NuxtLink></dd>
          </div>
        </dl>
        <p class="mt-4 text-xs text-muted leading-6">{{ $t('account.identityNote') }}</p>
      </SectionCard>

      <div class="lg:col-span-2 space-y-6">
        <!-- profile -->
        <SectionCard :title="$t('account.profileTitle')" :sub="$t('account.profileSub')">
          <form class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveProfile">
            <FormField v-model="profile.values.name" name="name" :label="$t('account.name')" :error="profile.errors.value.name" required class="sm:col-span-2" autocomplete="name" />
            <FormField v-model="profile.values.preferred_contact" name="preferred_contact" type="select" :label="$t('account.preferredContact')" :options="contactOptions" :error="profile.errors.value.preferred_contact" required />
            <FormField v-model="profile.values.locale" name="locale" type="select" :label="$t('account.language')" :options="localeOptions" :error="profile.errors.value.locale" required />
            <FormAlert :message="profile.formError.value" class="sm:col-span-2" />
            <div class="sm:col-span-2 flex justify-end">
              <AppButton type="submit" :loading="profile.submitting.value">{{ $t('common.save') }}</AppButton>
            </div>
          </form>
        </SectionCard>

        <!-- UX-05 / BRD-04 — calendar (Gregorian / Hijri) and portal theme -->
        <AccountDisplayPrefs />

        <!-- ACC-09 — Nafath identity verification (shown only when a driver is configured) -->
        <IdentityVerificationCard />

        <!-- password -->
        <SectionCard :title="$t('account.password.title')" :sub="$t('account.password.sub')">
          <form class="grid gap-4 sm:grid-cols-3 sm:items-start" novalidate @submit.prevent="savePassword">
            <FormField v-model="pwd.values.current_password" name="current_password" type="password" :label="$t('account.password.current')" :error="pwd.errors.value.current_password" required autocomplete="current-password" ltr />
            <div>
              <FormField v-model="pwd.values.password" name="password" type="password" :label="$t('account.password.new')" :error="pwd.errors.value.password" required autocomplete="new-password" ltr />
              <PasswordChecklist :password="pwd.values.password" />
            </div>
            <FormField v-model="pwd.values.password_confirmation" name="password_confirmation" type="password" :label="$t('account.password.confirm')" :error="pwd.errors.value.password_confirmation" required autocomplete="new-password" ltr />
            <FormAlert :message="pwd.formError.value" class="sm:col-span-3" />
            <div class="sm:col-span-3 flex justify-end">
              <AppButton type="submit" :loading="pwd.submitting.value">{{ $t('account.password.submit') }}</AppButton>
            </div>
          </form>
        </SectionCard>

        <!-- notification preferences -->
        <SectionCard v-if="prefs.length" :title="$t('account.notifications.title')" :sub="$t('account.notifications.sub')" flush>
          <div class="overflow-x-auto">
            <table class="table-base">
              <thead>
                <tr><th>{{ $t('account.notifications.category') }}</th><th class="text-center">{{ $t('account.notifications.inApp') }}</th><th class="text-center">{{ $t('account.notifications.email') }}</th><th class="text-center">{{ $t('account.notifications.sms') }}</th><th class="text-center">{{ $t('account.notifications.whatsapp') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="p in prefs" :key="p.category">
                  <td class="font-medium text-ink">{{ prefLabel(p.category) }}</td>
                  <td class="text-center"><input type="checkbox" checked disabled class="h-4 w-4 accent-accent" :aria-label="`${prefLabel(p.category)} — ${$t('account.notifications.inApp')}`"></td>
                  <td class="text-center"><input v-model="p.mail" type="checkbox" class="h-4 w-4 accent-accent" :aria-label="`${prefLabel(p.category)} — ${$t('account.notifications.email')}`"></td>
                  <!-- OPP-07 alerts / MSG-03 digest: in-app + e-mail only -->
                  <template v-if="EMAIL_ONLY.includes(p.category)"><td class="text-center text-muted">—</td><td class="text-center text-muted">—</td></template>
                  <template v-else>
                    <td class="text-center"><input v-model="p.sms" type="checkbox" class="h-4 w-4 accent-accent" :aria-label="`${prefLabel(p.category)} — ${$t('account.notifications.sms')}`"></td>
                    <td class="text-center"><input v-model="p.whatsapp" type="checkbox" class="h-4 w-4 accent-accent disabled:opacity-40" :disabled="!whatsappOn" :aria-label="`${prefLabel(p.category)} — ${$t('account.notifications.whatsapp')}`"></td>
                  </template>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3 border-t border-border">
            <p class="text-xs text-muted">{{ whatsappOn ? $t('collab.whatsapp.prefHint') : $t('collab.whatsapp.prefOff') }}</p>
            <AppButton size="sm" :loading="prefsBusy" @click="savePrefs">{{ $t('common.save') }}</AppButton>
          </div>
        </SectionCard>

        <!-- MTG-05 calendar subscription -->
        <CalendarFeedCard />
      </div>
    </div>
  </div>
</template>
