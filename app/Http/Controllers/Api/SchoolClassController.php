<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SchoolClassController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $query = SchoolClass::query()->with(['homeroomTeacher.user:id,name']);

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('section', 'like', "%{$search}%")
                    ->orWhere('academic_year', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('name')->paginate($perPage);

        return ApiResponse::success([
            'items' => array_map(fn (SchoolClass $c) => $this->formatClass($c), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:50'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'max_students' => ['nullable', 'integer', 'min:1'],
            'homeroom_teacher_id' => ['nullable', 'exists:teachers,id'],
        ]);

        $class = SchoolClass::create($data);

        return ApiResponse::success(
            ['school_class' => $this->formatClass($class->load('homeroomTeacher.user'))],
            'Class created',
            201
        );
    }

    public function show(SchoolClass $class): JsonResponse
    {
        $class->load(['homeroomTeacher.user', 'subjects.teacher.user']);

        $row = $this->formatClass($class);
        $row['students_count'] = $class->students()->count();
        $row['subjects_count'] = $class->subjects()->count();
        $row['subjects'] = $class->subjects->map(fn (Subject $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'code' => $s->code,
            'teacher_id' => $s->teacher_id,
            'teacher' => $s->teacher ? [
                'id' => $s->teacher->id,
                'name' => $s->teacher->relationLoaded('user') && $s->teacher->user
                    ? $s->teacher->user->name : null,
            ] : null,
        ])->values()->all();

        $row['students_preview'] = $class->students()
            ->orderBy('name')
            ->take(15)
            ->get(['id', 'name', 'admission_number'])
            ->map(fn ($st) => [
                'id' => $st->id,
                'name' => $st->name,
                'admission_number' => $st->admission_number,
            ])->values()->all();

        return ApiResponse::success(['school_class' => $row]);
    }

    public function update(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:50'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'max_students' => ['nullable', 'integer', 'min:1'],
            'homeroom_teacher_id' => ['nullable', 'exists:teachers,id'],
        ]);

        $class->update($data);

        return ApiResponse::success([
            'school_class' => $this->formatClass($class->fresh()->load('homeroomTeacher.user')),
        ]);
    }

    /**
     * Roster rows for generating attendance barcode labels (frontend renders Code 128 etc.).
     */
    public function barcodeLabels(SchoolClass $class): JsonResponse
    {
        $students = $class->students()
            ->orderBy('name')
            ->get(['id', 'name', 'admission_number']);

        $rows = $students->map(function ($s): array {
            return [
                'id' => $s->id,
                'name' => $s->name,
                'admission_number' => $s->admission_number,
                'payload_admission' => (string) $s->admission_number,
                'payload_sid' => 'SID:'.$s->id,
            ];
        })->values()->all();

        return ApiResponse::success([
            'class' => [
                'id' => $class->id,
                'name' => $class->name,
                'section' => $class->section,
            ],
            'students' => $rows,
        ]);
    }

    public function destroy(SchoolClass $class): JsonResponse
    {
        DB::transaction(function () use ($class): void {
            ClassSchedule::where('class_id', $class->id)->delete();
            Subject::where('class_id', $class->id)->delete();
            $class->students()->update(['class_id' => null]);
            $class->delete();
        });

        return ApiResponse::success(null, 'Deleted');
    }

    private function formatClass(SchoolClass $class): array
    {
        $homeroom = null;
        if ($class->relationLoaded('homeroomTeacher') && $class->homeroomTeacher) {
            $t = $class->homeroomTeacher;
            $homeroom = [
                'id' => $t->id,
                'employee_id' => $t->employee_id,
                'name' => $t->relationLoaded('user') && $t->user ? $t->user->name : null,
            ];
        }

        return [
            'id' => $class->id,
            'name' => $class->name,
            'section' => $class->section,
            'academic_year' => $class->academic_year,
            'max_students' => $class->max_students,
            'homeroom_teacher_id' => $class->homeroom_teacher_id,
            'homeroom_teacher' => $homeroom,
        ];
    }
}
