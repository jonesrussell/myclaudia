export default defineNuxtConfig({
  compatibilityDate: '2025-01-01',
  devtools: { enabled: true },
  ssr: false,
  spaLoadingTemplate: 'app/spa-loading-template.html',

  experimental: {
    viteEnvironmentApi: true,
  },
  srcDir: 'app/',

  nitro: {
    devProxy: {
      '/api': {
        target: 'http://localhost:8081/api',
        changeOrigin: true,
      },
    },
  },

  routeRules: {
    '/api/**': { proxy: 'http://localhost:8081/api/**' },
  },

  app: {
    baseURL: '/admin/',
    head: {
      title: 'Claudriel Admin',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      ],
      link: [
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        { rel: 'stylesheet', href: 'https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&display=swap' },
      ],
    },
  },

  runtimeConfig: {
    public: {
      // Disable SSE by default in dev to avoid php -S single-process request starvation.
      // Set NUXT_PUBLIC_ENABLE_REALTIME=1 to force-enable.
      enableRealtime: process.env.NUXT_PUBLIC_ENABLE_REALTIME ?? (process.env.NODE_ENV === 'production' ? '1' : '0'),
      appName: process.env.NUXT_PUBLIC_APP_NAME ?? 'Claudriel Admin',
    },
  },
})
