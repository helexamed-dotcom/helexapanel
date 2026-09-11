# API

Every endpoint the app calls. Two families: the panel's own auth routes, which
predate this app and are shared with the website, and `/api/mobile/*`, which
was added for it.

All responses are JSON. All of them require the session cookie except the two
marked *guest*. All state-changing calls require `X-CSRF-Token`.

## Headers, on every request

```
User-Agent:       HeleXaApp/1 (Android)      # frozen — see SECURITY.md
Accept-Language:  fa-IR,fa;q=0.9             # frozen
X-Requested-With: XMLHttpRequest             # makes refusals JSON, not redirects
Cookie:           HLX_SID=…; HLX_REMEMBER=…
X-CSRF-Token:     …                          # state-changing calls
```

`X-Requested-With` is load-bearing. Without it the panel answers a browser: a
302 to the login page instead of a 401 a client can act on.

## Authentication

### `POST /login` — *guest*

```
identifier=09123456789   # or a username
password=…
remember=1
```

```json
{ "ok": true, "redirect": "/student" }
```

```json
{ "ok": false, "code": "INVALID_CREDENTIALS", "message": "نام کاربری یا رمز عبور نادرست است." }
```

| Status | Meaning |
|---|---|
| 200 | signed in; `redirect` says where — `/admin` means this is not a student |
| 401 | wrong credentials, locked, or inactive |
| 403 | `code: SINGLE_DEVICE` — signed in elsewhere |
| 419 | CSRF token stale |
| 422 | a field was empty |

The JSON shape is returned **only** when `X-Requested-With` is present. Without
it this route behaves exactly as it always has, for the website.

### `POST /auth/request-otp` — *guest*

```
phone=09123456789
```

```json
{ "ok": true, "message": "کد تأیید پیامک شد.", "retry_after": 60, "expires_in": 120, "code_length": 6 }
```

`code_length` is not a constant. Codes this panel mints are six digits; codes
minted by MeliPayamak's one-time-code service are whatever that account is set
to, and its documented sample is ten. **Size the input from this field.**

429 carries `retry_after`, which is a wait rather than a failure.

### `POST /auth/verify-otp` — *guest*

```
phone=09123456789
code=123456
remember=1
```

Same success shape as `/login`. Creates the account if the number is new and
self-registration is enabled, so this one call is both sign-in and sign-up.

### `POST /logout`

Ends the session server-side. The client clears local storage regardless of the
result — "sign out" must not mean "sign out unless the wifi is bad".

## Mobile API

All under `/api/mobile`, all `GET` unless noted, all student-only.

### `/me`

```json
{
  "ok": true,
  "user": {
    "uuid": "…", "full_name": "…", "username": "…",
    "mobile": "09123456789", "phone_verified": true,
    "has_password": true,
    "university": "…", "major": "…",
    "avatar_url": "/account/avatar/…",
    "joined_at": "2026-02-11 09:12:00", "joined_label": "۱۴۰۴/۱۱/۲۲"
  },
  "unread": { "notifications": 3, "messages": 1 }
}
```

`has_password` is a boolean. The hash is never selected, let alone sent.

### `/dashboard`

`today` (classes, sorted by start), `exams` (next five), `courses`, `unread`.

### `/courses` and `/courses/{uuid}`

The detail returns `sections` and `contents`. Each content carries a
**`viewer_path`**, never a file path and never markup — the viewer is what
mints the per-open token, so a direct path would have no token to mint.

An unenrolled course answers **404**, not 403: "exists but not yours" and "does
not exist" must be indistinguishable, or the API becomes a way to enumerate the
catalogue.

### `/schedule`

`weekdays` (Persian names, Saturday first) and `days` — seven arrays, indexed to
match, each sorted by start time.

### `/exams?kind=final|midterm|quiz|practical|other`

### `/calendar?year=1404&month=11`

One Jalali month of `events` and `exams`. Year and month are clamped server-side;
a crafted `?month=99` falls back to the current month rather than walking off
the grid.

### `/notifications` and `POST /notifications/{id}/read`

## Dates

Every date appears twice:

```json
{ "date": "2026-05-02", "date_label": "۱۴۰۵/۰۲/۱۲" }
```

`date` sorts and compares; `date_label` is what a student reads. Converting on
the device would mean shipping a second Jalali implementation and having it
disagree with the website's.

## Errors

| Status | Client meaning |
|---|---|
| 401 | session gone — sign in again |
| 403 | signed in, not allowed (or `SINGLE_DEVICE` on login) |
| 404 | not found, or not yours |
| 419 | CSRF stale — re-prime the token and retry once |
| 422 | validation; `message` is already in Persian |
| 429 | rate limited; honour `retry_after` |
| 5xx | server; never show the student why |

The server's own `message` is preferred over anything the client could invent:
it knows how many attempts remain and how long a cooldown has left to run.
