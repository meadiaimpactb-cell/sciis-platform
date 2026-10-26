<script setup lang="ts">
/**
 * ACC-09 (P3) — national identity verification through Nafath on "My account". Shown only when the API has an
 * identity driver configured (GET /me/identity-verification → `available`). Flow: national ID → the platform shows a
 * number → the user picks it in the Nafath app → this card polls until approved / rejected / expired.
 */
import type { IdentityVerificationRequest, IdentityVerificationState } from '~/types/platform'
import { parseApiError } from '~/utils/apiError'
import { toastApiError, toastError, toastSuccess } from '~/utils/notify'

const { t } = useI18n()
const api = useApi()
const auth = useAuthStore()
const { dateTime } = useFormat()

const state = ref<IdentityVerificationState | null>(null)
const request = ref<IdentityVerificationRequest | null>(null)
const nationalId = ref('')
const error = ref<string | undefined>()
const starting = ref(false)
const secondsLeft = ref(0)
let pollTimer: ReturnType<typeof setTimeout> | null = null
let tick: ReturnType<typeof setInterval> | null = null

const stop = () => {
  if (pollTimer) clearTimeout(pollTimer)
  if (tick) clearInterval(tick)
  pollTimer = null
  tick = null
}
onBeforeUnmount(stop)

const countdown = () => {
  const end = request.value?.expires_at ? new Date(request.value.expires_at).getTime() : 0
  secondsLeft.value = Math.max(0, Math.round((end - Date.now()) / 1000))
}
const poll = async () => {
  if (!request.value) return
  try {
    const r = (await api.get<{ data: IdentityVerificationRequest }>(`/me/identity-verification/${request.value.uuid}`)).data
    request.value = r
    if (r.status === 'pending') {
      pollTimer = setTimeout(poll, 4000)
      return
    }
    stop()
    if (r.status === 'approved') {
      toastSuccess(t('platform.identity.verifiedToast'))
      await auth.fetchMe(true)
      await load()
    } else toastError(t(`platform.identity.result.${r.status}`))
  } catch (e) {
    stop()
    toastApiError(e)
  }
}
const follow = (r: IdentityVerificationRequest) => {
  request.value = r
  stop()
  countdown()
  tick = setInterval(countdown, 1000)
  pollTimer = setTimeout(poll, 4000)
}

const load = async () => {
  try {
    state.value = (await api.get<{ data: IdentityVerificationState }>('/me/identity-verification')).data
    if (state.value.pending) follow(state.value.pending)
  } catch {
    state.value = null
  }
}
onMounted(load)

const start = async () => {
  error.value = undefined
  const id = nationalId.value.trim()
  if (!/^[12]\d{9}$/.test(id)) {
    error.value = t('platform.identity.format')
    return
  }
  starting.value = true
  try {
    follow((await api.post<{ data: IdentityVerificationRequest }>('/me/identity-verification', { national_id: id })).data)
    nationalId.value = ''
  } catch (e) {
    const p = parseApiError(e)
    if (p.errors.national_id) error.value = p.errors.national_id
    else toastApiError(e)
  } finally {
    starting.value = false
  }
}
const pending = computed(() => request.value?.status === 'pending')
const verifiedAt = computed(() => state.value?.verified_at ?? auth.me?.national_id_verified_at ?? null)
</script>

<template>
  <SectionCard v-if="state?.available || verifiedAt" :title="$t('platform.identity.title')" :sub="$t('platform.identity.sub')">
    <div v-if="verifiedAt" class="flex items-center gap-3 text-sm">
      <span class="inline-flex items-center gap-1 rounded-full bg-success/10 text-success font-medium px-2.5 py-1">
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
        {{ $t('platform.identity.verified') }}
      </span>
      <span class="text-muted num">{{ dateTime(verifiedAt) }}</span>
    </div>
    <div v-else-if="pending && request" class="space-y-3" aria-live="polite">
      <p class="text-sm text-ink">{{ $t('platform.identity.pickNumber') }}</p>
      <p class="num text-5xl font-bold text-navy text-center tracking-widest">{{ request.random }}</p>
      <p class="text-xs text-muted text-center num">{{ $t('platform.identity.expiresIn', { s: secondsLeft }) }}</p>
    </div>
    <form v-else class="grid sm:grid-cols-[1fr_auto] gap-3 items-start" novalidate @submit.prevent="start">
      <FormField v-model="nationalId" name="national_id" :label="$t('platform.identity.nationalId')" :help="$t('platform.identity.help')" :error="error" :maxlength="10" autocomplete="off" ltr required />
      <AppButton type="submit" class="sm:mt-[1.625rem]" :loading="starting">{{ $t('platform.identity.start') }}</AppButton>
      <p v-if="request && !pending" class="sm:col-span-2 text-sm text-warning" role="status">{{ $t(`platform.identity.result.${request.status}`) }}</p>
    </form>
  </SectionCard>
</template>
