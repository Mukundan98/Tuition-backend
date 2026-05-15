<?php

namespace App\Services;

final class GradeService
{
    public static function letterFromPercentage(float $percentage): string
    {
        return match (true) {
            $percentage >= 97 => 'A+',
            $percentage >= 93 => 'A',
            $percentage >= 89 => 'A-',
            $percentage >= 85 => 'B+',
            $percentage >= 81 => 'B',
            $percentage >= 77 => 'B-',
            $percentage >= 73 => 'C+',
            $percentage >= 69 => 'C',
            $percentage >= 65 => 'C-',
            $percentage >= 61 => 'D+',
            $percentage >= 57 => 'D',
            default => 'F',
        };
    }

    public static function fromMarks(float $marksObtained, float $maxMarks): string
    {
        if ($maxMarks <= 0) {
            return '—';
        }
        $pct = round(100 * $marksObtained / $maxMarks, 4);

        return self::letterFromPercentage($pct);
    }
}
