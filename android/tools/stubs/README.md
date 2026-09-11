# Stubs

Three Android-only references the Compose layer makes, stood in for so the UI
can be type-checked on a plain JVM: the generated `R` class, `ViewModel`, and
`collectAsStateWithLifecycle`.

They are **not** compiled into the app. `app/build.gradle.kts` never sees this
directory; only `tools/typecheck-ui.sh` does, and it writes to
`build/typecheck` which is thrown away.

If a screen starts using a fourth Android-only API, add it here rather than
dropping the screen from the check.
