# Build

## Requirements

- Android Studio Ladybug (2024.2) or newer
- JDK 17
- Android SDK 35, build tools 35.0.0
- Network access to `dl.google.com` and `repo1.maven.org`

The last one is worth checking first. The environment this project was written
in could reach Maven Central but not Google's Maven, which is why `app/` has
never been compiled — see README.

## Debug

```bash
./gradlew :app:assembleDebug
# app/build/outputs/apk/debug/app-debug.apk
```

Installs alongside a release build: the debug variant carries the
`.debug` application id suffix.

## Core tests

```bash
./gradlew :core:test
HELEXA_BASE=https://staging.example.ir ./gradlew :core:test
```

Needs a reachable panel. See README for the fixtures they expect.

## Release

```bash
./gradlew :app:assembleRelease
```

Release turns on R8 shrinking, resource shrinking and obfuscation, and disables
debugging. Verify after any dependency change:

```bash
./gradlew :app:assembleRelease
unzip -p app/build/outputs/apk/release/app-release.apk classes.dex | strings | grep -i "password\|secret\|api_key"
```

Expect nothing. There are no secrets in this app to find — it authenticates as
the student with the student's own credentials — but the check costs a second
and catches a mistake before a store listing does.

## Signing

Create a keystore **outside** the repository:

```bash
keytool -genkey -v -keystore ~/helexa-release.jks \
  -keyalg RSA -keysize 4096 -validity 10000 -alias helexa
```

Then `~/.gradle/gradle.properties` — never the project:

```properties
HELEXA_STORE_FILE=/absolute/path/helexa-release.jks
HELEXA_STORE_PASSWORD=…
HELEXA_KEY_ALIAS=helexa
HELEXA_KEY_PASSWORD=…
```

Wire a `signingConfig` in `app/build.gradle.kts` that reads those properties and
skips signing when they are absent, so a fresh clone still builds.

Back the keystore up somewhere you will still have in five years. Losing it
means never updating this app under its current listing again.

## Pointing at a different panel

`PANEL_URL` in `app/build.gradle.kts`. Deliberately a build constant, not a
setting: a client that can be aimed at another host is a client whose session
cookie can be sent somewhere else.

A staging build should also update the deep-link host in the manifest, or links
will open the production app.

## Before release

- [ ] `./gradlew :core:test` green against the target panel
- [ ] release APK installs and signs in on a real device
- [ ] a lesson opens, and a screenshot attempt is refused
- [ ] a lesson does not appear in the recents preview
- [ ] sign out, force stop, reopen — still signed out
- [ ] airplane mode shows the offline state, not a white screen
- [ ] `strings` on the dex finds no credential
