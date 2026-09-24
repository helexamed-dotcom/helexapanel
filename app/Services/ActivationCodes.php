<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Models\ActivationCodeRepository;
use HeleXa\Models\PackageRepository;

/**
 * Using an activation code: one code, one student, one package.
 *
 * The claim on the code and the activation of the package happen in one
 * transaction — a failure half-way leaves the code unused rather than spent
 * on nothing.
 */
final class ActivationCodes
{
    /** "hlx 7kq2-m9pt" and "HLX-7KQ2-M9PT" are the same code. */
    public static function normalize(string $raw): string
    {
        $raw   = strtoupper(Jalali::toLatinDigits(trim($raw)));
        $plain = (string) preg_replace('/[^A-Z0-9]/', '', $raw);
        if (str_starts_with($plain, 'HLX')) {
            $plain = substr($plain, 3);
        }
        if (strlen($plain) !== 8) {
            return '';
        }
        return 'HLX-' . substr($plain, 0, 4) . '-' . substr($plain, 4, 4);
    }

    /**
     * @return array{ok:bool, message:string}
     */
    public static function redeem(array $user, string $input): array
    {
        $code = self::normalize($input);
        if ($code === '') {
            return ['ok' => false, 'message' => 'کد وارد‌شده درست نیست. کد شبیه HLX-XXXX-XXXX است.'];
        }

        $codes = new ActivationCodeRepository();
        $row   = $codes->findByCode($code);

        if ($row === null) {
            return ['ok' => false, 'message' => 'این کد پیدا نشد. حروف و اعداد را دوباره بررسی کن.'];
        }
        if ($row['revoked_at'] !== null) {
            return ['ok' => false, 'message' => 'این کد باطل شده است.'];
        }
        if ($row['redeemed_by'] !== null) {
            return ['ok' => false, 'message' => (int) $row['redeemed_by'] === (int) $user['id']
                ? 'این کد را قبلاً خودت استفاده کرده‌ای.'
                : 'این کد قبلاً استفاده شده است. هر کد فقط یک بار قابل استفاده است.'];
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return ['ok' => false, 'message' => 'مهلت استفاده از این کد تمام شده است.'];
        }

        $package = (new PackageRepository())->findById((int) $row['package_id']);
        if ($package === null) {
            return ['ok' => false, 'message' => 'پکیج این کد دیگر وجود ندارد.'];
        }

        $days   = $row['duration_days'] !== null ? (int) $row['duration_days'] : null;
        $result = null;

        $claimed = Database::transaction(static function () use ($codes, $row, $user, $package, $days, &$result): bool {
            if (!$codes->claim((int) $row['id'], (int) $user['id'])) {
                return false;
            }
            $result = ActivationService::activatePackage($user, $package, [
                'status'    => 'active',
                'starts_at' => null,
                'ends_at'   => $days !== null ? date('Y-m-d 23:59:59', strtotime('+' . $days . ' days')) : null,
            ], null);
            return true;
        });

        if (!$claimed) {
            return ['ok' => false, 'message' => 'این کد همین حالا استفاده شد یا دیگر معتبر نیست.'];
        }

        ActivityLogger::log('activation_code.redeemed', (int) $user['id'], 'package', (int) $package['id'],
            ['code' => $code], 'notice');

        return [
            'ok'      => true,
            'message' => sprintf(
                '🎉 پکیج «%s» برایت فعال شد%s: %s.',
                $package['title'],
                $days !== null ? ' (به مدت ' . fa((string) $days) . ' روز)' : '',
                PackageAccess::summarise($result ?? [])
            ),
        ];
    }
}
