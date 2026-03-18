// packages/admin/tests/setup.ts
// Provides Nuxt runtime mocks for happy-dom environment.
// restoreMocks: true in vitest.config handles mock cleanup between tests.

import { vi, beforeEach } from 'vitest'
import { ref, defineComponent, h, type Ref } from 'vue'
import { config } from '@vue/test-utils'

// --- Stub Nuxt components that call useNuxtApp() internally ---
const NuxtLinkStub = defineComponent({
  name: 'NuxtLink',
  props: { to: { type: [String, Object], default: '' } },
  setup(props, { slots }) {
    return () => h('a', { href: typeof props.to === 'string' ? props.to : '' }, slots.default?.())
  },
})

config.global.stubs = {
  NuxtLink: NuxtLinkStub,
  NuxtPage: defineComponent({ name: 'NuxtPage', render: () => h('div') }),
  ClientOnly: defineComponent({ name: 'ClientOnly', setup(_, { slots }) { return () => slots.default?.() } }),
}

// --- Shared state stores (cleared between tests) ---
const stateStore = new Map<string, Ref>()
const cookieStore = new Map<string, Ref>()

function mockUseState<T>(key: string, init?: () => T): Ref<T> {
  if (!stateStore.has(key)) {
    stateStore.set(key, ref(init ? init() : undefined) as Ref)
  }
  return stateStore.get(key) as Ref<T>
}

function mockUseCookie(key: string) {
  if (!cookieStore.has(key)) {
    cookieStore.set(key, ref(null))
  }
  return cookieStore.get(key)
}

// --- Mock Nuxt's auto-import source modules ---
// These intercept the actual module imports that Nuxt's transform resolves.
const nuxtAppExports = {
  useState: mockUseState,
  useCookie: mockUseCookie,
  useNuxtApp: () => ({ $fetch: vi.fn(() => Promise.resolve({})) }),
  useRuntimeConfig: () => ({
    public: { enableRealtime: '0', appName: 'Claudriel Admin' },
  }),
  useRoute: () => ({
    path: '/', params: {}, query: {}, fullPath: '/', name: 'index',
  }),
  useRouter: () => ({
    push: vi.fn(), replace: vi.fn(), back: vi.fn(),
    currentRoute: ref({ path: '/', params: {}, query: {} }),
    afterEach: vi.fn(),
  }),
  navigateTo: vi.fn(),
  definePageMeta: vi.fn(),
  defineNuxtPlugin: vi.fn(),
  useHead: vi.fn(),
}

vi.mock('#app', () => nuxtAppExports)

// Mock the actual nuxt module paths that auto-import transforms may resolve to
vi.mock('nuxt/app', () => nuxtAppExports)
vi.mock('#app/nuxt', () => nuxtAppExports)
vi.mock('#app/composables/head', () => ({
  useHead: vi.fn(),
  useHeadSafe: vi.fn(),
  useServerHead: vi.fn(),
  useServerHeadSafe: vi.fn(),
  useSeoMeta: vi.fn(),
  useServerSeoMeta: vi.fn(),
  injectHead: vi.fn(),
}))

// Also mock the specific composable paths Nuxt may resolve to
vi.mock('#app/composables/state', () => ({
  useState: mockUseState,
}))

vi.mock('#app/composables/cookie', () => ({
  useCookie: mockUseCookie,
}))

vi.mock('#app/composables/router', () => ({
  useRoute: () => ({
    path: '/', params: {}, query: {}, fullPath: '/', name: 'index',
  }),
  useRouter: () => ({
    push: vi.fn(), replace: vi.fn(), back: vi.fn(),
    currentRoute: ref({ path: '/', params: {}, query: {} }),
    afterEach: vi.fn(),
  }),
  navigateTo: vi.fn(),
}))

// --- Global stubs (for template-level auto-imports) ---
vi.stubGlobal('useState', mockUseState)
vi.stubGlobal('useCookie', mockUseCookie)
vi.stubGlobal('useRuntimeConfig', () => ({
  public: { enableRealtime: '0', appName: 'Claudriel Admin' },
}))
vi.stubGlobal('useRoute', () => ({
  path: '/', params: {}, query: {}, fullPath: '/', name: 'index',
}))
vi.stubGlobal('useRouter', () => ({
  push: vi.fn(), replace: vi.fn(), back: vi.fn(),
  currentRoute: ref({ path: '/', params: {}, query: {} }),
}))
vi.stubGlobal('navigateTo', vi.fn())
vi.stubGlobal('definePageMeta', vi.fn())
vi.stubGlobal('useHead', vi.fn())
vi.stubGlobal('$fetch', vi.fn(() => Promise.resolve({})))

// --- Clear state between tests ---
beforeEach(() => {
  stateStore.clear()
  cookieStore.clear()
})
