# Contributing to AlfacodeTeam PhpServicePlatform

First off — thank you for taking the time to contribute! This document explains
how to propose changes, the branch model we follow, and the standards your code
must meet to be merged.

By participating in this project you agree to abide by our
[Code of Conduct](CODE_OF_CONDUCT.md).

---

## Table of contents

- [Ways to contribute](#ways-to-contribute)
- [Branch model & release flow](#branch-model--release-flow)
- [Getting set up](#getting-set-up)
- [Making a change](#making-a-change)
- [Coding standards](#coding-standards)
- [Commit messages](#commit-messages)
- [Tests](#tests)
- [Opening a pull request](#opening-a-pull-request)
- [Reporting bugs & requesting features](#reporting-bugs--requesting-features)
- [Reporting security issues](#reporting-security-issues)

---

## Ways to contribute

- **Report a bug** — open a [Bug report](../../issues/new/choose).
- **Request a feature** — open a [Feature request](../../issues/new/choose).
- **Improve docs** — typo fixes and clarifications are always welcome.
- **Submit code** — bug fixes, new plugins, and framework improvements via a
  pull request (see below).

> **Security vulnerabilities must NOT be filed as public issues.** See
> [Reporting security issues](#reporting-security-issues) and
> [SECURITY.md](SECURITY.md).

---

## Branch model & release flow

This project uses a strict two-branch model. **Please read this before opening a
PR** — PRs to the wrong branch will be redirected.

| Branch   | Role                                                                 |
|----------|----------------------------------------------------------------------|
| `master` | **Development branch** — all day-to-day work, features, and fixes go here |
| `main`   | **Stable/release branch** — only ever updated via a PR from `master` |

Releases are **CHANGELOG-driven and automatic** — never tag or publish by hand:

1. Work and commit on **`master`** (or a branch based off `master`).
2. To ship, add a new `## [x.y.z] - YYYY-MM-DD` section to
   [`CHANGELOG.md`](CHANGELOG.md), below `## [Unreleased]`, describing the change.
3. Open a PR **`master` → `main`** and get it merged.
4. On merge, CI reads the top CHANGELOG version and, if no matching `vX.Y.Z` tag
   exists, creates the tag, builds all OS bundles, and publishes the GitHub
   Release automatically.

**Do not:**
- Commit feature work directly to `main`.
- Manually run `git tag` or create a GitHub Release.
- Bump the version anywhere but the top section of `CHANGELOG.md`.

---

## Getting set up

Requirements: **PHP >= 8.4** with the standard extensions (json, mbstring,
ctype, tokenizer, filter, pdo, openssl, curl, fileinfo), Composer, and — for the
native launcher — the pinned [Zig](tools/.zig-version) toolchain.

```bash
git clone --recurse-submodules git@github.com:AlfaCode-Team/php-service-platform.git
cd php-service-platform
composer install                 # also wires the git hooks (core.hooksPath=.githooks)
vendor/bin/phpunit               # run the test suite
```

> `composer install` sets `core.hooksPath=.githooks` for you. If you skip
> Composer, run `git config core.hooksPath .githooks` manually so the commit
> hooks are active.

---

## Making a change

1. Fork the repo (external contributors) or create a topic branch off `master`.
2. Name your branch descriptively: `fix/route-manifest-cache`,
   `feat/redis-queue-adapter`, `docs/security-policy`.
3. Keep each PR focused on a single concern.
4. Update or add tests (see [Tests](#tests)).
5. Update relevant docs and, for shippable changes, add a `## [Unreleased]`
   CHANGELOG entry.

---

## Coding standards

This is **not** Laravel, Symfony, or Slim — do not introduce their patterns,
classes, or conventions. The framework follows the **Gated Demand Architecture
(GDA)**; the authoritative rules live in [`CLAUDE.md`](CLAUDE.md) and
[`docs/ai-context/`](docs/ai-context/). The essentials:

- **PHP 8.4+** — use readonly classes, enums, named arguments where they fit.
- **`declare(strict_types=1);`** at the top of every PHP file.
- **The five access rules** (runtime-enforced):
  `Controller → Service → (Repository + Gateway) → (DatabasePort | Vendor SDK)`;
  the Domain layer imports nothing external.
- **No business logic in the Kernel** — the kernel knows nothing about any domain.
- **Routes are declared in `module.json` / `proj.json`**, never in PHP.
- **Every env var a module reads must be declared** in that module's `config[]`.
- **Money is integer cents** in a value object — never a float.
- **Vendor exceptions never escape their layer** — translate `\PDOException`,
  Stripe, Guzzle, etc. into the appropriate framework exception.
- **Use `env()`, never `getenv()`** for `.env` values in first-party code.

New local business modules go in `plugins/` (namespace `Plugins\`), **not**
`projects/`.

---

## Commit messages

We follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<optional scope>): <short summary>

<optional body>

<optional footer>
```

Common types: `feat`, `fix`, `docs`, `test`, `refactor`, `chore`, `build`,
`perf`, `ci`.

Examples:

```
feat(edge): add TLS mode selection to the launcher
fix(http): deep-clone parameter bags on Request::__clone
docs(security): add coordinated disclosure policy
```

A repo `commit-msg` hook automatically strips AI-generated co-author trailers —
commits are authored by humans only.

---

## Tests

- All contributions that change behavior must include tests.
- Use **fakes / in-memory adapters**, never real infrastructure, in unit tests
  (see the testing patterns in `CLAUDE.md` and `docs/ai-context/10_TESTING.md`).
- Run the full suite locally before opening a PR:

  ```bash
  vendor/bin/phpunit
  ```

- CI runs the test suite as a merge gate; a red build blocks the merge.

---

## Opening a pull request

1. Push your branch and open a PR **into `master`** (release PRs into `main` are
   `master → main` only, and must include a CHANGELOG version bump).
2. Fill out the [pull request template](.github/PULL_REQUEST_TEMPLATE.md)
   completely.
3. Ensure CI is green and resolve review feedback.
4. Squash/fixup noise commits where it helps readability; keep meaningful history.

Maintainers may request changes or redirect the target branch. Please be patient
and responsive to review comments.

---

## Reporting bugs & requesting features

Use the issue templates via **[New issue](../../issues/new/choose)**. Include as
much detail as possible — reproduction steps, expected vs. actual behavior,
PHP/OS versions, and relevant logs.

---

## Reporting security issues

**Do not open a public issue for a security vulnerability.** Follow the
coordinated-disclosure process in [SECURITY.md](SECURITY.md). We will work with
you to verify, fix, and disclose responsibly.

---

Thank you for helping make AlfacodeTeam PhpServicePlatform better! 🎉
