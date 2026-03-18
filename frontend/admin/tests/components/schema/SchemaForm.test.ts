// packages/admin/tests/components/schema/SchemaForm.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SchemaForm from '~/components/schema/SchemaForm.vue'
import { userSchema } from '../../fixtures/schemas'

// Mock the host adapter so we control transport calls without hitting
// real $fetch or graphqlFetch.
const mockTransport = {
  schema: vi.fn(),
  list: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
  search: vi.fn(),
}

vi.mock('~/host/useHostAdapter', () => ({
  useHostAdapter: () => ({
    transport: mockTransport,
    fetchSession: vi.fn(),
    loginUrl: vi.fn(),
    logout: vi.fn(),
    loadEntityCatalog: vi.fn(),
  }),
}))

// Clear the schema cache between tests by re-importing useSchema internals.
// The schemaCache is a module-level Map inside useSchema.
beforeEach(() => {
  mockTransport.schema.mockReset()
  mockTransport.get.mockReset()
  mockTransport.create.mockReset()
  mockTransport.update.mockReset()
})

describe('SchemaForm loading and error states', () => {
  it('shows loading state while schema is fetching', async () => {
    // Never resolves — component stays in loading state
    mockTransport.schema.mockReturnValue(new Promise(() => {}))
    const wrapper = mount(SchemaForm, {
      props: { entityType: 'user_loading' },
    })
    await flushPromises()
    expect(wrapper.find('.loading').exists()).toBe(true)
  })

  it('shows error state when schema fetch fails', async () => {
    mockTransport.schema.mockRejectedValue({ message: 'Schema not found' })
    const wrapper = mount(SchemaForm, {
      props: { entityType: 'user_error' },
    })
    await flushPromises()
    expect(wrapper.find('.error').exists()).toBe(true)
  })

  it('renders form fields after schema loads', async () => {
    mockTransport.schema.mockResolvedValue(userSchema)
    const wrapper = mount(SchemaForm, {
      props: { entityType: 'user_form' },
    })
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(true)
  })
})

describe('SchemaForm submit — create mode (no entityId)', () => {
  it('emits saved event with resource on successful create', async () => {
    const resource = { type: 'user', id: '5', attributes: { name: 'alice' } }
    mockTransport.schema.mockResolvedValue(userSchema)
    mockTransport.create.mockResolvedValue(resource)

    const wrapper = mount(SchemaForm, {
      props: { entityType: 'user_create' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('saved')?.[0]).toEqual([resource])
  })

  it('initializes boolean fields from schema defaults in create mode', async () => {
    const schemaWithDefaults = {
      ...userSchema,
      'x-entity-type': 'node_defaults',
      properties: {
        ...userSchema.properties,
        status: {
          type: 'boolean',
          'x-widget': 'boolean',
          'x-label': 'Published',
          'x-weight': 10,
          default: true,
        },
        promote: {
          type: 'boolean',
          'x-widget': 'boolean',
          'x-label': 'Promoted',
          'x-weight': 11,
          default: false,
        },
        sticky: {
          type: 'boolean',
          'x-widget': 'boolean',
          'x-label': 'Sticky',
          'x-weight': 12,
        },
      },
    }
    mockTransport.schema.mockResolvedValue(schemaWithDefaults)
    const wrapper = mount(SchemaForm, {
      props: { entityType: 'node_defaults' },
    })
    await flushPromises()

    const checkboxes = wrapper.findAll('input[type="checkbox"]')
    // 3 boolean fields should render as checkboxes
    expect(checkboxes.length).toBe(3)
    // status (default: true) should be checked
    expect((checkboxes[0].element as HTMLInputElement).checked).toBe(true)
    // promote (default: false) should be unchecked
    expect((checkboxes[1].element as HTMLInputElement).checked).toBe(false)
    // sticky (no default, convention: false) should be unchecked
    expect((checkboxes[2].element as HTMLInputElement).checked).toBe(false)
  })

  it('emits error event when create fails', async () => {
    mockTransport.schema.mockResolvedValue(userSchema)
    mockTransport.create.mockRejectedValue({
      data: { errors: [{ detail: 'Validation failed' }] },
    })

    const wrapper = mount(SchemaForm, {
      props: { entityType: 'user_create_err' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('error')?.[0]).toEqual(['Validation failed'])
  })
})

describe('SchemaForm submit — edit mode (with entityId)', () => {
  it('loads existing entity attributes into form', async () => {
    const resource = { type: 'user', id: '3', attributes: { name: 'bob' } }
    mockTransport.schema.mockResolvedValue(userSchema)
    mockTransport.get.mockResolvedValue(resource)

    const wrapper = mount(SchemaForm, {
      props: { entityType: 'user_edit', entityId: '3' },
    })
    await flushPromises()
    // The name field should be pre-populated
    const nameInput = wrapper.find('input[type="text"]')
    expect((nameInput.element as HTMLInputElement).value).toBe('bob')
  })

  it('emits saved event after update when entityId is provided', async () => {
    const existing = { type: 'user', id: '3', attributes: { name: 'bob' } }
    const updated = { type: 'user', id: '3', attributes: { name: 'bob-updated' } }
    mockTransport.schema.mockResolvedValue(userSchema)
    mockTransport.get.mockResolvedValue(existing)
    mockTransport.update.mockResolvedValue(updated)

    const wrapper = mount(SchemaForm, {
      props: { entityType: 'user_edit_patch', entityId: '3' },
    })
    await flushPromises()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    // Verify update was called with the right args
    expect(mockTransport.update).toHaveBeenCalledWith('user_edit_patch', '3', expect.any(Object))
    expect(wrapper.emitted('saved')?.[0]).toEqual([updated])
  })
})
