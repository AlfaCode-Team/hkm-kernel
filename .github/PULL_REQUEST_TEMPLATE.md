<!--
  Thanks for contributing! Please fill out this template.
  See CONTRIBUTING.md for the branch model and coding standards.
-->

## Summary

<!-- What does this PR do and why? Link the issue it closes. -->

Closes #

## Type of change

- [ ] 🐛 Bug fix (non-breaking change that fixes an issue)
- [ ] ✨ New feature (non-breaking change that adds functionality)
- [ ] 💥 Breaking change (fix or feature that changes existing behavior)
- [ ] 📝 Documentation only
- [ ] 🧹 Refactor / chore (no functional change)
- [ ] 🚀 Release PR (`master` → `main`, includes a CHANGELOG version bump)

## Target branch

<!-- Feature/fix work targets `master`. Only release PRs target `main`. -->

- [ ] This PR targets **`master`** (development), **or**
- [ ] This is a **`master` → `main`** release PR and adds a `## [x.y.z] - YYYY-MM-DD` section to `CHANGELOG.md`.

## How has this been tested?

<!-- Describe the tests you added/ran. Paste relevant output. -->

```bash
vendor/bin/phpunit
```

## Checklist

- [ ] My code follows the **Gated Demand Architecture** rules (no Laravel/Symfony/Slim patterns).
- [ ] Every PHP file has `declare(strict_types=1);`.
- [ ] The five access rules are respected (Controller → Service → Repository/Gateway → Port/SDK; Domain imports nothing external).
- [ ] Routes are declared in `module.json` / `proj.json`, not in PHP.
- [ ] Every env var read is declared in the relevant `config[]`.
- [ ] Vendor exceptions are translated at their layer (no `\PDOException`/SDK exceptions escape).
- [ ] I added or updated **tests** and they pass locally.
- [ ] I updated **documentation** where relevant.
- [ ] For shippable changes, I added a `## [Unreleased]` entry to `CHANGELOG.md`.
- [ ] CI is green.

## Screenshots / notes (optional)

<!-- Anything reviewers should pay special attention to. -->
