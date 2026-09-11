package ir.helexapanel.app.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Shapes
import androidx.compose.runtime.CompositionLocalProvider
import androidx.core.view.WindowCompat
import ir.helexapanel.app.R
import ir.helexapanel.core.design.Palette

/**
 * The panel's appearance, on Android.
 *
 * Every value comes from `core`'s Palette, which is lifted verbatim from the
 * website's stylesheet and checked by a test. Nothing is chosen here — this
 * file only maps those numbers onto Material 3's slots, so the app and the
 * site cannot drift apart by someone adjusting a colour in one of them.
 *
 * Dynamic colour is deliberately not used. Material You would repaint the app
 * in the user's wallpaper palette, which for a branded product means the
 * brand disappears on most devices.
 */

private fun UInt.toColor(): Color = Color(this.toInt())

private val LightScheme = lightColorScheme(
    primary = Palette.Light.BLUE_FILL.toColor(),   // the fill behind white text
    onPrimary = Color.White,
    primaryContainer = Palette.Light.BLUE_SOFT.toColor(),
    onPrimaryContainer = Palette.Light.BLUE.toColor(),

    secondary = Palette.Light.TEAL.toColor(),
    onSecondary = Color.White,
    secondaryContainer = Palette.Light.TEAL_SOFT.toColor(),
    onSecondaryContainer = Palette.Light.TEAL.toColor(),

    tertiary = Palette.Light.PURPLE.toColor(),
    onTertiary = Color.White,
    tertiaryContainer = Palette.Light.PURPLE_SOFT.toColor(),

    background = Palette.Light.CANVAS.toColor(),
    onBackground = Palette.Light.INK.toColor(),
    surface = Palette.Light.SURFACE.toColor(),
    onSurface = Palette.Light.INK.toColor(),
    surfaceVariant = Palette.Light.SURFACE_2.toColor(),
    onSurfaceVariant = Palette.Light.INK_2.toColor(),

    outline = Palette.Light.LINE.toColor(),
    outlineVariant = Palette.Light.LINE.toColor(),

    error = Palette.Light.RED.toColor(),
    onError = Color.White
)

private val DarkScheme = darkColorScheme(
    primary = Palette.Dark.BLUE_FILL.toColor(),
    // Dark mode inverts the problem: the lifted blue is bright, so a filled
    // button carries dark ink rather than white.
    onPrimary = Palette.Dark.ON_BLUE_FILL.toColor(),
    primaryContainer = Palette.Dark.BLUE_SOFT.toColor(),
    onPrimaryContainer = Palette.Dark.BLUE.toColor(),

    secondary = Palette.Dark.TEAL.toColor(),
    onSecondary = Palette.Dark.ON_BLUE_FILL.toColor(),
    secondaryContainer = Palette.Dark.TEAL_SOFT.toColor(),
    onSecondaryContainer = Palette.Dark.TEAL.toColor(),

    tertiary = Palette.Dark.PURPLE.toColor(),
    onTertiary = Palette.Dark.ON_BLUE_FILL.toColor(),
    tertiaryContainer = Palette.Dark.PURPLE_SOFT.toColor(),

    background = Palette.Dark.CANVAS.toColor(),
    onBackground = Palette.Dark.INK.toColor(),
    surface = Palette.Dark.SURFACE.toColor(),
    onSurface = Palette.Dark.INK.toColor(),
    surfaceVariant = Palette.Dark.SURFACE_2.toColor(),
    onSurfaceVariant = Palette.Dark.INK_2.toColor(),

    outline = Palette.Dark.LINE.toColor(),
    outlineVariant = Palette.Dark.LINE.toColor(),

    error = Palette.Dark.RED.toColor(),
    onError = Palette.Dark.ON_BLUE_FILL.toColor()
)

/**
 * The website's own face, self-hosted.
 *
 * IRANSansWeb FaNum, the same three weights the site ships. The FaNum variant
 * renders Latin digits in Persian shapes, which is why a number typed as "12"
 * still reads as ۱۲ without the app converting anything.
 */
private val IranSans = FontFamily(
    Font(R.font.iransans_regular, FontWeight.Normal),
    Font(R.font.iransans_medium, FontWeight.Medium),
    Font(R.font.iransans_bold, FontWeight.Bold)
)

/**
 * A hierarchy, not a pile of sizes.
 *
 * Line heights are generous because Persian ascenders and descenders collide
 * at Material's defaults — the website uses the same ratio for the same
 * reason.
 */
private val AppTypography = Typography(
    displaySmall = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Bold, fontSize = 26.sp, lineHeight = 38.sp),
    headlineMedium = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Bold, fontSize = 22.sp, lineHeight = 34.sp),
    headlineSmall = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Bold, fontSize = 19.sp, lineHeight = 30.sp),
    titleLarge = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Medium, fontSize = 17.sp, lineHeight = 28.sp),
    titleMedium = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Medium, fontSize = 15.sp, lineHeight = 26.sp),
    bodyLarge = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Normal, fontSize = 15.sp, lineHeight = 28.sp),
    bodyMedium = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Normal, fontSize = 14.sp, lineHeight = 26.sp),
    bodySmall = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Normal, fontSize = 12.5.sp, lineHeight = 22.sp),
    labelLarge = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Medium, fontSize = 14.5.sp, lineHeight = 24.sp),
    labelMedium = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Medium, fontSize = 13.sp, lineHeight = 22.sp),
    labelSmall = TextStyle(fontFamily = IranSans, fontWeight = FontWeight.Normal, fontSize = 11.5.sp, lineHeight = 20.sp)
)

/** The site's three radii, in the three slots Material offers. */
private val AppShapes = Shapes(
    small = RoundedCornerShape(Palette.Radius.SMALL.dp),
    medium = RoundedCornerShape(Palette.Radius.CONTROL.dp),
    large = RoundedCornerShape(Palette.Radius.CARD.dp)
)

@Composable
fun HeleXaTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit
) {
    val scheme = if (darkTheme) DarkScheme else LightScheme
    val view = LocalContext.current

    SideEffect {
        (view as? Activity)?.window?.let { window ->
            // Edge to edge, with status bar icons that contrast with whichever
            // scheme is showing.
            WindowCompat.getInsetsController(window, window.decorView)
                .isAppearanceLightStatusBars = !darkTheme
        }
    }

    /**
     * Right to left, for the whole app, unconditionally.
     *
     * Not `LayoutDirection.Rtl` inherited from the locale: the panel is a
     * Persian product and its layout is right-to-left even if a student's
     * phone is set to English. Leaving it to the system would mirror the
     * entire interface for anyone who changed their device language.
     */
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) {
        MaterialTheme(
            colorScheme = scheme,
            typography = AppTypography,
            shapes = AppShapes,
            content = content
        )
    }
}
