import type { EntityTypeInfo } from '~/composables/useNavGroups'

export interface AuthUser {
  id: string
  email: string
  tenantId: string
  roles: string[]
}

export interface TenantContext {
  uuid: string
  name: string
  default_workspace_uuid?: string | null
}

export interface AdminSessionPayload {
  account: {
    uuid: string
    email: string
    tenant_id: string
    roles: string[]
  }
  tenant: TenantContext | null
  entity_types: EntityTypeInfo[]
}

export interface SessionBootstrap {
  currentUser: AuthUser
  tenant: TenantContext | null
  entityTypes: EntityTypeInfo[]
}

export interface JsonApiResource {
  type: string
  id: string
  attributes: Record<string, any>
  relationships?: Record<string, any>
  links?: Record<string, string>
  meta?: Record<string, any>
}

export interface JsonApiDocument {
  jsonapi: { version: string }
  data: JsonApiResource | JsonApiResource[] | null
  errors?: Array<{ status: string; title: string; detail?: string }>
  meta?: Record<string, any>
  links?: Record<string, string>
}

export interface SchemaProperty {
  type: string
  description?: string
  format?: string
  readOnly?: boolean
  enum?: string[]
  minimum?: number
  maximum?: number
  maxLength?: number
  'x-widget'?: string
  'x-label'?: string
  'x-description'?: string
  'x-weight'?: number
  'x-required'?: boolean
  'x-enum-labels'?: Record<string, string>
  'x-target-type'?: string
  'x-access-restricted'?: boolean
  'x-source-field'?: string
  'x-list-display'?: boolean
  default?: string | number | boolean
}

export interface EntitySchema {
  $schema: string
  title: string
  description: string
  type: string
  'x-entity-type': string
  'x-translatable': boolean
  'x-revisionable': boolean
  properties: Record<string, SchemaProperty>
  required?: string[]
}
