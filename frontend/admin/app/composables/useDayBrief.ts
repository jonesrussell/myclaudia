import type { DayBriefPayload } from '~/types/dayBrief'

/**
 * Fetches JSON from GET /brief (same-origin; Nitro proxies to PHP in dev).
 */
export async function fetchDayBriefJson(params: {
  workspaceUuid?: string | null
  tenantId?: string | null
} = {}): Promise<DayBriefPayload> {
  const sp = new URLSearchParams()
  if (params.workspaceUuid) {
    sp.set('workspace_uuid', params.workspaceUuid)
  }
  if (params.tenantId) {
    sp.set('tenant_id', params.tenantId)
  }
  const qs = sp.toString()
  const url = qs ? `/brief?${qs}` : '/brief'

  const response = await fetch(url, {
    method: 'GET',
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    const text = await response.text()
    throw new Error(text || `Brief request failed: ${response.status}`)
  }

  const data = await response.json() as DayBriefPayload | { error?: string }
  if (data && typeof data === 'object' && 'error' in data && typeof (data as { error: string }).error === 'string') {
    throw new Error((data as { error: string }).error)
  }

  return data as DayBriefPayload
}

export function useDayBrief() {
  const brief = ref<DayBriefPayload | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)
  const streamLive = ref(false)
  const { workspaceUuid } = useWorkspaceScope()
  const { currentUser } = useAuth()

  let briefEventSource: EventSource | null = null

  function stopBriefStream() {
    briefEventSource?.close()
    briefEventSource = null
    streamLive.value = false
  }

  function startBriefStream() {
    if (typeof window === 'undefined') {
      return
    }
    stopBriefStream()
    const sp = new URLSearchParams()
    if (workspaceUuid.value) {
      sp.set('workspace_uuid', workspaceUuid.value)
    }
    if (currentUser.value?.tenantId) {
      sp.set('tenant_id', currentUser.value.tenantId)
    }
    const qs = sp.toString()
    const url = qs ? `/stream/brief?${qs}` : '/stream/brief'
    briefEventSource = new EventSource(url, { withCredentials: true })
    streamLive.value = true

    briefEventSource.addEventListener('brief-update', (ev: MessageEvent) => {
      try {
        brief.value = JSON.parse(ev.data) as DayBriefPayload
        error.value = null
      } catch {
        /* ignore malformed SSE */
      }
    })

    briefEventSource.addEventListener('brief-keepalive', () => {
      /* keep connection alive; optional hook */
    })

    briefEventSource.onerror = () => {
      stopBriefStream()
    }
  }

  onUnmounted(() => {
    stopBriefStream()
  })

  watch(workspaceUuid, () => {
    if (streamLive.value) {
      startBriefStream()
    }
  })

  async function refresh() {
    loading.value = true
    error.value = null
    try {
      brief.value = await fetchDayBriefJson({
        workspaceUuid: workspaceUuid.value,
        tenantId: currentUser.value?.tenantId ?? null,
      })
    } catch (e) {
      brief.value = null
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      loading.value = false
    }
  }

  return { brief, error, loading, refresh, streamLive, startBriefStream, stopBriefStream }
}
