import { createI18n } from 'vue-i18n'
import th from './th'
import en from './en'

const messages = {
  th,
  en,
} as const

export type AppLocale = keyof typeof messages

const i18n = createI18n({
  legacy: false,
  locale: 'th',
  fallbackLocale: 'th',
  messages,
})

export default i18n
