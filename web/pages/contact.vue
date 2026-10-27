<script setup lang="ts">
/** Contact form — CMS-05: categorised, honeypot, reference number. Phone optional (E.164 via PhoneField). */
import { z } from 'zod'
import { emailField, messageField, optionalPhoneField, requiredChoice, requiredString, RULES } from '~/utils/validation'
import { toastSuccess } from '~/utils/notify'
definePageMeta({ layout: 'default' })
const { t } = useI18n()
// CMS — the page header (label, title, lead) is editable in admin → content (utils/pageSections.ts).
const header = await usePageContent('contact')
useSeo({
  title: t('contact.metaTitle'),
  description: t('contact.metaDescription'),
  jsonLd: { '@type': 'ContactPage', name: t('contact.title'), description: t('contact.metaDescription'), about: { '@id': `${useSiteUrl()}/#organization` } },
})
const api = useApi()
const schema = z.object({ category: requiredChoice('contact.category'), name: requiredString('common.name', RULES.nameMin, RULES.staffNameMax), email: emailField, phone: optionalPhoneField, message: messageField, website: z.string().optional() })
const form = useZodForm(schema as never, { category: 'investor', name: '', email: '', phone: '', message: '', website: '' })
const contact = usePlatformContact()
const reference = ref<string | null>(null)
const categories = ['investor', 'entrepreneur', 'partnership', 'media', 'other'].map((c) => ({ value: c, label: t(`contact.categories.${c}`) }))
const submit = () => form.submit(async (v) => {
  // Laravel wraps in { data }, the mock returns the object directly. Empty phone is not sent (E.164 or nothing).
  const res = await api.post<{ data?: { reference_no: string }; reference_no?: string }>('/public/contact', { ...v, email: v.email.trim(), phone: v.phone || undefined })
  reference.value = res.data?.reference_no ?? res.reference_no ?? null
  toastSuccess(t('notify.sent', { item: t('notify.entities.message') }))
})
const again = () => { reference.value = null; form.reset() }
</script>

<template>
  <div>
    <PageHero :title="header.text('title', 'contact.title')" :lead="header.text('lead', 'contact.lead')" />
    <section class="container-x section-y grid lg:grid-cols-[1.3fr_1fr] gap-8">
      <div class="card card-pad">
        <div v-if="reference" class="text-center py-8">
          <span class="mx-auto h-14 w-14 rounded-full bg-success/10 text-success flex items-center justify-center" aria-hidden="true"><svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd" /></svg></span>
          <h2 class="card-title mt-4">{{ $t('contact.successTitle') }}</h2>
          <p class="mt-2 text-muted">{{ $t('contact.successText', { ref: reference }) }}</p>
          <AppButton variant="secondary" class="mt-6" @click="again">{{ $t('common.back') }}</AppButton>
        </div>
        <form v-else class="space-y-5" novalidate @submit.prevent="submit">
          <FormField v-model="form.values.category" name="category" type="select" :label="$t('contact.category')" :options="categories" :error="form.errors.value.category" required />
          <div class="grid sm:grid-cols-2 gap-5">
            <FormField v-model="form.values.name" name="name" :label="$t('common.name')" :error="form.errors.value.name" required autocomplete="name" />
            <FormField v-model="form.values.email" name="email" type="email" :label="$t('common.email')" :error="form.errors.value.email" required autocomplete="email" ltr />
          </div>
          <PhoneField v-model="form.values.phone" name="phone" :label="$t('common.phone')" :error="form.errors.value.phone" />
          <FormField v-model="form.values.message" name="message" type="textarea" :label="$t('contact.message')" :placeholder="$t('contact.messagePlaceholder')" :error="form.errors.value.message" required :rows="6" :maxlength="RULES.messageMax" />
          <!-- honeypot: hidden from humans, bots fill it -->
          <div class="hidden" aria-hidden="true"><label for="website">Website</label><input id="website" v-model="form.values.website" type="text" tabindex="-1" autocomplete="off" /></div>
          <FormAlert />
          <AppButton type="submit" size="lg" :loading="form.submitting.value">{{ $t('contact.submit') }}</AppButton>
        </form>
      </div>
      <aside class="space-y-4">
        <div class="card card-pad">
          <h2 class="card-title">{{ $t('contact.channels') }}</h2>
          <dl class="mt-4 space-y-3 text-sm">
            <div v-if="contact.email.value"><dt class="text-muted text-xs">{{ $t('contact.officialEmail') }}</dt><dd><a :href="`mailto:${contact.email.value}`" class="link ltr inline-block">{{ contact.email.value }}</a></dd></div>
            <div v-if="contact.emailInvestors.value"><dt class="text-muted text-xs">{{ $t('contact.emailInvestors') }}</dt><dd><a :href="`mailto:${contact.emailInvestors.value}`" class="link ltr inline-block">{{ contact.emailInvestors.value }}</a></dd></div>
            <div v-if="contact.emailEntrepreneurs.value"><dt class="text-muted text-xs">{{ $t('contact.emailEntrepreneurs') }}</dt><dd><a :href="`mailto:${contact.emailEntrepreneurs.value}`" class="link ltr inline-block">{{ contact.emailEntrepreneurs.value }}</a></dd></div>
            <div v-if="contact.phone.value"><dt class="text-muted text-xs">{{ $t('contact.officialPhone') }}</dt><dd><a :href="`tel:${contact.dial(contact.phone.value)}`" class="link ltr inline-block num">{{ contact.phone.value }}</a></dd></div>
            <div><dt class="text-muted text-xs">{{ $t('contact.whatsapp') }}</dt><dd><a v-if="contact.whatsapp.value" :href="`https://wa.me/${contact.dial(contact.whatsapp.value).replace('+', '')}`" target="_blank" rel="noopener" class="link ltr inline-block num">{{ contact.whatsapp.value }}</a><span v-else class="text-muted">{{ $t('common.notAvailable') }}</span></dd></div>
          </dl>
          <p class="mt-4 text-xs text-muted">{{ $t('contact.hours') }}</p>
        </div>
        <div v-if="contact.social.value.length" class="card card-pad">
          <h2 class="card-title">{{ $t('footer.follow') }}</h2>
          <SocialLinks class="mt-3" />
        </div>
        <Disclaimer variant="box" />
      </aside>
    </section>
  </div>
</template>
