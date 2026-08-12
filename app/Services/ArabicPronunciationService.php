<?php

declare(strict_types=1);

namespace BYD\Services;

/**
 * تحويل الأرقام/التواريخ/الأوقات لنص عربي فلسطيني منطوق بشكل حتمي،
 * بدل ما نخلي الموديل (GPT-4.1) "يحسب" النطق كل مرة من جديد وقت الرد.
 *
 * القواعد هون مطابقة تماماً لقسم "قاعدة الأرقام" و"التواريخ" و"الأوقات"
 * الموجودين بـ buildSystemPrompt() — أي تعديل على القواعد هناك لازم
 * ينعكس هون كمان عشان يبقوا متطابقين.
 */
final class ArabicPronunciationService
{
    // الصيغة المستقلة (لحالها، بعد فاصلة، آخر رقم بدون وحدة بعده)
    private const ABSOLUTE = [
        0 => 'صفر', 1 => 'واحد', 2 => 'تنين', 3 => 'تلاته', 4 => 'اربعه',
        5 => 'خمسه', 6 => 'سته', 7 => 'سبعه', 8 => 'تمنية', 9 => 'تسعه',
    ];

    // صيغة الإضافة (قبل اسم/وحدة مباشرة) — بس لـ 3-9، 0/1/2 نفس الصيغة المستقلة
    private const CONSTRUCT = [
        0 => 'صفر', 1 => 'واحد', 2 => 'تنين', 3 => 'تلاته', 4 => 'اربعه',
        5 => 'خمسه', 6 => 'سته', 7 => 'سبعه', 8 => 'تمنية', 9 => 'تسعه',
    ];

    private const TEENS = [
        10 => 'عشره', 11 => 'حداش', 12 => 'اتناش', 13 => 'تلتاش',
        14 => 'اربعتاش', 15 => 'خمستاش', 16 => 'ستاش', 17 => 'سبعتاش',
        18 => 'تمنتاش', 19 => 'تسعتاش',
    ];
    
    

    private const TENS = [
        20 => 'عشرين', 30 => 'تلاتين', 40 => 'اربعين', 50 => 'خمسين',
        60 => 'ستين', 70 => 'سبعين', 80 => 'تمانين', 90 => 'تسعين',
    ];
    
    private const HOUR_WORDS = [
    1 => 'واحد', 2 => 'تنتين', 3 => 'تلاته', 4 => 'اربعه',
    5 => 'خمسه', 6 => 'سته', 7 => 'سبعه', 8 => 'تمنية',
    9 => 'تسعه', 10 => 'عشره', 11 => 'حداش', 12 => 'اتناش',
    ];

    private const WEEKDAYS_AR = [
    'Sunday' => 'الأحد', 'Monday' => 'الاثنين', 'Tuesday' => 'الثلاثاء',
    'Wednesday' => 'الأربعاء', 'Thursday' => 'الخميس',
    'Friday' => 'الجمعة', 'Saturday' => 'السبت',
    ];
    

    /**
     * يحول رقم (صحيح أو عشري) لنص عربي فلسطيني منطوق بالكامل.
     *
     * @param float|int $number   الرقم المطلوب تحويله (يدعم 0 لغاية 999999)
     * @param bool      $construct  true إذا الرقم رح يتقال ملاصق مباشرة
     *                              لاسم/وحدة بعده مباشرة (زي "3 مقاعد" → تلت مقاعد).
     *                              اتركيها false إذا الرقم لحاله أو آخر شي بالجملة.
     */
    public static function numberToWords(float|int $number, bool $construct = false): string
    {
        if ($number < 0) {
            return 'ناقص ' . self::numberToWords(abs($number), $construct);
        }

        // فصل الجزء العشري
        if (is_float($number) && floor($number) != $number) {
            $intPart  = (int) floor($number);
            $fracStr  = rtrim(sprintf('%.4f', $number - $intPart), '0');
            $fracStr  = ltrim($fracStr, '0.');
            $fracPart = (int) $fracStr;

            $intWords  = self::integerToWords($intPart, $construct);
            $fracWords = self::integerToWords($fracPart, false);

            return "{$intWords} فاصلة {$fracWords}";
        }

        return self::integerToWords((int) $number, $construct);
    }

    private static function integerToWords(int $n, bool $construct): string
    {
        if ($n === 0) {
            return self::ABSOLUTE[0];
        }

        // أرقام لغاية 999999 (كافية لكل مواصفات السيارة العملية)
        $thousands = intdiv($n, 1000);
        $remainder = $n % 1000;
        $hundreds  = intdiv($remainder, 100);
        $tensOnes  = $remainder % 100;

        $parts = [];

        if ($thousands > 0) {
            if ($thousands === 1) {
                $parts[] = 'ألف';
            } elseif ($thousands === 2) {
                $parts[] = 'ألفين';
            } elseif ($thousands <= 9) {
                $parts[] = self::CONSTRUCT[$thousands] . ' تالاف';
            } else {
                // أكبر من 9999 (نادر بمواصفات سيارة) — احتياطي بسيط
                $parts[] = self::integerToWords($thousands, true) . ' ألف';
            }
        }

        if ($hundreds > 0) {
            if ($hundreds === 1) {
                $parts[] = 'ميه';
            } elseif ($hundreds === 2) {
                $parts[] = 'متين';
            } else {
                $parts[] = self::CONSTRUCT[$hundreds] . ' ميه';
            }
        }

        if ($tensOnes > 0) {
            // آخر جزء بالرقم: لو ما في أجزاء قبله (يعني هو الرقم كامل) وطلب construct
            // وهو رقم مفرد 3-9، استخدمي صيغة الإضافة. غير هيك، صيغة مستقلة عادي.
            $isWholeNumber = empty($parts);
            $parts[] = self::tensOnesToWords($tensOnes, $isWholeNumber && $construct);
        }

        return implode(' و', $parts);
    }

    private static function tensOnesToWords(int $n, bool $construct): string
    {
        if ($n < 10) {
            return $construct ? self::CONSTRUCT[$n] : self::ABSOLUTE[$n];
        }
        if ($n < 20) {
            return self::TEENS[$n];
        }

        $tens = intdiv($n, 10) * 10;
        $ones = $n % 10;

        if ($ones === 0) {
            return self::TENS[$tens];
        }

        // الآحاد بصيغة مستقلة دايماً بالمركّب (زي "خمسة وعشرين")
        return self::ABSOLUTE[$ones] . ' و' . self::TENS[$tens];
    }

    /**
     * يحول تاريخ YYYY-MM-DD لنص منطوق: يوم + شهر (كرقم، بدون اسم شهر) + سنة.
     * مطابق لقاعدة "### التواريخ (نطق)" بالبرومبت.
     */
public static function dateToWords(string $ymd): string
{
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }

    $dayName = self::WEEKDAYS_AR[date('l', $ts)] ?? '';
    $day     = (int) date('j', $ts);
    $month   = (int) date('n', $ts);

    $dayWords   = self::numberToWords($day, false);
    $monthWords = self::numberToWords($month, false);

    return "يوم {$dayName}، {$dayWords}، {$monthWords}،";

}

    /**
     * يحول وقت HH:MM (24 ساعة) لصيغة 12 ساعة منطوقة مع تحديد الفترة.
     * مطابق لقاعدة "### الأوقات (نطق)" بالبرومبت.
     */
   public static function timeToWords(string $hm): string
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hm), $m)) {
        return $hm;
    }

    $hour24 = (int) $m[1];
    $minute = (int) $m[2];

    if ($hour24 === 0) {
        $hour12 = 12;
        $period = 'نص الليل';
    } elseif ($hour24 < 12) {
        $hour12 = $hour24;
        $period = 'الصُبِح';
    } elseif ($hour24 === 12) {
        $hour12 = 12;
        $period = 'الظُهُر';
    } elseif ($hour24 < 17) {
        $hour12 = $hour24 - 12;
        $period = 'بعد الظُهُر';
    } else {
        $hour12 = $hour24 - 12;
        $period = 'المسه';
    }

    $hourWords = self::HOUR_WORDS[$hour12] ?? self::numberToWords($hour12, false);

    if ($minute === 0) {
        return "الساعه {$hourWords}، {$period}";
    }

    if ($minute === 30) {
        return "الساعه {$hourWords} ونص، {$period}";
    }

    if ($minute === 15) {
        return "الساعه {$hourWords} وربع، {$period}";
    }

    if ($minute === 45) {
        $nextHour = $hour12 === 12 ? 1 : $hour12 + 1;
        $nextHourWords = self::HOUR_WORDS[$nextHour] ?? self::numberToWords($nextHour, false);
        return "الساعه {$nextHourWords} إلا ربع، {$period}";
    }

    $minuteWords = self::numberToWords($minute, false);
    return "الساعه {$hourWords} و{$minuteWords} دقيقة، {$period}";
}
    /**
     * رقم الجوال — كل رقم يُقرأ منفرد، بدون صيغة إضافة أو مجموع.
     */
    public static function phoneToWords(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $words  = [];
        foreach (str_split($digits) as $d) {
            $words[] = self::ABSOLUTE[(int) $d];
        }
        return implode(' ', $words);
    }
}