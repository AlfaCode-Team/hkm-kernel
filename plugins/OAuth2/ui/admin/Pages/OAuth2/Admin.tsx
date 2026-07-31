import { useCallback, useEffect, useState } from "react";
import { useAuth, Head, Link } from "@pageflow/react";
import { toast } from "sonner";
import { Toaster } from "@ui/sonner";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@ui/card";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@ui/tabs";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@ui/table";
import { Badge } from "@ui/badge";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { Switch } from "@ui/switch";
import { Avatar, AvatarFallback, AvatarImage } from "@ui/avatar";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@ui/dialog";
import { OAUTH_GRANT_TYPES, type AdminClientRow, type OwnerProfile } from "@oauth2";

// OAuth2 admin dashboard — a PLUGIN-contributed Pageflow page. The admin surface
// globs plugins/*/admin/Pages/**, so this resolves as component "OAuth2/Admin".
// Server: Plugins\OAuth2 AdminUiController@dashboard (session + admin gated).
// Data comes from the /oauth/admin/* JSON API, fetched same-origin (session cookie).

type ScopeEntry = { id: string; description: string };
type Grant = { id: string; client_id: string; user_id: string; scopes: string[]; expires_at: string };
type Result<T> = { ok: true; data: T } | { ok: false };

/* eslint-disable @typescript-eslint/no-explicit-any */
async function api(path: string, opts: RequestInit = {}): Promise<any> {
  const headers: Record<string, string> = { Accept: "application/json" };
  if (opts.body) headers["Content-Type"] = "application/json";
  const res = await fetch(path, { credentials: "same-origin", headers, ...opts });
  const text = await res.text();
  let body: any = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    body = text;
  }
  if (!res.ok && res.status !== 204) {
    const msg = body?.error?.message || body?.error || body?.message || `HTTP ${res.status}`;
    const err: any = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return body;
}
const pick = (body: any, key: string): any[] => body?.data?.[key] ?? body?.[key] ?? [];

// Clipboard that also works in an INSECURE context (plain http://host, where
// navigator.clipboard is undefined) via a legacy execCommand fallback.
function legacyCopy(text: string): boolean {
  try {
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.setAttribute("readonly", "");
    ta.style.position = "fixed";
    ta.style.top = "-1000px";
    ta.style.opacity = "0";
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    const ok = document.execCommand("copy");
    document.body.removeChild(ta);
    return ok;
  } catch {
    return false;
  }
}
function copyToClipboard(text: string): Promise<boolean> {
  if (typeof navigator !== "undefined" && navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text).then(
      () => true,
      () => legacyCopy(text),
    );
  }
  return Promise.resolve(legacyCopy(text));
}
function copyText(text: string) {
  void copyToClipboard(text).then((ok) =>
    ok ? toast.success("Copied", { description: text }) : toast.error("Copy failed"),
  );
}

function useAction() {
  return useCallback(async <T,>(fn: () => Promise<T>, ok?: string): Promise<Result<T>> => {
    try {
      const data = await fn();
      if (ok) toast.success(ok);
      return { ok: true, data };
    } catch (e: any) {
      toast.error(e?.message ?? String(e));
      return { ok: false };
    }
  }, []);
}

export default function OAuth2Admin() {
  const auth = useAuth();
  const [host, setHost] = useState("");
  useEffect(() => setHost(typeof window !== "undefined" ? window.location.host : ""), []);

  return (
    <>
      <Head title="OAuth2 Admin" />
      <Toaster richColors position="top-right" closeButton />
      <main className="mx-auto max-w-6xl space-y-6 p-6 md:p-10">
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-lg text-primary">🛡</div>
            <div>
              <h1 className="text-2xl font-semibold tracking-tight">OAuth2 Admin</h1>
              <p className="mt-1 text-sm text-muted-foreground">
                Tenant-wide administration — all clients, scopes and authorized grants.
              </p>
            </div>
          </div>
          <div className="text-right text-xs text-muted-foreground">
            signed in as <strong className="text-foreground">{auth.fullName || auth.email || auth.userId}</strong>
            <div className="mt-0.5 font-mono">{host}</div>
          </div>
        </header>

        <Tabs defaultValue="clients">
          <TabsList>
            <TabsTrigger value="clients">Clients</TabsTrigger>
            <TabsTrigger value="scopes">Scopes</TabsTrigger>
            <TabsTrigger value="grants">Grants</TabsTrigger>
          </TabsList>
          <TabsContent value="clients" className="mt-4">
            <ClientsCard />
          </TabsContent>
          <TabsContent value="scopes" className="mt-4">
            <ScopesCard />
          </TabsContent>
          <TabsContent value="grants" className="mt-4">
            <GrantsCard />
          </TabsContent>
        </Tabs>
      </main>
    </>
  );
}

function OwnerCell({ owner, fallbackId }: { owner?: OwnerProfile | null; fallbackId?: string | null }) {
  if (!owner && !fallbackId) return <span className="text-muted-foreground">—</span>;
  const name = owner?.full_name || owner?.username || fallbackId || "—";
  const initials = (owner?.full_name || owner?.username || owner?.email || fallbackId || "?").slice(0, 2).toUpperCase();
  return (
    <div className="flex items-center gap-2">
      <Avatar className="h-7 w-7">
        {owner?.avatar_url ? <AvatarImage src={owner.avatar_url} alt="" /> : null}
        <AvatarFallback className="text-[10px]">{initials}</AvatarFallback>
      </Avatar>
      <div className="min-w-0 leading-tight">
        <div className="truncate text-xs font-medium">{name}</div>
        {owner?.email && <div className="truncate text-[11px] text-muted-foreground">{owner.email}</div>}
      </div>
    </div>
  );
}

function ClientsCard() {
  const run = useAction();
  const [clients, setClients] = useState<AdminClientRow[] | null>(null);
  const [loading, setLoading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    const r = await run(() => api("/oauth/admin/clients").then((b) => pick(b, "clients") as AdminClientRow[]));
    if (r.ok) setClients(r.data);
    setLoading(false);
  }, [run]);
  useEffect(() => void load(), [load]);

  const rotate = async (id: string) => {
    const r = await run(() => api(`/oauth/admin/clients/${encodeURIComponent(id)}/rotate`, { method: "POST" }));
    if (r.ok) {
      const secret = (r.data.data ?? r.data).client_secret;
      await copyToClipboard(secret);
      toast.success("Secret rotated — copied", { description: secret });
    }
  };
  const revoke = async (id: string, name: string) => {
    const r = await run(() => api(`/oauth/admin/clients/${encodeURIComponent(id)}`, { method: "DELETE" }), `Revoked “${name}”`);
    if (r.ok) void load();
  };

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between space-y-0">
        <div>
          <CardTitle>Clients</CardTitle>
          <CardDescription>Every OAuth client registered in this tenant.</CardDescription>
        </div>
        <div className="flex items-center gap-2">
          <NewClientDialog onCreated={load} />
          <Button variant="outline" size="sm" onClick={load} disabled={loading}>
            ↻
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>client_id</TableHead>
                <TableHead>Owner</TableHead>
                <TableHead>Type</TableHead>
                <TableHead>Scopes</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {clients?.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                    No clients yet.
                  </TableCell>
                </TableRow>
              )}
              {clients?.map((c) => (
                <TableRow key={c.id}>
                  <TableCell className="font-medium">{c.name}</TableCell>
                  <TableCell className="font-mono text-xs">
                    <button
                      type="button"
                      onClick={() => copyText(c.id)}
                      title="Copy client_id"
                      className="inline-flex max-w-40 items-center gap-1 rounded px-1 py-0.5 hover:bg-muted"
                    >
                      <span className="truncate">{c.id}</span>
                      <span aria-hidden className="shrink-0 text-muted-foreground">
                        ⧉
                      </span>
                    </button>
                  </TableCell>
                  <TableCell>
                    <OwnerCell owner={c.owner} fallbackId={c.owner_id} />
                  </TableCell>
                  <TableCell>
                    <Badge variant={c.confidential ? "default" : "secondary"}>{c.confidential ? "confidential" : "public"}</Badge>
                  </TableCell>
                  <TableCell className="max-w-48 truncate text-xs text-muted-foreground">
                    {(c.scopes ?? []).join(" ") || "—"}
                  </TableCell>
                  <TableCell>
                    {c.revoked ? <Badge variant="destructive">revoked</Badge> : <Badge variant="outline">active</Badge>}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                      {!c.revoked && (c.grant_types ?? []).includes("authorization_code") && (
                        <Button variant="ghost" size="sm" asChild title="Android simulation (real flow)">
                          <Link href={`/oauth/admin/simulate?client=${encodeURIComponent(c.id)}`}>simulate</Link>
                        </Button>
                      )}
                      {c.confidential && (
                        <Button variant="ghost" size="sm" onClick={() => rotate(c.id)}>
                          rotate
                        </Button>
                      )}
                      {!c.revoked && (
                        <Button variant="ghost" size="sm" className="text-destructive" onClick={() => revoke(c.id, c.name)}>
                          revoke
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </CardContent>
    </Card>
  );
}

function NewClientDialog({ onCreated }: { onCreated: () => void }) {
  const run = useAction();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("Admin-created client");
  const [redirect, setRedirect] = useState("");
  const [scopes, setScopes] = useState("");
  const [grants, setGrants] = useState<string[]>(["authorization_code", "refresh_token"]);
  const [isPublic, setIsPublic] = useState(true);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (typeof window !== "undefined") setRedirect(window.location.origin + "/oauth/callback");
  }, []);

  const toggle = (g: string) => setGrants((cur) => (cur.includes(g) ? cur.filter((x) => x !== g) : [...cur, g]));

  const submit = async () => {
    setBusy(true);
    const r = await run(
      () =>
        api("/oauth/admin/clients", {
          method: "POST",
          body: JSON.stringify({
            name: name.trim(),
            redirect_uris: redirect.trim() ? [redirect.trim()] : [],
            scopes: scopes.trim() ? scopes.trim().split(/\s+/) : [],
            grant_types: grants,
            public: isPublic,
          }),
        }),
      `Created “${name.trim()}”`,
    );
    setBusy(false);
    if (r.ok) {
      const c = r.data.data ?? r.data;
      if (c.client_secret) {
        await copyToClipboard(c.client_secret);
        toast.success("client_secret copied (shown once)", { description: c.client_secret });
      }
      setOpen(false);
      onCreated();
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button size="sm">+ New client</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Register OAuth client</DialogTitle>
          <DialogDescription>Created in the current tenant. Confidential secrets are shown once.</DialogDescription>
        </DialogHeader>
        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label htmlFor="c-name">Name</Label>
            <Input id="c-name" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="c-redirect">redirect_uri</Label>
            <Input id="c-redirect" className="font-mono text-xs" value={redirect} onChange={(e) => setRedirect(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="c-scopes">Scopes (space-separated)</Label>
            <Input id="c-scopes" className="font-mono text-xs" value={scopes} onChange={(e) => setScopes(e.target.value)} />
            <p className="text-xs text-muted-foreground">Every scope must already exist in the catalogue.</p>
          </div>
          <div className="space-y-1.5">
            <Label>Grant types</Label>
            <div className="grid grid-cols-2 gap-1.5">
              {OAUTH_GRANT_TYPES.map((g) => (
                <label key={g} className="flex items-center gap-2 font-mono text-xs">
                  <input type="checkbox" checked={grants.includes(g)} onChange={() => toggle(g)} />
                  {g === "urn:ietf:params:oauth:grant-type:device_code" ? "device_code" : g}
                </label>
              ))}
            </div>
          </div>
          <div className="flex items-center justify-between rounded-md border p-3">
            <div>
              <Label htmlFor="c-public">Public client (PKCE)</Label>
              <p className="text-xs text-muted-foreground">Off = confidential (issues a secret).</p>
            </div>
            <Switch id="c-public" checked={isPublic} onCheckedChange={setIsPublic} />
          </div>
        </div>
        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline">Cancel</Button>
          </DialogClose>
          <Button onClick={submit} disabled={busy || !name.trim()}>
            {busy ? "Creating…" : "Create client"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function ScopesCard() {
  const run = useAction();
  const [scopes, setScopes] = useState<ScopeEntry[] | null>(null);
  const [id, setId] = useState("");
  const [desc, setDesc] = useState("");

  const load = useCallback(async () => {
    const r = await run(() => api("/oauth/admin/scopes").then((b) => pick(b, "scopes") as ScopeEntry[]));
    if (r.ok) setScopes(r.data);
  }, [run]);
  useEffect(() => void load(), [load]);

  const add = async () => {
    const r = await run(
      () => api("/oauth/admin/scopes", { method: "POST", body: JSON.stringify({ id: id.trim(), description: desc.trim() }) }),
      `Added “${id.trim()}”`,
    );
    if (r.ok) {
      setId("");
      setDesc("");
      void load();
    }
  };
  const del = async (sid: string) => {
    const r = await run(() => api(`/oauth/admin/scopes/${encodeURIComponent(sid)}`, { method: "DELETE" }), `Deleted “${sid}”`);
    if (r.ok) void load();
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Scope catalogue</CardTitle>
        <CardDescription>Grantable scopes shown on consent and validated at /authorize.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex flex-wrap items-end gap-3 rounded-md border p-3">
          <div className="w-40 space-y-1.5">
            <Label htmlFor="s-id">scope id</Label>
            <Input id="s-id" className="font-mono text-xs" value={id} onChange={(e) => setId(e.target.value)} placeholder="read" />
          </div>
          <div className="flex-1 space-y-1.5">
            <Label htmlFor="s-desc">description</Label>
            <Input id="s-desc" value={desc} onChange={(e) => setDesc(e.target.value)} placeholder="Read your data" />
          </div>
          <Button size="sm" onClick={add} disabled={!id.trim()}>
            + Add
          </Button>
        </div>
        <div className="divide-y rounded-md border">
          {scopes?.length === 0 && <p className="p-4 text-sm text-muted-foreground">No scopes registered.</p>}
          {scopes?.map((s) => (
            <div key={s.id} className="flex items-center justify-between px-4 py-2.5">
              <div className="text-sm">
                <code className="font-mono font-medium">{s.id}</code>
                <span className="text-muted-foreground"> — {s.description || "no description"}</span>
              </div>
              <Button variant="ghost" size="sm" className="text-destructive" onClick={() => del(s.id)}>
                delete
              </Button>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}

function GrantsCard() {
  const run = useAction();
  const [tokens, setTokens] = useState<Grant[] | null>(null);

  const load = useCallback(async () => {
    const r = await run(() => api("/oauth/admin/authorized-tokens").then((b) => pick(b, "authorized_tokens") as Grant[]));
    if (r.ok) setTokens(r.data);
  }, [run]);
  useEffect(() => void load(), [load]);

  const revoke = async (id: string) => {
    const r = await run(() => api(`/oauth/admin/authorized-tokens/${encodeURIComponent(id)}`, { method: "DELETE" }), "Grant revoked");
    if (r.ok) void load();
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Authorized grants</CardTitle>
        <CardDescription>Every active refresh-token grant across all users in the tenant.</CardDescription>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>grant id</TableHead>
                <TableHead>client</TableHead>
                <TableHead>user</TableHead>
                <TableHead>scopes</TableHead>
                <TableHead>expires</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {tokens?.length === 0 && (
                <TableRow>
                  <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                    No active grants.
                  </TableCell>
                </TableRow>
              )}
              {tokens?.map((t) => (
                <TableRow key={t.id}>
                  <TableCell className="max-w-40 truncate font-mono text-xs">{t.id}</TableCell>
                  <TableCell className="font-mono text-xs">{t.client_id}</TableCell>
                  <TableCell className="font-mono text-xs">{t.user_id}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{(t.scopes ?? []).join(" ") || "—"}</TableCell>
                  <TableCell className="text-xs text-muted-foreground">{t.expires_at}</TableCell>
                  <TableCell className="text-right">
                    <Button variant="ghost" size="sm" className="text-destructive" onClick={() => revoke(t.id)}>
                      revoke
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </CardContent>
    </Card>
  );
}
