<script setup lang="ts">
import type { MetadataField } from '~/composables/useEntityDetailConfig'

const props = defineProps<{
  fields: MetadataField[]
  entity: Record<string, any>
}>()

function formatValue(field: MetadataField): string {
  const raw = props.entity[field.key]
  if (raw == null || raw === '') return '\u2014'
  if (field.format === 'date') {
    try { return new Date(raw).toLocaleDateString() } catch { return String(raw) }
  }
  if (field.truncate && typeof raw === 'string' && raw.length > 12) {
    return raw.slice(0, 4) + '\u2026' + raw.slice(-4)
  }
  return String(raw)
}
</script>

<template>
  <div class="metadata-card">
    <div v-for="field in fields" :key="field.key" class="metadata-field">
      <span class="metadata-label">{{ field.label }}</span>
      <span
        class="metadata-value"
        :class="{ 'metadata-badge': field.format === 'badge' }"
        :title="String(entity[field.key] ?? '')"
      >
        {{ formatValue(field) }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.metadata-card { display: flex; flex-direction: column; gap: 10px; padding: 12px; }
.metadata-field { display: flex; flex-direction: column; gap: 2px; }
.metadata-label { font-size: 10px; text-transform: uppercase; color: var(--color-text-muted, #999); letter-spacing: 0.05em; }
.metadata-value { font-size: 13px; }
.metadata-badge { font-weight: bold; }
</style>
