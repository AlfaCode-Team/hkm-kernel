import { usePage, useForm, router, Head, Link } from "@pageflow/react";
import { Button } from "@ui/button";

// ── Example ADMIN page ───────────────────────────────────────────────────────
// A server-driven CRUD list. The controller sends { users, filters, pagination }
// as props; this component only renders + drives navigation through the router.
//
// Server side (a plugin controller or a project route) returns something like:
//   return $pageflow->render($request, 'Users/Index', [
//       'users'      => $repo->paginate($page, $q),
//       'filters'    => ['q' => $q],
//       'pagination' => ['page' => $page, 'pages' => $pages],
//   ]);

interface User {
  id: string;
  name: string;
  email: string;
  role: string;
}

type UsersProps = {
  users: User[];
  filters: { q: string };
  pagination: { page: number; pages: number };
}

export default function UsersIndex() {
  const { props } = usePage<UsersProps>();

  // Partial reload: only re-fetch the `users` prop as the operator types, keeping
  // the URL in sync (?q=…) without a full page navigation.
  function search(q: string) {
    router.get(
      "/admin/users",
      { q },
      { only: ["users", "filters"], preserveState: true, replace: true },
    );
  }

  return (
    <>
      <Head title="Users" />
      <main className="mx-auto max-w-5xl p-8">
        <header className="mb-6 flex items-center justify-between">
          <h1 className="text-2xl font-semibold">Users</h1>
          <Link href="/admin/users/create" as="button">
            <Button size="sm">New user</Button>
          </Link>
        </header>

        <input
          type="search"
          defaultValue={props.filters.q}
          onChange={(e) => search(e.target.value)}
          placeholder="Search name or email…"
          className="mb-4 w-full rounded-md border border-[hsl(var(--input))] px-3 py-2"
        />

        <table className="w-full text-left text-sm">
          <thead className="border-b border-[hsl(var(--input))] text-[hsl(var(--foreground))]/60">
            <tr>
              <th className="py-2">Name</th>
              <th className="py-2">Email</th>
              <th className="py-2">Role</th>
              <th className="py-2 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {props.users.map((u) => (
              <UserRow key={u.id} user={u} />
            ))}
            {props.users.length === 0 && (
              <tr>
                <td colSpan={4} className="py-8 text-center text-[hsl(var(--foreground))]/50">
                  No users match “{props.filters.q}”.
                </td>
              </tr>
            )}
          </tbody>
        </table>

        <Pagination page={props.pagination.page} pages={props.pagination.pages} q={props.filters.q} />
      </main>
    </>
  );
}

function UserRow({ user }: { user: User }) {
  // useForm drives a DELETE through the router with CSRF + processing state.
  const form = useForm({});

  function destroy() {
    if (confirm(`Delete ${user.name}?`)) {
      form.delete(`/admin/users/${user.id}`, { preserveScroll: true });
    }
  }

  return (
    <tr className="border-b border-[hsl(var(--input))]/50">
      <td className="py-2">
        <Link href={`/admin/users/${user.id}`} className="hover:underline">
          {user.name}
        </Link>
      </td>
      <td className="py-2">{user.email}</td>
      <td className="py-2 capitalize">{user.role}</td>
      <td className="py-2 text-right">
        <Button variant="ghost" size="sm" onClick={destroy} disabled={form.processing}>
          {form.processing ? "…" : "Delete"}
        </Button>
      </td>
    </tr>
  );
}

function Pagination({ page, pages, q }: { page: number; pages: number; q: string }) {
  if (pages <= 1) return null;
  return (
    <nav className="mt-6 flex items-center justify-center gap-2">
      {Array.from({ length: pages }, (_, i) => i + 1).map((p) => (
        <Link
          key={p}
          href={`/admin/users?page=${p}${q ? `&q=${encodeURIComponent(q)}` : ""}`}
          only={["users", "pagination", "filters"]}
          className={
            "rounded px-3 py-1 text-sm " +
            (p === page ? "bg-primary text-primary-foreground" : "hover:bg-accent")
          }
        >
          {p}
        </Link>
      ))}
    </nav>
  );
}
