export interface CodifiedContextSession {
  id: string
  sessionId: string
  repoHash: string
  startedAt: string
  endedAt: string | null
  durationMs: number | null
  eventCount: number
  latestDriftScore: number | null
  latestSeverity: string | null
}

export interface CodifiedContextEvent {
  id: string
  sessionId: string
  eventType: string
  data: Record<string, unknown>
  createdAt: string
}

export interface ValidationReport {
  sessionId: string
  driftScore: number
  components: {
    semantic_alignment: number
    structural_checks: number
    contradiction_checks: number
  }
  issues: Array<{ type: string; message: string; severity: string }>
  recommendation: string
  validatedAt: string
}

async function fetchJsonData<T>(url: string): Promise<T | null> {
  const response = await fetch(url)

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`)
  }

  const json = await response.json() as { data?: T }

  return json.data ?? null
}

function getErrorMessage(error: unknown, fallback: string): string {
  return error instanceof Error ? error.message : fallback
}

export function useCodifiedContext() {
  const sessions = ref<CodifiedContextSession[]>([])
  const currentSession = ref<CodifiedContextSession | null>(null)
  const events = ref<CodifiedContextEvent[]>([])
  const validationReport = ref<ValidationReport | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  async function fetchSessions(limit = 50) {
    loading.value = true
    error.value = null
    try {
      sessions.value = await fetchJsonData<CodifiedContextSession[]>(
        `/api/telescope/codified-context/sessions?limit=${limit}`,
      ) ?? []
    } catch (caughtError: unknown) {
      error.value = getErrorMessage(caughtError, 'Failed to load sessions.')
    } finally {
      loading.value = false
    }
  }

  async function fetchSession(id: string) {
    loading.value = true
    error.value = null
    try {
      currentSession.value = await fetchJsonData<CodifiedContextSession>(
        `/api/telescope/codified-context/sessions/${id}`,
      )
    } catch (caughtError: unknown) {
      error.value = getErrorMessage(caughtError, 'Failed to load session.')
    } finally {
      loading.value = false
    }
  }

  async function fetchEvents(id: string) {
    loading.value = true
    error.value = null
    try {
      events.value = await fetchJsonData<CodifiedContextEvent[]>(
        `/api/telescope/codified-context/sessions/${id}/events`,
      ) ?? []
    } catch (caughtError: unknown) {
      error.value = getErrorMessage(caughtError, 'Failed to load events.')
    } finally {
      loading.value = false
    }
  }

  async function fetchValidation(id: string) {
    loading.value = true
    error.value = null
    try {
      validationReport.value = await fetchJsonData<ValidationReport>(
        `/api/telescope/codified-context/sessions/${id}/validation`,
      )
    } catch (caughtError: unknown) {
      error.value = getErrorMessage(caughtError, 'Failed to load validation.')
    } finally {
      loading.value = false
    }
  }

  return {
    sessions,
    currentSession,
    events,
    validationReport,
    loading,
    error,
    fetchSessions,
    fetchSession,
    fetchEvents,
    fetchValidation,
  }
}
