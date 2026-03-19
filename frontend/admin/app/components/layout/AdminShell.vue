<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'

const { t, locale, locales, setLocale } = useLanguage()
const config = useRuntimeConfig()
const appName = config.public.appName as string
const isDesktop = ref(false)
const sidebarOpen = ref(false)
const { logout } = useAuth()

onMounted(() => {
  const mql = window.matchMedia('(min-width: 769px)')
  isDesktop.value = mql.matches
  sidebarOpen.value = mql.matches
  mql.addEventListener('change', (e) => {
    isDesktop.value = e.matches
    sidebarOpen.value = e.matches
  })
})

function toggleSidebar() {
  sidebarOpen.value = !sidebarOpen.value
}

// Close sidebar on route change (mobile only).
const route = useRoute()
watch(() => route.fullPath, () => {
  if (!isDesktop.value) {
    sidebarOpen.value = false
  }
})

function onLocaleChange(event: Event) {
  setLocale((event.target as HTMLSelectElement).value)
}

async function handleLogout() {
  await logout()
  window.location.assign('/login?redirect=%2Fadmin')
}
</script>

<template>
  <div class="admin-shell">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <header class="topbar" role="banner">
      <button class="topbar-toggle" :aria-label="t('toggle_menu')" @click="toggleSidebar">
        <span class="topbar-toggle-icon">&#9776;</span>
      </button>
      <NuxtLink to="/" class="topbar-brand">{{ appName }}</NuxtLink>
      <button class="topbar-logout" type="button" @click="handleLogout">
        {{ t('logout') }}
      </button>
      <label class="topbar-locale">
        <span class="sr-only">{{ t('language') }}</span>
        <select
          class="topbar-locale-select"
          :value="locale"
          :aria-label="t('language')"
          @change="onLocaleChange"
        >
          <option v-for="code in locales" :key="code" :value="code">
            {{ code.toUpperCase() }}
          </option>
        </select>
      </label>
    </header>

    <div class="admin-body">
      <div v-if="sidebarOpen" class="sidebar-overlay" @click="sidebarOpen = false" />
      <aside
        class="sidebar"
        :class="{ 'sidebar--open': sidebarOpen }"
        role="navigation"
        :aria-label="t('sidebar_nav')"
      >
        <LayoutNavBuilder />
      </aside>
      <main id="main-content" class="content" role="main">
        <slot />
      </main>
    </div>
  </div>
</template>

<style>
:root {
  --sidebar-width: 220px;
  --topbar-height: 48px;

  /* Font families */
  --font-display: 'Bricolage Grotesque', system-ui, sans-serif;
  --font-body: 'DM Sans', system-ui, sans-serif;

  /* Surfaces */
  --bg-deep: #0a0c10;
  --bg-surface: #131620;
  --bg-elevated: #1a1d2a;
  --bg-hover: #222536;
  --bg-input: rgba(255, 255, 255, 0.06);

  /* Borders */
  --border: rgba(255, 255, 255, 0.06);
  --border-subtle: rgba(255, 255, 255, 0.04);
  --border-emphasis: rgba(255, 255, 255, 0.1);

  /* Text */
  --text-primary: #e8e9ed;
  --text-secondary: #9b9cb5;
  --text-muted: #6b6d82;

  /* Accents */
  --accent-amber: #f0b040;
  --accent-amber-hover: #d49a2e;
  --accent-teal: #2dd4bf;
  --accent-blue: #6b9bff;
  --accent-red: #f06060;
  --accent-green: #34d399;
  --accent-purple: #a78bfa;

  /* Spacing */
  --space-xs: 0.25rem;
  --space-sm: 0.5rem;
  --space-md: 1rem;
  --space-lg: 1.5rem;
  --space-xl: 2rem;
  --space-2xl: 3rem;

  /* Radius */
  --radius-sm: 6px;
  --radius-md: 10px;
  --radius-lg: 14px;
  --radius-pill: 999px;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: var(--font-body);
  color: var(--text-primary);
  background: var(--bg-deep);
}

.admin-shell {
  min-height: 100vh;
}

.topbar {
  height: var(--topbar-height);
  background: var(--bg-surface);
  border-bottom: 1px solid var(--border);
  color: var(--text-primary);
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
}

.topbar-brand {
  color: var(--text-primary);
  text-decoration: none;
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 16px;
  letter-spacing: -0.02em;
  margin-right: auto;
}

.topbar-toggle {
  display: none;
  background: none;
  border: none;
  color: var(--text-primary);
  font-size: 20px;
  cursor: pointer;
  padding: 0 8px;
  margin-right: 8px;
}

.topbar-locale {
  display: inline-flex;
  align-items: center;
}

.topbar-logout {
  border: 1px solid var(--border-emphasis);
  background: transparent;
  color: var(--text-secondary);
  border-radius: var(--radius-sm);
  padding: 6px 10px;
  cursor: pointer;
  font-family: var(--font-body);
  font-size: 0.8rem;
  transition: border-color 0.15s, color 0.15s;
}

.topbar-logout:hover {
  border-color: var(--text-muted);
  color: var(--text-primary);
}

.topbar-locale-select {
  height: 32px;
  border: 1px solid var(--border-emphasis);
  background: transparent;
  color: var(--text-secondary);
  border-radius: var(--radius-sm);
  padding: 0 8px;
  font-size: 12px;
  font-weight: 600;
  font-family: var(--font-body);
}

.admin-body {
  display: flex;
  min-height: calc(100vh - var(--topbar-height));
}

.sidebar {
  width: var(--sidebar-width);
  background: var(--bg-surface);
  border-right: 1px solid var(--border);
  padding: 16px 0;
}

.sidebar-overlay {
  display: none;
}

.content {
  flex: 1;
  padding: 24px;
  background: var(--bg-deep);
}

/* Buttons */
.btn {
  display: inline-block;
  padding: 8px 16px;
  border: 1px solid var(--border-emphasis);
  border-radius: var(--radius-sm);
  background: var(--bg-surface);
  color: var(--text-primary);
  cursor: pointer;
  font-size: 14px;
  font-family: var(--font-body);
  text-decoration: none;
  transition: background 0.15s, border-color 0.15s;
}
.btn:hover { background: var(--bg-elevated); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-primary {
  background: linear-gradient(135deg, #f0b040 0%, #d46337 100%);
  color: #15120e;
  border-color: transparent;
  font-weight: 600;
}
.btn-primary:hover { background: linear-gradient(135deg, #d49a2e 0%, #b8522d 100%); }
.btn-danger { color: var(--accent-red); border-color: var(--accent-red); }
.btn-sm { padding: 4px 10px; font-size: 12px; }

/* Form fields */
.field { margin-bottom: 16px; }
.field-label { display: block; margin-bottom: 4px; font-weight: 500; font-size: 14px; color: var(--text-secondary); }
.field-input {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border-emphasis);
  border-radius: var(--radius-sm);
  background: var(--bg-input);
  color: var(--text-primary);
  font-size: 14px;
  font-family: var(--font-body);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.field-input:focus { outline: none; border-color: var(--accent-teal); box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.12); }
.field-textarea { resize: vertical; min-height: 100px; }
.field-richtext { min-height: 120px; padding: 8px 12px; border: 1px solid var(--border-emphasis); border-radius: var(--radius-sm); background: var(--bg-input); color: var(--text-primary); }
.field-description { margin-top: 4px; font-size: 12px; color: var(--text-muted); }
.required { color: var(--accent-red); }

/* Entity table */
.entity-table { width: 100%; border-collapse: collapse; background: var(--bg-surface); border-radius: var(--radius-sm); }
.entity-table th, .entity-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--border); }
.entity-table th { font-weight: 600; font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; }
.entity-table th.sortable { cursor: pointer; }
.entity-table .empty { text-align: center; color: var(--text-muted); padding: 40px; }
.entity-table .actions { white-space: nowrap; }
.entity-table .actions > * { margin-right: 4px; }

/* Pagination */
.pagination { display: flex; align-items: center; gap: 12px; margin-top: 16px; font-size: 14px; color: var(--text-muted); }

/* Form actions */
.form-actions { margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border); }

/* Status messages */
.loading { padding: 40px; text-align: center; color: var(--text-muted); }
.error { padding: 12px 16px; background: rgba(240, 96, 96, 0.12); color: #fca5a5; border: 1px solid rgba(240, 96, 96, 0.25); border-radius: var(--radius-sm); margin-bottom: 16px; }
.success { padding: 12px 16px; background: rgba(45, 212, 191, 0.12); color: #5eead4; border: 1px solid rgba(45, 212, 191, 0.25); border-radius: var(--radius-sm); margin-bottom: 16px; }

/* Page header */
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.page-header h1 { font-family: var(--font-display); font-size: 1.75rem; font-weight: 700; letter-spacing: -0.03em; }

/* Skip link */
.skip-link {
  position: absolute;
  top: -100%;
  left: 16px;
  background: var(--accent-amber);
  color: #15120e;
  padding: 8px 16px;
  border-radius: 0 0 var(--radius-sm) var(--radius-sm);
  z-index: 1000;
  font-size: 14px;
  text-decoration: none;
}
.skip-link:focus { top: 0; }

/* Screen reader only utility */
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

/* SSE connection indicator */
.sse-status {
  display: inline-block;
  color: var(--accent-green);
  font-size: 10px;
  margin-left: 8px;
  vertical-align: middle;
  animation: pulse 2s ease-in-out infinite;
}
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}

/* Responsive layout */
@media (max-width: 768px) {
  .topbar-toggle {
    display: inline-flex;
    align-items: center;
  }

  .sidebar {
    position: fixed;
    top: var(--topbar-height);
    left: 0;
    bottom: 0;
    z-index: 50;
    transform: translateX(-100%);
    transition: transform 0.2s ease;
  }

  .sidebar--open {
    transform: translateX(0);
  }

  .sidebar-overlay {
    display: block;
    position: fixed;
    inset: 0;
    top: var(--topbar-height);
    background: rgba(0, 0, 0, 0.5);
    z-index: 40;
  }

  .content {
    padding: 16px;
  }

  .page-header h1 {
    font-size: 1.25rem;
  }

  .entity-table {
    font-size: 13px;
  }
  .entity-table th, .entity-table td {
    padding: 8px;
  }
}
</style>
