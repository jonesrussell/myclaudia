import { describe, it, expect, vi } from 'vitest'
import { useEntity } from '~/composables/useEntity'

describe('useEntity.search', () => {
  it('returns empty array when query is less than 2 characters', async () => {
    const mockFetch = vi.fn()
    vi.stubGlobal('$fetch', mockFetch)
    const { search } = useEntity()
    expect(await search('user', 'name', '')).toEqual([])
    expect(await search('user', 'name', 'a')).toEqual([])
    expect(mockFetch).not.toHaveBeenCalled()
  })

  it('calls $fetch with correct filter params when query is 2+ chars', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ people: [{ uuid: 'person-1', name: 'John' }] })
    vi.stubGlobal('$fetch', mockFetch)
    const { search } = useEntity()
    const result = await search('person', 'name', 'jo')
    expect(mockFetch).toHaveBeenCalledWith('/api/people')
    expect(result).toHaveLength(1)
  })
})

describe('useEntity.list', () => {
  it('calls the Claudriel collection endpoint for the requested entity type', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ people: [] })
    vi.stubGlobal('$fetch', mockFetch)
    const { list } = useEntity()
    await list('person')
    expect(mockFetch).toHaveBeenCalledWith('/api/people')
  })

  it('returns normalized resources plus derived pagination meta', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      commitments: [{ uuid: 'commitment-1', title: 'Hello' }],
    })
    vi.stubGlobal('$fetch', mockFetch)
    const { list } = useEntity()
    const result = await list('commitment', { page: { offset: 25, limit: 10 } })
    expect(result.data).toEqual([{ type: 'commitment', id: 'commitment-1', attributes: { uuid: 'commitment-1', title: 'Hello' } }])
    expect(result.meta).toEqual({ total: 1, offset: 25, limit: 10 })
    expect(result.links).toEqual({})
  })
})

describe('useEntity.create', () => {
  it('sends POST to the Claudriel entity endpoint with raw attributes', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ commitment: { uuid: '1', title: 'New' } })
    vi.stubGlobal('$fetch', mockFetch)
    const { create } = useEntity()
    await create('commitment', { title: 'New' })
    expect(mockFetch).toHaveBeenCalledWith('/api/commitments', expect.objectContaining({
      method: 'POST',
      body: { title: 'New' },
    }))
  })
})

describe('useEntity.get', () => {
  it('calls the mapped item endpoint and returns the normalized resource', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ person: { uuid: '7', name: 'alice' } })
    vi.stubGlobal('$fetch', mockFetch)
    const { get } = useEntity()
    const result = await get('person', '7')
    expect(mockFetch).toHaveBeenCalledWith('/api/people/7')
    expect(result).toEqual({ type: 'person', id: '7', attributes: { uuid: '7', name: 'alice' } })
  })
})

describe('useEntity.update', () => {
  it('sends PATCH to the mapped item endpoint', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ workspace: { uuid: '3', name: 'Updated' } })
    vi.stubGlobal('$fetch', mockFetch)
    const { update } = useEntity()
    await update('workspace', '3', { name: 'Updated' })
    expect(mockFetch).toHaveBeenCalledWith('/api/workspaces/3', expect.objectContaining({
      method: 'PATCH',
      body: { name: 'Updated' },
    }))
  })
})

describe('useEntity.remove', () => {
  it('sends DELETE to the mapped item endpoint', async () => {
    const mockFetch = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('$fetch', mockFetch)
    const { remove } = useEntity()
    await remove('triage_entry', '5')
    expect(mockFetch).toHaveBeenCalledWith('/api/triage/5', expect.objectContaining({
      method: 'DELETE',
    }))
  })
})

describe('useEntity unsupported types', () => {
  it('throws when the host adapter has no mapping for the entity type', async () => {
    vi.stubGlobal('$fetch', vi.fn())
    const { list } = useEntity()
    await expect(list('node')).rejects.toThrow('Unsupported admin entity type: node')
  })
})

describe('useEntity error propagation', () => {
  it('propagates $fetch errors to the caller', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockRejectedValue(new Error('Network error')))
    const { list } = useEntity()
    await expect(list('node')).rejects.toThrow('Network error')
  })
})
