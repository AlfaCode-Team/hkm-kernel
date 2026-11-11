# Daemons — Provisioning external processes

Some capabilities are a **client** for a separate process: a message bus, a broker, a search engine. Installing one of those leaves a deployment a process short, and the symptom is always the same — everything appears to work and nothing arrives.

A plugin can therefore declare the daemon it needs, and `daemon:install` fetches, verifies and configures it.

::: tip The kernel knows no daemon
The kernel holds only the mechanism. Which binary, which version, which checksum, which config template and which arguments are all declared **by the plugin, in the plugin's own manifest, versioned with the plugin**. A second daemon is a manifest block in a second plugin and no kernel change.
:::

## Declaring one

```jsonc
"daemon": {
  "name":    "somed",                 // the binary; becomes var/daemon/somed/
  "version": "1.2.3",                 // EXACT. A range or `latest` fails the parse
  "why":     "one sentence an operator reads before approving a download",
  "url":     "https://…/somed-{version}-{os}-{arch}.tar.gz",   // https only
  "archive": "tar.gz",                // the only type handled
  "member":  "somed-{version}-{os}-{arch}/somed",  // ONE path, as `tar -tzf` prints it

  "platforms": {                      // sha256 of the ARCHIVE, per platform
    "darwin-arm64": "…64 hex…",       // tokens are GOOS-GOARCH, not PHP's
    "linux-amd64":  "…64 hex…"
  },

  "defaults": { "port": "7777" },
  "config":  { "template": "daemon/somed.conf.tmpl", "target": "somed.conf",
               "verify": ["-t", "-c", "{config}"] },
  "service": { "args": ["-c", "{config}"], "reload": ["-sl", "reload={pidfile}"] },
  "env":     { "SOMED_URL": "somed://127.0.0.1:{port}", "SOMED_PASS": "{secret}" }
}
```

### Tokens

Available to every template, argv entry and env value:

`{name}` `{version}` `{os}` `{arch}` `{dir}` `{binary}` `{config}` `{logfile}` `{pidfile}` `{store}` `{secret}` `{secret_bcrypt}` `{project}` `{root}`, plus the plugin's own `defaults`.

An **unresolved** `{token}` fails the write rather than becoming empty. `port = ""` is syntactically valid and means something else entirely.

## Commands

```bash
hkm cli daemon:install   # fetch, verify, configure
hkm cli daemon:status    # compare installed against what the commit pins
hkm cli daemon:run       # run it in the foreground
```

`daemon:status` **exits non-zero on drift**, so it works as a deploy gate.

## The guarantees

Each of these is a test, and each exists because the alternative fails quietly.

- **The checksum is pinned in the manifest** — committed, reviewed, diffable. Fetching `SHA256SUMS` from the same host that served the binary verifies nothing: whoever can replace one can replace the other.
- **A platform with no pinned digest is refused.** There is no unverified path, because that branch becomes the one every unpinned platform takes silently.
- **A digest mismatch deletes the download and stops.** No flag skips it.
- **Exactly one named archive member is extracted**, so zip-slip is designed out rather than guarded against — nothing else is ever written.
- **The generated secret is minted once and reused.** Rotating it would leave the daemon's config and the project's `.env` disagreeing, both looking right.
- **An existing config is never overwritten.** It holds the running credential and whatever was tuned. The plugin's version lands beside it as `.conf.new`.
- **`.env` keys are added only when absent**, including when merely commented out.
- **Nothing is started and no service unit is installed.** The unit is written into the project, with the commands to install it printed.
- **A re-run with nothing changed downloads nothing and writes nothing**, because this runs on every deploy — `var/` does not survive a git clone.

## Where everything lands

Under `var/daemon/<name>/`:

| File | Mode | What |
|---|---|---|
| the binary | `0750` | the verified executable |
| the rendered config | `0640` | from the plugin's template |
| the secret | `0600` | minted once |
| `installed.json` | — | version, platform and digest actually installed |
| the service unit | — | written, never installed |

## Anti-patterns

- **Declaring a daemon a plugin does not actually need.** It is a download an operator has to approve, and `why` is what they read.
- **A version range, `latest`, or an `http://` url.** All three fail the parse.
- **Expecting the kernel to know your daemon's name, flags or config format.** It does not and will not.
- **Documenting a particular daemon in the kernel's own docs.** The plugin documents itself.

## See also

- [Servers](/background/servers) — the in-process half: long-running PHP processes declared in `servers[]`.
