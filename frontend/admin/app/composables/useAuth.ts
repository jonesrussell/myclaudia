import type { EntityTypeInfo } from '~/composables/useNavGroups'
import { useHostAdapter } from '~/host/useHostAdapter'
import type { AuthUser, TenantContext } from '~/host/types'

export type { AuthUser } from '~/host/types'

const STATE_KEY = 'claudriel.admin.session.user'
const CHECKED_KEY = 'claudriel.admin.session.checked'
const TENANT_KEY = 'claudriel.admin.session.tenant'
const ENTITY_TYPES_KEY = 'claudriel.admin.session.entity-types'

export function useAuth() {
  const host = useHostAdapter()
  const currentUser = useState<AuthUser | null>(STATE_KEY, () => null)
  const authChecked = useState<boolean>(CHECKED_KEY, () => false)
  const tenant = useState<TenantContext | null>(TENANT_KEY, () => null)
  const entityTypes = useState<EntityTypeInfo[]>(ENTITY_TYPES_KEY, () => [])
  const isAuthenticated = computed(() => currentUser.value !== null)

  async function fetchSession(): Promise<void> {
    const session = await host.fetchSession()
    if (session === null) {
      currentUser.value = null
      tenant.value = null
      entityTypes.value = []
      return
    }

    currentUser.value = session.currentUser
    tenant.value = session.tenant
    entityTypes.value = await host.loadEntityCatalog(session)
  }

  async function checkAuth(): Promise<void> {
    if (authChecked.value) {
      return
    }

    await fetchSession()
    authChecked.value = true
  }

  function loginUrl(path: string = '/admin'): string {
    return host.loginUrl(path)
  }

  async function logout(): Promise<void> {
    await host.logout()
    currentUser.value = null
    authChecked.value = false
    tenant.value = null
    entityTypes.value = []
  }

  return { currentUser, entityTypes, isAuthenticated, tenant, fetchSession, checkAuth, loginUrl, logout }
}
