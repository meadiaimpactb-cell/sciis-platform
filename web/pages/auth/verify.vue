<script setup lang="ts">
/** Email + phone OTP verification (ACC-01). */
definePageMeta({ layout: 'auth', middleware: 'auth' })
const { t } = useI18n()
useHead({ title: t('auth.verify.metaTitle') })
const auth = useAuthStore()
const router = useRouter()
const localePath = useLocalePath()
const toast = useToast()
const { mock } = useApiBase()
const emailCode = ref(''); const phoneCode = ref('')
const emailErr = ref(''); const phoneErr = ref('')
const busy = ref<'email' | 'phone' | null>(null)
const verify = async (ch: 'email' | 'phone') => {
  const code = ch === 'email' ? emailCode.value : phoneCode.value
  const err = ch === 'email' ? emailErr : phoneErr
  err.value = ''
  if (!/^\d{6}$/.test(code)) return (err.value = t('validation.code6'))
  busy.value = ch
  try { await auth.verify(ch, code); toast.success(t('auth.verify.verified')) } catch (e) { err.value = (e as { message: string }).message } finally { busy.value = null }
}
const resend = async (ch: 'email' | 'sms') => { await auth.resendOtp(ch); toast.info(t('auth.verify.resent')) }
</script>

<template>
  <div class="card card-pad">
    <h1 class="text-2xl font-semibold">{{ $t('auth.verify.title') }}</h1>
    <p class="text-muted mt-1">{{ $t('auth.verify.sub') }}</p>
    <p v-if="mock" class="text-xs text-muted mt-2">{{ $t('auth.verify.demoHint') }}</p>
    <div class="mt-6 space-y-6">
      <section class="rounded-xl border border-border p-4">
        <div class="flex items-center justify-between gap-2"><h2 class="font-medium">{{ $t('common.email') }} <span class="text-muted text-sm ltr inline-block">{{ auth.me?.email }}</span></h2><AppBadge v-if="auth.me?.email_verified_at" kind="generic" value="approved" :label="$t('auth.verify.verified')" /></div>
        <form v-if="!auth.me?.email_verified_at" class="mt-3 flex flex-col sm:flex-row gap-2 sm:items-end" novalidate @submit.prevent="verify('email')">
          <FormField v-model="emailCode" name="email_code" :label="$t('auth.verify.emailCode')" :error="emailErr" ltr :maxlength="6" placeholder="000000" class="flex-1" autocomplete="one-time-code" />
          <AppButton type="submit" :loading="busy === 'email'">{{ $t('auth.verify.verifyEmail') }}</AppButton>
          <AppButton type="button" variant="ghost" @click="resend('email')">{{ $t('auth.verify.resend') }}</AppButton>
        </form>
      </section>
      <section class="rounded-xl border border-border p-4">
        <div class="flex items-center justify-between gap-2"><h2 class="font-medium">{{ $t('common.phone') }} <span class="text-muted text-sm ltr inline-block num">{{ auth.me?.phone }}</span></h2><AppBadge v-if="auth.me?.phone_verified_at" kind="generic" value="approved" :label="$t('auth.verify.verified')" /></div>
        <form v-if="!auth.me?.phone_verified_at" class="mt-3 flex flex-col sm:flex-row gap-2 sm:items-end" novalidate @submit.prevent="verify('phone')">
          <FormField v-model="phoneCode" name="phone_code" :label="$t('auth.verify.phoneCode')" :error="phoneErr" ltr :maxlength="6" placeholder="000000" class="flex-1" autocomplete="one-time-code" />
          <AppButton type="submit" :loading="busy === 'phone'">{{ $t('auth.verify.verifyPhone') }}</AppButton>
          <AppButton type="button" variant="ghost" @click="resend('sms')">{{ $t('auth.verify.resend') }}</AppButton>
        </form>
      </section>
      <AppButton block size="lg" :disabled="!auth.isVerified" @click="router.push(localePath(auth.homePath))">{{ $t('auth.verify.continue') }}</AppButton>
    </div>
  </div>
</template>
