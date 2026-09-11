<?php
declare(strict_types=1);

namespace HeleXa\Services;

/**
 * Iranian mobile numbers, reduced to one shape.
 *
 * The same SIM is typed half a dozen ways — +98, 0098, a leading 9, Persian
 * digits pasted from a bank SMS, spaces and dashes from a contact card. All of
 * them must resolve to the single form stored in users.mobile, because that
 * column carries a UNIQUE index: two spellings of one number reaching the
 * database as two rows would let one person hold two accounts, and would make
 * "is this number already registered?" answer wrongly.
 *
 * The canonical form is 09xxxxxxxxx — the shape the panel has always stored
 * and the shape every existing row is already in.
 */
final class Phone
{
    /**
     * @return string the canonical 09xxxxxxxxx form, or '' when the input is
     *                not a valid Iranian mobile number
     */
    public static function normalize(string $raw): string
    {
        // Persian and Arabic-Indic digits arrive whenever the number was
        // copied out of another Persian app.
        $value = Jalali::toLatinDigits($raw);

        // Separators people type: spaces, dashes, dots, parentheses, and the
        // RTL/LTR marks that ride along with a copy-paste out of a PDF.
        $value = preg_replace('/[\s\-\.\(\)\x{200c}\x{200e}\x{200f}]/u', '', $value) ?? '';

        if ($value === '') {
            return '';
        }

        // Country prefixes, longest first: 0098 before 98, or "0098..." would
        // keep a stray 0 and fail the final check.
        foreach (['+98', '0098', '98'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $value = substr($value, strlen($prefix));
                break;
            }
        }

        // What is left is either 9xxxxxxxxx or 09xxxxxxxxx.
        if (preg_match('/^9\d{9}$/', $value) === 1) {
            $value = '0' . $value;
        }

        return preg_match('/^09\d{9}$/', $value) === 1 ? $value : '';
    }

    public static function isValid(string $raw): bool
    {
        return self::normalize($raw) !== '';
    }

    /**
     * The form the SMS gateway is given. MeliPayamak accepts the national
     * format, and sending it exactly as stored keeps the delivery log and the
     * user row readable as the same number.
     */
    public static function forGateway(string $normalized): string
    {
        return $normalized;
    }

    /**
     * Partly hidden, for screens where the number identifies an account but
     * does not need to be readable in full — a shoulder-surfer at a library
     * terminal should not collect student numbers.
     */
    public static function mask(string $normalized): string
    {
        if (preg_match('/^09\d{9}$/', $normalized) !== 1) {
            return '';
        }
        return substr($normalized, 0, 4) . '***' . substr($normalized, -4);
    }
}
