import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useCodifiedContext } from '~/composables/useCodifiedContext'

describe('useCodifiedContext', () => {
  const originalFetch = globalThis.fetch

  beforeEach(() => {
    globalThis.fetch = vi.fn()
  })

  afterEach(() => {
    globalThis.fetch = originalFetch
  })

  it('loads sessions from the API on success', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        data: [
          {
            id: '1',
            sessionId: 'sess-123',
            repoHash: 'repo-abc',
            startedAt: '2026-03-12T10:00:00Z',
            endedAt: null,
            durationMs: null,
            eventCount: 5,
            latestDriftScore: 42,
            latestSeverity: 'low',
          },
        ],
      }),
    })

    const { sessions, error, fetchSessions } = useCodifiedContext()
    await fetchSessions()

    expect(error.value).toBeNull()
    expect(sessions.value).toHaveLength(1)
    expect(sessions.value[0]?.sessionId).toBe('sess-123')
  })

  it('surfaces non-ok HTTP responses as errors', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 500,
      json: () => Promise.resolve({ errors: [{ detail: 'Server exploded' }] }),
    })

    const { sessions, error, fetchSessions } = useCodifiedContext()
    await fetchSessions()

    expect(sessions.value).toEqual([])
    expect(error.value).toBe('HTTP 500')
  })
})
