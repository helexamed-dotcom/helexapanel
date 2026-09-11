/**
 * Pure Kotlin, no Android.
 *
 * Everything that decides something lives here — what a valid phone number is,
 * what each failure means, when a code may be resent — so it can be tested on
 * a plain JVM against a running copy of the panel. The Android module above is
 * deliberately left with nothing to decide.
 */
plugins {
    alias(libs.plugins.kotlin.jvm)
    alias(libs.plugins.kotlin.serialization)
}

kotlin {
    jvmToolchain(17)
}

dependencies {
    implementation(libs.okhttp)
    implementation(libs.kotlinx.serialization.json)
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-core:1.9.0")
}

sourceSets {
    named("main") { kotlin.srcDir("src/main/kotlin") }
    named("test") { kotlin.srcDir("src/test/kotlin") }
}
