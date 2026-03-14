import type { EntityTypeInfo } from '~/composables/useNavGroups'
import type { AdminSessionPayload, EntitySchema, JsonApiResource, SessionBootstrap } from '~/host/types'

export interface EntityTransport {
  list(
    type: string,
    query?: Record<string, any>,
  ): Promise<{ data: JsonApiResource[]; meta: Record<string, any>; links: Record<string, string> }>
  get(type: string, id: string): Promise<JsonApiResource>
  create(type: string, attributes: Record<string, any>): Promise<JsonApiResource>
  update(type: string, id: string, attributes: Record<string, any>): Promise<JsonApiResource>
  remove(type: string, id: string): Promise<void>
  search(type: string, labelField: string, query: string, limit?: number): Promise<JsonApiResource[]>
  schema(type: string): Promise<EntitySchema>
}

export interface HostAdapter {
  fetchSession(): Promise<SessionBootstrap | null>
  loginUrl(path?: string): string
  logout(): Promise<void>
  loadEntityCatalog(session: SessionBootstrap | null, payload?: AdminSessionPayload | null): Promise<EntityTypeInfo[]> | EntityTypeInfo[]
  transport: EntityTransport
}
