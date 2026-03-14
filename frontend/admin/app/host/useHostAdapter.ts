import type { HostAdapter } from '~/host/hostAdapter'
import { claudrielHostAdapter } from '~/host/claudrielAdapter'

export function useHostAdapter(): HostAdapter {
  return claudrielHostAdapter
}
