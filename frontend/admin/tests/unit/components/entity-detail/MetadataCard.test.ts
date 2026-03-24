import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MetadataCard from '~/components/entity-detail/MetadataCard.vue'
import type { MetadataField } from '~/composables/useEntityDetailConfig'

describe('MetadataCard', () => {
  const fields: MetadataField[] = [
    { key: 'status', label: 'Status', format: 'badge' },
    { key: 'mode', label: 'Mode' },
    { key: 'tenant_id', label: 'Tenant', truncate: true },
    { key: 'created_at', label: 'Created', format: 'date' },
  ]

  const entity = {
    status: 'active',
    mode: 'persistent',
    tenant_id: '3d1984c2-4aa0-4af3-97bb-a9e6db4e8ce0',
    created_at: '2026-03-23T12:00:00Z',
  }

  it('renders all metadata fields', () => {
    const wrapper = mount(MetadataCard, { props: { fields, entity } })
    expect(wrapper.text()).toContain('Status')
    expect(wrapper.text()).toContain('active')
    expect(wrapper.text()).toContain('Mode')
    expect(wrapper.text()).toContain('persistent')
  })

  it('truncates long values when truncate is true', () => {
    const wrapper = mount(MetadataCard, { props: { fields, entity } })
    expect(wrapper.text()).not.toContain('3d1984c2-4aa0-4af3-97bb-a9e6db4e8ce0')
    expect(wrapper.text()).toContain('3d19')
  })

  it('applies badge class for badge format', () => {
    const wrapper = mount(MetadataCard, { props: { fields, entity } })
    const badge = wrapper.find('.metadata-badge')
    expect(badge.exists()).toBe(true)
    expect(badge.text()).toBe('active')
  })

  it('renders em dash for missing values', () => {
    const wrapper = mount(MetadataCard, {
      props: { fields, entity: { status: 'active' } },
    })
    expect(wrapper.text()).toContain('\u2014')
  })

  it('sets title attribute to full value', () => {
    const wrapper = mount(MetadataCard, { props: { fields, entity } })
    const tenantValue = wrapper.findAll('.metadata-value')[2]
    expect(tenantValue.attributes('title')).toBe('3d1984c2-4aa0-4af3-97bb-a9e6db4e8ce0')
  })
})
