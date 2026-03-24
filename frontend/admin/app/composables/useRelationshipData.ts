import { ref } from 'vue'
import { useEntity, type JsonApiResource } from '~/composables/useEntity'
import type { RelationshipQuery } from '~/composables/useEntityDetailConfig'

export function useRelationshipData(query: RelationshipQuery, parentId: string) {
  const count = ref<number | null>(null)
  const items = ref<JsonApiResource[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)

  const { list } = useEntity()

  async function fetchCount() {
    try {
      error.value = null
      const result = await list(query.entityType, {
        filter: [{ field: query.filterField, value: parentId }],
        page: { limit: 0, offset: 0 },
      })
      count.value = result.meta.total ?? 0
    } catch (e: any) {
      error.value = e.message ?? 'Failed to fetch count'
      count.value = null
    }
  }

  async function fetchItems() {
    try {
      loading.value = true
      error.value = null

      const result = await list(query.entityType, {
        filter: [{ field: query.filterField, value: parentId }],
      })

      if (query.resolveType) {
        const resolveField = query.resolveField ?? `${query.resolveType}_uuid`
        const targetUuids = result.data
          .map((item: JsonApiResource) => item.attributes[resolveField])
          .filter((uuid: unknown): uuid is string => typeof uuid === 'string' && uuid !== '')

        if (targetUuids.length > 0) {
          const resolved = await list(query.resolveType, {
            filter: [{ field: 'uuid', value: targetUuids.join(','), operator: 'IN' }],
            page: { limit: targetUuids.length, offset: 0 },
          })
          items.value = resolved.data
        } else {
          items.value = []
        }
      } else {
        items.value = result.data
      }

      count.value = items.value.length
    } catch (e: any) {
      error.value = e.message ?? 'Failed to fetch items'
      items.value = []
    } finally {
      loading.value = false
    }
  }

  return { count, items, loading, error, fetchCount, fetchItems }
}
