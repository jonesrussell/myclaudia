<script setup lang="ts">
import { useCodifiedContext } from '~/composables/useCodifiedContext'
import { useLanguage } from '~/composables/useLanguage'

const { t } = useLanguage()
const { sessions, loading, error, fetchSessions } = useCodifiedContext()

onMounted(() => fetchSessions())

const config = useRuntimeConfig()
useHead({ title: computed(() => `${t('telescope_codified_context')} | ${config.public.appName}`) })

function formatDuration(ms: number | null): string {
  if (ms === null) return '—'
  if (ms < 1000) return `${ms}ms`
  return `${(ms / 1000).toFixed(1)}s`
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString()
}

function severityClass(severity: string | null): string {
  switch (severity) {
    case 'critical': return 'badge badge--critical'
    case 'high': return 'badge badge--high'
    case 'medium': return 'badge badge--medium'
    case 'low': return 'badge badge--low'
    default: return 'badge badge--none'
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('telescope_codified_context') }}</h1>
    </div>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>

    <div v-else-if="error" class="error">{{ error }}</div>

    <template v-else>
      <p v-if="sessions.length === 0" class="empty-state">
        {{ t('telescope_cc_no_sessions') }}
      </p>

      <table v-else class="data-table">
        <thead>
          <tr>
            <th>{{ t('telescope_cc_sessions') }}</th>
            <th>Repo</th>
            <th>Started</th>
            <th>Duration</th>
            <th>Events</th>
            <th>{{ t('telescope_cc_drift_score') }}</th>
            <th>{{ t('telescope_cc_severity') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="session in sessions" :key="session.id">
            <td>
              <NuxtLink :to="`/telescope/codified-context/${session.sessionId}`">
                {{ session.sessionId.slice(0, 8) }}…
              </NuxtLink>
            </td>
            <td>{{ session.repoHash.slice(0, 8) }}</td>
            <td>{{ formatDate(session.startedAt) }}</td>
            <td>{{ formatDuration(session.durationMs) }}</td>
            <td>{{ session.eventCount }}</td>
            <td>{{ session.latestDriftScore !== null ? session.latestDriftScore : '—' }}</td>
            <td>
              <span :class="severityClass(session.latestSeverity)">
                {{ session.latestSeverity ?? '—' }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style scoped>
.data-table {
  width: 100%;
  border-collapse: collapse;
  background: var(--bg-surface);
  border-radius: var(--radius-sm);
  overflow: hidden;
  border: 1px solid var(--border);
}
.data-table th,
.data-table td {
  padding: 10px 12px;
  text-align: left;
  border-bottom: 1px solid var(--border);
}
.data-table th {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
}
.data-table tbody tr:last-child td {
  border-bottom: none;
}
.data-table a {
  color: var(--accent-blue);
  text-decoration: none;
  font-weight: 500;
}
.data-table a:hover {
  text-decoration: underline;
}
.badge {
  display: inline-block;
  padding: 0.22rem 0.55rem;
  border-radius: var(--radius-pill);
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: capitalize;
  border: 1px solid transparent;
}
.badge--critical {
  color: #fca5a5;
  background: rgba(240, 96, 96, 0.12);
  border-color: rgba(240, 96, 96, 0.28);
}
.badge--high {
  color: var(--accent-amber);
  background: rgba(240, 176, 64, 0.12);
  border-color: rgba(240, 176, 64, 0.28);
}
.badge--medium {
  color: var(--accent-blue);
  background: rgba(107, 155, 255, 0.1);
  border-color: rgba(107, 155, 255, 0.22);
}
.badge--low {
  color: var(--accent-teal);
  background: rgba(45, 212, 191, 0.12);
  border-color: rgba(45, 212, 191, 0.25);
}
.badge--none {
  color: var(--text-muted);
  background: var(--bg-elevated);
  border-color: var(--border-emphasis);
}
.empty-state {
  color: var(--text-muted);
  margin-top: var(--space-lg);
}
</style>
