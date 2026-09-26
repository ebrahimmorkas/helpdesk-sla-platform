# Helpdesk SLA Platform

A multi-tenant customer-support API built with **Laravel 13**, **PHP 8.4**,
**MySQL 8.4** and **Redis 7**. Many companies (tenants) use the same deployment
to handle their customers' support tickets, each with its own staff, customers,
business hours and service-level agreements (SLAs).

The two engineering problems at its centre:

1. **Tenant isolation** — one company must never see, change or even detect
   another company's data.
2. **SLA timers in business time** — response and resolution targets are
   measured in each tenant's working hours and timezone, pause while waiting on
   the customer, and breaches are detected and escalated automatically.

[![tests](https://github.com/ebrahimmorkas/helpdesk-sla-platform/actions/workflows/tests.yml/badge.svg)](https://github.com/ebrahimmorkas/helpdesk-sla-platform/actions/workflows/tests.yml)

Further reading: [`docs/api.md`](docs/api.md) ·
[`docs/technical-decisions.md`](docs/technical-decisions.md) ·
[`docs/interview-notes.md`](docs/interview-notes.md)

---

## Business problem

A support team promises customers, for example, "urgent issues answered within
15 minutes, resolved within 4 business hours". Measuring that correctly is
harder than it looks:

- A ticket raised at 16:30 on Friday is not late at 17:31; the clock stops at
  close of business and restarts on Monday.
- Time spent waiting for the customer's answer must not count against the team.
- Nobody should have to check dashboards to notice a missed target.
- As a SaaS product, one database serves many companies, so every query must
  be tenant-safe.

## Features

**Core**

- Organisation sign-up (creates the tenant, its first admin, default SLA
  policies and Monday–Friday 09:00–17:00 business hours).
- Token login (Sanctum), user management within the tenant.
- Invitations for staff and customers: single-use, expiring, only a hash of
  the token is stored, delivered by an encrypted queued email.
- Tickets with per-tenant sequential numbers (`#1`, `#2`, … per organisation).
- Public replies and internal notes (never visible to customers).
- Status workflow: `open` → `pending` (waiting on customer) → `resolved` → `closed`.
- Assignment, priorities, activity timeline.
- Private attachments with content-type validation and authorised download.

**Advanced**

- **Fail-closed tenancy**: a global scope limits every tenant-owned model to
  the current organisation and returns *nothing* when no organisation is set.
  Route model binding is tenant-scoped, so another tenant's ticket is a `404`.
- **Business-time SLA engine**: due dates computed in the tenant's timezone
  and working hours, across weekends and daylight-saving changes.
- **SLA pause/resume**: waiting on the customer extends the resolution target
  by the business minutes spent waiting.
- **Scheduled breach detection** every minute across all tenants, idempotent
  via a unique constraint, escalating to admins when a ticket is unassigned.
- **Auto-close** of resolved tickets after 7 days.
- **Full-text search** (MySQL `FULLTEXT`) over subject and description.
- **SLA compliance report** per priority (cached per tenant) and agent workload.
- Per-user and per-organisation rate limits.

## Technology stack

| Concern | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.4) |
| Database | MySQL 8.4 (row locks, FULLTEXT index, unique constraints) |
| Cache / queue | Redis 7 with database failover |
| Auth | Laravel Sanctum personal access tokens |
| Tests | PHPUnit 12 on MySQL, including parallel-process tests |
| Code style | Laravel Pint |
| Runtime | Docker Compose: PHP-FPM, nginx, MySQL, Redis, queue workers, scheduler |
| CI | GitHub Actions |

## Architecture

```
Request ─► auth:sanctum ─► tenant (SetCurrentOrganization) ─► SubstituteBindings ─► throttle
                                   │ sets CurrentOrganization (scoped singleton)
                                   ▼
         Eloquent models using BelongsToOrganization ──► OrganizationScope
         (where organization_id = current, or 1 = 0 when none is set)

Controllers ─► TicketService ─► SlaClock ─► BusinessCalendar (pure, unit tested)
                     │              (due dates in business time)
                     ├─► AttachmentStore (private disk)
                     └─► notifications (queued after commit)

Scheduler (every minute) ─► sla:detect-breaches ─► sla_breaches (unique ticket+metric)
                                                 └► SlaBreached ─► EscalateSlaBreach (queued)
          (hourly)       ─► tickets:close-resolved
```

| Path | Responsibility |
|---|---|
| `app/Tenancy/*` | Tenant context, global scope, model trait |
| `app/Http/Middleware/SetCurrentOrganization.php` | Sets the tenant before route binding |
| `app/Support/BusinessCalendar.php` | Adds/measures business minutes in a timezone |
| `app/Services/SlaClock.php` | Derives due dates from priority, calendar and paused time |
| `app/Services/TicketService.php` | Ticket lifecycle, numbering, status rules, activity |
| `app/Services/InvitationService.php` | Hashed single-use invitations |
| `app/Console/Commands/DetectSlaBreaches.php` | Cross-tenant, idempotent breach detection |
| `app/Reports/SlaComplianceReport.php` | Cached compliance figures per tenant |

## Database overview

| Table | Notes |
|---|---|
| `organizations` | Tenant; `timezone`; `ticket_sequence` (next ticket number, updated under a row lock) |
| `users` | `organization_id`, `role` (admin, agent, customer), globally unique email |
| `business_hours` | One window per ISO weekday; none means 24/7 |
| `sla_policies` | First-response and resolution targets per priority; unique `(organization_id, priority)` |
| `tickets` | Unique `(organization_id, number)`; due dates, pause state and total paused minutes; FULLTEXT `(subject, description)`; indexes for listing, assignment queues and breach detection |
| `ticket_messages` | Replies and internal notes |
| `attachments` | Stored path, sanitised original name, detected MIME type, size |
| `ticket_activities` | Append-only timeline |
| `sla_breaches` | Unique `(ticket_id, metric)` — makes detection idempotent |
| `invitations` | `token_hash` (SHA-256) unique, expiry, acceptance time |
| `notifications`, `jobs`, `failed_jobs`, `cache` | Laravel infrastructure |

Every tenant-owned table has an `organization_id` foreign key with
`ON DELETE CASCADE`, and a test fails if a model on such a table does not use
the tenancy trait.

## Getting started (Docker)

```bash
git clone https://github.com/ebrahimmorkas/helpdesk-sla-platform.git
cd helpdesk-sla-platform
cp .env.example .env                    # set DB_PASSWORD / DB_ROOT_PASSWORD

docker compose build
docker compose up -d mysql redis
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d                    # app, nginx, queue workers, scheduler
docker compose exec app php artisan migrate

# Optional: two demo tenants with two weeks of tickets
docker compose exec app php artisan db:seed --class=DemoDataSeeder
```

API: `http://localhost:8081/api/v1`. MySQL is exposed on `localhost:33062`.

Demo accounts (password from `DEMO_USER_PASSWORD`, or printed by the seeder):
`admin@northwind.test`, `agent1@northwind.test`, `customer1@northwind.test`
(and the same pattern for `contoso.test`, a second tenant in New York time).

Emails are written to `storage/logs/laravel.log` (`MAIL_MAILER=log`), which is
also where invitation tokens can be found locally.

## Environment configuration

| Variable | Meaning |
|---|---|
| `DB_*`, `DB_ROOT_PASSWORD` | MySQL (host `mysql` in Docker) |
| `CACHE_STORE=failover`, `QUEUE_CONNECTION=failover` | Redis with database fallback |
| `REDIS_TIMEOUT`, `REDIS_READ_TIMEOUT` | Seconds before a Redis call gives up (default 2) |
| `SANCTUM_TOKEN_EXPIRATION` | Token lifetime in minutes (default 7 days) |
| `DEMO_USER_PASSWORD` | Password for demo accounts |
| `APP_PORT`, `FORWARD_DB_PORT` | Host ports |

Production: `APP_ENV=production`, `APP_DEBUG=false`, a real mailer, and the
`production` Docker target (no dev dependencies, OPcache, non-root user).

## Authentication and roles

`POST /api/v1/register` creates an organisation; `POST /api/v1/auth/tokens`
logs in; `POST /api/v1/invitations/accept` joins an organisation. All other
endpoints need `Authorization: Bearer <token>`.

| Capability | Admin | Agent | Customer |
|---|:-:|:-:|:-:|
| See all tickets of the organisation | ✓ | ✓ | own only |
| Open tickets | for customers | for customers | for self |
| Set priority, assign, change status | ✓ | ✓ | |
| Internal notes, activity timeline, SLA data | ✓ | ✓ | |
| Invite users | any role | customers | |
| Manage users, SLA policies, business hours | ✓ | read SLA settings | |
| Reports | ✓ | ✓ | |

## API documentation

Full reference: [`docs/api.md`](docs/api.md).

| Area | Endpoints |
|---|---|
| Auth | `POST /register`, `POST /auth/tokens`, `DELETE /auth/tokens/current`, `GET /auth/me` |
| Users | `GET /users`, `GET /users/{id}`, `PATCH /users/{id}` |
| Invitations | `GET/POST /invitations`, `DELETE /invitations/{id}`, `POST /invitations/accept` |
| SLA settings | `GET /sla-policies`, `PUT /sla-policies/{priority}`, `GET/PUT /business-hours` |
| Tickets | `GET/POST /tickets`, `GET/PATCH /tickets/{number}`, `GET /tickets/{number}/activity`, `POST /tickets/{number}/messages` |
| Attachments | `GET /attachments/{id}` |
| Reports | `GET /reports/sla-compliance`, `GET /reports/workload` |
| Notifications | `GET /notifications`, `POST /notifications/{id}/read` |

## SLA rules

| Event | Effect |
|---|---|
| Ticket created | `first_response_due_at` and `resolution_due_at` = created + target (business minutes) |
| Priority changed | Both due dates recomputed from creation time with the new target |
| Agent public reply | Records first response; status becomes `pending` unless another status is given |
| `pending` | Resolution clock paused |
| Customer reply / leaving `pending` | Paused business minutes added; resolution due date moves |
| `resolved` | Resolution recorded; customer reply reopens |
| Resolved for 7 days | Closed automatically; closed tickets are final |
| Internal note | No effect on status or SLA |

Default targets (editable per organisation):

| Priority | First response | Resolution |
|---|---|---|
| urgent | 15 min | 4 h |
| high | 1 h | 8 h |
| normal | 4 h | 24 h |
| low | 8 h | 40 h |

## Queues, scheduler and Redis

| Queued work | Notes |
|---|---|
| Invitation email | Encrypted on the queue (`ShouldBeEncrypted`) — the payload contains the token |
| Reply and assignment notifications | Queued after the transaction commits |
| `EscalateSlaBreach` listener | Loads the ticket inside the tenant's context |
| `SlaBreachedNotification` | Mail + in-app |

Scheduler: `sla:detect-breaches` every minute, `tickets:close-resolved`
hourly, `sanctum:prune-expired` daily, `queue:prune-failed` weekly — all with
`onOneServer()`, the first two also `withoutOverlapping()`.

**Redis** is used for the queue, the SLA compliance report cache (10 minutes,
key includes the organisation id), rate limiting and scheduler locks.

**If Redis is unavailable**, cache and queue fall back to the database
(`failover` drivers; the `queue-fallback` container processes the database
queue). Verified by stopping Redis on the running stack: login, listing and an
agent reply succeeded and the fallback worker delivered the reply
notification. This check first failed with a 500 — see
`docs/technical-decisions.md` §8 for the bug and its fix. The remaining cost is
latency: every Redis call is attempted before failing over; in the
stopped-container test the reply took about 38 seconds (the missing container's
DNS lookup is slow). A refused connection fails over instantly.

## Testing

```bash
docker compose exec app php artisan test
```

79 tests on MySQL:

| Area | Examples |
|---|---|
| Tenancy | scope filters and fails closed; stamping new rows; cross-tenant tickets, users, invitations and attachments return 404; every tenant model is scoped |
| SLA engine | 14 unit tests for the calendar (weekends, timezones, DST, round trips); clock scheduling, pause/resume |
| Tickets | numbering, status rules, internal notes, assignment rules, customer isolation, closed tickets |
| Concurrency | 8 separate PHP processes open tickets simultaneously and receive numbers 1–8 without gaps or duplicates |
| Breaches | detection, idempotency across repeated runs, paused/resolved exclusion, escalation, all tenants |
| Attachments | upload/download, rejected HTML/SVG/oversized files, access control, cleanup on rollback |
| Search | FULLTEXT within tenant and customer visibility (commits real rows) |
| Reports | compliance maths, per-tenant cache isolation, workload |
| Infrastructure | Redis failover for queue, cache and notifications sent from transactions |

## Security

- Tenant isolation by fail-closed global scope, tenant-scoped route binding and
  validation rules that constrain referenced users to the same organisation.
- Every intentional scope bypass (`withoutGlobalScope`) is in a system process
  (scheduler commands) or the invitation token lookup, and is documented inline.
- Customers cannot see internal notes, their attachments, SLA data or the
  activity timeline, and cannot set priority or requester.
- Invitations: only the SHA-256 hash is stored; tokens expire and are single-use;
  the queued email is encrypted.
- Attachments: content-type validation (no HTML/SVG/scripts), random storage
  names, private disk, forced download with `nosniff`, sanitised file names.
- Login: generic errors, constant bcrypt work, rate limits; deactivated users
  are rejected on every request and their tokens revoked.
- Rate limits per user and per organisation; sign-up limited per IP.

## Known limitations

- One working window per day; no public holidays; windows cannot cross midnight.
- Changing business hours or SLA targets does not recalculate existing tickets.
- Email-to-ticket (inbound mail) is not implemented; tickets are created via the API.
- Search covers subject and description, not replies.
- Users belong to exactly one organisation (email addresses are globally unique).
- Redis failover adds latency per call during an outage (no circuit breaker).
- Not deployed to a public environment.

## Future improvements

- Holiday calendars per organisation.
- Inbound email processing and email threading.
- SLA warnings before a breach (e.g. at 75% of the target).
- Teams/groups with round-robin assignment.
- Webhooks for ticket events.
- Search across replies (Scout + Meilisearch).

## License

MIT
