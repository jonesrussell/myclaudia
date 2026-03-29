<script setup lang="ts">
import type { DayBriefCommitmentRow } from '~/types/dayBrief'
import { useLanguage } from '~/composables/useLanguage'
import { useDayBrief } from '~/composables/useDayBrief'
import { useWorkspaceScope } from '~/composables/useWorkspaceScope'
import { useOpsDetailDrawer } from '~/composables/useOpsDetailDrawer'

const { t } = useLanguage()
const config = useRuntimeConfig()
useHead({ title: computed(() => `${t('ops_nav_today')} | ${config.public.appName}`) })

const { brief, error, loading, refresh, streamLive, startBriefStream, stopBriefStream } = useDayBrief()
const { workspaceUuid, setWorkspace } = useWorkspaceScope()
const { openDrawer } = useOpsDetailDrawer()

onMounted(() => {
  refresh()
})

const countCards = computed(() => {
  const b = brief.value
  if (!b?.counts) {
    return []
  }
  const c = b.counts
  return [
    { key: 'due', val: c.due_today ?? 0, labelKey: 'ops_count_due' },
    { key: 'drift', val: c.drifting ?? 0, labelKey: 'ops_count_drifting' },
    { key: 'wait', val: c.waiting_on ?? 0, labelKey: 'ops_count_waiting' },
    { key: 'fu', val: c.follow_ups ?? 0, labelKey: 'ops_count_followups' },
    { key: 'tr', val: c.triage ?? 0, labelKey: 'ops_count_triage' },
  ]
})

function linkCommitment(c: DayBriefCommitmentRow) {
  const u = c.uuid
  if (u) {
    return `/commitment/${u}`
  }
  return '/commitment'
}

function peekCommitment(c: DayBriefCommitmentRow) {
  if (c.uuid) {
    openDrawer('commitment', c.uuid)
  }
}

function toggleBriefStream() {
  if (streamLive.value) {
    stopBriefStream()
  } else {
    startBriefStream()
  }
}

watch(workspaceUuid, () => {
  refresh()
})
</script>

<template>
  <div>
    <div class="page-header">
      <h1>{{ t('ops_nav_today') }}</h1>
      <div class="page-actions">
        <button
          type="button"
          class="btn btn-sm"
          :class="{ 'btn-primary': streamLive }"
          :title="streamLive ? t('ops_brief_live_on') : t('ops_brief_live_off')"
          @click="toggleBriefStream"
        >
          {{ t('ops_brief_live') }}
        </button>
        <button type="button" class="btn btn-sm" :disabled="loading" @click="refresh">
          {{ t('ops_refresh_brief') }}
        </button>
      </div>
    </div>

    <p v-if="workspaceUuid" class="scope-hint">
      {{ t('ops_workspace_filter') }}:
      <code>{{ workspaceUuid }}</code>
      <button type="button" class="btn btn-sm linkish" @click="setWorkspace(null)">
        {{ t('ops_clear_workspace') }}
      </button>
    </p>

    <div v-if="loading" class="loading">{{ t('loading') }}</div>
    <div v-else-if="error" class="error">{{ error }}</div>
    <template v-else-if="brief">
      <div class="ops-counts">
        <div v-for="row in countCards" :key="row.key" class="ops-count-card">
          <span class="ops-count-val">{{ row.val }}</span>
          <span class="ops-count-lbl">{{ t(row.labelKey) }}</span>
        </div>
      </div>

      <section class="card-surface">
        <h2 class="section-title">{{ t('ops_section_do') }}</h2>
        <ul v-if="brief.commitments?.pending?.length" class="ops-list">
          <li v-for="(c, i) in brief.commitments.pending" :key="'p'+i" class="ops-li-row">
            <button
              v-if="c.uuid"
              type="button"
              class="ops-link-btn"
              @click="peekCommitment(c)"
            >
              {{ c.title }}
            </button>
            <span v-else>{{ c.title }}</span>
            <NuxtLink v-if="c.uuid" class="ops-li-edit" :to="linkCommitment(c)">→</NuxtLink>
            <span v-if="c.due_date" class="ops-meta">{{ c.due_date }}</span>
          </li>
        </ul>
        <p v-else class="muted">{{ t('ops_empty') }}</p>
      </section>

      <section class="card-surface">
        <h2 class="section-title">{{ t('ops_section_respond') }}</h2>
        <h3 class="subsection">{{ t('ops_followups') }}</h3>
        <ul v-if="brief.follow_ups?.length" class="ops-list">
          <li v-for="(f, i) in brief.follow_ups" :key="'f'+i">
            <span class="ops-li-title">{{ f.subject }}</span>
            <span class="ops-li-sub">{{ f.recipient }} · {{ f.sent_at }}</span>
          </li>
        </ul>
        <p v-else class="muted">{{ t('ops_empty') }}</p>
        <h3 class="subsection">{{ t('ops_triage') }}</h3>
        <ul v-if="brief.triage?.length" class="ops-list">
          <li v-for="(tr, i) in brief.triage" :key="'t'+i">
            <span class="ops-li-title">{{ tr.summary }}</span>
            <span class="ops-li-sub">{{ tr.person_name || tr.person_email }}</span>
          </li>
        </ul>
        <p v-else class="muted">{{ t('ops_empty') }}</p>
      </section>

      <section class="card-surface">
        <h2 class="section-title">{{ t('ops_section_prep') }}</h2>
        <h3 class="subsection">{{ t('ops_schedule') }}</h3>
        <ul v-if="brief.schedule?.length" class="ops-list">
          <li v-for="(s, i) in brief.schedule" :key="'s'+i">
            <span class="ops-li-title">{{ s.title }}</span>
            <span class="ops-li-sub">{{ s.start_time }} — {{ s.end_time }}</span>
          </li>
        </ul>
        <p v-else class="muted">{{ t('ops_empty') }}</p>
        <h3 class="subsection">{{ t('ops_suggestions') }}</h3>
        <ul v-if="brief.temporal_suggestions?.length" class="ops-list">
          <li v-for="(ts, i) in brief.temporal_suggestions" :key="'ts'+i">
            <span class="ops-li-title">{{ ts.title }}</span>
            <span class="ops-li-sub">{{ ts.summary }}</span>
          </li>
        </ul>
        <p v-else class="muted">{{ t('ops_empty') }}</p>
      </section>

      <section class="card-surface">
        <h2 class="section-title">{{ t('ops_section_connect') }}</h2>
        <h3 class="subsection">{{ t('ops_waiting') }}</h3>
        <ul v-if="brief.commitments?.waiting_on?.length" class="ops-list">
          <li v-for="(c, i) in brief.commitments.waiting_on" :key="'w'+i" class="ops-li-row">
            <button
              v-if="c.uuid"
              type="button"
              class="ops-link-btn"
              @click="peekCommitment(c)"
            >
              {{ c.title }}
            </button>
            <span v-else>{{ c.title }}</span>
            <NuxtLink v-if="c.uuid" class="ops-li-edit" :to="linkCommitment(c)">→</NuxtLink>
          </li>
        </ul>
        <p v-else class="muted">{{ t('ops_empty') }}</p>
        <h3 class="subsection">{{ t('ops_drifting') }}</h3>
        <ul v-if="brief.commitments?.drifting?.length" class="ops-list">
          <li v-for="(c, i) in brief.commitments.drifting" :key="'d'+i" class="ops-li-row">
            <button
              v-if="c.uuid"
              type="button"
              class="ops-link-btn"
              @click="peekCommitment(c)"
            >
              {{ c.title }}
            </button>
            <span v-else>{{ c.title }}</span>
            <NuxtLink v-if="c.uuid" class="ops-li-edit" :to="linkCommitment(c)">→</NuxtLink>
          </li>
        </ul>
        <p v-else class="muted">{{ t('ops_empty') }}</p>
        <h3 class="subsection">{{ t('ops_people') }}</h3>
        <ul v-if="brief.people?.length" class="ops-list">
          <li v-for="(p, i) in brief.people" :key="'ppl'+i">
            <span class="ops-li-title">{{ p.summary }}</span>
            <span class="ops-li-sub">{{ p.person_name }} {{ p.person_email }}</span>
          </li>
        </ul>
        <p v-else class="muted">{{ t('ops_empty') }}</p>
      </section>

      <OpsGitHubBriefPanel :github="brief.github" />
    </template>
  </div>
</template>

<style scoped>
.page-actions {
  display: flex;
  gap: 8px;
}
.scope-hint {
  font-size: 13px;
  color: var(--text-muted);
  margin-bottom: 16px;
}
.scope-hint code {
  font-size: 12px;
  color: var(--accent-teal);
}
.linkish {
  margin-left: 8px;
  vertical-align: middle;
}
.ops-counts {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 10px;
  margin-bottom: 20px;
}
.ops-count-card {
  background: var(--bg-surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 12px;
  text-align: center;
}
.ops-count-val {
  display: block;
  font-family: var(--font-display);
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--accent-amber);
}
.ops-count-lbl {
  font-size: 11px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.card-surface {
  background: var(--bg-surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 20px;
  margin-bottom: 20px;
}
.section-title {
  font-family: var(--font-display);
  font-size: 1.1rem;
  margin-bottom: 12px;
}
.subsection {
  font-size: 12px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  margin: 16px 0 8px;
}
.subsection:first-of-type {
  margin-top: 0;
}
.ops-list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.ops-list li {
  padding: 8px 0;
  border-bottom: 1px solid var(--border-subtle);
  font-size: 14px;
}
.ops-li-row {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px;
}
.ops-link-btn {
  background: none;
  border: none;
  padding: 0;
  font: inherit;
  color: var(--accent-teal);
  cursor: pointer;
  text-align: left;
}
.ops-link-btn:hover {
  text-decoration: underline;
}
.ops-li-edit {
  font-size: 12px;
  color: var(--text-muted);
  text-decoration: none;
}
.ops-li-edit:hover {
  color: var(--accent-teal);
}
.ops-link {
  color: var(--accent-teal);
  text-decoration: none;
}
.ops-link:hover {
  text-decoration: underline;
}
.ops-meta {
  display: block;
  font-size: 12px;
  color: var(--text-muted);
}
.ops-li-title {
  display: block;
  color: var(--text-primary);
}
.ops-li-sub {
  display: block;
  font-size: 12px;
  color: var(--text-muted);
  margin-top: 2px;
}
.muted {
  color: var(--text-muted);
  font-size: 13px;
}
</style>
