<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    /** Weekly schedules for the signed-in student’s class (cohort) with subject + teacher. */
    public function student(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('studentProfile');
        $student = $user->studentProfile;

        if (! $student) {
            return ApiResponse::success([
                'class' => null,
                'schedules' => [],
                'stats' => $this->emptyStudentStats(),
                'message' => 'No student profile linked.',
            ]);
        }

        if (! $student->class_id) {
            return ApiResponse::success([
                'class' => null,
                'schedules' => [],
                'stats' => $this->emptyStudentStats(),
                'message' => 'No class assigned yet.',
            ]);
        }

        $class = SchoolClass::query()->find($student->class_id);

        $schedules = ClassSchedule::query()
            ->where('class_id', $student->class_id)
            ->with(['subject.teacher.user'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $items = $schedules->map(fn (ClassSchedule $s) => $this->formatStudentRow($s))->values()->all();

        $uniqueDays = $schedules->pluck('day_of_week')->unique()->count();
        $subjectIds = $schedules->pluck('subject_id')->filter()->unique()->count();

        return ApiResponse::success([
            'class' => $class
                ? [
                    'id' => $class->id,
                    'name' => $class->name,
                    'section' => $class->section,
                    'academic_year' => $class->academic_year,
                ]
                : null,
            'schedules' => $items,
            'stats' => [
                'total_periods' => $schedules->count(),
                'days_with_class' => $uniqueDays,
                'distinct_subjects' => $subjectIds,
            ],
            'message' => null,
        ]);
    }

    /** All timetable slots where the teacher teaches (any class), plus light stats. */
    public function teacher(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('teacherProfile');
        $tid = $user->teacherProfile?->id;

        if (! $tid) {
            return ApiResponse::success([
                'items' => [],
                'stats' => $this->emptyTeacherStats(),
                'message' => 'No teacher profile linked.',
            ]);
        }

        $schedules = ClassSchedule::query()
            ->whereHas('subject', function ($q) use ($tid): void {
                $q->where('teacher_id', $tid);
            })
            ->with(['schoolClass:id,name,section,academic_year', 'subject:id,name,code,teacher_id'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $items = $schedules->map(fn (ClassSchedule $s) => $this->formatTeacherRow($s))->values()->all();

        $byDay = [];
        for ($d = 0; $d <= 6; $d++) {
            $byDay[$d] = $schedules->where('day_of_week', $d)->count();
        }

        $uniqueClasses = $schedules->pluck('class_id')->unique()->filter()->count();

        return ApiResponse::success([
            'items' => $items,
            'stats' => [
                'total_sessions' => $schedules->count(),
                'unique_classes' => $uniqueClasses,
                'by_day' => $byDay,
                'busiest_day' => $this->busiestDayLabel($byDay),
            ],
            'message' => null,
        ]);
    }

    /** All classes with their schedules for admin timetable view. */
    public function admin(): JsonResponse
    {
        $classes = SchoolClass::query()
            ->orderBy('name')
            ->get(['id', 'name', 'section', 'academic_year']);

        $schedules = ClassSchedule::query()
            ->with(['subject.teacher.user', 'schoolClass:id,name,section'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $grouped = [];
        foreach ($classes as $class) {
            $classSchedules = $schedules->where('class_id', $class->id)->values();
            $grouped[] = [
                'class' => [
                    'id' => $class->id,
                    'name' => $class->name,
                    'section' => $class->section,
                    'academic_year' => $class->academic_year,
                ],
                'schedules' => $classSchedules->map(fn (ClassSchedule $s) => $this->formatAdminRow($s))->all(),
                'stats' => [
                    'total_periods' => $classSchedules->count(),
                    'days_with_class' => $classSchedules->pluck('day_of_week')->unique()->count(),
                    'distinct_subjects' => $classSchedules->pluck('subject_id')->filter()->unique()->count(),
                ],
            ];
        }

        return ApiResponse::success([
            'classes' => $grouped,
        ]);
    }

    private function formatAdminRow(ClassSchedule $s): array
    {
        $subject = $s->subject;
        $teacherName = null;
        if ($subject && $subject->relationLoaded('teacher') && $subject->teacher) {
            $teacherName = $subject->teacher->relationLoaded('user') && $subject->teacher->user
                ? $subject->teacher->user->name
                : null;
        }

        return [
            'id' => $s->id,
            'class_id' => $s->class_id,
            'subject_id' => $s->subject_id,
            'day_of_week' => $s->day_of_week,
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
            'subject' => $subject
                ? [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code,
                ]
                : null,
            'teacher_name' => $teacherName,
        ];
    }

    private function emptyStudentStats(): array
    {
        return [
            'total_periods' => 0,
            'days_with_class' => 0,
            'distinct_subjects' => 0,
        ];
    }

    private function emptyTeacherStats(): array
    {
        $byDay = [];
        for ($d = 0; $d <= 6; $d++) {
            $byDay[$d] = 0;
        }

        return [
            'total_sessions' => 0,
            'unique_classes' => 0,
            'by_day' => $byDay,
            'busiest_day' => null,
        ];
    }

    /** @param  array<int, int>  $byDay */
    private function busiestDayLabel(array $byDay): ?array
    {
        if ($byDay === [] || max($byDay) === 0) {
            return null;
        }
        $max = max($byDay);
        $day = array_search($max, $byDay, true);
        if ($day === false) {
            return null;
        }

        return ['day_of_week' => (int) $day, 'count' => $max];
    }

    private function formatStudentRow(ClassSchedule $s): array
    {
        $subject = $s->subject;
        $teacherName = null;
        if ($subject && $subject->relationLoaded('teacher') && $subject->teacher) {
            $teacherName = $subject->teacher->relationLoaded('user') && $subject->teacher->user
                ? $subject->teacher->user->name
                : null;
        }

        return [
            'id' => $s->id,
            'class_id' => $s->class_id,
            'subject_id' => $s->subject_id,
            'day_of_week' => $s->day_of_week,
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
            'subject' => $subject
                ? [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code,
                ]
                : null,
            'teacher_name' => $teacherName,
        ];
    }

    private function formatTeacherRow(ClassSchedule $s): array
    {
        $subject = $s->subject;
        $schoolClass = $s->relationLoaded('schoolClass') ? $s->schoolClass : null;

        return [
            'id' => $s->id,
            'class_id' => $s->class_id,
            'subject_id' => $s->subject_id,
            'day_of_week' => $s->day_of_week,
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
            'subject' => $subject
                ? [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'code' => $subject->code,
                ]
                : null,
            'school_class' => $schoolClass
                ? [
                    'id' => $schoolClass->id,
                    'name' => $schoolClass->name,
                    'section' => $schoolClass->section,
                ]
                : null,
        ];
    }
}
