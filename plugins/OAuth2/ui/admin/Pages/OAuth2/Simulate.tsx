import { useCallback, useEffect, useMemo, useState } from "react";
import { Head, Link } from "@pageflow/react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@ui/card";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { Badge } from "@ui/badge";
import type { AdminClientRow } from "@oauth2";

// Per-client OAuth simulation — a PLUGIN Pageflow page ("OAuth2/Simulate", server:
// AdminUiController@simulate). Two things in one page:
//   • a PKCE demo (offline: verifier → challenge → server-verify), and
//   • the REAL Authorization Code + PKCE flow — this same page is the redirect
//     target (redirect_uri = {origin}/oauth/admin/simulate), so it launches
//     /oauth/authorize and, on return, exchanges the code for real tokens.
// Session-authenticated, same-origin. crypto.subtle is unavailable over plain
// http, so SHA-256 is pure-JS below.

/* eslint-disable @typescript-eslint/no-explicit-any */
function b64url(bytes: Uint8Array): string {
  let s = "";
  for (const x of bytes) s += String.fromCharCode(x);
  return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}
function sha256(ascii: string): Uint8Array {
  const rr = (x: number, n: number) => (x >>> n) | (x << (32 - n));
  const K = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
  ];
  const bytes: number[] = [];
  for (let i = 0; i < ascii.length; i++) {
    const c = ascii.charCodeAt(i);
    if (c < 0x80) bytes.push(c);
    else if (c < 0x800) bytes.push(0xc0 | (c >> 6), 0x80 | (c & 0x3f));
    else bytes.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 0x3f), 0x80 | (c & 0x3f));
  }
  const bitLen = bytes.length * 8;
  bytes.push(0x80);
  while (bytes.length % 64 !== 56) bytes.push(0);
  bytes.push(0, 0, 0, 0, (bitLen >>> 24) & 0xff, (bitLen >>> 16) & 0xff, (bitLen >>> 8) & 0xff, bitLen & 0xff);
  let h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
  let h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
  const w = new Array<number>(64);
  for (let i = 0; i < bytes.length; i += 64) {
    for (let t = 0; t < 16; t++)
      w[t] = (bytes[i + t * 4] << 24) | (bytes[i + t * 4 + 1] << 16) | (bytes[i + t * 4 + 2] << 8) | bytes[i + t * 4 + 3];
    for (let t = 16; t < 64; t++) {
      const s0 = rr(w[t - 15], 7) ^ rr(w[t - 15], 18) ^ (w[t - 15] >>> 3);
      const s1 = rr(w[t - 2], 17) ^ rr(w[t - 2], 19) ^ (w[t - 2] >>> 10);
      w[t] = (w[t - 16] + s0 + w[t - 7] + s1) | 0;
    }
    let a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
    for (let t = 0; t < 64; t++) {
      const S1 = rr(e, 6) ^ rr(e, 11) ^ rr(e, 25);
      const ch = (e & f) ^ (~e & g);
      const t1 = (h + S1 + ch + K[t] + w[t]) | 0;
      const S0 = rr(a, 2) ^ rr(a, 13) ^ rr(a, 22);
      const maj = (a & b) ^ (a & c) ^ (b & c);
      const t2 = (S0 + maj) | 0;
      h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
    }
    h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
    h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
  }
  const hs = [h0, h1, h2, h3, h4, h5, h6, h7];
  const out = new Uint8Array(32);
  for (let i = 0; i < 8; i++) {
    out[i * 4] = (hs[i] >>> 24) & 0xff;
    out[i * 4 + 1] = (hs[i] >>> 16) & 0xff;
    out[i * 4 + 2] = (hs[i] >>> 8) & 0xff;
    out[i * 4 + 3] = hs[i] & 0xff;
  }
  return out;
}
const s256 = (v: string) => b64url(sha256(v));
function genVerifier(len = 64): string {
  const a = new Uint8Array(len);
  crypto.getRandomValues(a);
  const A = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~";
  let s = "";
  for (const x of a) s += A[x % A.length];
  return s;
}
function copy(text: string) {
  const noop = () => void 0;
  if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(noop, noop);
  else {
    try {
      const t = document.createElement("textarea");
      t.value = text;
      t.style.position = "fixed";
      t.style.opacity = "0";
      document.body.appendChild(t);
      t.select();
      document.execCommand("copy");
      document.body.removeChild(t);
    } catch {
      /* ignore */
    }
  }
}
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
    const e: any = new Error(msg);
    e.status = res.status;
    throw e;
  }
  return body;
}
const pick = (b: any, k: string): any[] => b?.data?.[k] ?? b?.[k] ?? [];

type SimState = { verifier: string; clientId: string; redirect: string };
const SKEY = "oauth2.sim.byState";
function saveState(state: string, d: SimState) {
  try {
    const m = JSON.parse(sessionStorage.getItem(SKEY) || "{}");
    m[state] = d;
    sessionStorage.setItem(SKEY, JSON.stringify(m));
  } catch {
    /* ignore */
  }
}
function loadState(state: string): SimState | undefined {
  try {
    return (JSON.parse(sessionStorage.getItem(SKEY) || "{}") as Record<string, SimState>)[state];
  } catch {
    return undefined;
  }
}

export default function OAuth2Simulate() {
  const [ready, setReady] = useState(false);
  const [q, setQ] = useState({ client: "", code: "", state: "", error: "" });
  useEffect(() => {
    const p = new URLSearchParams(window.location.search);
    setQ({ client: p.get("client") || "", code: p.get("code") || "", state: p.get("state") || "", error: p.get("error") || "" });
    setReady(true);
  }, []);

  return (
    <>
      <Head title="OAuth Simulation" />
      <main className="min-h-screen bg-background px-4 py-10">
        <div className="mx-auto max-w-3xl space-y-6">
          <div className="flex items-center justify-between">
            <Button asChild variant="ghost" size="sm">
              <Link href="/oauth/admin">← Admin</Link>
            </Button>
            <div className="text-xs text-muted-foreground">Authorization Code + PKCE (RFC 7636 / 6749)</div>
          </div>
          {!ready ? null : q.code || q.error ? <CallbackView code={q.code} state={q.state} error={q.error} /> : <Simulator clientId={q.client} />}
        </div>
      </main>
    </>
  );
}

// ── real callback: exchange the code for tokens ──────────────────────────────
function CallbackView({ code, state, error }: { code: string; state: string; error: string }) {
  const [ok, setOk] = useState<boolean | null>(null);
  const [out, setOut] = useState("Exchanging authorization code…");
  const [clientId, setClientId] = useState("");

  useEffect(() => {
    if (error) {
      setOk(false);
      setOut(`Authorization error: ${error}`);
      return;
    }
    const data = loadState(state);
    if (!data) {
      setOk(false);
      setOut("No PKCE verifier stored for this state — launch the flow again from the simulator.");
      return;
    }
    setClientId(data.clientId);
    const form = new URLSearchParams({
      grant_type: "authorization_code",
      client_id: data.clientId,
      redirect_uri: data.redirect,
      code,
      code_verifier: data.verifier,
    });
    fetch("/oauth/token", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
      body: form.toString(),
    })
      .then((r) => r.json())
      .then((b) => {
        setOk(Boolean(b?.access_token));
        setOut(JSON.stringify(b, null, 2));
      })
      .catch((e) => {
        setOk(false);
        setOut(String(e));
      });
  }, [code, state, error]);

  return (
    <Card className={ok === false ? "border-destructive/40" : ok ? "border-emerald-500/40" : ""}>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          Token exchange {ok === true && <Badge className="bg-emerald-600">success</Badge>}
          {ok === false && <Badge variant="destructive">failed</Badge>}
        </CardTitle>
        <CardDescription>
          Posted <code className="font-mono">code + code_verifier</code> to <code className="font-mono">/oauth/token</code>.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <pre className="max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-md border bg-muted p-3 font-mono text-[11px]">
          {out}
        </pre>
        <Button asChild variant="outline" size="sm">
          <Link href={`/oauth/admin/simulate?client=${encodeURIComponent(clientId)}`}>Run again</Link>
        </Button>
      </CardContent>
    </Card>
  );
}

// ── PKCE demo + real-flow launcher ───────────────────────────────────────────
function Simulator({ clientId }: { clientId: string }) {
  const [method, setMethod] = useState<"S256" | "plain">("S256");
  const [verifier, setVerifier] = useState("");
  const [challenge, setChallenge] = useState("");
  const [state, setState] = useState("");
  const [stored, setStored] = useState<string | null>(null);

  const [client, setClient] = useState<AdminClientRow | null | "loading" | "missing">(clientId ? "loading" : "missing");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");

  const origin = typeof window !== "undefined" ? window.location.origin : "";
  const simRedirect = `${origin}/oauth/admin/simulate`;

  useEffect(() => {
    setVerifier(genVerifier(64));
    setState(genVerifier(24));
  }, []);
  useEffect(() => {
    setChallenge(!verifier ? "" : method === "plain" ? verifier : s256(verifier));
  }, [verifier, method]);

  const loadClient = useCallback(() => {
    if (!clientId) return;
    api("/oauth/admin/clients")
      .then((b) => setClient((pick(b, "clients") as AdminClientRow[]).find((c) => c.id === clientId) ?? "missing"))
      .catch((e) => {
        setErr(e?.message ?? String(e));
        setClient("missing");
      });
  }, [clientId]);
  useEffect(loadClient, [loadClient]);

  const obj = client && client !== "loading" && client !== "missing" ? client : null;
  const registered = (obj?.redirect_uris ?? []).includes(simRedirect);
  const scope = (obj?.scopes ?? []).join(" ");

  const play = () => {
    const v = genVerifier(64);
    setVerifier(v);
    setState(genVerifier(24));
    const ch = method === "plain" ? v : s256(v);
    setChallenge(ch);
    setStored(ch);
  };

  const enableRedirect = async () => {
    if (!obj) return;
    setBusy(true);
    setErr("");
    try {
      await api(`/oauth/admin/clients/${encodeURIComponent(obj.id)}`, {
        method: "PUT",
        body: JSON.stringify({ name: obj.name, redirect_uris: [...(obj.redirect_uris ?? []), simRedirect], scopes: obj.scopes ?? [] }),
      });
      loadClient();
    } catch (e: any) {
      setErr(e?.message ?? String(e));
    } finally {
      setBusy(false);
    }
  };

  const launch = () => {
    if (!obj) return;
    saveState(state, { verifier, clientId: obj.id, redirect: simRedirect });
    const qs = new URLSearchParams({
      response_type: "code",
      client_id: obj.id,
      redirect_uri: simRedirect,
      state,
      code_challenge: challenge,
      code_challenge_method: method,
    });
    if (scope) qs.set("scope", scope);
    window.location.assign(`/oauth/authorize?${qs.toString()}`);
  };

  const matches = stored === null ? null : challenge !== "" && challenge === stored;
  const len = verifier.length;
  const valid = len >= 43 && len <= 128 && /^[A-Za-z0-9\-._~]*$/.test(verifier);

  const authorizeUrl = useMemo(() => {
    const qs = new URLSearchParams({
      response_type: "code",
      client_id: clientId || "<client_id>",
      redirect_uri: obj ? simRedirect : "<registered redirect_uri>",
      code_challenge: challenge || "…",
      code_challenge_method: method,
      state: state || "…",
    });
    if (scope) qs.set("scope", scope);
    return `/oauth/authorize?${qs.toString()}`;
  }, [clientId, obj, simRedirect, challenge, method, state, scope]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">OAuth Simulator</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          <code className="font-mono">code_challenge = BASE64URL(SHA256(code_verifier))</code>
        </p>
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <Button size="lg" onClick={play}>
            ▶ Play PKCE
          </Button>
          <span className="text-xs text-muted-foreground">offline demo — generates a fresh pair + state and verifies it</span>
        </div>
      </div>

      {/* Real flow */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Run the real flow</CardTitle>
          <CardDescription>
            Launches <code className="font-mono">/oauth/authorize</code> with a registered web redirect and returns here to
            exchange the code.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3 text-xs">
          {client === "loading" && <p className="text-muted-foreground">Loading client…</p>}
          {client === "missing" && (
            <p className="text-muted-foreground">
              {clientId ? (
                <>Client <code className="font-mono">{clientId}</code> not found{err ? ` — ${err}` : ""}.</>
              ) : (
                <>Open this from <Link href="/oauth/admin" className="underline">Admin</Link> → “simulate” on a client to run the real flow. (The PKCE demo below works without one.)</>
              )}
            </p>
          )}
          {obj && (
            <>
              <Kv k="client" v={`${obj.name} · ${obj.id}`} />
              <Kv k="redirect_uri" v={simRedirect} />
              {err && <div className="rounded-md border border-destructive/40 bg-destructive/10 p-2 text-destructive">{err}</div>}
              {!registered ? (
                <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-amber-700">
                  This client hasn’t registered the simulation redirect yet.
                  <div className="mt-2">
                    <Button size="sm" onClick={enableRedirect} disabled={busy}>
                      {busy ? "Enabling…" : `Add ${simRedirect} & enable`}
                    </Button>
                  </div>
                </div>
              ) : (
                <Button onClick={launch}>▶ Launch real flow</Button>
              )}
            </>
          )}
        </CardContent>
      </Card>

      {/* PKCE pair */}
      <Card>
        <CardHeader className="flex-row items-center justify-between space-y-0">
          <div>
            <CardTitle className="text-base">1 · The app generates a PKCE pair</CardTitle>
            <CardDescription>The verifier stays on the device; only the challenge goes in the URL.</CardDescription>
          </div>
          <div className="flex items-center gap-2">
            <Button variant={method === "S256" ? "default" : "outline"} size="sm" onClick={() => setMethod("S256")}>
              S256
            </Button>
            <Button variant={method === "plain" ? "default" : "outline"} size="sm" onClick={() => setMethod("plain")}>
              plain
            </Button>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label htmlFor="verifier">code_verifier</Label>
              <div className="flex items-center gap-2">
                <Badge variant={valid ? "outline" : "destructive"}>{len} chars</Badge>
                <Button variant="outline" size="sm" onClick={() => setVerifier(genVerifier(64))}>
                  Regenerate
                </Button>
                <Button variant="ghost" size="sm" onClick={() => copy(verifier)}>
                  copy
                </Button>
              </div>
            </div>
            <Input id="verifier" className="font-mono text-xs" value={verifier} onChange={(e) => setVerifier(e.target.value)} />
            {!valid && (
              <p className="text-[11px] text-destructive">
                A verifier must be 43–128 chars from <code className="font-mono">[A-Za-z0-9-._~]</code>.
              </p>
            )}
          </div>

          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label>code_challenge ({method})</Label>
              <Button variant="ghost" size="sm" onClick={() => copy(challenge)}>
                copy
              </Button>
            </div>
            <div className="break-all rounded-md border bg-muted p-2 font-mono text-xs">{challenge || "…"}</div>
          </div>

          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label>state (CSRF token)</Label>
              <Button variant="ghost" size="sm" onClick={() => setState(genVerifier(24))}>
                regenerate
              </Button>
            </div>
            <div className="break-all rounded-md border bg-muted p-2 font-mono text-xs">{state || "…"}</div>
            <p className="text-[11px] text-muted-foreground">A random per-request value the client generates and re-checks on the callback (CSRF defence).</p>
          </div>

          <div className="space-y-1.5">
            <Label>Authorization request (preview)</Label>
            <div className="break-all rounded-md border bg-background p-2 font-mono text-[11px] text-muted-foreground">GET {authorizeUrl}</div>
          </div>
        </CardContent>
      </Card>

      {/* server check */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">2 · The server verifies at /token</CardTitle>
          <CardDescription>
            At <code className="font-mono">/authorize</code> the server stores the challenge; at{" "}
            <code className="font-mono">/token</code> the app sends the verifier and the server recomputes it.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-center gap-3">
            <Button onClick={() => setStored(challenge)} disabled={!challenge}>
              Authorize → store challenge
            </Button>
            {stored !== null && (
              <Button variant="outline" onClick={() => setStored(null)}>
                Reset
              </Button>
            )}
          </div>
          {stored !== null && (
            <div className="space-y-3 text-xs">
              <Kv k="stored code_challenge (at /authorize)" v={stored} />
              <Kv k="recomputed — BASE64URL(SHA256(verifier))" v={challenge} />
              <div
                className={`rounded-md border p-3 text-sm font-medium ${
                  matches ? "border-emerald-500/40 bg-emerald-500/10 text-emerald-600" : "border-destructive/40 bg-destructive/10 text-destructive"
                }`}
              >
                {matches
                  ? "✓ PKCE verified — the server issues access_token + refresh_token."
                  : "✕ invalid_grant — the verifier does not match the stored challenge."}
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function Kv({ k, v }: { k: string; v: string }) {
  return (
    <div>
      <div className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{k}</div>
      <div className="mt-0.5 break-all rounded-md border bg-background p-2 font-mono">{v || "…"}</div>
    </div>
  );
}
