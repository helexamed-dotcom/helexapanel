# Architecture

## The one decision everything follows from

The panel authenticates with a **session cookie bound to a device
fingerprint**. It has no token API, and adding one would have meant changing a
backend the website depends on, for a problem the app can solve by speaking the
protocol that already exists.

So the app holds a cookie jar and sends two frozen headers. That is the whole
auth design, and three things follow from it:

- `DeviceIdentity` can never change. The fingerprint is `hash(User-Agent +
  Accept-Language)`, re-checked every request; a drift signs out every
  installed copy at once. It carries no version number for that reason, and a
  test asserts it.
- The CSRF token must be primed from a rendered page before the first post,
  because the panel has no endpoint that hands one out.
- One device per account is a real constraint, not an error to retry. It gets
  its own outcome type so no screen can mistake it for a failed password.

## The split

```
core/   pure Kotlin. No Android imports anywhere.
app/    Compose, WebView, encrypted storage. No rules.
```

`core/` holds everything that decides something: what a valid phone number is,
what each HTTP status means, when a code may be resent, how many digits it has,
what the palette is. `app/` draws.

This is not architecture for its own sake. It is what allowed 82 checks to run
against a real server in an environment with no Android SDK — and it is what
will let them run in CI without an emulator. A rule that lives in a Composable
is a rule nobody can test.

## Why the content viewer is a WebView

Because the content is HTML.

The panel's material is authored as documents with their own styling and
scripts: notes, question banks, mind maps, and the Balin case player that
reveals a clinical conversation one message at a time. Rendering that natively
would mean writing a second HTML renderer and watching it disagree with the
website on the first lesson that used a table.

Everything *around* the content is native — lists, navigation, schedule,
notifications, sign-in — because those are data, and data deserves a real
interface. The WebView is one screen, and it is locked down: see `SECURITY.md`.

## Why the mobile API returns no HTML

A lesson reaches the screen through the panel's viewer, which mints a
single-use token bound to the session, the user agent and the IP prefix, and
expires it in seconds. If `/api/mobile/courses/{uuid}` returned the lesson
body, every one of those checks would be bypassed and the content would become
cacheable on the device.

So a content item carries a **`viewer_path`**. The app opens it in the viewer,
which mints the token. The app never sees a file path, because there is nothing
it could correctly do with one.

## Errors

`ApiError` is a sealed set. Every case has a Persian message a student can act
on, and the server's own wording wins wherever it sent one — it knows how many
attempts remain and how long a cooldown runs.

Two cases exist because collapsing them would mislead:

- `Unreadable` vs `Server` — a reply that did not parse usually means the app
  is behind a captive portal, not that the panel broke. Retrying will not help
  until that changes, and the message says so.
- `StaleToken` vs `Unauthorized` — a rotated CSRF token is recoverable by
  re-priming. Being signed out is not. Treating them alike would sign students
  out for a recoverable condition.

## What is not built yet

The Compose screens and the navigation graph are written but were never
compiled — no Android SDK in the build environment. The shape is settled: a
bottom bar over Home, Courses, Schedule, Notifications and Profile, each screen
a `ViewModel` exposing a sealed `UiState` with loading, empty, error, offline
and unauthorized cases, and no screen reaching the network directly.

Push notifications and deep-link handling beyond the manifest filter are not
built. Both need decisions that are the operator's: a Firebase project for the
first, and a `assetlinks.json` on the panel's domain for the second.
