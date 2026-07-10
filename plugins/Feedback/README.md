# Feedback Plugin

> Solves: **`feedback.management`** · Namespace: **`Plugins\Feedback\`** · Type: on-demand GDA module

Owns the **feedback.management** domain — users submit categorised, rated
feedback; admins triage it. **Extracted from the User plugin** so each plugin
owns exactly one domain (the framework's "one module, one domain" rule).

## Data & security

- Feedback rows live in the request's **TENANT** database (`user_feedback`
  table, shipped as a tenant-template migration). The repository is bound to the
  tenant-routed `DatabasePort`.
- The submitter id is taken from the authenticated **Identity**, never the body.
- Reading one entry is self-or-admin; listing/triage requires the
  `feedback:manage` permission.
- The `feedback.submitted` integration event is dispatched only **after** the
  write succeeds. Security-relevant actions are audited to the shared central
  `audit_log` table.

## Routes

| Method | Path | Action | Filters |
|---|---|---|---|
| POST  | `/ajx/feedback`      | `submit`       | `auth, tenant, throttle:5,1` |
| GET   | `/ajx/feedback`      | `index` (triage) | `auth, tenant` |
| GET   | `/ajx/feedback/{id}` | `show`         | `auth, tenant` |
| PATCH | `/ajx/feedback/{id}` | `updateStatus` | `auth, tenant` |

## Layout

```
API/DTOs, API/IntegrationEvents       — SubmitFeedbackDTO, ListFeedbackQuery, FeedbackPage, FeedbackSubmittedIntegrationEvent
Application/Ports/FeedbackStore        — persistence seam (DIP)
Application/Services/FeedbackService   — authorization + orchestration
Domain/Entities/FeedbackEntry          — aggregate; VOs: FeedbackId/Category/Rating/Status/Message
Infrastructure/Persistence             — FeedbackRepository (DatabasePort only)
Infrastructure/Http/Controllers        — FeedbackController (thin)
Infrastructure/Audit + Domain/Ulid     — self-contained copies so the plugin has zero cross-plugin dependency
```

## Enabling

Add `Plugins\Feedback\Provider::class` to the project's `withModules([...])` and
run `hkm plugins enable Feedback` to publish the `user_feedback` tenant
migration. `requires: ["database.management"]`.

> Note: the User plugin no longer serves feedback. If you want a server-rendered
> feedback demo page, build it against the `/ajx/feedback` API in your frontend.
