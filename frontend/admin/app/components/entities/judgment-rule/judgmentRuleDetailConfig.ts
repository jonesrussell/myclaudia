import { registerEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

export const judgmentRuleDetailConfig: EntityDetailConfig = {
  metadata: [
    { key: 'status', label: 'Status', format: 'badge' },
    { key: 'confidence', label: 'Confidence' },
    { key: 'application_count', label: 'Applied' },
    { key: 'last_applied_at', label: 'Last Applied', format: 'date' },
    { key: 'source', label: 'Source' },
  ],
  sidebar: [
    {
      key: 'details',
      label: 'Details',
    },
  ],
}

registerEntityDetailConfig('judgment_rule', judgmentRuleDetailConfig)
