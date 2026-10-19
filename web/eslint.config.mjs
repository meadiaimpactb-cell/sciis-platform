// Minimal flat config (lint is optional per deliverables). `npm run lint`
import vue from 'eslint-plugin-vue'
import vueParser from 'vue-eslint-parser'
import tsParser from '@typescript-eslint/parser'

export default [
  { ignores: ['.nuxt/**', '.output/**', 'node_modules/**', 'dist/**'] },
  ...vue.configs['flat/essential'],
  {
    files: ['**/*.ts', '**/*.mjs'],
    languageOptions: { parser: tsParser, parserOptions: { ecmaVersion: 'latest', sourceType: 'module' } },
  },
  {
    files: ['**/*.vue', '**/*.ts', '**/*.mjs'],
    languageOptions: {
      parser: vueParser,
      parserOptions: { parser: '@typescript-eslint/parser', ecmaVersion: 'latest', sourceType: 'module', extraFileExtensions: ['.vue'] },
      globals: { defineNuxtConfig: 'readonly', defineNuxtPlugin: 'readonly', defineNuxtRouteMiddleware: 'readonly', navigateTo: 'readonly', useRuntimeConfig: 'readonly', defineI18nConfig: 'readonly', defineEventHandler: 'readonly' },
    },
    rules: { 'vue/multi-word-component-names': 'off', 'vue/no-v-html': 'off' },
  },
]
