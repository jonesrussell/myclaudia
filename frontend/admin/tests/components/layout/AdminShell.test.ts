import { describe, it, expect, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AdminShell from '~/components/layout/AdminShell.vue'
import { useLanguage } from '~/composables/useLanguage'

describe('AdminShell locale switcher', () => {
  beforeEach(() => {
    const { setLocale } = useLanguage()
    setLocale('en')
  })

  it('switches translated UI labels when locale changes', async () => {
    const wrapper = mount(AdminShell, {
      slots: {
        default: '<div>Body</div>',
      },
    })

    const select = wrapper.find('select.topbar-locale-select')
    expect(select.exists()).toBe(true)
    expect(select.attributes('aria-label')).toBe('Language')
    expect(wrapper.find('button.topbar-toggle').attributes('aria-label')).toBe('Toggle menu')

    await select.setValue('fr')

    expect(select.attributes('aria-label')).toBe('Langue')
    expect(wrapper.find('button.topbar-toggle').attributes('aria-label')).toBe('Basculer le menu')
  })
})
