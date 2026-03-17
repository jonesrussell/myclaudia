import type { EntityTypeInfo } from '~/composables/useNavGroups'
import type { HostAdapter } from '~/host/hostAdapter'
import type { AdminSessionPayload, EntitySchema, JsonApiResource, SessionBootstrap } from '~/host/types'
import { graphqlFetch } from '~/utils/graphqlFetch'

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

/** Entity types routed through GraphQL instead of REST. */
const GRAPHQL_TYPES = new Set(['commitment', 'person'])

/** Fields to request per GraphQL entity type. */
const GRAPHQL_FIELDS: Record<string, string> = {
  commitment: 'uuid title status confidence due_date person_uuid source tenant_id created_at updated_at',
  person: 'uuid name email tier source tenant_id latest_summary last_interaction_at last_inbox_category created_at updated_at',
}

function toPascalCase(s: string): string {
  return s.replace(/(^|_)(\w)/g, (_, __, c) => c.toUpperCase())
}

function toCamelCase(s: string): string {
  return s.replace(/_(\w)/g, (_, c) => c.toUpperCase())
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
      if (GRAPHQL_TYPES.has(type)) {
        const listField = `${toCamelCase(type)}List`
        const fields = GRAPHQL_FIELDS[type]
        const limit = typeof query.page?.limit === 'number' ? query.page.limit : 50
        const offset = typeof query.page?.offset === 'number' ? query.page.offset : 0
        const data = await graphqlFetch<Record<string, { items: Record<string, any>[]; total: number }>>(
          `query($limit: Int, $offset: Int) { ${listField}(limit: $limit, offset: $offset) { items { ${fields} } total } }`,
          { limit, offset },
        )
        const result = data[listField]
        if (!result) throw new Error(`GraphQL: no data returned for ${type} list`)
        const resources = result.items.map(item => toResource(type, item))

        return {
          data: resources,
          meta: { total: result.total, offset, limit },
          links: {},
        }
      }

      const config = configFor(type)
      const response = await $fetch<Record<string, any>>(config.basePath)
      const items = Array.isArray(response[config.collectionKey]) ? response[config.collectionKey] : []
      const resources = items.map((item: Record<string, any>) => toResource(type, item))

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
      if (GRAPHQL_TYPES.has(type)) {
        const camel = toCamelCase(type)
        const fields = GRAPHQL_FIELDS[type]
        const data = await graphqlFetch<Record<string, Record<string, any> | null>>(
          `query($id: ID!) { ${camel}(id: $id) { ${fields} } }`,
          { id },
        )
        const item = data[camel]
        if (!item) throw new Error(`${type} not found: ${id}`)
        return toResource(type, item)
      }

      const config = configFor(type)
      const response = await $fetch<Record<string, any>>(`${config.basePath}/${id}`)
      const item = response[config.itemKey]
      if (!item || typeof item !== 'object') {
        throw new Error(`Failed to load ${type} ${id}`)
      }

      return toResource(type, item)
    },

    async create(type: string, attributes: Record<string, any>): Promise<JsonApiResource> {
      if (GRAPHQL_TYPES.has(type)) {
        const pascal = toPascalCase(type)
        const fields = GRAPHQL_FIELDS[type]
        const data = await graphqlFetch<Record<string, Record<string, any>>>(
          `mutation($input: ${pascal}CreateInput!) { create${pascal}(input: $input) { ${fields} } }`,
          { input: attributes },
        )
        const created = data[`create${pascal}`]
        if (!created) throw new Error(`GraphQL: create ${type} returned no data`)
        return toResource(type, created)
      }

      const config = configFor(type)
      const response = await $fetch<Record<string, any>>(config.basePath, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: attributes,
      })

      return toResource(type, response[config.itemKey])
    },

    async update(type: string, id: string, attributes: Record<string, any>): Promise<JsonApiResource> {
      if (GRAPHQL_TYPES.has(type)) {
        const pascal = toPascalCase(type)
        const fields = GRAPHQL_FIELDS[type]
        const data = await graphqlFetch<Record<string, Record<string, any>>>(
          `mutation($id: ID!, $input: ${pascal}UpdateInput!) { update${pascal}(id: $id, input: $input) { ${fields} } }`,
          { id, input: attributes },
        )
        const updated = data[`update${pascal}`]
        if (!updated) throw new Error(`GraphQL: update ${type} returned no data`)
        return toResource(type, updated)
      }

      const config = configFor(type)
      const response = await $fetch<Record<string, any>>(`${config.basePath}/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: attributes,
      })

      return toResource(type, response[config.itemKey])
    },

    async remove(type: string, id: string): Promise<void> {
      if (GRAPHQL_TYPES.has(type)) {
        const pascal = toPascalCase(type)
        await graphqlFetch(
          `mutation($id: ID!) { delete${pascal}(id: $id) { deleted } }`,
          { id },
        )
        return
      }

      const config = configFor(type)
      await $fetch(`${config.basePath}/${id}`, { method: 'DELETE' })
    },

    async search(type: string, labelField: string, query: string, limit: number = 10): Promise<JsonApiResource[]> {
      if (query.length < 2) {
        return []
      }

      if (GRAPHQL_TYPES.has(type)) {
        const listField = `${toCamelCase(type)}List`
        const fields = GRAPHQL_FIELDS[type]
        const config = ENTITY_CONFIG[type]
        const effectiveField = labelField || config?.labelField || 'name'
        const data = await graphqlFetch<Record<string, { items: Record<string, any>[] }>>(
          `query($filter: [FilterInput!], $limit: Int) { ${listField}(filter: $filter, limit: $limit) { items { ${fields} } total } }`,
          { filter: [{ field: effectiveField, value: `%${query}%`, operator: 'LIKE' }], limit },
        )
        const result = data[listField]
        return result ? result.items.map(item => toResource(type, item)) : []
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
