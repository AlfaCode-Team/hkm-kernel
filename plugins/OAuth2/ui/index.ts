// OAuth2 plugin UI entry — federated into the app frontend by `hkm ui sync`
// (mirrored to frontend/plugins/oauth2, aliased "@oauth2"). The admin surface
// globs plugins/*/admin/Pages/**, so admin/Pages/OAuth2/Admin.tsx resolves as
// the Pageflow component "OAuth2/Admin" (server: AdminUiController@dashboard).

export type OwnerProfile = {
  id: string;
  username?: string;
  email?: string;
  full_name?: string;
  avatar_url?: string | null;
};

export type AdminClientRow = {
  id: string;
  name: string;
  redirect_uris?: string[];
  grant_types?: string[];
  scopes?: string[];
  confidential?: boolean;
  revoked?: boolean;
  owner_id?: string | null;
  owner?: OwnerProfile | null;
};

export const OAUTH_GRANT_TYPES = [
  "authorization_code",
  "refresh_token",
  "client_credentials",
  "password",
  "urn:ietf:params:oauth:grant-type:device_code",
] as const;
