package ir.helexapanel.core.design

/**
 * The panel's colours, as plain numbers.
 *
 * These are lifted verbatim from the stylesheet the website already ships, so
 * the app and the site are the same product rather than two things that
 * resemble each other. They live here — in pure Kotlin, with no Compose types
 * — for two reasons: the values are the part most likely to be mistyped, and
 * keeping them free of the UI toolkit means they can be checked by a test
 * that runs anywhere, including the contrast checks below.
 *
 * Source of truth: public_html/assets/css/app.css, `:root` and
 * `:root[data-theme="dark"]`.
 *
 * Format is 0xAARRGGBB, fully opaque unless a name says otherwise.
 */
object Palette {

    object Light {
        /** The brand blue: accents, icons, links, the logo gradient. */
        const val BLUE = 0xFF2F6BFFu

        /**
         * The blue used *behind white text* — solid buttons, filled chips.
         *
         * Measured, not chosen by eye: white on the brand blue is 4.4988:1,
         * which misses WCAG AA for body text by about a thousandth. Rather
         * than invent a colour, this is the shade the website's own stylesheet
         * already uses for `.btn-primary:hover`, and it measures 5.70:1. The
         * brand hue is unchanged everywhere it is not carrying white text.
         */
        const val BLUE_FILL = 0xFF245CE0u

        const val BLUE_SOFT = 0xFFEBF1FFu
        const val TEAL = 0xFF0D9488u
        const val TEAL_SOFT = 0xFFD7F5F0u
        const val PURPLE = 0xFF7C6CF3u
        const val PURPLE_SOFT = 0xFFEFECFEu

        /** Primary text. */
        const val INK = 0xFF0D1526u
        /** Secondary text: labels, captions. */
        const val INK_2 = 0xFF4A5772u
        /** Tertiary text: hints, metadata. */
        const val INK_3 = 0xFF8892A6u

        const val LINE = 0xFFE8EDF6u
        const val SURFACE = 0xFFFFFFFFu
        const val SURFACE_2 = 0xFFF8FAFDu
        const val CANVAS = 0xFFF4F6FBu

        const val GREEN = 0xFF16A34Au
        const val AMBER = 0xFFD97706u
        const val RED = 0xFFDC2626u
    }

    object Dark {
        const val BLUE = 0xFF5B8CFFu

        /**
         * Dark mode inverts the problem: the lifted blue is bright enough that
         * white on it is unreadable, so a filled button carries dark ink
         * instead. Kept as its own name so a screen never has to decide.
         */
        const val BLUE_FILL = 0xFF5B8CFFu
        const val ON_BLUE_FILL = 0xFF0E131Au
        const val BLUE_SOFT = 0xFF1B2A4Au
        const val TEAL = 0xFF2DD4BFu
        const val TEAL_SOFT = 0xFF123531u
        const val PURPLE = 0xFFA99BFFu
        const val PURPLE_SOFT = 0xFF241F45u

        const val INK = 0xFFE8EDF6u
        const val INK_2 = 0xFFAAB6C8u
        const val INK_3 = 0xFF7C8798u

        const val LINE = 0xFF26303Fu
        const val SURFACE = 0xFF151C26u
        const val SURFACE_2 = 0xFF1B2431u
        const val CANVAS = 0xFF0E131Au

        const val GREEN = 0xFF34D399u
        const val AMBER = 0xFFFBBF24u
        const val RED = 0xFFF87171u
    }

    /**
     * Corner radii, in density-independent pixels.
     *
     * Three sizes, same as the stylesheet: cards, controls, and the small
     * things inside controls.
     */
    object Radius {
        const val CARD = 18
        const val CONTROL = 12
        const val SMALL = 10
    }
}

/**
 * Relative luminance and contrast, per WCAG 2.1.
 *
 * Here rather than in a test because a palette that cannot be checked is a
 * palette that quietly drifts: the dark theme in particular was written by
 * hand, and "looks fine on my screen" is not a measurement.
 */
object Contrast {

    /** WCAG 2.1 relative luminance of an 0xAARRGGBB colour. */
    fun luminance(argb: UInt): Double {
        val r = channel(((argb shr 16) and 0xFFu).toInt())
        val g = channel(((argb shr 8) and 0xFFu).toInt())
        val b = channel((argb and 0xFFu).toInt())
        return 0.2126 * r + 0.7152 * g + 0.0722 * b
    }

    /** Contrast ratio between two opaque colours, from 1.0 to 21.0. */
    fun ratio(a: UInt, b: UInt): Double {
        val la = luminance(a)
        val lb = luminance(b)
        val lighter = maxOf(la, lb)
        val darker = minOf(la, lb)
        return (lighter + 0.05) / (darker + 0.05)
    }

    private fun channel(value: Int): Double {
        val c = value / 255.0
        return if (c <= 0.03928) c / 12.92 else Math.pow((c + 0.055) / 1.055, 2.4)
    }
}
