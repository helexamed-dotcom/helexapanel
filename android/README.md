# HeleXa Med — Android client

A student client for the HeleXa Med panel. It signs in with the panel's own
accounts, reads the panel's own data, and renders the panel's own lessons — it
is a second face on one system, not a second system.

## State of this repository

Read this first; it decides what you can trust.

| Layer | Status |
|---|---|
| `core/` — phone rules, models, errors, session, HTTP, auth flows, palette | **Compiled, 106 checks pass** against a running panel |
| PHP mobile API (`/api/mobile/*`) | **39 checks pass** against a real MariaDB with real sessions |
| PHP JSON login branch | **Tested**, including proof the website's form is unchanged |
| `LoginViewModel` | **Type-checked** against the real core API (androidx stubbed) |
| Android manifest, resources, fonts, Gradle, R8 | **Validated** — XML well-formed, every resource reference resolves, fonts carry all Persian glyphs |
| `app/` — Compose screens, viewer, secure store | **Written, not compiled** |
| APK build, on-device QA, APK inspection | **Not done** |

The last two rows are not an oversight. The environment this was built in has
a Kotlin compiler and Maven Central, but no Android SDK and no access to
Google's Maven repository, so anything depending on `androidx` cannot be
compiled here. Everything that could be verified without it was moved into
`core/` and verified; the Android layer is deliberately thin for that reason.

**Before shipping: open this in Android Studio, build it, and fix whatever the
compiler finds in `app/`.** Expect small things — an import, a nullable, an API
level. The logic they wrap is already tested.

## Layout

```
core/    pure Kotlin, no Android. Everything that decides something.
         Runs on a plain JVM, which is how it is tested.
app/     the Android shell: Compose screens, the content viewer, storage.
         Holds no rules of its own.
```

The split is not ceremony. It is what let 82 checks run against a real server
in an environment that cannot build an APK, and it is what will let them keep
running in CI without an emulator.

## Running the core tests

They talk to a real panel, because a mock would agree with whatever the test
assumed — which is the bug worth catching.

```bash
# against a local copy
./gradlew :core:test

# against somewhere else
HELEXA_BASE=https://staging.example.ir ./gradlew :core:test
```

They expect two accounts to exist (`balinstudent`, `balinadmin`) and they sign
out after themselves, because the panel allows one device per account and a
suite that left a session open would break the next one.

## Building

See `BUILD.md`. Short version: Android Studio Ladybug or newer, JDK 17,
`./gradlew :app:assembleDebug`.

Release builds need a keystore that is **not** in this repository and must
never be.

## Pointing it at a panel

`PANEL_URL` in `app/build.gradle.kts`. It is a build constant rather than a
setting on purpose: a client that can be aimed at another host is a client
whose session cookie can be sent somewhere else.

## Documents

- `ARCHITECTURE.md` — why the split, why a WebView for content, how the session works
- `SECURITY.md` — what is protected, how, and what is not
- `BUILD.md` — toolchain, variants, signing
- `API.md` — every endpoint the app calls, with request and response shapes

## What is still to write

The Compose screens themselves: dashboard, courses, course detail, schedule,
exams, notifications, profile, and the navigation graph that joins them. The
login screen's state machine is written and type-checked; its Composable is
not.

The shape they should follow is settled and documented in `ARCHITECTURE.md`:
one `ViewModel` per screen exposing a sealed `UiState`, no screen touching the
network directly, and every screen able to render loading, empty, error,
offline and unauthorized — the components for which are in
`ui/components/States.kt`.

Push notifications and deep-link handling beyond the manifest filter are also
outstanding. Both need a decision that is the operator's: a Firebase project
for the first, and an `assetlinks.json` served from the panel's domain for the
second.
