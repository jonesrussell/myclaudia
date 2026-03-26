/**
 * Split dev: Nuxt on :3000, PHP on :8081. Session + GraphQL live on PHP; Nitro proxies those paths.
 * Production: same host — login stays a relative `/login?redirect=…` path-only redirect.
 */

export function claudrielPhpOrigin(): string {
  const env = import.meta.env.NUXT_PUBLIC_PHP_ORIGIN
  if (typeof env === 'string' && env.trim() !== '') {
    return env.replace(/\/$/, '')
  }
  if (import.meta.dev) {
    return 'http://127.0.0.1:8081'
  }

  return ''
}

/**
 * After login, PHP redirects here. In dev with split servers, this must be an absolute admin URL
 * on the Nuxt origin so the browser returns to the SPA; path-only redirects stay on PHP.
 */
export function claudrielAdminReturnUrl(internalPath: string): string {
  const path = internalPath.startsWith('/') ? internalPath : `/${internalPath}`
  if (import.meta.dev && typeof window !== 'undefined') {
    return `${window.location.origin}${path}`
  }

  return path
}

export function claudrielPhpLoginUrl(redirectAfterLogin: string): string {
  const php = claudrielPhpOrigin()
  const qs = `/login?redirect=${encodeURIComponent(redirectAfterLogin)}`
  return php !== '' ? `${php}${qs}` : qs
}
