<?php
declare(strict_types=1);

namespace HeleXa\Services;

/**
 * Small builders for Telegram inline keyboards.
 *
 * Telegram gives no real layout system — the "premium" feel the spec asks
 * for comes from spacing (one item per row instead of cramming), consistent
 * emoji as visual icons, and short, calm button labels rather than a dense
 * grid of terse buttons.
 */
final class TelegramKeyboard
{
    /** @param array<int, array{0:string,1:string}> $rows label => callback_data pairs, one per row */
    public static function rows(array $rows): array
    {
        return ['inline_keyboard' => array_map(
            static fn (array $row): array => [['text' => $row[0], 'callback_data' => $row[1]]],
            $rows
        )];
    }

    /** Several buttons per row, e.g. [[['label','data'],['label','data']], ...]. */
    public static function grid(array $grid): array
    {
        return ['inline_keyboard' => array_map(
            static fn (array $row): array => array_map(
                static fn (array $btn): array => ['text' => $btn[0], 'callback_data' => $btn[1]],
                $row
            ),
            $grid
        )];
    }

    public static function mainMenu(): array
    {
        return self::rows([
            ['📚 دوره‌های من', 'menu:courses'],
            ['📅 برنامه هفتگی', 'menu:schedule'],
            ['📝 برنامه امتحانات', 'menu:exams'],
            ['🔔 اطلاعیه‌ها', 'menu:notifications'],
            ['👤 پروفایل من', 'menu:profile'],
            ['📊 وضعیت من', 'menu:status'],
            ['🆘 پشتیبانی', 'menu:support'],
            ['⚙️ تنظیمات', 'menu:settings'],
            ['🚪 خروج', 'menu:logout'],
        ]);
    }

    public static function backTo(string $target = 'menu', string $label = '⬅️ بازگشت'): array
    {
        return self::rows([[$label, 'menu:' . $target]]);
    }

    public static function editProfileMenu(): array
    {
        return self::rows([
            ['👤 جنسیت', 'edit:gender'],
            ['📱 شماره تلفن', 'edit:phone'],
            ['🖼️ عکس پروفایل', 'edit:avatar'],
            ['🔐 تغییر رمز عبور', 'edit:password'],
            ['⬅️ بازگشت به پروفایل', 'menu:profile'],
        ]);
    }

    public static function genderChoice(): array
    {
        return self::rows([
            ['👨 مرد', 'edit:gender:male'],
            ['👩 زن', 'edit:gender:female'],
            ['⬅️ انصراف', 'menu:profile'],
        ]);
    }

    public static function supportEnd(): array
    {
        return self::rows([['🔚 پایان گفتگو با پشتیبانی', 'support:end']]);
    }

    public static function requestPhone(): array
    {
        return [
            'keyboard' => [[['text' => '📱 اشتراک شماره تلفن', 'request_contact' => true]]],
            'resize_keyboard'   => true,
            'one_time_keyboard' => true,
        ];
    }

    public static function removeReplyKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }
}
