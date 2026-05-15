<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Fee;
use App\Models\FeePayment;
use App\Models\InAppNotification;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class DashboardStatsService
{
    /** @return array<string, mixed> */
    public function admin(): array
    {
        $start = Carbon::today()->subDays(6)->startOfDay();
        $end = Carbon::today();

        return [
            'students_count' => Student::query()->count(),
            'teachers_count' => Teacher::query()->count(),
            'classes_count' => SchoolClass::query()->count(),
            'subjects_count' => Subject::query()->count(),
            'exams_upcoming' => Exam::query()->where('exam_date', '>=', $end)->count(),
            'fee_summary' => $this->feeSummary(),
            'attendance_series' => $this->attendanceSeries($start, $end, null),
        ];
    }

    /** @return array<string, mixed> */
    public function teacher(User $user): array
    {
        $teacher = $user->teacherProfile;
        if (! $teacher instanceof Teacher) {
            return [
                'has_profile' => false,
                'message' => 'No teacher profile linked to this account.',
            ];
        }

        $classIds = Subject::query()
            ->where('teacher_id', $teacher->id)
            ->distinct()
            ->pluck('class_id')
            ->filter()
            ->values()
            ->all();

        $start = Carbon::today()->subDays(6)->startOfDay();
        $end = Carbon::today();

        $series = count($classIds) === 0
            ? []
            : $this->attendanceSeries($start, $end, $classIds);

        $upcomingExams = count($classIds) === 0
            ? collect()
            : Exam::query()
                ->whereIn('class_id', $classIds)
                ->where('exam_date', '>=', $end)
                ->with(['schoolClass:id,name,section'])
                ->orderBy('exam_date')
                ->limit(8)
                ->get();

        $homeroomCount = SchoolClass::query()
            ->where('homeroom_teacher_id', $teacher->id)
            ->count();

        return [
            'has_profile' => true,
            'subjects_assigned' => Subject::query()->where('teacher_id', $teacher->id)->count(),
            'classes_count' => count(array_unique($classIds)),
            'students_reachable' => count($classIds) === 0
                ? 0
                : Student::query()->whereIn('class_id', $classIds)->count(),
            'homeroom_classes' => $homeroomCount,
            'unread_notifications' => $this->unreadNotifications($user),
            'attendance_series' => $series,
            'upcoming_exams' => $upcomingExams->map(fn (Exam $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'exam_date' => $e->exam_date?->toDateString(),
                'class' => $e->schoolClass ? [
                    'name' => $e->schoolClass->name,
                    'section' => $e->schoolClass->section,
                ] : null,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function student(User $user): array
    {
        $student = $user->studentProfile;
        if (! $student instanceof Student) {
            return [
                'has_profile' => false,
                'message' => 'No student profile linked to this account.',
            ];
        }

        return array_merge([
            'has_profile' => true,
            'unread_notifications' => $this->unreadNotifications($user),
        ], $this->studentProgressPayload($student));
    }

    /** @return array<string, mixed> */
    public function parent(User $user): array
    {
        $students = $this->studentsLinkedToParent($user);

        if ($students->isEmpty()) {
            return [
                'has_children' => false,
                'message' => 'No student matched your login email or phone to a guardian contact on file. Ask the office to confirm parent email or phone on each student.',
                'unread_notifications' => $this->unreadNotifications($user),
            ];
        }

        return [
            'has_children' => true,
            'unread_notifications' => $this->unreadNotifications($user),
            'children' => $students
                ->map(fn (Student $s) => $this->studentProgressPayload($s))
                ->values()
                ->all(),
        ];
    }

    /**
     * Shared student-centred metrics (dashboard + guardian view).
     *
     * @return array<string, mixed>
     */
    private function studentProgressPayload(Student $student): array
    {
        $student->loadMissing(['schoolClass:id,name,section']);
        $since = Carbon::today()->subDays(29)->startOfDay();

        $base = Attendance::query()
            ->where('student_id', $student->id)
            ->where('attended_on', '>=', $since);
        $totalDays = (clone $base)->count();
        $presentDays = (clone $base)->whereIn('status', [
            Attendance::STATUS_PRESENT,
            Attendance::STATUS_LATE,
        ])->count();
        $attendanceRate = $totalDays > 0 ? round(100 * $presentDays / $totalDays) : null;

        $feeBalance = $this->studentFeeBalance($student->id);

        $classId = $student->class_id;
        $upcomingExams = $classId === null
            ? collect()
            : Exam::query()
                ->where('class_id', $classId)
                ->where('exam_date', '>=', Carbon::today())
                ->orderBy('exam_date')
                ->limit(6)
                ->get(['id', 'title', 'exam_date']);

        $recentResults = ExamResult::query()
            ->where('student_id', $student->id)
            ->with([
                'exam:id,title,exam_date,max_marks',
                'subject:id,name',
            ])
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
            ],
            'class' => $student->schoolClass ? [
                'id' => $student->schoolClass->id,
                'name' => $student->schoolClass->name,
                'section' => $student->schoolClass->section,
            ] : null,
            'attendance_rate_30d' => $attendanceRate,
            'attendance_marked_30d' => $totalDays,
            'fee_balance' => $feeBalance,
            'upcoming_exams' => $upcomingExams->map(fn (Exam $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'exam_date' => $e->exam_date?->toDateString(),
            ])->values()->all(),
            'recent_results' => $recentResults->map(function (ExamResult $r): array {
                return [
                    'exam_title' => $r->exam?->title,
                    'subject' => $r->subject?->name,
                    'marks' => (string) $r->marks_obtained,
                    'grade' => $r->grade,
                ];
            })->values()->all(),
        ];
    }

    /** @return Collection<int, Student> */
    private function studentsLinkedToParent(User $user): Collection
    {
        $email = strtolower(trim((string) ($user->email ?? '')));
        $digits = $this->digitsOnly($user->phone);

        $byEmail = new EloquentCollection;
        if ($email !== '') {
            $byEmail = Student::query()
                ->whereNotNull('parent_email')
                ->whereRaw('LOWER(TRIM(parent_email)) = ?', [$email])
                ->get();
        }

        $byPhone = new EloquentCollection;
        if (strlen($digits) >= 8) {
            $byPhone = Student::query()
                ->whereNotNull('parent_phone')
                ->get()
                ->filter(fn (Student $s) => $this->digitsOnly($s->parent_phone) === $digits)
                ->values();
        }

        /** @var Collection<int, Student> */
        return $byEmail->merge($byPhone)->unique('id')->values();
    }

    private function digitsOnly(?string $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    /** @return array<string, float|int> */
    private function feeSummary(): array
    {
        $fees = Fee::query()->withSum('payments', 'amount')->get();
        $pendingBalance = 0.0;
        $unpaid = 0;
        foreach ($fees as $f) {
            $paid = (float) ($f->payments_sum_amount ?? 0);
            $bal = max(0, (float) $f->amount - $paid);
            if ($bal > 0.009) {
                $pendingBalance += $bal;
                $unpaid++;
            }
        }

        $thisMonthPaid = (float) FeePayment::query()->whereBetween('paid_at', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ])->sum('amount');

        return [
            'pending_balance' => round($pendingBalance, 2),
            'unpaid_fee_records' => $unpaid,
            'paid_this_month' => round($thisMonthPaid, 2),
        ];
    }

    private function studentFeeBalance(int $studentId): float
    {
        $fees = Fee::query()
            ->where('student_id', $studentId)
            ->withSum('payments', 'amount')
            ->get();
        $sum = 0.0;
        foreach ($fees as $f) {
            $paid = (float) ($f->payments_sum_amount ?? 0);
            $sum += max(0, (float) $f->amount - $paid);
        }

        return round($sum, 2);
    }

    /**
     * @param  array<int, int>|null  $classIds
     * @return list<array{date: string, marked: int, present: int}>
     */
    private function attendanceSeries(Carbon $start, Carbon $end, ?array $classIds): array
    {
        $q = Attendance::query()->whereBetween('attended_on', [$start->toDateString(), $end->toDateString()]);
        if ($classIds !== null) {
            $q->whereIn('class_id', $classIds);
        }

        $rows = $q
            ->selectRaw("DATE(attended_on) as day, COUNT(*) as total, SUM(CASE WHEN status IN ('".Attendance::STATUS_PRESENT."','".Attendance::STATUS_LATE."') THEN 1 ELSE 0 END) as attended")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $key = $d->toDateString();
            $row = $rows->get($key);
            $out[] = [
                'date' => $key,
                'marked' => $row ? (int) $row->total : 0,
                'present' => $row ? (int) $row->attended : 0,
            ];
        }

        return $out;
    }

    private function unreadNotifications(User $user): int
    {
        return InAppNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }
}
