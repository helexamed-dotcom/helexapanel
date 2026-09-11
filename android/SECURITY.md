# Security

What this app protects, how, and — just as importantly — what it does not
protect. A security document that only lists wins is a document that will get
someone hurt.

## The thing being protected

The panel's teaching material: notes, summaries, question banks, mind maps and
the Balin clinical cases. All of it is **HTML**, not PDF or video. There is no
file in this system that a student could be handed; there are documents that a
student can be shown.

That shapes everything below. "Stopping file extraction" is not the problem,
because there are no files to extract. The problem is stopping a *rendered
lesson* from leaving the screen it was rendered on, and stopping anything
usable from being left behind afterwards.

## How content reaches the screen

```
student opens a lesson
   -> app asks the panel for the viewer page (session cookie)
   -> panel checks: authenticated? enrolled? course published? content published?
   -> panel mints a viewer token: single use, seconds long,
      bound to this session, this user agent, this IP prefix
   -> document streams to the WebView
   -> nothing is written to disk
```

The token is the server's, not the app's. It was already in the panel before
this app existed, and the app does not weaken it: the mobile API deliberately
returns a **viewer path**, never a file path and never markup, so there is no
way for a client to fetch a lesson except through the checks above.

## What is closed, and where

| Risk | Closed by | File |
|---|---|---|
| Screenshot, screen recording | `FLAG_SECURE`, set before content attaches | `ContentViewerActivity` |
| Lesson visible in recents preview | `FLAG_SECURE` + `excludeFromRecents` | `ContentViewerActivity`, manifest |
| Download to public storage | `DownloadListener` that does nothing | `ContentViewerActivity` |
| Copy into another app | long-press selection disabled | `ContentViewerActivity` |
| Navigation off the panel | host check on navigation **and** subresources | `PanelOnlyClient` |
| Local file access from a document | `allowFileAccess`, `allowContentAccess`, both file-URL flags off | `ContentViewerActivity` |
| Script injected over http | `MIXED_CONTENT_NEVER_ALLOW`, cleartext disabled app-wide | viewer, `network_security_config` |
| Cached copy on disk | `LOAD_NO_CACHE`, DOM storage off, database off, cleared on destroy | `ContentViewerActivity` |
| Man in the middle | SSL errors cancel and close, never proceed | `PanelOnlyClient` |
| Session stolen from disk | AES-256-GCM under a hardware keystore key | `SecureSessionStore` |
| Session copied via backup or adb | `allowBackup=false`, explicit extraction excludes | manifest, `data_extraction_rules` |
| Credential in logcat | nothing logs one; `Log.*` stripped by R8 in release | `proguard-rules.pro` |
| Secret in the APK | there are none — see below | — |

## Secrets

There are none in the APK, and there is nothing to add. The app holds no API
key, no shared password and no server credential: it authenticates as the
student, using the student's own session, obtained by the student typing their
own password or receiving their own code.

The SMS gateway key, the database password and the application key all live on
the server and never reach a client. `BuildConfig.PANEL_URL` is a hostname, not
a secret.

The signing keystore is excluded by `.gitignore`. A keystore in version control
is a keystore that belongs to everyone who has ever cloned the repository.

## The session

Cookie-based, because that is what the panel uses; there is no token API and
inventing one would have meant changing a backend the website depends on.

Two consequences the code has to respect:

**The fingerprint is the User-Agent and the Accept-Language.** The panel hashes
those two headers and re-checks the hash on every request; a mismatch destroys
the session as a suspected theft. So `DeviceIdentity` is frozen and carries no
app version, OS version or device model — an OS update would otherwise sign out
every user at once. A test asserts it has not drifted.

**One device per account, by the operator's choice.** Signing in on the app ends
the session on the website and the reverse. The app does not try to work around
this; it reports it as its own outcome (`SignInOutcome.OtherDevice`) because it
is a decision the student has to make, not an error to retry.

Thirty-day persistence comes from the panel's existing remember-me handle, which
is revoked on sign-out and on any password change. It does not bypass the
one-device rule: a restore that would violate it is refused server-side.

## What this does **not** protect against

Stated plainly, because the alternative is someone believing otherwise:

- **A photograph of the screen.** `FLAG_SECURE` stops the device capturing
  itself. It cannot stop a second phone.
- **A rooted device.** Code running as this app can read the decrypted session
  from memory and the rendered DOM from the WebView. The encrypted store raises
  the cost of *offline* extraction — a pulled backup, a stolen phone, an adb
  dump — not of an attacker who already controls the runtime.
- **A determined reverse engineer.** R8 shrinks and obfuscates; it does not make
  the app unreadable. There is nothing in it worth extracting anyway.
- **Content reuse by a legitimate student.** Someone entitled to read a lesson
  can transcribe it. No client-side measure changes that.

The goal is that casual extraction is impractical, offline reuse is impossible,
and nothing usable is left on the device. Not that extraction is impossible.

## Backend changes made for this app

Two, both additive, both verified not to alter the website:

1. **`/api/mobile/*`** — read-only JSON, behind the same authentication, role
   and password-change middleware as the student pages. Returns no lesson body,
   no storage path and no password hash.

2. **A JSON branch on `POST /login`** — taken only when the request carries
   `X-Requested-With`, which the website's own form does not send. The form
   still posts and redirects exactly as before; the browser suite confirms it.

No existing route, controller or repository method was changed.

## Reporting

A security problem in the panel or the app should go to the operator directly,
not into a public issue tracker.
