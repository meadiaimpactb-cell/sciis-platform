export default defineI18nConfig(() => ({
  legacy: false,
  fallbackLocale: 'ar',
  missingWarn: false,
  fallbackWarn: false,
  warnHtmlMessage: false,
  numberFormats: {
    // Latin digits in Arabic too (platform rule: no Arabic-Indic numerals anywhere).
    ar: {
      decimal: { style: 'decimal', maximumFractionDigits: 2, numberingSystem: 'latn' },
      percent: { style: 'percent', maximumFractionDigits: 0, numberingSystem: 'latn' },
    },
    en: {
      decimal: { style: 'decimal', maximumFractionDigits: 2 },
      percent: { style: 'percent', maximumFractionDigits: 0 },
    },
  },
}))
