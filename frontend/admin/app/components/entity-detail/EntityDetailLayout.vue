<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import type { EntityDetailConfig, SidebarSection } from '~/composables/useEntityDetailConfig'
import { useRelationshipData } from '~/composables/useRelationshipData'
import MetadataCard from './MetadataCard.vue'
import RelationshipPanel from './RelationshipPanel.vue'
import ActivityTimeline from './ActivityTimeline.vue'

const props = defineProps<{
  config: EntityDetailConfig
  entity: Record<string, any>
  entityType: string
}>()

const emit = defineEmits<{
  saved: []
  error: [message: string]
}>()

const activeSection = ref<string>(props.config.sidebar[0]?.key ?? '')

const entityName = computed(() =>
  props.entity.name ?? props.entity.title ?? props.entity.uuid ?? 'Unknown',
)

// Fetch counts for sections with queries (in parallel)
const sectionCounts = ref<Record<string, number | null>>({})

onMounted(() => {
  const countPromises = props.config.sidebar
    .filter((s) => s.query)
    .map(async (section) => {
      const { count, fetchCount } = useRelationshipData(section.query!, props.entity.uuid)
      await fetchCount()
      sectionCounts.value = { ...sectionCounts.value, [section.key]: count.value }
    })
  Promise.all(countPromises)
})

function selectSection(key: string) {
  activeSection.value = key
}

const currentSection = computed<SidebarSection | undefined>(() =>
  props.config.sidebar.find((s) => s.key === activeSection.value),
)
</script>

<template>
  <div class="entity-detail-layout">
    <header class="detail-header">
      <div class="header-title">
        <h1>{{ entityName }}</h1>
        <span v-if="entity.status" class="status-badge">{{ entity.status }}</span>
      </div>
      <div class="header-actions">
        <button
          v-for="action in config.actions"
          :key="action.label"
          class="btn btn-sm"
        >
          {{ action.label }}
        </button>
        <NuxtLink :to="`/${entityType}`" class="btn btn-sm">Back to list</NuxtLink>
      </div>
    </header>

    <div class="detail-body">
      <aside class="detail-sidebar">
        <MetadataCard
          v-if="config.metadata"
          :fields="config.metadata"
          :entity="entity"
        />
        <hr class="sidebar-divider" />
        <nav class="sidebar-sections">
          <button
            v-for="section in config.sidebar"
            :key="section.key"
            :class="['sidebar-section', { active: activeSection === section.key }]"
            :data-section="section.key"
            @click="selectSection(section.key)"
          >
            <span>{{ section.label }}</span>
            <span v-if="sectionCounts[section.key] != null" class="count">
              {{ sectionCounts[section.key] }}
            </span>
            <span v-else-if="section.query" class="count count-loading">&middot;</span>
          </button>
        </nav>
      </aside>

      <main class="detail-main">
        <template v-if="currentSection">
          <!-- Custom component -->
          <component
            v-if="currentSection.component"
            :is="currentSection.component"
            :entity="entity"
            :entity-type="entityType"
          />
          <!-- Activity timeline (by key convention) -->
          <ActivityTimeline
            v-else-if="currentSection.key === 'activity'"
            :events="[]"
          />
          <!-- Relationship panel (has query) -->
          <RelationshipPanel
            v-else-if="currentSection.query"
            :query="currentSection.query"
            :parent-id="entity.uuid"
            :entity-type="entityType"
          />
          <!-- Details / edit form (by key convention) -->
          <div v-else-if="currentSection.key === 'details'" class="details-section">
            <SchemaForm
              :entity-type="entityType"
              :entity-id="entity.uuid"
              @saved="emit('saved')"
              @error="(msg: string) => emit('error', msg)"
            />
          </div>
        </template>
      </main>
    </div>
  </div>
</template>

<style scoped>
.entity-detail-layout { display: flex; flex-direction: column; height: 100%; }
.detail-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--color-border, #333); }
.header-title { display: flex; align-items: center; gap: 10px; }
.header-title h1 { margin: 0; font-size: 18px; }
.status-badge { font-size: 11px; padding: 2px 8px; border-radius: 4px; background: rgba(34, 197, 94, 0.15); color: #22c55e; }
.header-actions { display: flex; gap: 8px; }
.detail-body { display: flex; flex: 1; min-height: 0; }
.detail-sidebar { width: 200px; border-right: 1px solid var(--color-border, #333); padding: 12px 0; overflow-y: auto; flex-shrink: 0; }
.sidebar-divider { border: none; border-top: 1px solid var(--color-border, #333); margin: 12px 0; }
.sidebar-sections { display: flex; flex-direction: column; gap: 2px; }
.sidebar-section { display: flex; justify-content: space-between; padding: 6px 12px; font-size: 13px; border: none; background: none; color: var(--color-text-muted, #888); cursor: pointer; text-align: left; border-left: 2px solid transparent; width: 100%; }
.sidebar-section.active { background: rgba(245, 158, 11, 0.08); border-left-color: #f59e0b; color: var(--color-text, #eee); }
.sidebar-section .count { font-size: 11px; background: var(--color-bg-subtle, #333); padding: 0 6px; border-radius: 8px; }
.sidebar-section .count-loading { opacity: 0.5; }
.detail-main { flex: 1; overflow-y: auto; }
.details-section { padding: 16px; }
</style>
