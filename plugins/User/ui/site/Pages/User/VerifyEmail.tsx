import { useForm, Head, Link } from "@pageflow/react";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@ui/card";

// PUBLIC page contributed by the User PLUGIN. The public surface globs
// plugins/*/site/Pages/**, so this resolves as component "User/VerifyEmail".
// Server: UserFlowController@verifyEmail. The emailed link points at
// /verify-email?token=... — the server passes `token` as a prop for prefill.
// Posts to the plugin's own /ajx/users/verify (UserController@verifyEmailByToken).
export default function VerifyEmail({ token = "" }: { token?: string }) {
  const form = useForm({ token });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post("/ajx/users/verify");
  }

  return (
    <>
      <Head title="Verify your email" />
      <main className="mx-auto flex min-h-screen max-w-sm flex-col justify-center p-8">
        <Card>
          <CardHeader>
            <CardTitle>Verify your email</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="mb-4 text-sm text-muted-foreground">
              Paste the verification token from your email below, or follow the
              link we sent you.
            </p>
            {form.wasSuccessful ? (
              <div className="space-y-4 text-center">
                <p className="text-sm text-foreground">
                  Your email has been verified. You can now sign in.
                </p>
                <Button asChild className="w-full">
                  <Link href="/login">Sign in</Link>
                </Button>
              </div>
            ) : (
              <form onSubmit={submit} className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="token">Verification token</Label>
                  <Input
                    id="token"
                    autoComplete="off"
                    value={form.data.token}
                    onChange={(e) => form.setData("token", e.target.value)}
                  />
                  {form.errors.token && (
                    <p className="text-sm text-destructive">{form.errors.token}</p>
                  )}
                </div>
                <Button
                  type="submit"
                  className="w-full"
                  disabled={form.processing || !form.data.token}
                >
                  {form.processing ? "Verifying…" : "Verify email"}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
        <p className="mt-4 text-center text-sm text-muted-foreground">
          Need a new account?{" "}
          <Button variant="link" className="h-auto p-0" asChild>
            <Link href="/register">Sign up</Link>
          </Button>
        </p>
      </main>
    </>
  );
}
