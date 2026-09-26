# API reference

Base URL `http://localhost:8081/api/v1`. Send `Accept: application/json`.
Authenticated endpoints need `Authorization: Bearer <token>`; everything they
return is limited to the caller's organisation.

**Errors:** `401` unauthenticated, `403` not allowed (or deactivated account),
`404` not found — also for resources of *other organisations*, `409` conflict
(closed ticket), `422` validation (`{"message", "errors": {field: [...]}}`),
`429` rate limited. Lists use Laravel pagination (`data`, `links`, `meta`),
`per_page` ≤ 100, `sort=field` / `sort=-field`.

Rate limits: login and invitation acceptance 5/min per email+IP and 20/min per
IP; sign-up 5/hour per IP; API 120/min per user and 1000/min per organisation.

---

## Sign-up and authentication

### `POST /register`
```json
{ "organization_name": "Acme Robotics", "timezone": "America/New_York",
  "name": "Ada Admin", "email": "ada@acme.test", "password": "correct-horse-42" }
```
`201` — same body as login. Creates the organisation, its admin, default SLA
policies and Monday–Friday 09:00–17:00 business hours.

### `POST /auth/tokens`
```json
{ "email": "agent1@northwind.test", "password": "…", "device_name": "laptop" }
```
`201`
```json
{
  "token": "3|…", "token_type": "Bearer", "expires_at": "2026-10-03T06:54:30+00:00",
  "user": { "id": 3, "name": "…", "email": "…", "role": "agent", "is_active": true,
            "organization": { "id": 1, "name": "Northwind Support", "slug": "northwind-support-y38ywr", "timezone": "Europe/London" } }
}
```
Wrong password, unknown email and deactivated accounts all return the same `422`.

### `DELETE /auth/tokens/current` → `204` · `GET /auth/me` → `{"data": User}`

---

## Invitations

### `POST /invitations`
Admins: any role. Agents: `customer` only.
```json
{ "email": "new.agent@northwind.test", "name": "New Agent", "role": "agent" }
```
`201` with `{id, email, name, role, expires_at, accepted_at}`. The token is
**not** returned — it is emailed to the invitee.

### `POST /invitations/accept` (public)
```json
{ "token": "<64 characters from the email>", "password": "correct-horse-42" }
```
`201` with a login token. `422` if the token is unknown, used or expired
(7 days), or the email already has an account.

### `GET /invitations` (admin) · `DELETE /invitations/{id}` (admin) → `204`

---

## Users

| Method | Path | Who |
|---|---|---|
| GET | `/users?role=agent&search=…` | staff |
| GET | `/users/{id}` | staff, or the user themselves |
| PATCH | `/users/{id}` `{name?, role?, is_active?}` | admin; cannot deactivate/demote self; deactivation revokes tokens |

---

## SLA settings

### `GET /sla-policies` (staff)
```json
{ "data": [ { "priority": "urgent", "first_response_minutes": 15, "resolution_minutes": 240 }, … ] }
```

### `PUT /sla-policies/{priority}` (admin)
`{ "first_response_minutes": 10, "resolution_minutes": 120 }` — resolution must
be ≥ first response. Unknown priority → `404`.

### `GET /business-hours` (staff) · `PUT /business-hours` (admin)
```json
{ "timezone": "Europe/London",
  "days": [ { "weekday": 1, "opens_at": "09:00", "closes_at": "17:30" }, … ] }
```
Replaces the whole schedule. `weekday` is ISO (1 = Monday). An empty `days`
list means 24/7. Existing tickets keep their due dates.

---

## Tickets

Tickets are addressed by their per-organisation **number**.

### `GET /tickets`
| Parameter | Notes |
|---|---|
| `q` | Full-text search in subject and description (min. 3 characters) |
| `status[]` | `open`, `pending`, `resolved`, `closed` |
| `priority` | `low`, `normal`, `high`, `urgent` |
| `assignee` | staff only: `me`, `none` or a user id |
| `requester_id` | staff only |
| `sort` | `created_at` (default `-created_at`), `updated_at`, `resolution_due_at`, `first_response_due_at`, `number` |

Customers only receive their own tickets.

### `POST /tickets`
Customer: `{ "subject": "…", "description": "…" }` (priority is always `normal`).
Staff: also `requester_id` (a customer of the organisation) and optional `priority`.
`201`:
```json
{
  "data": {
    "number": 28, "subject": "Export broken", "description": "CSV export fails",
    "status": "open", "priority": "normal",
    "requester": { "id": 5, "name": "…", "email": "…" }, "assignee": null,
    "sla": { "first_response_due_at": "2026-09-28T12:00:00+00:00", "first_responded_at": null,
             "resolution_due_at": "2026-09-30T16:00:00+00:00", "paused": false, "paused_minutes": 0 },
    "resolved_at": null, "closed_at": null, "created_at": "2026-09-26T06:41:32+00:00", "updated_at": "…"
  }
}
```
`sla` is only included for staff. In this example (Europe/London, Monday–Friday
09:00–17:00, normal priority) the ticket was opened on a Saturday, so both
clocks start on Monday at 09:00 BST (08:00 UTC): first response 4 business
hours later, resolution 24 business hours later.

### `GET /tickets/{number}`
Adds `messages` (customers do not receive internal notes), each with
`attachments: [{id, name, mime_type, size, download_url}]`.

### `PATCH /tickets/{number}` (staff)
`{ "status": "resolved", "priority": "high", "assignee_id": 7 }` — any subset.
`assignee_id` must be an active admin/agent of the organisation (`null` to
unassign). Changing priority recomputes due dates. `409` if the ticket is closed.

### `POST /tickets/{number}/messages`
`multipart/form-data` or JSON:

| Field | Notes |
|---|---|
| `body` | required |
| `is_internal` | staff only; internal notes never change status or SLA |
| `status` | staff only: `open`, `pending` (default after a staff reply), `resolved` |
| `attachments[]` | up to 5 files, 10 MB each: pdf, png, jpg, jpeg, gif, txt, csv, log, zip, docx, xlsx |

A customer reply to a `pending` or `resolved` ticket reopens it. `409` if closed.

### `GET /tickets/{number}/activity` (staff)
```json
{ "data": [ { "type": "created", "data": {"priority": "normal"}, "actor": {"id": 3, "name": "…"}, "created_at": "…" },
            { "type": "status_changed", "data": {"from": "open", "to": "pending"}, … },
            { "type": "sla_breached", "data": {"metric": "first_response"}, "actor": null, … } ] }
```

### `GET /attachments/{id}`
Downloads the file (`Content-Disposition: attachment`, `nosniff`). Same access
as the ticket; attachments on internal notes are staff-only.

---

## Reports (staff)

### `GET /reports/sla-compliance?from=2026-01-01&to=2026-01-31`
```json
{
  "data": {
    "from": "2026-01-01", "to": "2026-01-31", "generated_at": "…",
    "priorities": [
      { "priority": "urgent", "tickets": 3,
        "first_response": { "met": 1, "breached": 2, "compliance_percent": 33.3, "average_minutes": 33 },
        "resolution": { "met": 1, "breached": 0, "compliance_percent": 100.0 } },
      …
    ]
  }
}
```
Cached for 10 minutes per organisation and date range. Range ≤ 366 days.

### `GET /reports/workload`
Open, pending and overdue tickets per active agent, plus unassigned totals.

---

## Notifications

`GET /notifications?unread=1` and `POST /notifications/{id}/read`. Types:
`ticket_assigned`, `sla_breached`.
