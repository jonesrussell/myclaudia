<script setup lang="ts">
import { PROSPECT_PIPELINE_STAGES } from '~/constants/prospectPipeline'
import type { ProspectRow } from '~/composables/useOpsGraphql'
import { useLanguage } from '~/composables/useLanguage'
import { useOpsDetailDrawer } from '~/composables/useOpsDetailDrawer'

const props = defineProps<{
  prospects: ProspectRow[]
}>()

const { t } = useLanguage()
const { openDrawer } = useOpsDetailDrawer()

function onProspectCard(p: ProspectRow) {
  openDrawer('prospect', p.uuid)
}

const byStage = computed(() => {
  const map = new Map<string, ProspectRow[]>()
  for (const s of PROSPECT_PIPELINE_STAGES) {
    map.set(s, [])
  }
  for (const p of props.prospects) {
    const stage = (p.stage && p.stage !== '' ? p.stage : 'lead') as string
    const bucket = map.get(stage) ?? map.get('lead')!
    bucket.push(p)
  }
  return map
})
</script>

<template>
  <div class="pipeline-board">
    <div
      v-for="stage in PROSPECT_PIPELINE_STAGES"
      :key="stage"
      class="pipeline-col"
    >
      <h3 class="pipeline-col-title">{{ stage }}</h3>
      <span class="pipeline-col-count">{{ (byStage.get(stage) ?? []).length }}</span>
      <ul class="pipeline-cards">
        <li v-for="p in byStage.get(stage) ?? []" :key="p.uuid" class="pipeline-card-li">
          <button type="button" class="pipeline-card" @click="onProspectCard(p)">
            <span class="pipeline-card-name">{{ p.name || t('ops_untitled') }}</span>
            <span v-if="p.contact_name" class="pipeline-card-sub">{{ p.contact_name }}</span>
          </button>
          <NuxtLink class="pipeline-card-open" :to="`/prospect/${p.uuid}`">→</NuxtLink>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.pipeline-board {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
  align-items: start;
}
.pipeline-col {
  background: var(--bg-surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 10px;
  min-height: 120px;
}
.pipeline-col-title {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--accent-amber);
  margin-bottom: 4px;
}
.pipeline-col-count {
  font-size: 12px;
  color: var(--text-muted);
  display: block;
  margin-bottom: 8px;
}
.pipeline-cards {
  list-style: none;
  padding: 0;
  margin: 0;
}
.pipeline-card-li {
  display: flex;
  align-items: flex-start;
  gap: 4px;
  margin-bottom: 6px;
}
.pipeline-card {
  flex: 1;
  display: block;
  padding: 8px;
  background: var(--bg-elevated);
  border-radius: var(--radius-sm);
  text-align: left;
  color: var(--text-primary);
  border: 1px solid var(--border-subtle);
  transition: border-color 0.15s;
  cursor: pointer;
  font: inherit;
}
.pipeline-card:hover {
  border-color: var(--accent-teal);
}
.pipeline-card-open {
  flex-shrink: 0;
  padding: 8px 4px;
  font-size: 12px;
  color: var(--text-muted);
  text-decoration: none;
  align-self: center;
}
.pipeline-card-open:hover {
  color: var(--accent-teal);
}
.pipeline-card-name {
  font-size: 13px;
  font-weight: 500;
  display: block;
}
.pipeline-card-sub {
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 2px;
  display: block;
}
</style>
