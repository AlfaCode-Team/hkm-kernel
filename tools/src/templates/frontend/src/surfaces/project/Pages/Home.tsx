import { usePage, useForm, Head, Link } from "@pageflow/react";
import { Button } from "@ui/button";

// ── Example PROJECT (public) page ────────────────────────────────────────────
// A marketing/landing page. Uses <Head> for SEO meta (title + description), a
// server-provided feature list, and a Pageflow form for a newsletter signup.
//
// Server side returns:
//   $pageflow->render($request, 'Home', [
//       'title'    => 'Acme — build faster',
//       'tagline'  => '…',
//       'features' => [['title'=>'…','body'=>'…'], …],
//   ]);

type HomeProps = {
  title: string;
  tagline: string;
  features: { title: string; body: string }[];
}

export default function Home() {
  const { props } = usePage<HomeProps>();
  const form = useForm({ email: "" });

  function subscribe(e: React.FormEvent) {
    e.preventDefault();
    form.post("/newsletter", { preserveScroll: true, onSuccess: () => form.reset("email") });
  }

  return (
    <>
      <Head>
        <title>{props.title}</title>
        <meta name="description" content={props.tagline} />
        <meta property="og:title" content={props.title} />
      </Head>

      <header className="border-b border-[hsl(var(--input))]">
        <nav className="mx-auto flex max-w-5xl items-center justify-between p-4">
          <Link href="/" className="font-semibold">Acme</Link>
          <div className="flex gap-4 text-sm">
            <Link href="/about" className="hover:underline">About</Link>
            <Link href="/admin" className="hover:underline">Sign in</Link>
          </div>
        </nav>
      </header>

      <main>
        <section className="mx-auto max-w-3xl px-4 py-20 text-center">
          <h1 className="text-4xl font-bold tracking-tight sm:text-5xl">{props.title}</h1>
          <p className="mx-auto mt-4 max-w-xl text-lg text-[hsl(var(--foreground))]/70">
            {props.tagline}
          </p>
          <div className="mt-8 flex justify-center gap-3">
            <Link href="/signup" as="button">
              <Button size="lg">Get started</Button>
            </Link>
            <Link href="/about" as="button">
              <Button size="lg" variant="outline">Learn more</Button>
            </Link>
          </div>
        </section>

        <section className="mx-auto grid max-w-5xl gap-6 px-4 pb-16 sm:grid-cols-3">
          {props.features.map((f) => (
            <div key={f.title} className="rounded-lg border border-[hsl(var(--input))] p-6">
              <h3 className="font-semibold">{f.title}</h3>
              <p className="mt-2 text-sm text-[hsl(var(--foreground))]/70">{f.body}</p>
            </div>
          ))}
        </section>

        <section className="border-t border-[hsl(var(--input))] bg-[hsl(var(--accent))]">
          <form onSubmit={subscribe} className="mx-auto flex max-w-md gap-2 px-4 py-12">
            <input
              type="email"
              required
              placeholder="you@example.com"
              value={form.data.email}
              onChange={(e) => form.setData("email", e.target.value)}
              className="flex-1 rounded-md border border-[hsl(var(--input))] bg-[hsl(var(--background))] px-3 py-2"
            />
            <Button type="submit" disabled={form.processing}>
              {form.processing ? "…" : "Subscribe"}
            </Button>
          </form>
          {form.errors.email && (
            <p className="pb-6 text-center text-sm text-red-600">{form.errors.email}</p>
          )}
        </section>
      </main>
    </>
  );
}
