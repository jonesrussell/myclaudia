import { describe, expect, it, vi } from 'vitest'
import { claudrielHostAdapter } from '~/host/claudrielAdapter'

describe('claudrielHostAdapter', () => {
  it('maps /admin/session into the generic session bootstrap shape', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({
      account: {
        uuid: 'account-1',
        email: 'owner@example.com',
        tenant_id: 'tenant-1',
        roles: ['tenant_owner'],
      },
      tenant: {
        uuid: 'tenant-1',
        name: 'Tenant One',
        default_workspace_uuid: 'workspace-1',
      },
      entity_types: [
        { id: 'workspace', label: 'Workspace', keys: { uuid: 'uuid' }, group: 'structure', disabled: false },
      ],
    }))

    const session = await claudrielHostAdapter.fetchSession()

    expect(session).toEqual({
      currentUser: {
        id: 'account-1',
        email: 'owner@example.com',
        tenantId: 'tenant-1',
        roles: ['tenant_owner'],
      },
      tenant: {
        uuid: 'tenant-1',
        name: 'Tenant One',
        default_workspace_uuid: 'workspace-1',
      },
      entityTypes: [
        { id: 'workspace', label: 'Workspace', keys: { uuid: 'uuid' }, group: 'structure', disabled: false },
      ],
    })
  })

  it('keeps Claudriel-specific entity transport mapping out of generic composables', async () => {
    vi.stubGlobal('$fetch', vi.fn().mockResolvedValue({
      commitments: [
        { uuid: 'commitment-1', title: 'Ship host adapter docs' },
      ],
    }))

    const result = await claudrielHostAdapter.transport.list('commitment')

    expect(result.data).toEqual([
      {
        type: 'commitment',
        id: 'commitment-1',
        attributes: { uuid: 'commitment-1', title: 'Ship host adapter docs' },
      },
    ])
    expect(result.meta.total).toBe(1)
  })
})
