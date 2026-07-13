import { useForm, Head, Link } from "@pageflow/react";
import { Button } from "@ui/button";
import { Input } from "@ui/input";
import { Label } from "@ui/label";
import { Card, CardContent, CardHeader, CardTitle } from "@ui/card";

// PUBLIC page contributed by the User PLUGIN. The public surface globs
// plugins/*/site/Pages/**, so this resolves as component "User/Register".
// Server: UserFlowController@register. Posts to the plugin's own /ajx/users.
export default function Register() {
  const form = useForm({ username: "", email: "", password: "" });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post("/ajx/users");
  }

  return (
    <>
      <Head title="Create your account" />
      <main className="mx-auto flex min-h-screen max-w-sm flex-col justify-center p-8">
        <Card>
          <CardHeader>
            <CardTitle>Create your account</CardTitle>
          </CardHeader>
          <CardContent>
            {form.wasSuccessful ? (
              <div className="space-y-4 text-center">
                <p className="text-sm text-foreground">
                  Almost there — we&apos;ve emailed you a verification link.
                  Follow it, or enter the token to confirm your address.
                </p>
                <Button asChild className="w-full">
                  <Link href="/verify-email">Verify email</Link>
                </Button>
              </div>
            ) : (
            <form onSubmit={submit} className="space-y-4">
              <Field label="Username" htmlFor="username" error={form.errors.username}>
                <Input
                  id="username"
                  value={form.data.username}
                  onChange={(e) => form.setData("username", e.target.value)}
                />
              </Field>
              <Field label="Email" htmlFor="email" error={form.errors.email}>
                <Input
                  id="email"
                  type="email"
                  value={form.data.email}
                  onChange={(e) => form.setData("email", e.target.value)}
                />
              </Field>
              <Field label="Password" htmlFor="password" error={form.errors.password}>
                <Input
                  id="password"
                  type="password"
                  value={form.data.password}
                  onChange={(e) => form.setData("password", e.target.value)}
                />
              </Field>
              <Button type="submit" className="w-full" disabled={form.processing}>
                {form.processing ? "Creating…" : "Sign up"}
              </Button>
            </form>
            )}
          </CardContent>
        </Card>
        <p className="mt-4 text-center text-sm text-muted-foreground">
          Already have an account?{" "}
          <Button variant="link" className="h-auto p-0" asChild>
            <Link href="/login">Sign in</Link>
          </Button>
        </p>
      </main>
    </>
  );
}

function Field({
  label,
  htmlFor,
  error,
  children,
}: {
  label: string;
  htmlFor: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={htmlFor}>{label}</Label>
      {children}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  );
}
