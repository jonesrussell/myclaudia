import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import EntityDetailLayout from '~/components/entity-detail/EntityDetailLayout.vue'
import type { EntityDetailConfig } from '~/composables/useEntityDetailConfig'

const mockFetchCount = vi.fn()
const mockCount = ref<number | null>(2)

vi.mock('~/composables/useRelationshipData', () => ({
  useRelationshipData: () => ({
    count: mockCount,
    items: ref([]),
    loading: ref(false),
    error: ref(null),
    fetchCount: mockFetchCount,
    fetchItems: vi.fn(),
  }),
}))

const stubs = {
  NuxtLink: { template: '<a :href="to"><slot /></a>', props: ['to'] },
  SchemaForm: { template: '<div data-testid="schema-form">SchemaForm</div>', props: ['entityType', 'entityId'] },
  RelationshipPanel: { template: '<div data-testid="relationship-panel">RelationshipPanel</div>', props: ['query', 'parentId', 'entityType'] },
  ActivityTimeline: { template: '<div data-testid="activity-timeline">ActivityTimeline</div>', props: ['events'] },
}

describe('EntityDetailLayout', () => {
  const config: EntityDetailConfig = {
    metadata: [
      { key: 'status', label: 'Status', format: 'badge' },
    ],
    sidebar: [
      {
        key: 'repos',
        label: 'Repos',
        query: { entityType: 'workspace_repo', filterField: 'workspace_uuid', resolveType: 'repo', resolveField: 'repo_uuid' },
      },
      {
        key: 'projects',
        label: 'Projects',
        query: { entityType: 'workspace_project', filterField: 'workspace_uuid', resolveType: 'project', resolveField: 'project_uuid' },
      },
      { key: 'activity', label: 'Activity' },
      { key: 'details', label: 'Details' },
    ],
    actions: [{ label: 'Link Repo', type: 'link', targetType: 'repo' }],
  }

  const entity = { uuid: 'w1', name: 'smoke-test', status: 'active' }

  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders entity name in header', () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('smoke-test')
  })

  it('renders status badge', () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
      global: { stubs },
    })
    expect(wrapper.find('.status-badge').text()).toBe('active')
  })

  it('renders action buttons', () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('Link Repo')
  })

  it('renders sidebar with all sections', () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('Repos')
    expect(wrapper.text()).toContain('Projects')
    expect(wrapper.text()).toContain('Activity')
    expect(wrapper.text()).toContain('Details')
  })

  it('selects first section by default', () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
      global: { stubs },
    })
    const active = wrapper.find('.sidebar-section.active')
    expect(active.text()).toContain('Repos')
  })

  it('switches main content when sidebar section clicked', async () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
      global: { stubs },
    })

    // Default: RelationshipPanel for repos
    expect(wrapper.find('[data-testid="relationship-panel"]').exists()).toBe(true)

    // Click activity
    await wrapper.find('[data-section="activity"]').trigger('click')
    expect(wrapper.find('[data-testid="activity-timeline"]').exists()).toBe(true)

    // Click details
    await wrapper.find('[data-section="details"]').trigger('click')
    expect(wrapper.find('[data-testid="schema-form"]').exists()).toBe(true)
  })

  it('renders back to list link', () => {
    const wrapper = mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
      global: { stubs },
    })
    expect(wrapper.text()).toContain('Back to list')
  })

  it('fetches counts on mount', async () => {
    mount(EntityDetailLayout, {
      props: { config, entity, entityType: 'workspace' },
      global: { stubs },
    })
    await flushPromises()
    // Two sections have queries (repos, projects)
    expect(mockFetchCount).toHaveBeenCalledTimes(2)
  })
})
