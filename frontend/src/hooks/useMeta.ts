import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { MetaOptions } from '@/types'

/** Domain vocabularies (enum options) for dropdowns + badges. Cached for the session. */
export function useMeta() {
  return useQuery({
    queryKey: ['meta', 'options'],
    queryFn: async () => (await api.get<MetaOptions>('/meta/options')).data,
    staleTime: Infinity,
  })
}
