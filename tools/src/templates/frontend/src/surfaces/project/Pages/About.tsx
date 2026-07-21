import { Head, Link } from "@pageflow/react";

// A second page in the public surface — shows in-app navigation via <Link>
// (no full reload) between project pages.
export default function About() {
  return (
    <>
      <Head title="About" />
      <main className="mx-auto max-w-2xl px-4 py-20">
        <Link href="/" className="text-sm hover:underline">← Home</Link>
        <h1 className="mt-4 text-3xl font-bold">About us</h1>
        <p className="mt-4 text-[hsl(var(--foreground))]/70">
          This page is served by the <code>project</code> surface. It shares the
          same Pageflow client and <code>@ui</code> design system as the admin
          surface, but builds and deploys independently.
        </p>
      </main>
    </>
  );
}
