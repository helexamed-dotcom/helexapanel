package ir.helexapanel.core.util

/**
 * Iranian mobile numbers, reduced to one shape.
 *
 * This mirrors the server's Phone service exactly, and it has to: the column
 * behind it carries a UNIQUE index, so if the app and the server disagreed
 * about what "the same number" means, one student could end up with two
 * accounts — or be told their own number is already taken.
 *
 * The canonical form is 09xxxxxxxxx, which is what the panel has always
 * stored and what every existing row is already in.
 */
object PhoneNumber {

    private val PERSIAN_DIGITS = '۰'..'۹'
    private val ARABIC_DIGITS = '٠'..'٩'

    /** Separators people actually type, plus the direction marks that ride along with a paste. */
    private val NOISE = setOf(' ', '\t', '-', '.', '(', ')', '‌', '‎', '‏')

    private val COUNTRY_PREFIXES = listOf("+98", "0098", "98")

    /** @return the canonical 09xxxxxxxxx form, or null when this is not an Iranian mobile number */
    fun normalize(raw: String): String? {
        var value = raw.map(::toLatinDigit).filterNot(NOISE::contains).joinToString("")

        if (value.isEmpty()) return null

        // Longest prefix first: "0098…" must not be matched as "98" and keep a stray zero.
        for (prefix in COUNTRY_PREFIXES) {
            if (value.startsWith(prefix)) {
                value = value.substring(prefix.length)
                break
            }
        }

        // What survives is either 9xxxxxxxxx or 09xxxxxxxxx.
        if (value.length == 10 && value.startsWith('9')) {
            value = "0$value"
        }

        return if (value.length == 11 && value.startsWith("09") && value.all(Char::isDigit)) value else null
    }

    fun isValid(raw: String): Boolean = normalize(raw) != null

    /**
     * Partly hidden, for screens where the number names an account without
     * needing to be readable across a lecture hall.
     */
    fun mask(normalized: String): String? =
        if (normalize(normalized) == normalized) {
            normalized.take(4) + "***" + normalized.takeLast(4)
        } else {
            null
        }

    /** Persian and Arabic-Indic digits arrive whenever a number was copied out of another app. */
    private fun toLatinDigit(c: Char): Char = when (c) {
        in PERSIAN_DIGITS -> '0' + (c - '۰')
        in ARABIC_DIGITS -> '0' + (c - '٠')
        else -> c
    }
}
