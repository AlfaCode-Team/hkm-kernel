import { usePage, Head } from "@pageflow/react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@ui/card";
import { Button } from "@ui/button";

// OAuth consent screen — a PLUGIN Pageflow page ("OAuth2/Consent", server:
// AuthorizationController@authorize). Approve/Deny use a NATIVE form POST to
// /oauth/authorize (not a Pageflow XHR) so the browser follows the 302 the
// decision() returns back to the client's redirect_uri. The kernel CSRF token
// rides in `_csrf_token` exactly like the old server-rendered form.

type Props = { csrf: string; clientName: string; scopes: string[]; authzId: string };

export default function OAuth2Consent() {
  const { props } = usePage<Props>();

  return (
    <>
      <Head title={`Authorize ${props.clientName}`} />
      <main className="flex min-h-screen items-center justify-center bg-background px-4 py-10">
        <Card className="w-full max-w-md">
          <CardHeader className="items-center text-center">
            <div className="mb-1 flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-2xl">🔐</div>
            <CardTitle>
              Authorize <span className="text-primary">{props.clientName}</span>
            </CardTitle>
            <CardDescription>{props.clientName} is requesting access to your account.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            <div className="rounded-lg border bg-muted/40 p-4">
              <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">This app will be able to</div>
              <ul className="mt-3 space-y-2">
                {props.scopes.length === 0 && <li className="text-sm text-muted-foreground">Basic access to your account.</li>}
                {props.scopes.map((s) => (
                  <li key={s} className="flex items-start gap-2 text-sm">
                    <span className="mt-0.5 text-primary">✓</span>
                    <code className="font-mono text-xs">{s}</code>
                  </li>
                ))}
              </ul>
            </div>

            <form method="post" action="/oauth/authorize" className="grid grid-cols-2 gap-3">
              <input type="hidden" name="_csrf_token" value={props.csrf} />
              <input type="hidden" name="authz_id" value={props.authzId} />
              <Button type="submit" name="action" value="deny" variant="outline">
                Deny
              </Button>
              <Button type="submit" name="action" value="approve">
                Allow
              </Button>
            </form>

            <p className="text-center text-[11px] text-muted-foreground">
              You can revoke access anytime in your account settings.
            </p>
          </CardContent>
        </Card>
      </main>
    </>
  );
}
