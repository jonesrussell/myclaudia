import type { EntityTypeInfo } from '~/composables/useNavGroups'
import type { HostAdapter } from '~/host/hostAdapter'
import type { AdminSessionPayload, EntitySchema, JsonApiResource, SessionBootstrap } from '~/host/types'
import { graphqlFetch } from '~/utils/graphqlFetch'

/** Default label field per entity type (used by search when no override is given). */
const LABEL_FIELDS: Record<string, string> = {
  workspace: 'name',
  person: 'name',
  commitment: 'title',
  schedule_entry: 'title',
  triage_entry: 'sender_name',
}

/** Fields to request per GraphQL entity type. */
const GRAPHQL_FIELDS: Record<string, string> = {
  commitment: 'uuid title status confidence due_date person_uuid source tenant_id created_at updated_at',
  person: 'uuid name email tier source tenant_id latest_summary last_interaction_at last_inbox_category created_at updated_at',
  workspace: 'uuid name description account_id tenant_id metadata repo_path repo_url branch codex_model last_commit_hash ci_status created_at updated_at',
  schedule_entry: 'uuid title starts_at ends_at notes source status external_id calendar_id recurring_series_id tenant_id created_at updated_at',
  triage_entry: 'uuid sender_name sender_email summary status source tenant_id occurred_at external_id content_hash raw_payload created_at updated_at',
}

function toPascalCase(s: string): string {
  return s.replace(/(^|_)(\w)/g, (_, __, c) => c.toUpperCase())
}

function toCamelCase(s: string): string {
  return s.replace(/_(\w)/g, (_, c) => c.toUpperCase())
}

function fieldsFor(type: string): string {
  const fields = GRAPHQL_FIELDS[type]
  if (!fields) {
    throw new Error(`Unsupported admin entity type: ${type}`)
  }

  return fields
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
      const listField = `${toCamelCase(type)}List`
      const fields = fieldsFor(type)
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
    },

    async get(type: string, id: string): Promise<JsonApiResource> {
      const camel = toCamelCase(type)
      const fields = fieldsFor(type)
      const data = await graphqlFetch<Record<string, Record<string, any> | null>>(
        `query($id: ID!) { ${camel}(id: $id) { ${fields} } }`,
        { id },
      )
      const item = data[camel]
      if (!item) throw new Error(`${type} not found: ${id}`)
      return toResource(type, item)
    },

    async create(type: string, attributes: Record<string, any>): Promise<JsonApiResource> {
      const pascal = toPascalCase(type)
      const fields = fieldsFor(type)
      const data = await graphqlFetch<Record<string, Record<string, any>>>(
        `mutation($input: ${pascal}CreateInput!) { create${pascal}(input: $input) { ${fields} } }`,
        { input: attributes },
      )
      const created = data[`create${pascal}`]
      if (!created) throw new Error(`GraphQL: create ${type} returned no data`)
      return toResource(type, created)
    },

    async update(type: string, id: string, attributes: Record<string, any>): Promise<JsonApiResource> {
      const pascal = toPascalCase(type)
      const fields = fieldsFor(type)
      const data = await graphqlFetch<Record<string, Record<string, any>>>(
        `mutation($id: ID!, $input: ${pascal}UpdateInput!) { update${pascal}(id: $id, input: $input) { ${fields} } }`,
        { id, input: attributes },
      )
      const updated = data[`update${pascal}`]
      if (!updated) throw new Error(`GraphQL: update ${type} returned no data`)
      return toResource(type, updated)
    },

    async remove(type: string, id: string): Promise<void> {
      const pascal = toPascalCase(type)
      await graphqlFetch(
        `mutation($id: ID!) { delete${pascal}(id: $id) { deleted } }`,
        { id },
      )
    },

    async search(type: string, labelField: string, query: string, limit: number = 10): Promise<JsonApiResource[]> {
      if (query.length < 2) {
        return []
      }

      const listField = `${toCamelCase(type)}List`
      const fields = fieldsFor(type)
      const effectiveField = labelField || LABEL_FIELDS[type] || 'name'
      const data = await graphqlFetch<Record<string, { items: Record<string, any>[] }>>(
        `query($filter: [FilterInput!], $limit: Int) { ${listField}(filter: $filter, limit: $limit) { items { ${fields} } total } }`,
        { filter: [{ field: effectiveField, value: `%${query}%`, operator: 'LIKE' }], limit },
      )
      const result = data[listField]
      return result ? result.items.map(item => toResource(type, item)) : []
    },

    async schema(type: string): Promise<EntitySchema> {
      const response = await $fetch<{ meta: { schema: EntitySchema } }>(`/api/schema/${type}`)
      return response.meta.schema
    },
  },
}
