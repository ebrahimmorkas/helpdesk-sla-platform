# Interview notes — Helpdesk SLA Platform

Likely interview questions with answers based on this code.

---

## Multi-tenancy

**Q: How does tenant isolation work?**
Single database, `organization_id` on every tenant-owned table. Models use the
`BelongsToOrganization` trait (`app/Tenancy`), which adds `OrganizationScope`
(a global scope: `where organization_id = current`) and fills
`organization_id` on create. `SetCurrentOrganization` middleware sets the
current organisation from the authenticated user.

**Q: What happens if someone forgets to set the tenant?**
The scope fails closed: with no organisation it adds `WHERE 1 = 0`, so queries
return nothing and creating a tenant row throws. Leaks become empty results
instead of data exposure.

**Q: Why does another tenant's ticket return 404 and not 403?**
The tenant middleware is placed before `SubstituteBindings` in the middleware
priority list, so route model binding runs through the scope. The ticket is not
found at all — the response does not even confirm it exists.

**Q: The User model is not globally scoped — isn't that dangerous?**
It is deliberate: login looks users up by email before a tenant is known. User
access is covered by `inCurrentOrganization()`, a tenant-restricted
`resolveRouteBinding()`, and validation rules scoped by organisation for
`requester_id` and `assignee_id`. `TenantModelCoverageTest` fails if any other
model with an `organization_id` column lacks the trait.

**Q: How do scheduled jobs work across tenants?**
They opt out explicitly with `withoutGlobalScope(OrganizationScope::class)`,
process each ticket, and use `CurrentOrganization::run($orgId, fn)` when they
need tenant-scoped writes (activity entries). `CurrentOrganization` is a scoped
singleton, so a queue worker never carries one job's tenant into the next.

**Q: Anything tenant-specific about caching?**
Cache keys include the organisation id (`reports:sla-compliance:org:{id}:…`).
A test checks two tenants get different figures for the same date range.

**Q: Why not database-per-tenant?**
More isolation, but per-tenant migrations and connections, and cross-tenant
jobs like breach detection become much harder. For many small tenants a shared
schema with a strict scope is the common trade-off.

## SLA engine

**Q: How do you compute a due date in business hours?**
`BusinessCalendar::addMinutes()` converts to the organisation's timezone and
walks day by day: skip days without hours, start at opening time if before it,
consume the minutes available until closing, carry the rest to the next
working day. Results are returned in UTC. It is a plain class with 14 unit tests.

**Q: How are daylight-saving changes handled?**
Windows are built from local wall-clock times each day (`setTimeFromTimeString`)
and elapsed time is real seconds, so a day when clocks change still has the
right window. A test adds 120 business minutes across the 29 March 2026 change
in London and checks the UTC result.

**Q: How does pausing work?**
Status `pending` means waiting on the customer; `sla_paused_at` is set. When
the ticket leaves `pending`, the business minutes between pause and resume are
added to `sla_paused_minutes`, and `resolution_due_at` is recomputed as
`created_at + target + paused minutes`. Waiting over a weekend adds nothing.
The first-response clock never pauses.

**Q: What if the priority changes?**
`SlaClock::schedule()` recomputes both due dates from creation time with the
new target, still including paused minutes. Because due dates are derived, not
shifted incrementally, they cannot drift.

**Q: How are breaches detected, and how do you avoid duplicate alerts?**
`sla:detect-breaches` runs every minute across all tenants, finds overdue
unmet targets (excluding paused, resolved and closed tickets for resolution),
and calls `insertOrIgnore` into `sla_breaches`, which has a unique
`(ticket_id, metric)` key. Only the insert that actually created the row
dispatches `SlaBreached`. Repeated or overlapping runs cannot double-alert.

**Q: Who gets notified?**
The queued `EscalateSlaBreach` listener notifies the assignee; if the ticket is
unassigned (or the assignee is deactivated), all active admins of that
organisation.

## Laravel and PHP

**Q: Which parts are services and why?**
`TicketService` (lifecycle rules used by controllers, the seeder and the
parallel-process test), `SlaClock` and `BusinessCalendar` (SLA maths),
`InvitationService`, `OrganizationRegistrar`, `AttachmentStore`. Controllers
validate, authorise and delegate.

**Q: Where are policies used?**
`TicketPolicy` (view/reply/update/viewInternals: staff vs requester) and
`UserPolicy`. Tenancy is not in policies — the scope has already removed other
tenants' data before a policy runs.

**Q: What PHP/Laravel features appear?**
Backed enums with behaviour (`TicketPriority::defaultTargets()`,
`Role::isStaff()`), `Rule::enum()->only()`, `prohibited` rules for customers,
readonly promoted properties, model attributes (`#[Fillable]`), command
attributes, `insertOrIgnore`, `whereFullText`, scoped singletons, middleware
priority, `ShouldBeEncrypted`, `DB::afterCommit`.

## Database

**Q: How are ticket numbers generated without duplicates?**
Per-organisation counter `organizations.ticket_sequence`, incremented while the
organisation row is locked `FOR UPDATE` in the same transaction as the ticket
insert, plus a unique `(organization_id, number)` index. Eight parallel PHP
processes get exactly 1–8.

**Q: Which indexes matter?**
`(organization_id, status, priority)` and `(organization_id, assignee_id, status)`
for queues; `(organization_id, created_at)` for the default list (added after
`EXPLAIN` showed a filesort); breach-scan indexes on the due/met columns;
FULLTEXT `(subject, description)`; unique keys that encode business rules.

**Q: Why did search tests not use RefreshDatabase?**
InnoDB updates FULLTEXT indexes only when a transaction commits, so rows
inside RefreshDatabase's transaction are invisible to `MATCH … AGAINST`. The
test commits real rows and deletes them afterwards.

## API and security

**Q: What can customers not do?**
See other customers' tickets (403), set priority or requester (422,
`prohibited`), post or see internal notes and their attachments, see SLA data
or the activity timeline, list users or read reports.

**Q: How are invitations secured?**
64-character random token; only its SHA-256 hash is stored; expires after 7
days; single use (checked under a row lock); the queued email is encrypted
because its payload contains the token. Agents can only invite customers.

**Q: How are uploads secured?**
Content-based `mimes` validation (no HTML/SVG/scripts), size and count limits,
random storage names on the private disk, authorised download that follows
ticket visibility, forced `attachment` disposition with `nosniff`, sanitised
original names, and cleanup of written files if the transaction fails.

## Queues and reliability

**Q: Tell me about a bug you found.**
With Redis stopped, an agent reply was saved but returned 500. The
notifications used `afterCommit()`; the Redis queue defers its push to commit
time, which happened outside the failover queue's try/catch, so the fallback
to the database queue never ran. I reproduced it in a test by pointing Redis at
a closed port, then changed the services to queue notifications with
`DB::afterCommit()`, so the push goes through the failover driver after commit.

**Q: What does Redis failure cost now?**
Requests keep working (cache and queue fall back to the database, a second
worker drains the database queue), but every call still tries Redis first; with
an unreachable host that adds a timeout per call. A circuit breaker would fix
that.

## Testing

**Q: What is tested?**
79 tests on MySQL: tenancy (scope, 404s across tenants, coverage guard), the
calendar (unit), SLA clock, ticket rules, concurrency (parallel processes),
breach detection and idempotency, attachments (including rollback cleanup),
search, reports and cache isolation, Redis failover.

**Q: What would you test next?**
Load testing the breach detector with many tenants, and property-based tests
for the calendar with random schedules.

## Deployment and scaling

Build the `production` Docker target; run PHP-FPM, two queue workers (Redis and
database fallback) and the scheduler as separate processes; `php artisan
migrate --force`, `config:cache`, `route:cache`. Not deployed publicly.
Stateless API containers scale horizontally; the scheduler is safe with
multiple instances because of `onOneServer()` and idempotent inserts.
