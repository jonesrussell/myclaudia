<script setup lang="ts">
import { useLanguage } from '~/composables/useLanguage'
import { useOpsDetailDrawer, type OpsDetailEntityType } from '~/composables/useOpsDetailDrawer'
import { fetchCommitmentPeek, fetchProspectPeek } from '~/composables/useOpsDetailPeek'

const { t } = useLanguage()
const { open, entityType, entityUuid, closeDrawer } = useOpsDetailDrawer()

const loading = ref(false)
const error = ref<string | null>(null)
const record = ref<Record<string, unknown> | null>(null)

watch(
  [open, entityType, entityUuid],
  async ([isOpen, type, uuid]) => {
    if (!isOpen || !type || !uuid) {
      record.value = null
      error.value = null
      return
    }
    loading.value = true
    error.value = null
    record.value = null
    try {
      if (type === 'commitment') {
        record.value = (await fetchCommitmentPeek(uuid)) as Record<string, unknown> | null
      } else if (type === 'prospect') {
        record.value = (await fetchProspectPeek(uuid)) as Record<string, unknown> | null
      }
      if (!record.value) {
        error.value = t('ops_drawer_not_found')
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

const fullPath = computed(() => {
  const type = entityType.value as OpsDetailEntityType | ''
  const u = entityUuid.value
  if (!type || !u) {
    return ''
  }
  if (type === 'commitment') {
    return `/commitment/${u}`
  }
  return `/prospect/${u}`
})

function onBackdropClick() {
  closeDrawer()
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="ops-drawer-root" role="dialog" aria-modal="true" :aria-label="t('ops_drawer_title')">
      <div class="ops-drawer-backdrop" @click="onBackdropClick" />
      <aside class="ops-drawer-panel">
        <header class="ops-drawer-head">
          <h2 class="ops-drawer-h">{{ t('ops_drawer_title') }}</h2>
          <button type="button" class="btn btn-sm" @click="closeDrawer">{{ t('ops_drawer_close') }}</button>
        </header>
        <p v-if="entityType && entityUuid" class="ops-drawer-meta">
          <code>{{ entityType }}</code> · <code>{{ entityUuid.slice(0, 8) }}…</code>
        </p>
        <div v-if="loading" class="loading">{{ t('loading') }}</div>
        <div v-else-if="error" class="error">{{ error }}</div>
        <pre v-else-if="record" class="ops-drawer-pre">{{ JSON.stringify(record, null, 2) }}</pre>
        <footer v-if="fullPath" class="ops-drawer-foot">
          <NuxtLink class="btn btn-primary btn-sm" :to="fullPath" @click="closeDrawer">
            {{ t('ops_drawer_full') }}
          </NuxtLink>
        </footer>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
.ops-drawer-root {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  justify-content: flex-end;
  pointer-events: none;
}
.ops-drawer-root > * {
  pointer-events: auto;
}
.ops-drawer-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
}
.ops-drawer-panel {
  position: relative;
  width: min(420px, 100vw);
  max-height: 100%;
  overflow: auto;
  background: var(--bg-surface, #131620);
  border-left: 1px solid var(--border, rgba(255, 255, 255, 0.06));
  padding: 20px;
  box-shadow: -8px 0 32px rgba(0, 0, 0, 0.35);
}
.ops-drawer-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}
.ops-drawer-h {
  font-family: var(--font-display, system-ui);
  font-size: 1.1rem;
  font-weight: 600;
}
.ops-drawer-meta {
  font-size: 12px;
  color: var(--text-muted, #6b6d82);
  margin-bottom: 16px;
}
.ops-drawer-meta code {
  font-size: 11px;
  color: var(--accent-teal, #2dd4bf);
}
.ops-drawer-pre {
  font-size: 12px;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
  background: var(--bg-deep, #0a0c10);
  border: 1px solid var(--border, rgba(255, 255, 255, 0.06));
  border-radius: var(--radius-md, 10px);
  padding: 12px;
  margin-bottom: 16px;
  color: var(--text-secondary, #9b9cb5);
}
.ops-drawer-foot {
  padding-top: 8px;
  border-top: 1px solid var(--border, rgba(255, 255, 255, 0.06));
}
</style>
