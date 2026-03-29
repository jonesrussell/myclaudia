export interface ChatMessageRow {
  role: 'user' | 'assistant' | 'system'
  content: string
  streaming?: boolean
}

export interface ChatSessionRow {
  uuid: string
  title: string
  created_at?: string | null
  workspace_id?: string | null
}

export function useChatRail() {
  const messages = ref<ChatMessageRow[]>([])
  const sessionId = ref<string | null>(null)
  const sessions = ref<ChatSessionRow[]>([])
  const sessionsLoading = ref(false)
  const sending = ref(false)
  const error = ref<string | null>(null)
  const continuation = ref<{ sessionUuid: string; message: string; turnsConsumed?: number } | null>(null)
  const { workspaceUuid } = useWorkspaceScope()
  const { currentUser } = useAuth()

  let eventSource: EventSource | null = null

  function closeStream() {
    eventSource?.close()
    eventSource = null
  }

  onUnmounted(() => {
    closeStream()
  })

  async function refreshSessions() {
    sessionsLoading.value = true
    try {
      const sp = new URLSearchParams()
      if (workspaceUuid.value) {
        sp.set('workspace_uuid', workspaceUuid.value)
      }
      if (currentUser.value?.tenantId) {
        sp.set('tenant_id', currentUser.value.tenantId)
      }
      const qs = sp.toString()
      const url = qs ? `/api/chat/sessions?${qs}` : '/api/chat/sessions'
      const res = await fetch(url, { credentials: 'include' })
      if (!res.ok) {
        sessions.value = []
        return
      }
      const data = await res.json() as { sessions?: ChatSessionRow[] }
      sessions.value = data.sessions ?? []
    } catch {
      sessions.value = []
    } finally {
      sessionsLoading.value = false
    }
  }

  async function loadSession(uuid: string) {
    error.value = null
    const res = await fetch(`/api/chat/sessions/${encodeURIComponent(uuid)}/messages`, {
      credentials: 'include',
    })
    if (!res.ok) {
      error.value = 'Could not load chat history.'
      return
    }
    const data = await res.json() as { messages?: Array<{ role: string; content: string }> }
    sessionId.value = uuid
    messages.value = (data.messages ?? []).map(m => ({
      role: m.role === 'user' ? 'user' : 'assistant',
      content: m.content ?? '',
    }))
  }

  async function sendMessage(text: string, options: { appendUser?: boolean } = {}) {
    const appendUser = options.appendUser !== false
    const trimmed = text.trim()
    if (!trimmed || sending.value) {
      return
    }

    sending.value = true
    error.value = null
    continuation.value = null

    if (appendUser) {
      messages.value.push({ role: 'user', content: trimmed })
    }

    const body: Record<string, unknown> = {
      message: trimmed,
      session_id: sessionId.value,
    }
    if (workspaceUuid.value) {
      body.workspace_uuid = workspaceUuid.value
    }
    if (currentUser.value?.tenantId) {
      body.tenant_id = currentUser.value.tenantId
    }

    try {
      const res = await fetch('/api/chat/send', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(body),
      })

      const data = await res.json().catch(() => ({})) as {
        error?: string
        message_id?: string
        session_id?: string
        response?: string
      }

      if (!res.ok) {
        error.value = data.error ?? `Chat failed (${res.status})`
        sending.value = false
        return
      }

      sessionId.value = data.session_id ?? sessionId.value

      if (!data.message_id) {
        messages.value.push({
          role: 'assistant',
          content: data.response ?? '',
        })
        sending.value = false
        return
      }

      const assistantIndex = messages.value.push({
        role: 'assistant',
        content: '',
        streaming: true,
      }) - 1

      await streamAssistant(data.message_id, assistantIndex)
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Network error'
    } finally {
      sending.value = false
    }
  }

  function streamAssistant(messageId: string, assistantIndex: number): Promise<void> {
    closeStream()
    return new Promise((resolve) => {
      const url = `/stream/chat/${encodeURIComponent(messageId)}`
      eventSource = new EventSource(url, { withCredentials: true })

      const row = messages.value[assistantIndex]
      if (!row) {
        closeStream()
        resolve()
        return
      }

      eventSource.addEventListener('chat-token', (ev: MessageEvent) => {
        try {
          const tokenData = JSON.parse(ev.data) as { token?: string }
          row.content += tokenData.token ?? ''
        } catch {
          /* ignore */
        }
      })

      eventSource.addEventListener('chat-needs-continuation', (ev: MessageEvent) => {
        try {
          const data = JSON.parse(ev.data) as {
            session_uuid?: string
            turns_consumed?: number
            message?: string
          }
          continuation.value = {
            sessionUuid: data.session_uuid ?? sessionId.value ?? '',
            message: data.message ?? 'The agent needs more turns to continue.',
            turnsConsumed: data.turns_consumed,
          }
        } catch {
          /* ignore */
        }
      })

      eventSource.addEventListener('chat-done', (ev: MessageEvent) => {
        closeStream()
        let doneData: { full_response?: string } | null = null
        try {
          doneData = JSON.parse(ev.data)
        } catch {
          /* ignore */
        }
        if (!row.content && doneData?.full_response) {
          row.content = doneData.full_response
        }
        row.streaming = false
        resolve()
      }, { once: true })

      eventSource.addEventListener('chat-error', (ev: MessageEvent) => {
        closeStream()
        row.streaming = false
        try {
          const errData = JSON.parse(ev.data) as { error?: string }
          error.value = errData.error ?? 'Stream error'
        } catch {
          error.value = 'Stream error'
        }
        resolve()
      }, { once: true })

      eventSource.onerror = () => {
        closeStream()
        row.streaming = false
        if (!row.content) {
          error.value = 'Chat stream interrupted.'
        }
        resolve()
      }
    })
  }

  async function continueSession() {
    const ctx = continuation.value
    if (!ctx?.sessionUuid) {
      return
    }
    error.value = null
    try {
      const res = await fetch(`/api/internal/session/${encodeURIComponent(ctx.sessionUuid)}/continue`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
      })
      if (res.status === 429) {
        error.value = 'Daily turn limit reached.'
        return
      }
      if (!res.ok) {
        const errBody = await res.json().catch(() => ({})) as { error?: string }
        error.value = errBody.error ?? 'Could not continue session.'
        return
      }
      continuation.value = null
      await sendMessage('[continue]', { appendUser: false })
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Continue failed'
    }
  }

  function clearConversation() {
    closeStream()
    sessionId.value = null
    messages.value = []
    continuation.value = null
    error.value = null
  }

  watch(workspaceUuid, () => {
    refreshSessions()
  })

  return {
    messages,
    sessionId,
    sessions,
    sessionsLoading,
    sending,
    error,
    continuation,
    loadSession,
    refreshSessions,
    sendMessage,
    continueSession,
    clearConversation,
  }
}
