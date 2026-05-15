<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Student;
use App\Models\Subject;
use App\Services\GradeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ExamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);
        $query = Exam::query()->with(['schoolClass:id,name,section']);

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->integer('class_id'));
        }

        if ($search = $request->get('q')) {
            $query->where('title', 'like', "%{$search}%");
        }

        $paginator = $query->orderByDesc('exam_date')->orderByDesc('id')->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (Exam $e) => $this->formatExam($e))->values()->all(),
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
            'class_id' => ['required', 'exists:classes,id'],
            'title' => ['required', 'string', 'max:255'],
            'exam_date' => ['required', 'date'],
            'max_marks' => ['required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $exam = Exam::create($data);

        return ApiResponse::success([
            'exam' => $this->formatExam($exam->load('schoolClass')),
        ], 'Exam created', 201);
    }

    public function show(Exam $exam): JsonResponse
    {
        $exam->load('schoolClass:id,name,section');

        return ApiResponse::success(['exam' => $this->formatExam($exam, true)]);
    }

    public function update(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'exam_date' => ['sometimes', 'required', 'date'],
            'max_marks' => ['sometimes', 'required', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $exam->update($data);

        if (isset($data['max_marks'])) {
            $exam->unsetRelation('results');
            ExamResult::query()->where('exam_id', $exam->id)->get()->each(fn (ExamResult $r) => $r->save());
        }

        return ApiResponse::success(['exam' => $this->formatExam($exam->fresh()->load('schoolClass'))]);
    }

    public function destroy(Exam $exam): JsonResponse
    {
        $exam->delete();

        return ApiResponse::success(null, 'Deleted');
    }

    public function markSheet(Exam $exam): JsonResponse
    {
        $exam->loadMissing('schoolClass:id,name,section');
        $subjects = $exam->subjectsForClass()->map(fn (Subject $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'code' => $s->code,
        ])->values()->all();

        $students = Student::query()
            ->where('class_id', $exam->class_id)
            ->orderBy('name')
            ->get(['id', 'name', 'admission_number'])
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'admission_number' => $s->admission_number,
            ])->all();

        $cells = ExamResult::query()
            ->where('exam_id', $exam->id)
            ->get(['student_id', 'subject_id', 'marks_obtained', 'grade', 'remarks'])
            ->groupBy(fn (ExamResult $r) => "{$r->student_id}:{$r->subject_id}");

        $marks = [];
        foreach ($cells as $key => $row) {
            $r = $row->first();
            $marks[$key] = [
                'marks_obtained' => (string) $r->marks_obtained,
                'grade' => $r->grade,
                'remarks' => $r->remarks,
            ];
        }

        return ApiResponse::success([
            'exam' => $this->formatExam($exam),
            'subjects' => $subjects,
            'students' => $students,
            'cells' => $marks,
            'subject_count' => count($subjects),
            'student_count' => count($students),
        ]);
    }

    public function bulkResults(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'items.*.subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'items.*.marks_obtained' => ['required', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $max = (float) $exam->max_marks;

        foreach ($data['items'] as $item) {
            Student::query()->where('id', $item['student_id'])->where('class_id', $exam->class_id)->firstOrFail();
            Subject::query()->where('id', $item['subject_id'])->where('class_id', $exam->class_id)->firstOrFail();
            $m = (float) $item['marks_obtained'];
            if ($m > $max + 0.001) {
                return ApiResponse::error(
                    'Marks exceed max marks for this exam.',
                    422,
                    ['items' => ['Each mark must be ≤ '.number_format($max, 2)]]
                );
            }
        }

        DB::transaction(function () use ($data, $exam): void {
            foreach ($data['items'] as $item) {
                $m = (float) $item['marks_obtained'];
                ExamResult::query()->updateOrCreate(
                    [
                        'exam_id' => $exam->id,
                        'student_id' => $item['student_id'],
                        'subject_id' => $item['subject_id'],
                    ],
                    [
                        'marks_obtained' => $m,
                        'remarks' => $item['remarks'] ?? null,
                    ]
                );
            }
        });

        return ApiResponse::success(null, 'Marks saved');
    }

    public function results(Exam $exam): JsonResponse
    {
        $exam->load(['schoolClass:id,name,section']);

        $students = Student::query()
            ->where('class_id', $exam->class_id)
            ->orderBy('name')
            ->get();

        $max = (float) $exam->max_marks;
        $byStudent = ExamResult::query()
            ->where('exam_id', $exam->id)
            ->with(['subject:id,name,code'])
            ->get()
            ->groupBy('student_id');

        $rows = $students->map(function (Student $s) use ($byStudent, $max): array {
            $res = $byStudent->get($s->id, collect());
            if ($res->isEmpty()) {
                return [
                    'student' => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'admission_number' => $s->admission_number,
                    ],
                    'average_marks' => null,
                    'average_grade' => null,
                    'average_percentage' => null,
                    'lines' => [],
                ];
            }
            $sum = round((float) $res->sum('marks_obtained'), 2);
            $n = $res->count();
            $avg = round($sum / $n, 2);
            $avgPct = round(100 * $avg / $max, 2);

            return [
                'student' => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'admission_number' => $s->admission_number,
                ],
                'average_marks' => $avg,
                'average_grade' => GradeService::fromMarks($avg, $max),
                'average_percentage' => $avgPct,
                'lines' => $res->map(fn (ExamResult $r) => [
                    'subject_id' => $r->subject_id,
                    'subject_name' => $r->subject?->name,
                    'subject_code' => $r->subject?->code,
                    'marks_obtained' => (string) $r->marks_obtained,
                    'grade' => $r->grade,
                    'remarks' => $r->remarks,
                ])->values()->all(),
            ];
        })->values()->all();

        return ApiResponse::success([
            'exam' => $this->formatExam($exam),
            'rows' => $rows,
        ]);
    }

    public function reportCard(Request $request, Exam $exam, Student $student)
    {
        unset($request);
        if ($student->class_id !== $exam->class_id) {
            return ApiResponse::error('Student is not in this exam class.', 422);
        }

        $lines = ExamResult::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->with('subject:id,name,code')
            ->orderBy('subject_id')
            ->get();

        $max = (float) $exam->max_marks;
        $sum = round((float) $lines->sum('marks_obtained'), 2);
        $count = max(1, $lines->count());
        $avg = round($sum / $count, 2);
        $avgGrade = GradeService::fromMarks($avg, $max);

        $pdf = Pdf::loadView('pdf.exam-report-card', [
            'exam' => $exam->loadMissing('schoolClass'),
            'student' => $student,
            'lines' => $lines,
            'maxMarks' => $max,
            'averageMarks' => $avg,
            'averageGrade' => $avgGrade,
            'appName' => config('app.name', 'Tuition Management'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download('report-'.$exam->id.'-student-'.$student->id.'.pdf');
    }

    public function studentPerformance(Request $request, Student $student): JsonResponse
    {
        $this->authorizeStudentExamPerformance($request, $student);

        $results = ExamResult::query()
            ->where('student_id', $student->id)
            ->with([
                'exam' => fn ($q) => $q->with('schoolClass:id,name,section'),
                'subject:id,name,code',
            ])
            ->get()
            ->groupBy('exam_id');

        $examsPayload = [];

        foreach ($results as $examId => $rows) {
            $first = $rows->first();
            $examModel = $first?->exam;
            if (! $examModel instanceof Exam) {
                continue;
            }
            $max = (float) $examModel->max_marks;
            $sum = round((float) $rows->sum('marks_obtained'), 2);
            $n = $rows->count();
            $avg = round($sum / max(1, $n), 2);
            $lines = $rows->sortBy(fn (ExamResult $r) => $r->subject?->code ?? (string) $r->subject_id)
                ->values()
                ->map(fn (ExamResult $r) => [
                    'subject_id' => $r->subject_id,
                    'subject_name' => $r->subject?->name,
                    'subject_code' => $r->subject?->code,
                    'max_marks' => (string) $examModel->max_marks,
                    'marks_obtained' => (string) $r->marks_obtained,
                    'grade' => $r->grade,
                    'remarks' => $r->remarks,
                ])
                ->all();
            $examsPayload[] = [
                'exam' => [
                    'id' => $examModel->id,
                    'title' => $examModel->title,
                    'exam_date' => $examModel->exam_date->toDateString(),
                    'max_marks' => (string) $examModel->max_marks,
                    'class' => $examModel->schoolClass ? [
                        'id' => $examModel->schoolClass->id,
                        'name' => $examModel->schoolClass->name,
                        'section' => $examModel->schoolClass->section,
                    ] : null,
                ],
                'average_marks' => $avg,
                'average_grade' => GradeService::fromMarks($avg, $max),
                'average_percentage' => round(100 * $avg / $max, 2),
                'subjects_count' => $n,
                'lines' => $lines,
            ];
        }

        usort($examsPayload, fn ($a, $b) => strcmp($b['exam']['exam_date'], $a['exam']['exam_date']));

        return ApiResponse::success([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
            ],
            'exams' => $examsPayload,
        ]);
    }

    private function authorizeStudentExamPerformance(Request $request, Student $student): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        $user->loadMissing('role');
        if ($user->role?->slug === 'admin') {
            return;
        }
        $user->loadMissing('studentProfile');
        if ($user->role?->slug === 'student'
            && $user->studentProfile
            && (int) $user->studentProfile->id === (int) $student->id) {
            return;
        }

        abort(Response::HTTP_FORBIDDEN);
    }

    /** @return array<string, mixed> */
    private function formatExam(Exam $exam, bool $includeNotes = false): array
    {
        $row = [
            'id' => $exam->id,
            'class_id' => $exam->class_id,
            'title' => $exam->title,
            'exam_date' => $exam->exam_date->toDateString(),
            'max_marks' => (string) $exam->max_marks,
            'school_class' => $exam->schoolClass ? [
                'id' => $exam->schoolClass->id,
                'name' => $exam->schoolClass->name,
                'section' => $exam->schoolClass->section,
            ] : null,
        ];

        if ($includeNotes) {
            $row['notes'] = $exam->notes;
        }

        return $row;
    }
}
