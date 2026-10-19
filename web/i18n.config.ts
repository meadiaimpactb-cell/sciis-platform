export default defineI18nConfig(() => ({
  legacy: false,
  fallbackLocale: 'ar',
  missingWarn: false,
  fallbackWarn: false,
  warnHtmlMessage: false,
  numberFormats: {
    ar: {
      decimal: { style: 'decimal', maximumFractionDigits: 2 },
      percent: { style: 'percent', maximumFractionDigits: 0 },
    },
    en: {
      decimal: { style: 'decimal', maximumFractionDigits: 2 },
      percent: { style: 'percent', maximumFractionDigits: 0 },
    },
  },
}))
