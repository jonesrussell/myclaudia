import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ActivityTimeline from '~/components/entity-detail/ActivityTimeline.vue'

describe('ActivityTimeline', () => {
  const events = [
    { type: 'event' as const, label: 'Repo claudriel linked', timestamp: '2026-03-23T15:00:00Z' },
    { type: 'triage' as const, label: 'Email from Sarah Chen', timestamp: '2026-03-23T09:00:00Z' },
    { type: 'schedule' as const, label: 'Sprint review', timestamp: '2026-03-22T14:00:00Z' },
    { type: 'commitment' as const, label: 'Send revised SOW', timestamp: '2026-03-21T10:00:00Z' },
  ]

  it('renders all events', () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    expect(wrapper.text()).toContain('Repo claudriel linked')
    expect(wrapper.text()).toContain('Email from Sarah Chen')
    expect(wrapper.text()).toContain('Sprint review')
    expect(wrapper.text()).toContain('Send revised SOW')
  })

  it('renders filter chips', () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    expect(wrapper.text()).toContain('All')
    expect(wrapper.text()).toContain('Events')
    expect(wrapper.text()).toContain('Triage')
    expect(wrapper.text()).toContain('Schedule')
    expect(wrapper.text()).toContain('Commitments')
  })

  it('filters events when chip is clicked', async () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    await wrapper.find('[data-filter="triage"]').trigger('click')
    const timeline = wrapper.find('.timeline')
    expect(timeline.text()).toContain('Email from Sarah Chen')
    expect(timeline.text()).not.toContain('Sprint review')
    expect(timeline.text()).not.toContain('Repo claudriel linked')
  })

  it('shows all events when All chip clicked after filtering', async () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    await wrapper.find('[data-filter="triage"]').trigger('click')
    await wrapper.find('[data-filter="all"]').trigger('click')
    expect(wrapper.text()).toContain('Repo claudriel linked')
    expect(wrapper.text()).toContain('Email from Sarah Chen')
  })

  it('renders empty state when no events', () => {
    const wrapper = mount(ActivityTimeline, { props: { events: [] } })
    expect(wrapper.text()).toContain('No activity')
  })

  it('renders type labels with correct text', () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    expect(wrapper.text()).toContain('EVENT')
    expect(wrapper.text()).toContain('TRIAGE')
    expect(wrapper.text()).toContain('SCHEDULE')
    expect(wrapper.text()).toContain('COMMITMENT')
  })

  it('sorts events newest first', () => {
    const wrapper = mount(ActivityTimeline, { props: { events } })
    const items = wrapper.findAll('.timeline-item')
    expect(items[0].text()).toContain('Repo claudriel linked')
    expect(items[items.length - 1].text()).toContain('Send revised SOW')
  })
})
