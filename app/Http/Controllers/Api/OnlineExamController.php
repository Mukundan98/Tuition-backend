<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\OnlineExam;
use App\Models\OnlineExamAttempt;
use App\Models\OnlineExamQuestion;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class OnlineExamController extends Controller
{
    // ——— Admin ———

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);
        $query = OnlineExam::query()
            ->with(['schoolClass:id,name,section'])
            ->withCount(['questions', 'attempts']);

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->integer('class_id'));
        }

        $paginator = $query->orderByDesc('updated_at')->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($paginator->items())->map(fn (OnlineExam $e) => $this->formatExamSummary($e))->values()->all(),
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
            'description' => ['nullable', 'string', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after_or_equal:available_from'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:600'],
        ]);

        $exam = OnlineExam::create($data);

        return ApiResponse::success([
            'exam' => $this->formatExamAdmin($exam->load('schoolClass')),
        ], 'Online exam created', 201);
    }

    public function show(OnlineExam $onlineExam): JsonResponse
    {
        $onlineExam->load(['schoolClass:id,name,section', 'questions']);

        return ApiResponse::success([
            'exam' => $this->formatExamAdmin($onlineExam),
        ]);
    }

    public function update(Request $request, OnlineExam $onlineExam): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['sometimes', 'required', 'exists:classes,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_published' => ['sometimes', 'boolean'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after_or_equal:available_from'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:600'],
        ]);

        $onlineExam->update($data);

        return ApiResponse::success([
            'exam' => $this->formatExamAdmin($onlineExam->fresh()->load(['schoolClass', 'questions'])),
        ]);
    }

    public function destroy(OnlineExam $onlineExam): JsonResponse
    {
        $onlineExam->delete();

        return ApiResponse::success(null, 'Deleted');
    }

    public function storeQuestion(Request $request, OnlineExam $onlineExam): JsonResponse
    {
        $data = $this->validateQuestionCreate($request);
        $maxOrder = (int) $onlineExam->questions()->max('sort_order');
        $data['online_exam_id'] = $onlineExam->id;
        $data['sort_order'] = $maxOrder + 1;
        $q = OnlineExamQuestion::create($data);

        return ApiResponse::success(['question' => $this->formatQuestionAdmin($q)], 'Question added', 201);
    }

    public function updateQuestion(Request $request, OnlineExamQuestion $onlineExamQuestion): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['sometimes', 'string', 'max:5000'],
            'options' => ['sometimes', 'array', 'min:2', 'max:12'],
            'options.*' => ['required_with:options', 'string', 'max:500'],
            'correct_index' => ['sometimes', 'integer', 'min:0'],
            'points' => ['sometimes', 'numeric', 'min:0.25', 'max:1000'],
        ]);

        $options = $data['options'] ?? $onlineExamQuestion->options;
        $correctIndex = array_key_exists('correct_index', $data)
            ? (int) $data['correct_index']
            : (int) $onlineExamQuestion->correct_index;

        if (is_array($options) && $correctIndex >= count($options)) {
            return ApiResponse::error('correct_index must be within options length.', 422);
        }

        $onlineExamQuestion->fill($data);
        if (array_key_exists('correct_index', $data)) {
            $onlineExamQuestion->correct_index = $correctIndex;
        }
        $onlineExamQuestion->save();

        return ApiResponse::success(['question' => $this->formatQuestionAdmin($onlineExamQuestion->fresh())]);
    }

    public function destroyQuestion(OnlineExamQuestion $onlineExamQuestion): JsonResponse
    {
        $onlineExamQuestion->delete();

        return ApiResponse::success(null, 'Question removed');
    }

    public function attempts(Request $request, OnlineExam $onlineExam): JsonResponse
    {
        unset($request);
        $rows = OnlineExamAttempt::query()
            ->where('online_exam_id', $onlineExam->id)
            ->whereNotNull('submitted_at')
            ->with(['student:id,name,admission_number,class_id'])
            ->orderByDesc('submitted_at')
            ->get();

        return ApiResponse::success([
            'items' => $rows->map(fn (OnlineExamAttempt $a) => [
                'id' => $a->id,
                'student' => $a->student ? [
                    'id' => $a->student->id,
                    'name' => $a->student->name,
                    'admission_number' => $a->student->admission_number,
                ] : null,
                'score' => $a->score !== null ? (string) $a->score : null,
                'max_score' => $a->max_score !== null ? (string) $a->max_score : null,
                'percentage' => $a->score !== null && $a->max_score && (float) $a->max_score > 0
                    ? round(100 * (float) $a->score / (float) $a->max_score, 1)
                    : null,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function analytics(Request $request, OnlineExam $onlineExam): JsonResponse
    {
        unset($request);
        $questions = $onlineExam->questions()->get();
        $maxScore = round((float) $questions->sum('points'), 2);
        $submitted = OnlineExamAttempt::query()
            ->where('online_exam_id', $onlineExam->id)
            ->whereNotNull('submitted_at')
            ->get();

        $n = $submitted->count();
        $avgScore = $n > 0 ? round((float) $submitted->avg('score'), 2) : null;
        $avgPct = $n > 0 && $maxScore > 0
            ? round(100 * (float) $submitted->sum('score') / ($n * $maxScore), 1)
            : null;

        $perQuestion = [];
        foreach ($questions as $q) {
            $correct = 0;
            $qid = (string) $q->id;
            foreach ($submitted as $att) {
                $r = $att->responses ?? [];
                if (isset($r[$qid]) && (int) $r[$qid] === (int) $q->correct_index) {
                    $correct++;
                }
            }
            $perQuestion[] = [
                'question_id' => $q->id,
                'prompt_preview' => Str::limit(strip_tags($q->prompt), 100),
                'points' => (string) $q->points,
                'correct_count' => $correct,
                'submitted_count' => $n,
                'correct_rate_pct' => $n > 0 ? round(100 * $correct / $n, 1) : null,
            ];
        }

        return ApiResponse::success([
            'exam_id' => $onlineExam->id,
            'title' => $onlineExam->title,
            'submitted_count' => $n,
            'max_score' => (string) $maxScore,
            'average_score' => $avgScore,
            'average_percentage' => $avgPct,
            'per_question' => $perQuestion,
        ]);
    }

    // ——— Student ———

    public function forStudent(Request $request): JsonResponse
    {
        $student = $this->currentStudentOrAbort($request);
        $exams = OnlineExam::query()
            ->where('class_id', $student->class_id)
            ->where('is_published', true)
            ->whereHas('questions')
            ->withCount('questions')
            ->with(['attempts' => fn ($q) => $q->where('student_id', $student->id)])
            ->orderByDesc('updated_at')
            ->get();

        $items = $exams->map(function (OnlineExam $e) use ($student) {
            $att = $e->attempts->first();
            $submitted = $att && $att->submitted_at;

            return [
                'id' => $e->id,
                'title' => $e->title,
                'description' => $e->description,
                'duration_minutes' => $e->duration_minutes,
                'questions_count' => $e->questions_count,
                'available_from' => $e->available_from?->toIso8601String(),
                'available_until' => $e->available_until?->toIso8601String(),
                'is_submitted' => (bool) $submitted,
                'score' => $submitted && $att->score !== null ? (string) $att->score : null,
                'max_score' => $submitted && $att->max_score !== null ? (string) $att->max_score : null,
            ];
        })->values()->all();

        return ApiResponse::success([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'admission_number' => $student->admission_number,
            ],
            'items' => $items,
        ]);
    }

    public function take(Request $request, OnlineExam $onlineExam): JsonResponse
    {
        $student = $this->currentStudentOrAbort($request);
        $this->ensureStudentClass($student, $onlineExam);
        $this->ensurePublished($onlineExam);
        $this->ensureWithinWindow($onlineExam);

        $questions = $onlineExam->questions()->get()->map(fn (OnlineExamQuestion $q) => [
            'id' => $q->id,
            'sort_order' => $q->sort_order,
            'prompt' => $q->prompt,
            'options' => $q->options,
        ])->values()->all();

        $attempt = OnlineExamAttempt::query()
            ->where('online_exam_id', $onlineExam->id)
            ->where('student_id', $student->id)
            ->first();

        return ApiResponse::success([
            'exam' => [
                'id' => $onlineExam->id,
                'title' => $onlineExam->title,
                'description' => $onlineExam->description,
                'duration_minutes' => $onlineExam->duration_minutes,
                'available_from' => $onlineExam->available_from?->toIso8601String(),
                'available_until' => $onlineExam->available_until?->toIso8601String(),
            ],
            'questions' => $questions,
            'attempt' => $attempt ? [
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'score' => $attempt->score !== null ? (string) $attempt->score : null,
                'max_score' => $attempt->max_score !== null ? (string) $attempt->max_score : null,
            ] : null,
        ]);
    }

    public function submit(Request $request, OnlineExam $onlineExam): JsonResponse
    {
        $student = $this->currentStudentOrAbort($request);
        $this->ensureStudentClass($student, $onlineExam);
        $this->ensurePublished($onlineExam);
        $this->ensureWithinWindow($onlineExam);

        $existing = OnlineExamAttempt::query()
            ->where('online_exam_id', $onlineExam->id)
            ->where('student_id', $student->id)
            ->first();
        if ($existing?->submitted_at) {
            return ApiResponse::error('You have already submitted this exam.', 422);
        }

        $questions = $onlineExam->questions()->get()->keyBy('id');
        if ($questions->isEmpty()) {
            return ApiResponse::error('This exam has no questions yet.', 422);
        }

        $data = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['integer', 'min:0', 'max:25'],
        ]);

        $answers = [];
        foreach ($data['answers'] as $key => $idx) {
            $answers[(string) $key] = (int) $idx;
        }

        foreach ($questions->keys() as $qid) {
            if (! array_key_exists((string) $qid, $answers)) {
                return ApiResponse::error('Answer every question.', 422, [
                    'answers' => ['Missing answer for question '.$qid],
                ]);
            }
        }

        foreach (array_keys($answers) as $qid) {
            if (! $questions->has((int) $qid)) {
                return ApiResponse::error('Invalid question id in answers.', 422);
            }
        }

        $maxScore = round((float) $questions->sum('points'), 2);
        $score = 0.0;
        foreach ($questions as $q) {
            $chosen = $answers[(string) $q->id];
            $opts = $q->options ?? [];
            if ($chosen < 0 || $chosen >= count($opts)) {
                return ApiResponse::error('Invalid option index for a question.', 422);
            }
            if ($chosen === (int) $q->correct_index) {
                $score += (float) $q->points;
            }
        }
        $score = round($score, 2);

        DB::transaction(function () use ($onlineExam, $student, $answers, $score, $maxScore): void {
            $attempt = OnlineExamAttempt::query()->firstOrCreate(
                [
                    'online_exam_id' => $onlineExam->id,
                    'student_id' => $student->id,
                ],
                [
                    'started_at' => now(),
                ]
            );

            $attempt->update([
                'responses' => $answers,
                'score' => $score,
                'max_score' => $maxScore,
                'submitted_at' => now(),
            ]);
        });

        $fresh = OnlineExamAttempt::query()
            ->where('online_exam_id', $onlineExam->id)
            ->where('student_id', $student->id)
            ->first();

        return ApiResponse::success([
            'attempt' => [
                'score' => (string) $score,
                'max_score' => (string) $maxScore,
                'percentage' => $maxScore > 0 ? round(100 * $score / $maxScore, 1) : null,
                'submitted_at' => $fresh?->submitted_at?->toIso8601String(),
            ],
        ], 'Submitted');
    }

    public function myResult(Request $request, OnlineExam $onlineExam): JsonResponse
    {
        $student = $this->currentStudentOrAbort($request);
        $this->ensureStudentClass($student, $onlineExam);

        $attempt = OnlineExamAttempt::query()
            ->where('online_exam_id', $onlineExam->id)
            ->where('student_id', $student->id)
            ->first();

        if (! $attempt || ! $attempt->submitted_at) {
            return ApiResponse::error('No submitted attempt yet.', 404);
        }

        $questions = $onlineExam->questions()->get()->keyBy('id');
        $responses = $attempt->responses ?? [];
        $lines = [];
        foreach ($questions as $q) {
            $chosen = $responses[(string) $q->id] ?? null;
            $ok = $chosen !== null && (int) $chosen === (int) $q->correct_index;
            $lines[] = [
                'question_id' => $q->id,
                'prompt' => $q->prompt,
                'options' => $q->options,
                'chosen_index' => $chosen,
                'correct_index' => (int) $q->correct_index,
                'is_correct' => $ok,
                'points' => (string) $q->points,
                'earned' => $ok ? (string) $q->points : '0',
            ];
        }

        return ApiResponse::success([
            'exam' => [
                'id' => $onlineExam->id,
                'title' => $onlineExam->title,
            ],
            'attempt' => [
                'score' => $attempt->score !== null ? (string) $attempt->score : null,
                'max_score' => $attempt->max_score !== null ? (string) $attempt->max_score : null,
                'percentage' => $attempt->max_score && (float) $attempt->max_score > 0
                    ? round(100 * (float) $attempt->score / (float) $attempt->max_score, 1)
                    : null,
                'submitted_at' => $attempt->submitted_at->toIso8601String(),
            ],
            'lines' => $lines,
        ]);
    }

    private function currentStudentOrAbort(Request $request): Student
    {
        $user = $request->user();
        $user->loadMissing('studentProfile');
        $s = $user->studentProfile;
        if (! $s) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $s;
    }

    private function ensureStudentClass(Student $student, OnlineExam $onlineExam): void
    {
        if ((int) $student->class_id !== (int) $onlineExam->class_id) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }

    private function ensurePublished(OnlineExam $onlineExam): void
    {
        if (! $onlineExam->is_published) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }

    private function ensureWithinWindow(OnlineExam $onlineExam): void
    {
        $now = now();
        if ($onlineExam->available_from && $now->lt($onlineExam->available_from)) {
            abort(Response::HTTP_FORBIDDEN, 'Exam not open yet.');
        }
        if ($onlineExam->available_until && $now->gt($onlineExam->available_until)) {
            abort(Response::HTTP_FORBIDDEN, 'Exam window closed.');
        }
    }

    /** @return array<string, mixed> */
    private function validateQuestionCreate(Request $request): array
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:5000'],
            'options' => ['required', 'array', 'min:2', 'max:12'],
            'options.*' => ['required', 'string', 'max:500'],
            'correct_index' => ['required', 'integer', 'min:0'],
            'points' => ['sometimes', 'numeric', 'min:0.25', 'max:1000'],
        ]);
        $n = count($data['options']);
        if ($data['correct_index'] >= $n) {
            throw ValidationException::withMessages([
                'correct_index' => ['Must be a valid option index for the given options.'],
            ]);
        }

        return $data;
    }

    private function formatExamSummary(OnlineExam $e): array
    {
        return [
            'id' => $e->id,
            'class_id' => $e->class_id,
            'title' => $e->title,
            'is_published' => $e->is_published,
            'questions_count' => $e->questions_count ?? $e->questions()->count(),
            'attempts_count' => $e->attempts_count ?? 0,
            'available_from' => $e->available_from?->toIso8601String(),
            'available_until' => $e->available_until?->toIso8601String(),
            'school_class' => $e->relationLoaded('schoolClass') && $e->schoolClass
                ? [
                    'id' => $e->schoolClass->id,
                    'name' => $e->schoolClass->name,
                    'section' => $e->schoolClass->section,
                ]
                : null,
        ];
    }

    private function formatExamAdmin(OnlineExam $e): array
    {
        $row = [
            'id' => $e->id,
            'class_id' => $e->class_id,
            'title' => $e->title,
            'description' => $e->description,
            'is_published' => $e->is_published,
            'available_from' => $e->available_from?->toIso8601String(),
            'available_until' => $e->available_until?->toIso8601String(),
            'duration_minutes' => $e->duration_minutes,
            'school_class' => $e->relationLoaded('schoolClass') && $e->schoolClass
                ? [
                    'id' => $e->schoolClass->id,
                    'name' => $e->schoolClass->name,
                    'section' => $e->schoolClass->section,
                ]
                : null,
        ];
        if ($e->relationLoaded('questions')) {
            $row['questions'] = $e->questions->map(fn (OnlineExamQuestion $q) => $this->formatQuestionAdmin($q))->values()->all();
        }

        return $row;
    }

    private function formatQuestionAdmin(OnlineExamQuestion $q): array
    {
        return [
            'id' => $q->id,
            'online_exam_id' => $q->online_exam_id,
            'sort_order' => $q->sort_order,
            'prompt' => $q->prompt,
            'options' => $q->options,
            'correct_index' => (int) $q->correct_index,
            'points' => (string) $q->points,
        ];
    }
}
