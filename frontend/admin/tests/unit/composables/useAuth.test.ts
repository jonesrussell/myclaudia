import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuth } from '~/composables/useAuth'

function resetAuthState() {
  useState('claudriel.admin.session.user').value = null
  useState('claudriel.admin.session.checked').value = false
  useState('claudriel.admin.session.tenant').value = null
  useState('claudriel.admin.session.entity-types').value = []
}

describe('useAuth', () => {
  beforeEach(() => {
    resetAuthState()
  })

  it('isAuthenticated is false when no user is loaded', () => {
    const { isAuthenticated } = useAuth()
    expect(isAuthenticated.value).toBe(false)
  })

  it('fetchSession populates the current session on success', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      account: { uuid: 'account-1', email: 'alice@example.com', tenant_id: 'tenant-1', roles: ['admin'] },
      tenant: { uuid: 'tenant-1', name: 'Tenant One', default_workspace_uuid: 'workspace-1' },
      entity_types: [{ id: 'workspace', label: 'Workspace', keys: { uuid: 'uuid' }, group: 'structure', disabled: false }],
    })
    vi.stubGlobal('$fetch', mockFetch)

    const { fetchSession, isAuthenticated, currentUser, tenant, entityTypes } = useAuth()
    await fetchSession()

    expect(isAuthenticated.value).toBe(true)
    expect(currentUser.value).toEqual({
      id: 'account-1',
      email: 'alice@example.com',
      tenantId: 'tenant-1',
      roles: ['admin'],
    })
    expect(tenant.value).toEqual({ uuid: 'tenant-1', name: 'Tenant One', default_workspace_uuid: 'workspace-1' })
    expect(entityTypes.value).toEqual([{ id: 'workspace', label: 'Workspace', keys: { uuid: 'uuid' }, group: 'structure', disabled: false }])
    expect(mockFetch).toHaveBeenCalledWith('/admin/session')
  })

  it('fetchSession clears session state when bootstrap fails', async () => {
    const mockFetch = vi.fn().mockRejectedValue(Object.assign(new Error('Unauthorized'), { status: 401 }))
    vi.stubGlobal('$fetch', mockFetch)

    const { fetchSession, isAuthenticated, currentUser, entityTypes } = useAuth()
    await fetchSession()

    expect(isAuthenticated.value).toBe(false)
    expect(currentUser.value).toBeNull()
    expect(entityTypes.value).toEqual([])
  })

  it('loginUrl keeps the current public login handoff behavior', () => {
    const { loginUrl } = useAuth()
    expect(loginUrl('/admin/commitment')).toBe('/login?redirect=%2Fadmin%2Fcommitment')
  })

  it('logout calls /admin/logout and clears client-side session state', async () => {
    const mockFetch = vi.fn()
      .mockResolvedValueOnce({
        account: { uuid: 'account-1', email: 'alice@example.com', tenant_id: 'tenant-1', roles: ['admin'] },
        tenant: { uuid: 'tenant-1', name: 'Tenant One' },
        entity_types: [],
      })
      .mockResolvedValueOnce({ logged_out: true })
    vi.stubGlobal('$fetch', mockFetch)

    const { fetchSession, logout, isAuthenticated, currentUser } = useAuth()
    await fetchSession()
    expect(isAuthenticated.value).toBe(true)

    await logout()
    expect(mockFetch).toHaveBeenLastCalledWith('/admin/logout', { method: 'POST' })
    expect(isAuthenticated.value).toBe(false)
    expect(currentUser.value).toBeNull()
  })

  it('checkAuth calls /admin/session only once until logout resets the checked flag', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      account: { uuid: 'account-3', email: 'dave@example.com', tenant_id: 'tenant-1', roles: [] },
      tenant: null,
      entity_types: [],
    })
    vi.stubGlobal('$fetch', mockFetch)

    const { checkAuth, logout } = useAuth()
    await checkAuth()
    await checkAuth()
    expect(mockFetch).toHaveBeenCalledTimes(1)

    mockFetch.mockResolvedValueOnce({ logged_out: true })
    mockFetch.mockResolvedValueOnce({
      account: { uuid: 'account-3', email: 'dave@example.com', tenant_id: 'tenant-1', roles: [] },
      tenant: null,
      entity_types: [],
    })

    await logout()
    await checkAuth()

    expect(mockFetch).toHaveBeenCalledTimes(3)
    expect(mockFetch).toHaveBeenNthCalledWith(2, '/admin/logout', { method: 'POST' })
    expect(mockFetch).toHaveBeenNthCalledWith(3, '/admin/session')
  })
})
