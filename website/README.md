# HKM Kernel documentation site

The public documentation, built with [VitePress](https://vitepress.dev). Pages are
Markdown under this directory; navigation lives in `.vitepress/config.mts`.

## Work on it

```bash
cd website
npm ci
npm run dev        # http://localhost:5173, hot reload
npm run build      # static site → .vitepress/dist (fails on dead links)
npm run preview    # serve the built site
```

`reference/changelog.md` includes `../CHANGELOG.md` at build time, so the changelog
page never needs editing by hand.

## Deploy

The build output (`.vitepress/dist`) is plain static files. Any static host works.

| Host | Setting |
|---|---|
| GitHub Pages | `.github/workflows/docs.yml` (enable Pages → Source: GitHub Actions). Serves under `/hkm-kernel/`, so it builds with `DOCS_BASE=/hkm-kernel/`. |
| Netlify / Vercel / Cloudflare Pages | base dir `website`, build `npm run build`, output `.vitepress/dist` |
| Your own server | upload `.vitepress/dist`; serve `foo.html` for `/foo` (clean URLs) |

When the site is served from a sub-path rather than a domain root, set `DOCS_BASE`
to that path at build time (for example `DOCS_BASE=/docs/ npm run build`).

For nginx, clean URLs need:

```nginx
location / {
    try_files $uri $uri.html $uri/ =404;
}
```

## Writing rules

- Verify every API, flag, default and env var against the source definition
  before documenting it — not against another doc.
- Document the kernel (`src/`) and the packages in `modules/` only. A plugin
  documents itself in its own repository.
- Internal links are absolute and extension-less: `[Groups](/routing/groups)`.
- Outside code, wrap `{...}` and `<Tag>` in backticks — VitePress compiles
  Markdown as Vue, and a trailing `{...}` is read as HTML attributes.
