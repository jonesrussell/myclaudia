<script setup lang="ts">
import type { CodifiedContextEvent } from '~/composables/useCodifiedContext'

const props = defineProps<{ events: CodifiedContextEvent[] }>()

interface HeatCell {
  path: string
  count: number
  intensity: number
}

const cells = computed<HeatCell[]>(() => {
  const freq: Record<string, number> = {}
  for (const event of props.events) {
    if (event.eventType !== 'context.load') continue
    const paths = event.data?.files
    if (Array.isArray(paths)) {
      for (const p of paths) {
        if (typeof p === 'string') {
          freq[p] = (freq[p] ?? 0) + 1
        }
      }
    }
    // Also check single path
    const singlePath = event.data?.path
    if (typeof singlePath === 'string') {
      freq[singlePath] = (freq[singlePath] ?? 0) + 1
    }
  }

  const max = Math.max(1, ...Object.values(freq))
  return Object.entries(freq)
    .sort(([, a], [, b]) => b - a)
    .map(([path, count]) => ({
      path,
      count,
      intensity: count / max,
    }))
})

/** Editorial operator heatmap: purple (AI) intensity on dark surface */
function cellBackground(intensity: number): string {
  const alpha = 0.12 + intensity * 0.42
  return `rgba(167, 139, 250, ${alpha.toFixed(2)})`
}

function cellColor(intensity: number): string {
  return intensity > 0.52 ? '#f8fafc' : 'var(--text-primary, #e8e9ed)'
}
</script>

<template>
  <div class="heatmap">
    <p v-if="cells.length === 0" class="empty">No context.load events found.</p>
    <div v-else class="heatmap-grid">
      <div
        v-for="cell in cells"
        :key="cell.path"
        class="heatmap-cell"
        :style="{ background: cellBackground(cell.intensity), color: cellColor(cell.intensity) }"
        :title="`${cell.path} (${cell.count})`"
      >
        <span class="cell-path">{{ cell.path.split('/').pop() }}</span>
        <span class="cell-count">{{ cell.count }}</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.heatmap {
  font-family: var(--font-body, system-ui, sans-serif);
}
.heatmap-grid {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm, 0.5rem);
}
.heatmap-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--space-sm, 0.5rem) var(--space-md, 0.75rem);
  border-radius: var(--radius-sm, 6px);
  border: 1px solid var(--border, rgba(255, 255, 255, 0.06));
  min-width: 6rem;
  max-width: 12rem;
  cursor: default;
  overflow: hidden;
}
.cell-path {
  font-size: 0.75rem;
  font-weight: 600;
  font-family: var(--font-display, system-ui, sans-serif);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}
.cell-count {
  font-size: 0.65rem;
  color: var(--text-muted, #6b6d82);
}
.empty {
  color: var(--text-muted, #6b6d82);
  font-size: 0.9rem;
}
</style>
