package ir.helexapanel.core.util

/**
 * Digit shaping for display.
 *
 * The panel's font is IRANSans FaNum, whose Latin digits already render in
 * Persian shapes, but text this app composes itself — countdowns, counts,
 * dates — goes through here so a number never appears in two different
 * scripts on one screen.
 */
object PersianDigits {

    private const val ZERO = '۰'

    fun format(value: String): String =
        value.map { if (it in '0'..'9') ZERO + (it - '0') else it }.joinToString("")

    fun format(value: Int): String = format(value.toString())

    fun toLatin(value: String): String = value.map {
        when (it) {
            in '۰'..'۹' -> '0' + (it - '۰')
            in '٠'..'٩' -> '0' + (it - '٠')
            else -> it
        }
    }.joinToString("")

    /** Keeps only digits, in Latin form — what a code field should submit. */
    fun digitsOnly(value: String): String = toLatin(value).filter(Char::isDigit)
}
