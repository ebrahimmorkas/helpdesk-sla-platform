# Technical decisions

## 1. Single database, `organization_id` column, fail-closed global scope

**Options considered**

| Approach | Why not chosen |
|---|---|
| Database per tenant | Strongest isolation, but migrations, connections and cross-tenant jobs (breach detection) multiply operational work. Overkill for a product with many small tenants. |
| Schema per tenant | Same trade-offs; MySQL has no real schema/database distinction. |
| Package (e.g. stancl/tenancy) | Would hide the mechanism this project is meant to demonstrate; the needed part is small. |

**Design.** Tenant-owned models use `BelongsToOrganization`, which adds
`OrganizationScope` and stamps `organization_id` on create. The current
organisation lives in `CurrentOrganization`, a **scoped** singleton (reset per
request and per queued job, which matters for long-running workers).

**Fail closed.** When no organisation is set, the scope adds `WHERE 1 = 0`.
A route accidentally registered without the `tenant` middleware, or a job that
forgot to set the context, returns nothing instead of every tenant's data.
Code that must work across tenants (the scheduler commands, the invitation
token lookup) opts out explicitly with `withoutGlobalScope(OrganizationScope::class)`.

**Route binding.** `SetCurrentOrganization` is inserted into the middleware
priority list *before* `SubstituteBindings`, so `{ticket}` is resolved through
the scope and another tenant's ticket number is a `404`, not a `403` — the
response does not even confirm it exists.

**Users are the exception.** Login must find a user by email before any tenant
is known, so `User` is not globally scoped. Instead: `inCurrentOrganization()`
for listings, `resolveRouteBinding()` restricted to the current organisation,
and validation rules (`requester_id`, `assignee_id`) constrained by
`organization_id`. A test (`TenantModelCoverageTest`) fails if any other model
on a table with `organization_id` lacks the trait.

**Cache keys include the tenant.** The SLA report cache key contains the
organisation id; a tenant-agnostic key would serve one tenant's numbers to
another (tested).

## 2. Business-time arithmetic in a pure class

`BusinessCalendar` knows nothing about Eloquent: it takes a timezone and a
weekday → `[opens, closes]` map. That keeps the hardest logic unit-testable
without a database (14 tests including DST and add/measure round trips).

It walks day by day in *local* time and uses real elapsed seconds inside each
window, so a day on which clocks change is handled by Carbon, and results are
returned in UTC for storage. A day-by-day walk is O(days) — trivial for SLA
targets measured in hours or days; a 10-year guard stops misconfiguration from
looping forever.

Limitations accepted for now: one window per day, no windows across midnight,
no holidays.

## 3. Due dates are derived, not incrementally adjusted

```
first_response_due_at = created_at + first_response_minutes          (business time)
resolution_due_at     = created_at + resolution_minutes + paused_minutes
```

`SlaClock::schedule()` can recompute both at any time. Pausing records
`sla_paused_at`; resuming adds the *business* minutes between pause and resume
to `sla_paused_minutes` and recomputes. Because the formula is always the same,
a priority change after a pause keeps the paused time, and there is no drift
from repeatedly shifting a date. (`sla_paused_minutes` was added in a later
migration when this became clear.)

The first-response clock never pauses — a ticket can only be waiting on the
customer after an agent has replied.

## 4. Per-tenant ticket numbers under a row lock

Customers talk about "ticket #1042", so numbers are sequential per
organisation. `organizations.ticket_sequence` is incremented while the
organisation row is locked (`SELECT … FOR UPDATE`), inside the transaction that
inserts the ticket. Concurrent ticket creation in one tenant is serialised for
a few milliseconds; other tenants are unaffected. A unique
`(organization_id, number)` index backs it up. `ConcurrentTicketNumberTest`
runs 8 PHP processes at once and asserts numbers 1–8 with no gaps or
duplicates.

Rejected: `MAX(number) + 1` (races), a global auto-increment (leaks volume
across tenants and is not sequential per tenant).

## 5. Idempotent breach detection

The detector runs every minute over all tenants. Each missed target is inserted
into `sla_breaches` with `insertOrIgnore`; the unique `(ticket_id, metric)` key
means only the run that actually inserted the row announces the breach. So
overlapping runs, retries after a crash, or two scheduler hosts cannot send
duplicate alerts. `withoutOverlapping()` and `onOneServer()` reduce wasted work
but are not what correctness relies on.

The query excludes tickets that already have a breach of that metric
(`whereDoesntHave`) and is processed with `chunkById`. Indexes on
`(first_responded_at, first_response_due_at)` and `(resolved_at, resolution_due_at)`
support the cross-tenant scan.

## 6. Queued work and the tenant context

Queued jobs have no request and therefore no tenant. Two rules:

- Events carry ids *and* the organisation id (`SlaBreached`), and the listener
  runs inside `CurrentOrganization::run()`.
- Notifications only read attributes of models they were given (ticket number,
  subject, priority); `SerializesModels` restores those models without global
  scopes, so no tenant context is needed to send them.

The invitation email contains the plain token, so the notification implements
`ShouldBeEncrypted`; the token is never readable in Redis or the `jobs` table.

## 7. Attachments

- Validated with `mimes`, which checks the detected content type, not only the
  extension. HTML, SVG and scripts are not accepted (stored XSS risk).
- Stored with random names under `organizations/{id}/tickets/{id}/` on the
  private disk; the original name is metadata only and is sanitised for
  `Content-Disposition`.
- Served only through a controller that authorises via the ticket, always as a
  download with `nosniff`.
- File writes are not transactional. `TicketService::reply()` records each
  path as soon as it is written and deletes them if the database transaction
  fails (tested by failing on the second of two files).

## 8. Notifications after commit, and a Redis failover bug

Replies and assignments send notifications. They must not be sent if the
transaction rolls back, so they are queued after commit.

The first version used the notification's own `afterCommit()`. An end-to-end
test with Redis stopped showed a reply being **saved but returning 500**. The
cause: with `afterCommit()`, `RedisQueue::push()` does not push — it registers a
callback that runs at commit. The `failover` queue only sees the registration
succeed, so the real push (and its failure) happens outside the failover's
error handling.

Fix: the services register `DB::afterCommit(fn () => $user->notify(...))`.
At that point no transaction is open, the push happens immediately inside the
failover driver, and it falls back to the database queue. A regression test
reproduces the outage by pointing Redis at a closed port.

## 9. Full-text search with MySQL FULLTEXT

A `FULLTEXT (subject, description)` index with `whereFullText` in natural
language mode (user input is never parsed as boolean operators). Good enough
for a helpdesk's own tickets and requires no extra service. Trade-offs: no
stemming or typo tolerance, minimum word length 3, and InnoDB only updates the
index at commit — which is why the search test commits real rows instead of
using `RefreshDatabase`. Scout + Meilisearch would be the next step, also to
include replies.

## 10. Rate limiting per user and per organisation

The API limiter returns two limits: 120/min per user and 1000/min per
organisation, so a single noisy tenant cannot degrade the shared deployment.
Sign-up is limited per IP; login and invitation acceptance per email+IP and per IP.

## 11. Indexes driven by query plans

`EXPLAIN` of the default listing (`WHERE organization_id = ? ORDER BY created_at DESC, id DESC`)
showed a filesort over every ticket of the tenant. Adding
`(organization_id, created_at)` changed it to a backward index scan (InnoDB
appends the primary key, which serves the `id` tie-breaker).

## 12. Scalability notes

- API containers are stateless; tenancy context is per request.
- The breach detector's cost grows with *overdue unbreached* tickets, not with
  all tickets, because breached tickets are excluded.
- Very large tenants could be moved to their own database later; all tenant
  access already goes through one scope and one context class.
- Report queries could move to a read replica.
