<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

/**
 * The ready-to-paste prompt and the sample file shown on the Balin JSON page.
 */
final class BalinTransferGuide
{
    public static function prompt(): string
    {
        return <<<'TXT'
تو یک استاد باتجربه آموزش پزشکی و طراح کیس‌های بالینی تعاملی هستی. برای «جزیره بالین» (پلتفرم آموزش بالینی دانشجویان پزشکی ایران) یک درس کامل بساز و خروجی را فقط و فقط به‌صورت یک JSON معتبر برگردان — بدون هیچ توضیح، مقدمه یا ``` اضافه.

═══════════ موضوع و مشخصات (این بخش را من پر می‌کنم) ═══════════
موضوع درس: [مثلاً: بیمار ۵۸ ساله با درد سینه — سندرم حاد کرونری]
مقطع مخاطب: [مثلاً: استاجر داخلی / اینترن / دانشجوی علوم پایه]
تعداد مرحله: [مثلاً: ۶]
تعداد سؤال بانک آزمون پایانی: [مثلاً: ۸]
═══════════════════════════════════════════════════════════════

ساختار دقیق خروجی:
{
  "format": "helexa-balin",
  "version": 1,
  "characters": [
    {"name": "دکتر احمدی", "type": "teacher", "gender": "male", "icon": "👨‍🏫", "side": "right"},
    {"name": "سارا (کارورز)", "type": "student", "gender": "female", "icon": "👩‍🎓", "side": "right"},
    {"name": "آقای رضایی (بیمار)", "type": "patient", "gender": "male", "icon": "🤒", "side": "left"},
    {"name": "پرستار مریم", "type": "nurse", "gender": "female", "icon": "👩‍⚕️", "side": "left"}
  ],
  "skill_tracks": [
    {"slug": "history-taking", "name": "شرح حال‌گیری", "category": "communication", "icon": "🗣️"},
    {"slug": "ecg", "name": "تفسیر نوار قلب", "category": "clinical_reasoning", "icon": "📈"}
  ],
  "lessons": [
    {
      "title": "عنوان درس",
      "description": "توضیح کوتاه ۲ تا ۳ جمله‌ای درباره اهداف درس",
      "icon": "🫀",
      "color": "#dc2626",
      "estimated_minutes": 45,
      "xp_reward": 100,
      "extra_notes": "اهداف آموزشی (Learning objectives) به‌صورت فهرست",
      "status": "published",
      "stages": [
        {
          "key": "s1",
          "title": "عنوان مرحله",
          "subtitle": "زیرعنوان کوتاه",
          "description": "این مرحله چه چیزی را آموزش می‌دهد",
          "xp_reward": 20,
          "estimated_minutes": 8,
          "is_final_case": false,
          "blocks": [
            {"type": "system", "text": "اورژانس — ساعت ۲ بامداد"},
            {"type": "chat", "character": "پرستار مریم", "text": "دکتر، بیمار آقای ۵۸ ساله با درد سینه آوردند."},
            {"type": "vitals", "rows": [
              {"name": "فشار خون", "value": "160/95", "unit": "mmHg", "flag": "high"},
              {"name": "ضربان قلب", "value": "108", "unit": "/min", "flag": "high"},
              {"name": "تعداد تنفس", "value": "22", "unit": "/min", "flag": "high"},
              {"name": "دما", "value": "36.8", "unit": "°C", "flag": "normal"},
              {"name": "SpO2", "value": "94", "unit": "%", "flag": "low"}
            ]},
            {"type": "finding", "text": "تعریق سرد، رنگ‌پریدگی، سمع قلب: S4"},
            {"type": "lab", "rows": [
              {"test": "Troponin I (hs)", "result": "0.45", "unit": "ng/mL", "range": "< 0.04", "flag": "high"},
              {"test": "K", "result": "4.1", "unit": "mEq/L", "range": "3.5 - 5.0", "flag": "normal"}
            ]},
            {"type": "question", "question": {
              "key": "q1",
              "prompt": "متن کامل سؤال به سبک کیس بالینی",
              "options": [
                {"text": "گزینه ۱", "correct": false},
                {"text": "گزینه ۲", "correct": true},
                {"text": "گزینه ۳", "correct": false},
                {"text": "گزینه ۴", "correct": false}
              ],
              "explanation": "پاسخ تشریحی کامل",
              "hint": "راهنمایی که جواب را لو ندهد",
              "difficulty": "medium",
              "xp_reward": 15,
              "skill_tracks": ["ecg"]
            }},
            {"type": "ddx", "items": ["سندرم حاد کرونری — ...", "دایسکشن آئورت — ...", "آمبولی ریه — ..."]},
            {"type": "pearl", "text": "نکته کلیدی به یاد ماندنی"},
            {"type": "warning", "text": "هشدار ایمنی بیمار یا خطای شایع"},
            {"type": "hint", "text": "راهنمای آموزشی"},
            {"type": "text", "text": "توضیح آموزشی کوتاه"},
            {"type": "reference", "items": ["Harrison's Principles of Internal Medicine, 21st ed., Chapter ..."]},
            {"type": "divider"}
          ]
        }
      ],
      "question_bank": [
        {
          "key": "b1",
          "prompt": "سؤال آزمون پایانی",
          "options": ["گزینه ۱", "گزینه ۲", "گزینه ۳", "گزینه ۴"],
          "answer": 3,
          "explanation": "پاسخ تشریحی",
          "difficulty": "hard",
          "xp_reward": 20,
          "skill_tracks": ["ecg"]
        }
      ],
      "checkpoint_exams": [
        {
          "key": "final",
          "title": "آزمون پایانی درس",
          "description": "توضیح آزمون",
          "position": "after_lesson",
          "mode": "fixed_list",
          "pass_percent": 70,
          "gating": false,
          "cooldown_hours": 24,
          "time_limit_minutes": 15,
          "xp_reward": 50,
          "questions": ["b1"]
        }
      ]
    }
  ]
}

انواع بلوک و کاربرد:
- system: توضیح صحنه (مکان، زمان، شرایط)
- chat: گفت‌وگو؛ "character" باید دقیقاً یکی از نام‌های فهرست characters باشد
- finding: یافته شرح حال یا معاینه فیزیکی
- vitals: علائم حیاتی؛ flag یکی از normal / high / low / critical
- lab: نتایج آزمایش با محدوده طبیعی؛ flag مثل بالا
- question: سؤال چندگزینه‌ای داخل مرحله
- ddx: تشخیص‌های افتراقی، هر مورد با یک دلیل کوتاه له یا علیه
- pearl: نکته کلیدی (Clinical pearl)
- warning: هشدار ایمنی، Red flag یا خطای شایع
- hint: راهنمای آموزشی
- text: توضیح آموزشی
- reference: منبع معتبر
- divider: جداکننده بخش‌ها

اصول آموزشی که باید رعایت کنی:
1) کیس را مثل یک شیفت واقعی بیمارستان روایت کن. ترتیب پیشنهادی مرحله‌ها:
   معرفی بیمار و شکایت اصلی ← شرح حال کامل (HPI، سابقه، دارو، حساسیت، اجتماعی) ← معاینه فیزیکی و علائم حیاتی
   ← تشخیص‌های افتراقی ← اقدامات پاراکلینیک و تفسیر نتایج ← تشخیص قطعی ← درمان و مدیریت (با دوز دارو)
   ← پایش، عوارض و ترخیص/پیگیری. آخرین مرحله "is_final_case": true و جمع‌بندی کل کیس باشد.
2) گفت‌وگو طبیعی و فارسی روان باشد؛ اصطلاحات تخصصی را با معادل انگلیسی داخل پرانتز بنویس. استاد با سؤال‌های سقراطی دانشجو را هدایت کند.
3) هر مرحله ۸ تا ۱۵ بلوک داشته باشد و حداقل ۱ تا ۲ سؤال. هر مرحله دست‌کم یک pearl یا warning داشته باشد.
4) سؤال‌ها:
   - به سبک vignette و در سطح مقطع مخاطب؛ تمرکز روی استدلال بالینی، نه حفظیات صرف.
   - ۴ یا ۵ گزینه؛ دقیقاً یک گزینه "correct": true. جای گزینه صحیح را تصادفی بچین.
   - گزینه‌های غلط باید «پرت‌کننده منطقی» باشند (اشتباه‌های رایج دانشجوها).
   - explanation: چرا گزینه درست، درست است و هر گزینه غلط چرا غلط است؛ با نکته عملی.
   - hint: یک سرنخ که جواب را مستقیم لو ندهد.
   - difficulty یکی از easy / medium / hard / expert؛ xp_reward بین ۱۰ و ۳۰ متناسب با سختی.
   - skill_tracks فقط از slugهای تعریف‌شده در skill_tracks.
5) دقت علمی: مطابق راهنماهای معتبر و به‌روز (Harrison، Cecil، UpToDate، راهنمای انجمن‌های تخصصی مثل AHA/ESC، و پروتکل‌های وزارت بهداشت ایران). دوز دارو را برای بزرگسال و با واحد صحیح بنویس. اعداد آزمایش با واحد و محدوده طبیعی رایج. هرگز منبع یا عدد ساختگی ننویس؛ اگر مطمئن نیستی، کلی‌تر بنویس.
6) در ddx برای هر تشخیص یک دلیل کوتاه بیاور. در بلوک‌های warning موارد Red flag و ایمنی بیمار را بگو.
7) question_bank سؤال‌های آزمون پایانی است (کل درس را پوشش دهد، سخت‌تر از سؤال‌های داخل مرحله‌ها) و در checkpoint_exams با کلیدشان آمده باشد.
8) کلیدها (key) یکتا باشند. همه رشته‌ها فارسی باشند، به‌جز اصطلاحات و نام داروها و تست‌ها که می‌توانند انگلیسی باشند.
9) فقط JSON معتبر برگردان (بدون کامنت، بدون ویرگول اضافه در انتهای فهرست‌ها).
TXT;
    }

    public static function sample(): array
    {
        return [
            'format'       => BalinTransfer::FORMAT,
            'version'      => BalinTransfer::VERSION,
            'characters'   => [
                ['name' => 'دکتر احمدی', 'type' => 'teacher', 'gender' => 'male', 'icon' => '👨‍🏫', 'side' => 'right'],
                ['name' => 'سارا (کارورز)', 'type' => 'student', 'gender' => 'female', 'icon' => '👩‍🎓', 'side' => 'right'],
                ['name' => 'آقای رضایی (بیمار)', 'type' => 'patient', 'gender' => 'male', 'icon' => '🤒', 'side' => 'left'],
            ],
            'skill_tracks' => [
                ['slug' => 'ecg', 'name' => 'تفسیر نوار قلب', 'category' => 'clinical_reasoning', 'icon' => '📈'],
            ],
            'lessons'      => [[
                'title'             => 'درد سینه در اورژانس',
                'description'       => 'رویکرد به بیمار با درد حاد سینه و تشخیص سندرم حاد کرونری.',
                'icon'              => '🫀',
                'color'             => '#dc2626',
                'estimated_minutes' => 20,
                'xp_reward'         => 60,
                'status'            => 'draft',
                'stages'            => [
                    [
                        'key'   => 's1',
                        'title' => 'ورود بیمار',
                        'subtitle' => 'شکایت اصلی و علائم حیاتی',
                        'xp_reward' => 20,
                        'estimated_minutes' => 8,
                        'blocks' => [
                            ['type' => 'system', 'text' => 'اورژانس — ساعت ۲ بامداد'],
                            ['type' => 'chat', 'character' => 'آقای رضایی (بیمار)', 'text' => 'دکتر، از یک ساعت پیش سینه‌ام مثل سنگ سنگینی می‌کند.'],
                            ['type' => 'vitals', 'rows' => [
                                ['name' => 'فشار خون', 'value' => '160/95', 'unit' => 'mmHg', 'flag' => 'high'],
                                ['name' => 'ضربان قلب', 'value' => '108', 'unit' => '/min', 'flag' => 'high'],
                                ['name' => 'SpO2', 'value' => '94', 'unit' => '%', 'flag' => 'low'],
                            ]],
                            ['type' => 'chat', 'character' => 'دکتر احمدی', 'text' => 'سارا، اولین اقدام تشخیصی در ۱۰ دقیقه اول چیست؟'],
                            ['type' => 'question', 'question' => [
                                'key'     => 'q1',
                                'prompt'  => 'در بیمار با درد سینه ایسکمیک، کدام اقدام باید ظرف ۱۰ دقیقه از ورود انجام شود؟',
                                'options' => [
                                    ['text' => 'اسکن CT آنژیوگرافی', 'correct' => false],
                                    ['text' => 'نوار قلب ۱۲ لید', 'correct' => true],
                                    ['text' => 'اکوکاردیوگرافی', 'correct' => false],
                                    ['text' => 'تست تردمیل', 'correct' => false],
                                ],
                                'explanation' => 'طبق راهنماها، ECG ۱۲ لید باید ظرف ۱۰ دقیقه گرفته شود تا STEMI سریع شناسایی شود. تست تردمیل در درد فعال ممنوع است.',
                                'hint'        => 'ساده‌ترین، سریع‌ترین و ارزان‌ترین تست کنار تخت.',
                                'difficulty'  => 'easy',
                                'xp_reward'   => 10,
                                'skill_tracks' => ['ecg'],
                            ]],
                            ['type' => 'pearl', 'text' => 'Door-to-ECG کمتر از ۱۰ دقیقه.'],
                        ],
                    ],
                    [
                        'key'   => 's2',
                        'title' => 'آزمایش‌ها و تشخیص افتراقی',
                        'is_final_case' => true,
                        'xp_reward' => 30,
                        'blocks' => [
                            ['type' => 'lab', 'rows' => [
                                ['test' => 'Troponin I (hs)', 'result' => '0.45', 'unit' => 'ng/mL', 'range' => '< 0.04', 'flag' => 'high'],
                            ]],
                            ['type' => 'ddx', 'items' => [
                                'سندرم حاد کرونری — درد فشارنده و تروپونین مثبت',
                                'دایسکشن آئورت — درد پاره‌کننده منتشر به پشت',
                                'آمبولی ریه — تاکی‌پنه و افت SpO2',
                            ]],
                            ['type' => 'warning', 'text' => 'پیش از شروع ضدانعقاد، دایسکشن آئورت را رد کنید.'],
                            ['type' => 'question', 'question_ref' => 'b1'],
                        ],
                    ],
                ],
                'question_bank' => [[
                    'key'         => 'b1',
                    'prompt'      => 'تروپونین افزایش یافته بدون بالا رفتن ST به نفع کدام تشخیص است؟',
                    'options'     => ['آنژین ناپایدار', 'NSTEMI', 'STEMI', 'پریکاردیت'],
                    'answer'      => 2,
                    'explanation' => 'افزایش بیومارکر قلبی بدون صعود ST، NSTEMI را مطرح می‌کند؛ در آنژین ناپایدار تروپونین طبیعی است.',
                    'difficulty'  => 'medium',
                    'xp_reward'   => 15,
                    'skill_tracks' => ['ecg'],
                ]],
                'checkpoint_exams' => [[
                    'key'           => 'final',
                    'title'         => 'آزمون پایانی درد سینه',
                    'position'      => 'after_lesson',
                    'mode'          => 'fixed_list',
                    'pass_percent'  => 70,
                    'xp_reward'     => 40,
                    'questions'     => ['b1'],
                ]],
            ]],
        ];
    }
}
