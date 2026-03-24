<script setup lang="ts">
const { isAuthenticated } = useAuth()
const authChecked = useState<boolean>('claudriel.admin.session.checked', () => false)
</script>

<template>
  <div v-if="!authChecked" class="app-loading">
    <div class="app-loading-topbar">
      <span class="app-loading-brand">Claudriel Admin</span>
    </div>
    <div class="app-loading-body">
      <div class="app-loading-sidebar">
        <div class="skel skel-heading"></div>
        <div class="skel skel-item"></div>
        <div class="skel skel-heading"></div>
        <div class="skel skel-item"></div>
        <div class="skel skel-item"></div>
        <div class="skel skel-heading"></div>
        <div class="skel skel-item"></div>
        <div class="skel skel-item"></div>
        <div class="skel skel-item"></div>
      </div>
      <div class="app-loading-content">
        <div class="skel skel-title"></div>
        <div class="app-loading-grid">
          <div class="skel skel-card"></div>
          <div class="skel skel-card"></div>
          <div class="skel skel-card"></div>
          <div class="skel skel-card"></div>
          <div class="skel skel-card"></div>
          <div class="skel skel-card"></div>
        </div>
      </div>
    </div>
  </div>
  <NuxtLayout v-else>
    <NuxtPage />
  </NuxtLayout>
</template>

<style scoped>
.app-loading { min-height: 100vh; background: var(--bg-deep, #0a0c10); color: var(--text-primary, #e8e9ed); font-family: 'DM Sans', system-ui, sans-serif; }
.app-loading-topbar { height: 48px; background: var(--bg-surface, #131620); border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; align-items: center; padding: 0 16px; }
.app-loading-brand { font-family: 'Bricolage Grotesque', system-ui, sans-serif; font-weight: 700; font-size: 16px; letter-spacing: -0.02em; }
.app-loading-body { display: flex; min-height: calc(100vh - 48px); }
.app-loading-sidebar { width: 220px; background: var(--bg-surface, #131620); border-right: 1px solid rgba(255,255,255,0.06); padding: 16px; display: flex; flex-direction: column; gap: 8px; }
.app-loading-content { flex: 1; padding: 24px; }
.app-loading-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.skel { background: rgba(255,255,255,0.04); border-radius: 6px; animation: shimmer 1.5s ease-in-out infinite; }
.skel-heading { height: 10px; width: 50%; margin-top: 8px; }
.skel-item { height: 28px; width: 80%; }
.skel-title { height: 28px; width: 200px; margin-bottom: 24px; }
.skel-card { height: 80px; background: var(--bg-surface, #131620); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; }
@keyframes shimmer { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
</style>
