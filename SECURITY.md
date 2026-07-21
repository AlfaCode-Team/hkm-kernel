# Security Policy

The AlfacodeTeam PhpServicePlatform team takes the security of the framework and
its users seriously. Thank you for helping keep it and the projects built on it
safe.

## Supported versions

Releases are published as Git tags in the form `vX.Y.Z` (for example
`v1.0.19`). Security fixes are provided for the latest published `v1.0.x` tag.
Older tags receive fixes only at the maintainers' discretion.

| Version tag         | Supported                             |
|---------------------|---------------------------------------|
| Latest `v1.0.x` tag | :white_check_mark:                    |
| Older `v1.0.x` tags | :x: (upgrade to the latest release)   |

Always run the most recently tagged release; run `hkm doctor` to verify your
runtime.

## Reporting a vulnerability

**Please do NOT report security vulnerabilities through public GitHub issues,
pull requests, or discussions.**

Instead, use one of the following private channels:

1. **GitHub Security Advisories (preferred).** Go to the repository's
   **Security → Advisories → Report a vulnerability** page
   ([Private vulnerability reporting](../../security/advisories/new)). This keeps
   the report confidential and lets us collaborate on a fix.
2. **Email.** Send details to **shamavurasheed@gmail.com** with the subject line
   `SECURITY: php-service-platform`.

### What to include

To help us triage quickly, please include as much of the following as you can:

- A description of the vulnerability and its impact.
- The affected version(s) / commit and component (kernel, a specific plugin,
  the native launcher, etc.).
- Step-by-step reproduction instructions or a proof-of-concept.
- Any relevant logs, stack traces, or configuration (redact secrets).
- Your assessment of severity, if you have one.

### What to expect

- **Acknowledgement** within **72 hours** of your report.
- An initial **assessment and severity triage** within **7 days**.
- Regular updates on remediation progress.
- **Coordinated disclosure:** we will agree on a disclosure timeline with you.
  Our target is to release a fix within **90 days** of confirmation, sooner for
  actively exploited issues.
- **Credit:** with your permission, we will credit you in the release notes and
  the security advisory. Let us know if you prefer to remain anonymous.

## Scope

In scope:

- The framework kernel (`src/Kernel/`) and its security layers.
- First-party plugins under `plugins/` and internal packages under `modules/`.
- The native launcher / bundling tooling (`tools/`).

Out of scope (report to the relevant upstream instead):

- Vulnerabilities in third-party dependencies — report to that project, though
  we appreciate a heads-up so we can bump the constraint.
- Issues that require a misconfiguration explicitly warned against in the docs.
- Denial of service via unrealistic resource exhaustion on unbounded input the
  operator controls.

## Disclosure policy

Once a fix is available, we will:

1. Release a patched version and update the CHANGELOG.
2. Publish a GitHub Security Advisory describing the issue and the fix.
3. Credit the reporter (unless anonymity was requested).

We ask that you give us a reasonable opportunity to remediate before any public
disclosure. Thank you for practicing responsible, coordinated disclosure.
