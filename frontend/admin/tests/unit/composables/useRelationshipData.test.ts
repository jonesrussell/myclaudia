import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useRelationshipData } from '~/composables/useRelationshipData'
import type { RelationshipQuery } from '~/composables/useEntityDetailConfig'

const mockList = vi.fn()

vi.mock('~/composables/useEntity', () => ({
  useEntity: () => ({ list: mockList }),
}))

describe('useRelationshipData', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('fetches count for a junction query', async () => {
    mockList.mockResolvedValue({
      data: [],
      meta: { total: 3 },
    })

    const query: RelationshipQuery = {
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
      resolveType: 'repo',
      resolveField: 'repo_uuid',
    }

    const { count, fetchCount } = useRelationshipData(query, 'test-uuid')
    await fetchCount()

    expect(count.value).toBe(3)
    expect(mockList).toHaveBeenCalledWith('workspace_repo', {
      filter: [{ field: 'workspace_uuid', value: 'test-uuid' }],
      page: { limit: 0, offset: 0 },
    })
  })

  it('fetches and resolves junction items', async () => {
    mockList
      .mockResolvedValueOnce({
        data: [
          { id: 'j1', type: 'workspace_repo', attributes: { uuid: 'j1', workspace_uuid: 'w1', repo_uuid: 'r1' } },
          { id: 'j2', type: 'workspace_repo', attributes: { uuid: 'j2', workspace_uuid: 'w1', repo_uuid: 'r2' } },
        ],
        meta: { total: 2 },
      })
      .mockResolvedValueOnce({
        data: [
          { id: 'r1', type: 'repo', attributes: { uuid: 'r1', name: 'claudriel' } },
          { id: 'r2', type: 'repo', attributes: { uuid: 'r2', name: 'waaseyaa' } },
        ],
        meta: { total: 2 },
      })

    const query: RelationshipQuery = {
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
      resolveType: 'repo',
      resolveField: 'repo_uuid',
    }

    const { items, fetchItems } = useRelationshipData(query, 'w1')
    await fetchItems()

    expect(items.value).toHaveLength(2)
    expect(items.value[0].attributes.name).toBe('claudriel')
    expect(items.value[1].attributes.name).toBe('waaseyaa')
    // Second call should be the batch resolve
    expect(mockList).toHaveBeenCalledTimes(2)
    expect(mockList).toHaveBeenNthCalledWith(2, 'repo', {
      filter: [{ field: 'uuid', value: 'r1,r2', operator: 'IN' }],
      page: { limit: 2, offset: 0 },
    })
  })

  it('handles direct query without junction resolution', async () => {
    mockList.mockResolvedValue({
      data: [
        { id: 'c1', type: 'commitment', attributes: { uuid: 'c1', title: 'Send SOW' } },
      ],
      meta: { total: 1 },
    })

    const query: RelationshipQuery = {
      entityType: 'commitment',
      filterField: 'person_uuid',
    }

    const { items, fetchItems } = useRelationshipData(query, 'p1')
    await fetchItems()

    expect(items.value).toHaveLength(1)
    expect(items.value[0].attributes.title).toBe('Send SOW')
    expect(mockList).toHaveBeenCalledTimes(1)
  })

  it('defaults resolveField to ${resolveType}_uuid', async () => {
    mockList
      .mockResolvedValueOnce({
        data: [
          { id: 'j1', type: 'workspace_repo', attributes: { uuid: 'j1', repo_uuid: 'r1' } },
        ],
        meta: { total: 1 },
      })
      .mockResolvedValueOnce({
        data: [{ id: 'r1', type: 'repo', attributes: { uuid: 'r1', name: 'test' } }],
        meta: { total: 1 },
      })

    const query: RelationshipQuery = {
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
      resolveType: 'repo',
      // no resolveField — should default to 'repo_uuid'
    }

    const { items, fetchItems } = useRelationshipData(query, 'w1')
    await fetchItems()

    expect(items.value).toHaveLength(1)
  })

  it('sets error on fetch failure', async () => {
    mockList.mockRejectedValue(new Error('Network error'))

    const query: RelationshipQuery = {
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
    }

    const { error, fetchCount } = useRelationshipData(query, 'w1')
    await fetchCount()

    expect(error.value).toBe('Network error')
  })

  it('sets loading state during fetch', async () => {
    let resolve: (v: any) => void
    mockList.mockReturnValue(new Promise(r => { resolve = r }))

    const query: RelationshipQuery = {
      entityType: 'commitment',
      filterField: 'person_uuid',
    }

    const { loading, fetchItems } = useRelationshipData(query, 'p1')
    expect(loading.value).toBe(false)

    const promise = fetchItems()
    expect(loading.value).toBe(true)

    resolve!({ data: [], meta: { total: 0 } })
    await promise
    expect(loading.value).toBe(false)
  })

  it('handles empty junction results', async () => {
    mockList.mockResolvedValue({
      data: [],
      meta: { total: 0 },
    })

    const query: RelationshipQuery = {
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
      resolveType: 'repo',
      resolveField: 'repo_uuid',
    }

    const { items, fetchItems } = useRelationshipData(query, 'w1')
    await fetchItems()

    expect(items.value).toHaveLength(0)
    // Should not attempt resolve call
    expect(mockList).toHaveBeenCalledTimes(1)
  })
})
