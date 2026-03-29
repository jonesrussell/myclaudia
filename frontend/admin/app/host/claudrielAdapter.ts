import type { EntityTypeInfo } from '~/composables/useNavGroups'
import type { HostAdapter } from '~/host/hostAdapter'
import type { AdminSessionPayload, EntitySchema, JsonApiResource, SessionBootstrap } from '~/host/types'
import { claudrielAdminReturnUrl, claudrielPhpLoginUrl } from '~/utils/claudrielAuthUrls'
import { graphqlFetch } from '~/utils/graphqlFetch'

/** Default label field per entity type (used by search when no override is given). */
const LABEL_FIELDS: Record<string, string> = {
  workspace: 'name',
  person: 'name',
  commitment: 'title',
  schedule_entry: 'title',
  triage_entry: 'sender_name',
  project: 'name',
  judgment_rule: 'rule_text',
  repo: 'name',
  milestone: 'name',
  taxonomy_term: 'name',
  taxonomy_vocabulary: 'name',
  prospect: 'name',
  prospect_attachment: 'filename',
  filtered_prospect: 'title',
  pipeline_config: 'name',
  chat_token_usage: 'session_uuid',
}

/** Fields that are TextValue types (text_long) and need { value } wrapping for mutations. */
const TEXT_VALUE_FIELDS: Record<string, string[]> = {
  workspace: ['saved_context'],
  triage_entry: ['raw_payload'],
  prospect: ['description', 'qualify_keywords', 'qualify_raw', 'draft_email_body', 'draft_pdf_markdown', 'draft_pdf_latex'],
  prospect_audit: ['payload'],
  filtered_prospect: ['description'],
  pipeline_config: ['sectors', 'company_profile', 'qualification_prompt_override'],
}

/** Wrap plain strings into TextValueInput format for text_long fields. */
function wrapTextValues(type: string, attrs: Record<string, any>): Record<string, any> {
  const textFields = TEXT_VALUE_FIELDS[type]
  if (!textFields) return attrs
  const out = { ...attrs }
  for (const field of textFields) {
    if (field in out && typeof out[field] === 'string') {
      out[field] = { value: out[field] }
    }
  }
  return out
}

/**
 * Admin surface catalog entity IDs (must match ClaudrielSurfaceHost::buildCatalog).
 * Used by tests to ensure every catalog type has GraphQL field maps.
 */
export const CLAUDRIEL_ADMIN_CATALOG_ENTITY_IDS = [
  'workspace',
  'project',
  'person',
  'commitment',
  'schedule_entry',
  'triage_entry',
  'pipeline_config',
  'prospect',
  'filtered_prospect',
  'prospect_attachment',
  'prospect_audit',
] as const

/** Fields to request per GraphQL entity type. */
const GRAPHQL_FIELDS: Record<string, string> = {
  commitment: 'uuid title status workflow_state confidence direction due_date person_uuid workspace_uuid source tenant_id importance_score access_count last_accessed_at created_at updated_at',
  person: 'uuid name email tier source tenant_id latest_summary last_interaction_at last_inbox_category importance_score access_count last_accessed_at created_at updated_at',
  project: 'uuid name description status account_id tenant_id created_at updated_at',
  workspace: 'uuid name description saved_context { value } anthropic_model account_id tenant_id mode status created_at updated_at',
  repo: 'uuid owner name full_name url default_branch local_path account_id tenant_id created_at updated_at',
  milestone: 'uuid name description status target_date account_id tenant_id created_at updated_at',
  project_repo: 'uuid project_uuid repo_uuid created_at',
  workspace_project: 'uuid workspace_uuid project_uuid created_at',
  workspace_repo: 'uuid workspace_uuid repo_uuid is_active created_at',
  milestone_project: 'uuid milestone_uuid project_uuid created_at',
  schedule_entry: 'uuid title starts_at ends_at notes source status external_id calendar_id recurring_series_id tenant_id created_at updated_at',
  triage_entry: 'uuid sender_name sender_email summary status source tenant_id occurred_at external_id content_hash raw_payload { value } created_at updated_at',
  judgment_rule: 'uuid rule_text context source confidence application_count last_applied_at status tenant_id created_at updated_at',
  taxonomy_term: 'uuid name vid description weight parent_id status created_at updated_at',
  taxonomy_vocabulary: 'uuid name description weight created_at updated_at',
  prospect: 'uuid name description { value } stage value contact_name contact_email source_url closing_date sector qualify_rating qualify_keywords { value } qualify_confidence qualify_notes qualify_raw { value } draft_email_subject draft_email_body { value } draft_pdf_markdown { value } draft_pdf_latex { value } external_id workspace_uuid person_uuid tenant_id deleted_at created_at updated_at',
  prospect_attachment: 'uuid prospect_uuid filename storage_path content_type workspace_uuid tenant_id created_at',
  prospect_audit: 'uuid prospect_uuid action payload { value } confirmed_at tenant_id created_at',
  filtered_prospect: 'uuid external_id title description { value } reject_reason import_batch workspace_uuid tenant_id created_at',
  pipeline_config: 'uuid name workspace_uuid source_type source_url sectors { value } company_profile { value } qualification_prompt_override { value } auto_qualify leads_api_bearer tenant_id created_at updated_at',
  chat_token_usage: 'uuid session_uuid turn_number model input_tokens output_tokens cache_read_tokens cache_write_tokens tenant_id workspace_id created_at',
}

/** @internal Tests: ensure admin catalog IDs have GraphQL field lists. */
export function getGraphqlFieldsMapForTests(): Readonly<Record<string, string>> {
  return GRAPHQL_FIELDS
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

/** Flatten GraphQL TextValue objects ({ value: "..." }) to plain strings. */
function flattenTextValues(item: Record<string, any>): Record<string, any> {
  const out: Record<string, any> = {}
  for (const [key, val] of Object.entries(item)) {
    out[key] = val !== null && typeof val === 'object' && 'value' in val && Object.keys(val).length === 1
      ? val.value
      : val
  }
  return out
}

function toResource(type: string, item: Record<string, any>): JsonApiResource {
  const id = typeof item.uuid === 'string' && item.uuid !== ''
    ? item.uuid
    : String(item.id ?? '')

  return {
    type,
    id,
    attributes: flattenTextValues(item),
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
      // Native fetch — Nuxt $fetch can resolve paths against app.baseURL (/admin/) and break session URLs.
      const response = await fetch('/admin/session', { credentials: 'include' })
      if (!response.ok) {
        return null
      }
      const payload = (await response.json()) as AdminSessionPayload
      return mapSessionPayload(payload)
    } catch {
      return null
    }
  },

  loginUrl(path: string = '/admin'): string {
    if (path.startsWith('http://') || path.startsWith('https://')) {
      return claudrielPhpLoginUrl(path)
    }

    const normalized = path.startsWith('/') ? path : `/${path}`

    return claudrielPhpLoginUrl(claudrielAdminReturnUrl(normalized))
  },

  async logout(): Promise<void> {
    await fetch('/admin/logout', { method: 'POST', credentials: 'include' })
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
      const filter = Array.isArray(query.filter) ? query.filter : undefined
      const varDefs = ['$limit: Int', '$offset: Int']
      const args = ['limit: $limit', 'offset: $offset']
      const variables: Record<string, any> = { limit, offset }
      if (filter) {
        varDefs.push('$filter: [FilterInput!]')
        args.push('filter: $filter')
        variables.filter = filter
      }
      const data = await graphqlFetch<Record<string, { items: Record<string, any>[]; total: number }>>(
        `query(${varDefs.join(', ')}) { ${listField}(${args.join(', ')}) { items { ${fields} } total } }`,
        variables,
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
        { input: wrapTextValues(type, attributes) },
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
        { id, input: wrapTextValues(type, attributes) },
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
      const response = await fetch(`/api/schema/${type}`)
      const json = await response.json() as { meta: { schema: EntitySchema } }
      return json.meta.schema
    },
  },
}
