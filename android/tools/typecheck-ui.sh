#!/usr/bin/env bash
# Type-checks the Compose layer without an Android SDK.
#
# Android's own Compose artifacts live on Google's Maven, which is not always
# reachable. Compose Multiplatform publishes the same API under the same
# androidx.compose.* package names to Maven Central, so the UI can be
# type-checked against those instead. It proves the code compiles; it does not
# replace building the real APK, which CI does.
#
# The three Android-only references the UI makes — R, ViewModel and
# collectAsStateWithLifecycle — are stubbed in tools/stubs/.
set -euo pipefail
cd "$(dirname "$0")/.."

LIB="${HELEXA_LIBS:-/tmp}"
ECP=$(cat "$LIB/ecp.txt")
CMP=$(cat "$LIB/cmp.txt")
DEPS=$(cat "$LIB/deps.txt")

java -cp "$ECP" org.jetbrains.kotlin.cli.jvm.K2JVMCompiler \
  tools/stubs/*.kt \
  $(find app/src/main/java -name '*.kt' -not -name 'MainActivity.kt' \
       -not -path '*/content/*' -not -name 'HeleXaApplication.kt' \
       -not -name 'SecureSessionStore.kt' -not -name 'Theme.kt') \
  $(find core/src/main -name '*.kt') \
  -d build/typecheck -cp "$LIB/kotlin-stdlib.jar:$CMP:$DEPS" \
  -Xplugin="$LIB/cmp/compose-plugin-2420.jar" \
  -Xplugin="$LIB/libs/kser-plugin.jar" \
  -nowarn
