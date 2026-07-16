import { usePage, Link } from "@pageflow/react";
import { Button } from "@ui/button";

// A Pageflow page: the server sends { component: "Dashboard", props: {...} }.
// Props are typed per page by augmenting the generated pageflow.d.ts.
type DashboardProps = {
  user?: { name: string };
  stats?: { label: string; value: string }[];
}

export default function Dashboard() {
  const { props } = usePage<DashboardProps>();
  const stats = props.stats ?? [
    { label: "Orders", value: "—" },
    { label: "Revenue", value: "—" },
    { label: "Users", value: "—" },
  ];

  return (
    <>
      <main className="mx-auto max-w-4xl p-8">
        <header className="mb-8 flex items-center justify-between">
          <h1 className="text-2xl font-semibold">
            Welcome{props.user ? `, ${props.user.name}` : ""}
          </h1>
          <Link href="/logout" as="button">
            <Button variant="outline" size="sm">Sign out</Button>
          </Link>
        </header>

        <section className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          {stats.map((s) => (
            <div key={s.label} className="rounded-lg border border-[hsl(var(--input))] p-6">
              <div className="text-sm text-[hsl(var(--foreground))]/60">{s.label}</div>
              <div className="mt-1 text-3xl font-bold">{s.value}</div>
            </div>
          ))}
        </section>
      </main>
    </>
  );
}
