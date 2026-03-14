import { useHostAdapter } from '~/host/useHostAdapter'

export type { JsonApiDocument, JsonApiResource } from '~/host/types'

export function useEntity() {
  const host = useHostAdapter()

  async function list(
    type: string,
    query: Record<string, any> = {},
  ) {
    return host.transport.list(type, query)
  }

  async function get(type: string, id: string) {
    return host.transport.get(type, id)
  }

  async function create(type: string, attributes: Record<string, any>) {
    return host.transport.create(type, attributes)
  }

  async function update(type: string, id: string, attributes: Record<string, any>) {
    return host.transport.update(type, id, attributes)
  }

  async function remove(type: string, id: string): Promise<void> {
    await host.transport.remove(type, id)
  }

  async function search(type: string, labelField: string, query: string, limit: number = 10) {
    return host.transport.search(type, labelField, query, limit)
  }

  return { list, get, create, update, remove, search }
}
