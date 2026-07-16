import { PageProps } from '@pageflow/core'
import usePage from './usePage'

/**
 * The authenticated identity projection the server shares on every page as the
 * `pageflow_auth` prop (see the PHP PageflowAuthSharer). It contains ONLY
 * non-sensitive fields — never tokens.
 */
export interface PageflowAuth {
  userId: string
  tenantId: string
  username: string
  fullName: string
  email: string
  avatarUrl: string | null
  roles: string[]
  permissions: string[]
  authenticated: boolean
}

const GUEST: PageflowAuth = {
  userId: '',
  tenantId: '',
  username: '',
  fullName: '',
  email: '',
  avatarUrl: null,
  roles: [],
  permissions: [],
  authenticated: false,
}

export interface AuthHelpers extends PageflowAuth {
  /** True if the identity has the given permission. */
  can: (permission: string) => boolean
  /** True if the identity has ANY of the given permissions. */
  canAny: (...permissions: string[]) => boolean
  /** True if the identity has ALL of the given permissions. */
  canAll: (...permissions: string[]) => boolean
  /** True if the identity has the given role. */
  hasRole: (role: string) => boolean
}

/**
 * Read the current identity + permission helpers from shared props.
 *
 * SECURITY: this is for UX only (hiding buttons, routing). It is NOT an
 * authorization boundary — the client can lie. Every mutating action MUST still
 * be authorized server-side in the Service layer. Never gate anything that
 * matters on `can()` alone.
 */
export default function useAuth(): AuthHelpers {
  const page = usePage<PageProps & { pageflow_auth?: PageflowAuth }>()
  const auth = { ...GUEST, ...(page.props.pageflow_auth ?? {}) }

  const permissionSet = new Set(auth.permissions)
  const roleSet = new Set(auth.roles)

  return {
    ...auth,
    can: (permission) => permissionSet.has(permission),
    canAny: (...permissions) => permissions.some((p) => permissionSet.has(p)),
    canAll: (...permissions) => permissions.every((p) => permissionSet.has(p)),
    hasRole: (role) => roleSet.has(role),
  }
}
