<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClassScheduleController extends Controller
{
    public function index(SchoolClass $class): JsonResponse
    {
        $schedules = ClassSchedule::query()
            ->where('class_id', $class->id)
            ->with('subject')
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return ApiResponse::success([
            'schedules' => $schedules->map(fn (ClassSchedule $s) => $this->formatRow($s))->all(),
        ]);
    }

    public function replace(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'schedules' => ['present', 'array'],
            'schedules.*.day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i'],
            'schedules.*.subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
        ]);

        foreach ($data['schedules'] as $i => $row) {
            if ($row['end_time'] <= $row['start_time']) {
                return ApiResponse::error("Schedule row {$i}: end time must be after start time.", 422);
            }
        }

        foreach ($data['schedules'] as $row) {
            if ($row['subject_id'] !== null) {
                $ok = DB::table('subjects')
                    ->where('id', $row['subject_id'])
                    ->where('class_id', $class->id)
                    ->exists();
                if (! $ok) {
                    return ApiResponse::error('Invalid subject for this class.', 422);
                }
            }
        }

        DB::transaction(function () use ($class, $data): void {
            ClassSchedule::where('class_id', $class->id)->delete();
            foreach ($data['schedules'] as $row) {
                ClassSchedule::create([
                    'class_id' => $class->id,
                    'subject_id' => $row['subject_id'] ?? null,
                    'day_of_week' => $row['day_of_week'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                ]);
            }
        });

        return $this->index($class);
    }

    private function formatRow(ClassSchedule $s): array
    {
        return [
            'id' => $s->id,
            'class_id' => $s->class_id,
            'subject_id' => $s->subject_id,
            'day_of_week' => $s->day_of_week,
            'start_time' => substr((string) $s->start_time, 0, 5),
            'end_time' => substr((string) $s->end_time, 0, 5),
            'subject' => $s->relationLoaded('subject') && $s->subject ? [
                'id' => $s->subject->id,
                'name' => $s->subject->name,
                'code' => $s->subject->code,
            ] : null,
        ];
    }
}
