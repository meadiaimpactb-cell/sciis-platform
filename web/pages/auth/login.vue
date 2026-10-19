<script setup lang="ts">
/** Login (ACC-01) — email+password, then 2FA challenge step when enabled (ACC-05). */
import { z } from 'zod'
import { makeSchemas } from '~/utils/validators'
definePageMeta({ layout: 'auth', middleware: 'guest' })
const { t } = useI18n()
useHead({ title: t('auth.login.metaTitle') })
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const localePath = useLocalePath()
const toast = useToast()
const { mock } = useApiBase()
const S = makeSchemas(t)
const form = useZodForm(z.object({ email: S.email, password: S.str(1) }), { email: '', password: '' })
const step = ref<'credentials' | '2fa'>('credentials')
const code = ref('')
const codeError = ref('')
const busy = ref(false)

const go = () => {
  const redirect = route.query.redirect as string | undefined
  router.push(redirect && redirect.startsWith('/') ? redirect : localePath(auth.homePath))
}
const submit = () => form.submit(async (v) => {
  const res = await auth.login(v.email, v.password)
  if (res.twoFactor) step.value = '2fa'
  else go()
})
const verify = async () => {
  codeError.value = ''
  if (!/^\d{6}$/.test(code.value)) return (codeError.value = t('validation.code6'))
  busy.value = true
  try {
    await auth.challenge2fa(code.value)
    go()
  } catch (e) {
    codeError.value = (e as { field?: (k: string) => string }).field?.('code') ?? t('auth.errors.invalidCode')
  } finally {
    busy.value = false
  }
}
const demo = [
  { key: 'investor', email: 'investor@demo.sciis.sa' },
  { key: 'investorInst', email: 'familyoffice@demo.sciis.sa' },
  { key: 'entrepreneur', email: 'entrepreneur@demo.sciis.sa' },
  { key: 'admin', email: 'admin@demo.sciis.sa' },
  { key: 'reviewer', email: 'reviewer@demo.sciis.sa' },
  { key: 'evaluator', email: 'evaluator1@demo.sciis.sa' },
  { key: 'matching', email: 'matching@demo.sciis.sa' },
  { key: 'membership', email: 'membership@demo.sciis.sa' },
]
const fill = (email: string) => { form.values.email = email; form.values.password = 'password'; toast.info(t('auth.login.demoHint')) }
</script>

<template>
  <div class="card card-pad">
    <template v-if="step === 'credentials'">
      <h1 class="text-2xl font-semibold">{{ $t('auth.login.title') }}</h1>
      <p class="text-muted mt-1">{{ $t('auth.login.sub') }}</p>
      <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
        <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required autocomplete="email" ltr />
        <FormField v-model="form.values.password" name="password" type="password" :label="$t('common.password')" :error="form.errors.value.password" required autocomplete="current-password" ltr />
        <div class="flex items-center justify-between text-sm"><NuxtLink :to="$localePath('/auth/forgot')" class="link">{{ $t('auth.login.forgot') }}</NuxtLink></div>
        <AppButton type="submit" block size="lg" :loading="form.submitting.value">{{ $t('auth.login.submit') }}</AppButton>
      </form>
      <p class="mt-5 text-sm text-muted text-center">{{ $t('auth.login.noAccount') }} <NuxtLink :to="$localePath('/auth/register')" class="link">{{ $t('auth.login.register') }}</NuxtLink></p>
      <div v-if="mock" class="mt-6 rounded-xl border border-dashed border-border bg-bg p-4">
        <p class="text-xs font-medium text-muted">{{ $t('auth.login.demoAccounts') }}</p>
        <p class="text-xs text-muted mt-0.5">{{ $t('auth.login.demoHint') }}</p>
        <div class="mt-3 flex flex-wrap gap-2"><button v-for="d in demo" :key="d.key" type="button" class="chip text-xs" @click="fill(d.email)">{{ $t(`auth.login.demoRoles.${d.key}`) }}</button></div>
      </div>
    </template>
    <template v-else>
      <h1 class="text-2xl font-semibold">{{ $t('auth.login.twoFactorTitle') }}</h1>
      <p class="text-muted mt-1">{{ $t('auth.login.twoFactorText') }}</p>
      <form class="mt-6 space-y-4" novalidate @submit.prevent="verify">
        <FormField v-model="code" name="code" type="text" :label="$t('auth.login.twoFactorCode')" :error="codeError" required ltr autocomplete="one-time-code" :maxlength="6" placeholder="000000" />
        <p v-if="mock" class="text-xs text-muted">{{ $t('auth.verify.demoHint') }}</p>
        <AppButton type="submit" block size="lg" :loading="busy">{{ $t('auth.login.verify') }}</AppButton>
        <AppButton type="button" variant="ghost" block @click="step = 'credentials'">{{ $t('common.back') }}</AppButton>
      </form>
    </template>
  </div>
</template>
