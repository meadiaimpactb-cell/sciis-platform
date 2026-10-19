<script setup lang="ts">
/** Contact form — CMS-05: categorised, honeypot, reference number. */
import { z } from 'zod'
import { makeSchemas } from '~/utils/validators'
definePageMeta({ layout: 'default' })
const { t } = useI18n()
useHead({ title: t('contact.metaTitle') })
const api = useApi()
const S = makeSchemas(t)
const schema = z.object({ category: S.str(), name: S.str(2), email: S.email, phone: z.string().optional(), message: S.str(10), website_url: z.string().optional() })
const form = useZodForm(schema, { category: 'investor', name: '', email: '', phone: '', message: '', website_url: '' })
const reference = ref<string | null>(null)
const categories = ['investor', 'entrepreneur', 'partnership', 'media', 'other'].map((c) => ({ value: c, label: t(`contact.categories.${c}`) }))
const submit = () => form.submit(async (v) => {
  const res = await api.post<{ reference_no: string }>('/public/contact', v)
  reference.value = res.reference_no
})
</script>

<template>
  <div>
    <section class="bg-ink text-white"><div class="container-x py-14 max-w-3xl"><h1 class="text-3xl sm:text-4xl font-semibold text-white">{{ $t('contact.title') }}</h1><p class="mt-3 text-white/75">{{ $t('contact.lead') }}</p></div></section>
    <section class="container-x py-12 grid lg:grid-cols-[1.3fr_1fr] gap-8">
      <div class="card card-pad">
        <div v-if="reference" class="text-center py-8">
          <span class="mx-auto h-14 w-14 rounded-full bg-success/10 text-success flex items-center justify-center" aria-hidden="true"><svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg></span>
          <h2 class="mt-4 text-xl font-semibold">{{ $t('contact.successTitle') }}</h2>
          <p class="mt-2 text-muted">{{ $t('contact.successText', { ref: reference }) }}</p>
          <AppButton variant="secondary" class="mt-6" @click="reference = null; form.reset()">{{ $t('common.back') }}</AppButton>
        </div>
        <form v-else class="space-y-5" novalidate @submit.prevent="submit">
          <FormField v-model="form.values.category" name="category" type="select" :label="$t('contact.category')" :options="categories" :error="form.errors.value.category" required />
          <div class="grid sm:grid-cols-2 gap-5">
            <FormField v-model="form.values.name" name="name" :label="$t('common.name')" :error="form.errors.value.name" required autocomplete="name" />
            <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required autocomplete="email" ltr />
          </div>
          <FormField v-model="form.values.phone" name="phone" type="tel" :label="$t('common.phone')" :error="form.errors.value.phone" autocomplete="tel" ltr placeholder="05xxxxxxxx" />
          <FormField v-model="form.values.message" name="message" type="textarea" :label="$t('contact.message')" :placeholder="$t('contact.messagePlaceholder')" :error="form.errors.value.message" required :rows="6" :maxlength="2000" />
          <!-- honeypot: hidden from humans, bots fill it -->
          <div class="hidden" aria-hidden="true"><label for="website_url">Website</label><input id="website_url" v-model="form.values.website_url" type="text" tabindex="-1" autocomplete="off" /></div>
          <AppButton type="submit" size="lg" :loading="form.submitting.value">{{ $t('contact.submit') }}</AppButton>
        </form>
      </div>
      <aside class="space-y-4">
        <div class="card card-pad">
          <h2 class="font-semibold">{{ $t('contact.channels') }}</h2>
          <dl class="mt-4 space-y-3 text-sm">
            <div><dt class="text-muted text-xs">{{ $t('contact.officialEmail') }}</dt><dd><a href="mailto:info@sciis.sa" class="link ltr inline-block">info@sciis.sa</a></dd></div>
            <div><dt class="text-muted text-xs">{{ $t('contact.officialPhone') }}</dt><dd><a href="tel:+966110000000" class="link ltr inline-block num">+966 11 000 0000</a></dd></div>
            <div><dt class="text-muted text-xs">{{ $t('contact.whatsapp') }}</dt><dd class="text-muted">{{ $t('common.notAvailable') }}</dd></div>
          </dl>
          <p class="mt-4 text-xs text-muted">{{ $t('contact.hours') }}</p>
        </div>
        <Disclaimer variant="box" />
      </aside>
    </section>
  </div>
</template>
