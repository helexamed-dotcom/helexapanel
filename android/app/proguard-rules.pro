# The app ships no reflection-driven models, so the default shrinking rules
# cover it. Two things are pinned deliberately.

# OkHttp publishes its own rules; these silence warnings for optional
# dependencies it references but never loads on Android.
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# kotlinx.serialization generates serializers as synthetic members of the
# classes it serializes; without this they are stripped and every model fails
# to parse at runtime rather than at build time.
-keepclassmembers class ** {
    *** Companion;
}
-keepclasseswithmembers class ** {
    kotlinx.serialization.KSerializer serializer(...);
}

# Strip every log call from the release build. Not a substitute for not
# logging secrets — the code does not — but a second wall between a
# credential and logcat.
-assumenosideeffects class android.util.Log {
    public static *** v(...);
    public static *** d(...);
    public static *** i(...);
    public static *** w(...);
    public static *** e(...);
}
