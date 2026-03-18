import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import Dashboard from '~/pages/index.vue'
import { entityTypes } from '../fixtures/entityTypes'

const IngestSummaryWidgetStub = defineComponent({
  name: 'IngestSummaryWidget',
  render: () => h('div', { class: 'ingest-stub' }),
})

describe('Dashboard', () => {
  it('renders entity type cards from auth state', () => {
    useState('claudriel.admin.session.entity-types').value = entityTypes

    const wrapper = mount(Dashboard, {
      global: {
        stubs: {
          IngestSummaryWidget: IngestSummaryWidgetStub,
        },
      },
    })

    expect(wrapper.text()).toContain('User')
    expect(wrapper.text()).toContain('Content')
    expect(wrapper.text()).toContain('Dashboard')
  })

  it('renders empty card grid when no entity types exist', () => {
    useState('claudriel.admin.session.entity-types').value = []

    const wrapper = mount(Dashboard, {
      global: {
        stubs: {
          IngestSummaryWidget: IngestSummaryWidgetStub,
        },
      },
    })

    expect(wrapper.text()).toContain('Dashboard')
    expect(wrapper.findAll('.card').length).toBe(0)
  })
})
