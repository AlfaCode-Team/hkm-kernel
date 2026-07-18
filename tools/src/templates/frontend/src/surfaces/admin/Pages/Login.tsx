import { useForm, Head } from "@pageflow/react";
import { Button } from "@ui/button";

// Pageflow form: useForm posts through the router; server validation errors come
// back in props.errors (surfaced automatically by the Pageflow validation stage).
export default function Login() {
  const form = useForm({ email: "", password: "" });

  function submit(e: React.FormEvent) {
    e.preventDefault();
    form.post("/auth/login");
  }

  return (
    <>
      <Head title="Sign in" />
      <main className="mx-auto flex min-h-screen max-w-sm flex-col justify-center p-8">
        <h1 className="mb-6 text-xl font-semibold">Sign in</h1>
        <form onSubmit={submit} className="space-y-4">
          <div>
            <input
              type="email"
              className="w-full rounded-md border border-[hsl(var(--input))] px-3 py-2"
              placeholder="Email"
              value={form.data.email}
              onChange={(e) => form.setData("email", e.target.value)}
            />
            {form.errors.email && <p className="mt-1 text-sm text-red-600">{form.errors.email}</p>}
          </div>
          <div>
            <input
              type="password"
              className="w-full rounded-md border border-[hsl(var(--input))] px-3 py-2"
              placeholder="Password"
              value={form.data.password}
              onChange={(e) => form.setData("password", e.target.value)}
            />
            {form.errors.password && <p className="mt-1 text-sm text-red-600">{form.errors.password}</p>}
          </div>
          <Button type="submit" disabled={form.processing} className="w-full">
            {form.processing ? "Signing in…" : "Sign in"}
          </Button>
        </form>
      </main>
    </>
  );
}
