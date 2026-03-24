<script setup lang="ts">
import { ref, computed } from 'vue'

export interface TimelineEvent {
  type: 'event' | 'triage' | 'schedule' | 'commitment'
  label: string
  timestamp: string
}

const props = defineProps<{ events: TimelineEvent[] }>()

const activeFilter = ref<string>('all')

const filters = [
  { key: 'all', label: 'All' },
  { key: 'event', label: 'Events' },
  { key: 'triage', label: 'Triage' },
  { key: 'schedule', label: 'Schedule' },
  { key: 'commitment', label: 'Commitments' },
]

const typeColors: Record<string, string> = {
  event: '#22c55e',
  triage: '#f59e0b',
  schedule: '#3b82f6',
  commitment: '#a855f7',
}

const typeLabels: Record<string, string> = {
  event: 'EVENT',
  triage: 'TRIAGE',
  schedule: 'SCHEDULE',
  commitment: 'COMMITMENT',
}

const filteredEvents = computed(() => {
  const sorted = [...props.events].sort(
    (a, b) => new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime(),
  )
  if (activeFilter.value === 'all') return sorted
  return sorted.filter((e) => e.type === activeFilter.value)
})

function relativeTime(ts: string): string {
  const diff = Date.now() - new Date(ts).getTime()
  const hours = Math.floor(diff / 3600000)
  if (hours < 1) return 'just now'
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  return `${days}d ago`
}
</script>

<template>
  <div class="activity-timeline">
    <div class="filter-chips">
      <button
        v-for="f in filters"
        :key="f.key"
        :data-filter="f.key"
        :class="['chip', { active: activeFilter === f.key }]"
        @click="activeFilter = f.key"
      >
        {{ f.label }}
      </button>
    </div>

    <div v-if="filteredEvents.length === 0" class="empty">No activity</div>

    <div v-else class="timeline">
      <div v-for="(event, i) in filteredEvents" :key="i" class="timeline-item">
        <div class="dot" :style="{ background: typeColors[event.type] }"></div>
        <div class="timeline-content">
          <span class="time">{{ relativeTime(event.timestamp) }}</span>
          <div>
            <span class="type-label" :style="{ color: typeColors[event.type] }">
              {{ typeLabels[event.type] }}
            </span>
            {{ event.label }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.activity-timeline { padding: 16px; }
.filter-chips { display: flex; gap: 6px; margin-bottom: 14px; flex-wrap: wrap; }
.chip { background: var(--color-bg-subtle, #333); color: var(--color-text-muted, #888); padding: 2px 10px; border-radius: 12px; font-size: 11px; border: none; cursor: pointer; }
.chip.active { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
.timeline { border-left: 2px solid var(--color-border, #333); padding-left: 16px; }
.timeline-item { margin-bottom: 14px; position: relative; font-size: 13px; }
.dot { position: absolute; left: -21px; top: 4px; width: 10px; height: 10px; border-radius: 50%; }
.time { color: var(--color-text-muted, #999); font-size: 11px; }
.type-label { font-size: 11px; font-weight: bold; margin-right: 4px; }
.empty { color: var(--color-text-muted, #888); padding: 24px; text-align: center; }
</style>
