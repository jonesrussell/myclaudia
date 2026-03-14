import type { EntityTypeInfo } from '~/composables/useNavGroups'
import type { HostAdapter } from '~/host/hostAdapter'
import type { AdminSessionPayload, EntitySchema, JsonApiResource, SessionBootstrap } from '~/host/types'

interface EntityConfig {
  basePath: string
  collectionKey: string
  itemKey: string
  labelField: string
}

const ENTITY_CONFIG: Record<string, EntityConfig> = {
  workspace: {
    basePath: '/api/workspaces',
    collectionKey: 'workspaces',
    itemKey: 'workspace',
    labelField: 'name',
  },
  person: {
    basePath: '/api/people',
    collectionKey: 'people',
    itemKey: 'person',
    labelField: 'name',
  },
  commitment: {
    basePath: '/api/commitments',
    collectionKey: 'commitments',
    itemKey: 'commitment',
    labelField: 'title',
  },
  schedule_entry: {
    basePath: '/api/schedule',
    collectionKey: 'schedule',
    itemKey: 'schedule',
    labelField: 'title',
  },
  triage_entry: {
    basePath: '/api/triage',
    collectionKey: 'triage',
    itemKey: 'triage',
    labelField: 'sender_name',
  },
}

function configFor(type: string): EntityConfig {
  const config = ENTITY_CONFIG[type]
  if (!config) {
    throw new Error(`Unsupported admin entity type: ${type}`)
  }

  return config
}

function toResource(type: string, item: Record<string, any>): JsonApiResource {
  const id = typeof item.uuid === 'string' && item.uuid !== ''
    ? item.uuid
    : String(item.id ?? '')

  return {
    type,
    id,
    attributes: { ...item },
  }
}

function mapSessionPayload(response: AdminSessionPayload): SessionBootstrap {
  return {
    currentUser: {
      id: response.account.uuid,
      email: response.account.email,
      tenantId: response.account.tenant_id,
      roles: response.account.roles ?? [],
    },
    tenant: response.tenant ?? null,
    entityTypes: Array.isArray(response.entity_types) ? response.entity_types : [],
  }
}

// TODO: Swap this implementation for the packaged Waaseyaa admin host adapter
// once the upstream host contract is released.
export const claudrielHostAdapter: HostAdapter = {
  async fetchSession(): Promise<SessionBootstrap | null> {
    try {
      const response = await $fetch<AdminSessionPayload>('/admin/session')
      return mapSessionPayload(response)
    } catch {
      return null
    }
  },

  loginUrl(path: string = '/admin'): string {
    return `/login?redirect=${encodeURIComponent(path)}`
  },

  async logout(): Promise<void> {
    await $fetch('/admin/logout', { method: 'POST' })
  },

  loadEntityCatalog(session: SessionBootstrap | null, payload?: AdminSessionPayload | null): EntityTypeInfo[] {
    if (session) {
      return session.entityTypes
    }

    return Array.isArray(payload?.entity_types) ? payload.entity_types : []
  },

  transport: {
    async list(
      type: string,
      query: Record<string, any> = {},
    ): Promise<{ data: JsonApiResource[]; meta: Record<string, any>; links: Record<string, string> }> {
      const config = configFor(type)
      const response = await $fetch<Record<string, any>>(config.basePath)
      const items = Array.isArray(response[config.collectionKey]) ? response[config.collectionKey] : []
      const resources = items.map((item) => toResource(type, item))

      return {
        data: resources,
        meta: {
          total: resources.length,
          offset: typeof query.page?.offset === 'number' ? query.page.offset : 0,
          limit: typeof query.page?.limit === 'number' ? query.page.limit : resources.length,
        },
        links: {},
      }
    },

    async get(type: string, id: string): Promise<JsonApiResource> {
      const config = configFor(type)
      const response = await $fetch<Record<string, any>>(`${config.basePath}/${id}`)
      const item = response[config.itemKey]
      if (!item || typeof item !== 'object') {
        throw new Error(`Failed to load ${type} ${id}`)
      }

      return toResource(type, item)
    },

    async create(type: string, attributes: Record<string, any>): Promise<JsonApiResource> {
      const config = configFor(type)
      const response = await $fetch<Record<string, any>>(config.basePath, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: attributes,
      })

      return toResource(type, response[config.itemKey])
    },

    async update(type: string, id: string, attributes: Record<string, any>): Promise<JsonApiResource> {
      const config = configFor(type)
      const response = await $fetch<Record<string, any>>(`${config.basePath}/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: attributes,
      })

      return toResource(type, response[config.itemKey])
    },

    async remove(type: string, id: string): Promise<void> {
      const config = configFor(type)
      await $fetch(`${config.basePath}/${id}`, { method: 'DELETE' })
    },

    async search(type: string, labelField: string, query: string, limit: number = 10): Promise<JsonApiResource[]> {
      if (query.length < 2) {
        return []
      }

      const config = configFor(type)
      const result = await this.list(type)
      const effectiveField = labelField || config.labelField
      const needle = query.toLowerCase()

      return result.data
        .filter((resource) => String(resource.attributes[effectiveField] ?? '').toLowerCase().includes(needle))
        .slice(0, limit)
    },

    async schema(type: string): Promise<EntitySchema> {
      const response = await $fetch<{ meta: { schema: EntitySchema } }>(`/api/schema/${type}`)
      return response.meta.schema
    },
  },
}
