<?php
// src/helpers/Jalali.php – Persian (Jalali/Shamsi) calendar conversion
// =====================================================================

class Jalali
{
    /**
     * Convert Gregorian date to Jalali (Shamsi) date
     */
    public static function gregorianToJalali($gYear, $gMonth, $gDay) {
        $gDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $jDays = [0, 31, 62, 93, 124, 155, 186, 216, 246, 276, 306, 336];

        $gy = $gYear - 1600;
        $gm = $gMonth - 1;
        $gd = $gDay - 1;

        $gDayNo = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
        $gDayNo += $gDays[$gm];
        if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
            $gDayNo++;
        }
        $gDayNo += $gd;

        $jDayNo = $gDayNo - 79;

        $jNp = floor($jDayNo / 12053);
        $jDayNo %= 12053;

        $jy = 979 + 33 * $jNp + 4 * floor($jDayNo / 1461);
        $jDayNo %= 1461;

        if ($jDayNo >= 366) {
            $jy += floor(($jDayNo - 1) / 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }

        $jMonths = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        for ($i = 0; $i < 12; $i++) {
            if ($jDayNo < $jMonths[$i]) {
                $jm = $i + 1;
                $jd = $jDayNo + 1;
                break;
            }
            $jDayNo -= $jMonths[$i];
        }

        return [$jy, $jm, $jd];
    }

    /**
     * Convert Jalali date to Gregorian date – CORRECTED
     */
    public static function jalaliToGregorian($jYear, $jMonth, $jDay) {
        // Days in Jalali months
        $jMonths = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        if (self::isLeapJalali($jYear)) {
            $jMonths[11] = 30;
        }

        // Calculate day number from Jalali epoch (1/1/1) to the given date
        $jDayNo = 0;
        for ($y = 1; $y < $jYear; $y++) {
            $jDayNo += self::isLeapJalali($y) ? 366 : 365;
        }
        for ($m = 0; $m < $jMonth - 1; $m++) {
            $jDayNo += $jMonths[$m];
        }
        $jDayNo += $jDay - 1;

        // Jalali 1/1/1 = Gregorian 622/3/21
        // Days from Gregorian 1/1/1 to 622/3/21 = 226894
        $gEpochDays = 226894;
        $gDayNo = $jDayNo + $gEpochDays;

        // Convert day number to Gregorian date
        $gYear = 1;
        while ($gDayNo >= 365) {
            $daysInYear = self::isLeapGregorian($gYear) ? 366 : 365;
            if ($gDayNo < $daysInYear) break;
            $gDayNo -= $daysInYear;
            $gYear++;
        }

        $gMonths = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if (self::isLeapGregorian($gYear)) {
            $gMonths[1] = 29;
        }
        $gMonth = 1;
        foreach ($gMonths as $days) {
            if ($gDayNo < $days) break;
            $gDayNo -= $days;
            $gMonth++;
        }
        $gDay = $gDayNo + 1;

        return [$gYear, $gMonth, $gDay];
    }

    /**
     * Check if a Jalali year is leap
     */
    public static function isLeapJalali($jYear) {
        $remainder = $jYear % 33;
        return in_array($remainder, [1, 5, 9, 13, 17, 22, 26, 30]);
    }

    /**
     * Check if a Gregorian year is leap
     */
    public static function isLeapGregorian($gYear) {
        return (($gYear % 4 == 0) && ($gYear % 100 != 0)) || ($gYear % 400 == 0);
    }

    /**
     * Get number of days in a Jalali month
     */
    public static function jMonthDays($jYear, $jMonth) {
        if ($jMonth <= 6) return 31;
        if ($jMonth <= 11) return 30;
        return self::isLeapJalali($jYear) ? 30 : 29;
    }

    /**
     * Get the first day of the week (0=Sunday) for a given Jalali month
     */
    public static function jMonthFirstDayOfWeek($jYear, $jMonth) {
        list($gYear, $gMonth, $gDay) = self::jalaliToGregorian($jYear, $jMonth, 1);
        $timestamp = mktime(0, 0, 0, $gMonth, $gDay, $gYear);
        return (int)date('w', $timestamp);
    }

    /**
     * Format Jalali date as YYYY/MM/DD
     */
    public static function format($jYear, $jMonth, $jDay, $shortYear = false) {
        $year = $shortYear ? substr($jYear, -2) : $jYear;
        return sprintf("%04d/%02d/%02d", $year, $jMonth, $jDay);
    }
     /**
     * Convert an ISO date string "2026-09-24" to Jalali parts.
     * Returns [jy, jm, jd] or null if the input is malformed.
     */
    public static function fromIso($iso) {
        if (!$iso || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m)) return null;
        return self::gregorianToJalali((int)$m[1], (int)$m[2], (int)$m[3]);
    }

    /**
     * Convert an ISO date string to a human Jalali format.
     * "2026-09-24" -> "2 Mehr 1405"
     */
    public static function formatHuman($iso) {
        $j = self::fromIso($iso);
        if (!$j) return $iso;
        $months = ['Farvardin','Ordibehesht','Khordad','Tir','Mordad','Shahrivar',
                   'Mehr','Aban','Azar','Dey','Bahman','Esfand'];
        return $j[2] . ' ' . $months[$j[1] - 1] . ' ' . $j[0];
    }

    /**
     * Convert an ISO date string to a numeric Jalali format.
     * "2026-09-24" -> "1405/07/02"
     */
    public static function formatNumeric($iso) {
        $j = self::fromIso($iso);
        if (!$j) return $iso;
        return sprintf('%04d/%02d/%02d', $j[0], $j[1], $j[2]);
    }

}

