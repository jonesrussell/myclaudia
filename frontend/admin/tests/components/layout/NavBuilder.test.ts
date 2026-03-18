// packages/admin/tests/components/layout/NavBuilder.test.ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import NavBuilder from '~/components/layout/NavBuilder.vue'
import { entityTypes } from '../../fixtures/entityTypes'

describe('NavBuilder', () => {
  it('renders the dashboard link always', () => {
    const wrapper = mount(NavBuilder)
    expect(wrapper.text()).toContain('Dashboard')
  })

  it('renders nav section headings when entity types are populated', () => {
    useState('claudriel.admin.session.entity-types').value = entityTypes
    const wrapper = mount(NavBuilder)
    const navSections = wrapper.findAll('.nav-section')
    expect(navSections.length).toBeGreaterThan(0)
  })

  it('renders entity type labels as nav links', () => {
    useState('claudriel.admin.session.entity-types').value = entityTypes
    const wrapper = mount(NavBuilder)
    expect(wrapper.text()).toContain('User')
    expect(wrapper.text()).toContain('Content')
  })

  it('renders no nav sections when entity types are empty', () => {
    useState('claudriel.admin.session.entity-types').value = []
    const wrapper = mount(NavBuilder)
    const navSections = wrapper.findAll('.nav-section')
    expect(navSections.length).toBe(0)
  })
})
