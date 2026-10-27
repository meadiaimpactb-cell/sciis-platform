<script setup lang="ts">
/** Reset password (ACC-03) — new password with the live policy checklist; success → toast + sign-in page. */
import { emailField, withPasswordConfirmation } from '~/utils/validation'
import { toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'auth', middleware: 'guest' })
const { t } = useI18n()
useHead({ title: t('auth.reset.metaTitle') })
const api = useApi()
const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const form = useZodForm(withPasswordConfirmation({ email: emailField }), { email: (route.query.email as string) ?? '', password: '', password_confirmation: '' })
const submit = () => form.submit(async (v) => {
  const email = v.email.trim().toLowerCase()
  await api.post('/auth/reset-password', { email, password: v.password, password_confirmation: v.password_confirmation, token: route.query.token ?? 'mock' })
  toastSuccess(t('notify.passwordChanged'))
  router.push({ path: localePath('/auth/login'), query: { email } })
})
</script>

<template>
  <div class="card p-6 sm:p-8 mx-auto max-w-lg">
    <h1 class="text-2xl">{{ $t('auth.reset.title') }}</h1>
    <form class="mt-7 space-y-5" novalidate @submit.prevent="submit">
      <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required ltr autocomplete="email" />
      <div>
        <FormField v-model="form.values.password" name="password" type="password" :label="$t('common.password')" :error="form.errors.value.password" required ltr autocomplete="new-password" />
        <PasswordChecklist :password="form.values.password" />
      </div>
      <FormField v-model="form.values.password_confirmation" name="password_confirmation" type="password" :label="$t('common.passwordConfirm')" :error="form.errors.value.password_confirmation" required ltr autocomplete="new-password" />
      <FormAlert />
      <AppButton type="submit" block size="lg" :loading="form.submitting.value">{{ $t('auth.reset.submit') }}</AppButton>
    </form>
    <p class="mt-6 border-t border-border pt-5 text-sm text-center"><NuxtLink :to="$localePath('/auth/login')" class="link">{{ $t('nav.login') }}</NuxtLink></p>
  </div>
</template>
