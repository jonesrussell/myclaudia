import { defineConfig, devices } from '@playwright/test'

// Nuxt app uses app.baseURL `/admin/` — tests use paths relative to this origin.
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:3000/admin'

export default defineConfig({
  testDir: './e2e',
  // Agent-backed chat UI stays disabled without Docker sidecar + keys; skip in CI smoke.
  testIgnore: process.env.CI ? ['**/claudriel-chat-continue.spec.ts'] : [],
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: process.env.CI ? 'line' : 'html',
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    // From frontend/admin: PHP serves claudriel public/; Nuxt dev on :3000 with /admin base.
    command:
      'sh -c "PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8081 -t ../../public & npm run dev"',
    url: 'http://localhost:3000/admin',
    reuseExistingServer: !process.env.CI,
    timeout: 120 * 1000,
  },
})
