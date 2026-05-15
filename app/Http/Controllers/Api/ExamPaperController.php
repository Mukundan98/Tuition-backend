<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExamPaperController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('role', 'teacherProfile');
        $slug = $user->role?->slug;
        if (! in_array($slug, ['admin', 'teacher'], true)) {
            return ApiResponse::error('Forbidden.', 403);
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $query = ExamPaper::query()
            ->with([
                'exam:id,title,exam_date',
                'teacher:id,user_id,employee_id',
                'teacher.user:id,name,email',
                'subject:id,class_id,name,code',
                'schoolClass:id,name,section',
            ]);

        $teacherId = $user->teacherProfile?->id;
        if ($slug === 'teacher') {
            if (! $teacherId) {
                return ApiResponse::success([
                    'items' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                    ],
                    'stats' => null,
                ]);
            }
            $query->where('teacher_id', $teacherId);
        }

        $stats = null;
        if ($slug === 'admin') {
            $basis = ExamPaper::query();
            $stats = [
                'total_uploads' => (clone $basis)->count(),
                'uploads_last_7_days' => (clone $basis)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
                'unique_teachers' => Teacher::query()->whereHas('examPapers')->count(),
                'unique_classes' => (int) (clone $basis)->whereNotNull('class_id')->distinct()->count('class_id'),
                'unique_subjects' => (int) (clone $basis)->whereNotNull('subject_id')->distinct()->count('subject_id'),
            ];
        }

        if ($request->filled('teacher_id') && $slug === 'admin') {
            $query->where('teacher_id', $request->integer('teacher_id'));
        }

        if ($request->filled('class_id') && $slug === 'admin') {
            $query->where('class_id', $request->integer('class_id'));
        }

        if ($request->filled('subject_id') && $slug === 'admin') {
            $query->where('subject_id', $request->integer('subject_id'));
        }

        if ($q = $request->get('q')) {
            $query->where(function ($sub) use ($q): void {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('original_filename', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%")
                    ->orWhereHas('subject', function ($sq) use ($q): void {
                        $sq->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%");
                    })
                    ->orWhereHas('schoolClass', function ($cq) use ($q): void {
                        $cq->where('name', 'like', "%{$q}%")
                            ->orWhere('section', 'like', "%{$q}%");
                    })
                    ->orWhereHas('teacher.user', function ($uq) use ($q): void {
                        $uq->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            });
        }

        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($paginator->items())
                ->map(fn (ExamPaper $p) => $this->formatExamPaper($p))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'stats' => $stats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('teacherProfile');
        $teacher = $user->teacherProfile;
        if (! $teacher) {
            return ApiResponse::error('No teacher profile is linked to this account.', 422);
        }

        $data = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'exam_id' => ['nullable', 'integer', 'exists:exams,id'],
            /** 20 MB — PDF or Word */
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx'],
        ]);

        $subject = Subject::query()->findOrFail($data['subject_id']);
        if ((int) $subject->teacher_id !== (int) $teacher->id) {
            throw ValidationException::withMessages([
                'subject_id' => ['You can only upload papers for subjects assigned to you.'],
            ]);
        }

        $file = $request->file('file');
        $path = $file->store('exam-papers', 'public');

        $paper = ExamPaper::create([
            'teacher_id' => $teacher->id,
            'class_id' => $subject->class_id,
            'subject_id' => $subject->id,
            'exam_id' => $data['exam_id'] ?? null,
            'title' => $data['title'] ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
            'size_bytes' => $file->getSize() ?: 0,
            'notes' => $data['notes'] ?? null,
        ]);

        $paper->load([
            'exam:id,title,exam_date',
            'subject:id,class_id,name,code',
            'schoolClass:id,name,section',
            'teacher.user:id,name,email',
        ]);

        return ApiResponse::success([
            'exam_paper' => $this->formatExamPaper($paper),
        ], 'Exam paper uploaded.', 201);
    }

    public function destroy(Request $request, ExamPaper $examPaper): JsonResponse
    {
        $user = $request->user()->loadMissing('role', 'teacherProfile');
        $slug = $user->role?->slug;
        if ($slug === 'teacher') {
            if ($examPaper->teacher_id !== $user->teacherProfile?->id) {
                return ApiResponse::error('Forbidden.', 403);
            }
        } elseif ($slug !== 'admin') {
            return ApiResponse::error('Forbidden.', 403);
        }

        if ($examPaper->stored_path && Storage::disk('public')->exists($examPaper->stored_path)) {
            Storage::disk('public')->delete($examPaper->stored_path);
        }
        $examPaper->delete();

        return ApiResponse::success(null, 'Removed.');
    }

    public function download(Request $request, ExamPaper $examPaper): StreamedResponse|JsonResponse
    {
        $user = $request->user()->loadMissing('role', 'teacherProfile');
        $slug = $user->role?->slug;
        if ($slug === 'teacher') {
            if ($examPaper->teacher_id !== $user->teacherProfile?->id) {
                return ApiResponse::error('Forbidden.', 403);
            }
        } elseif ($slug !== 'admin') {
            return ApiResponse::error('Forbidden.', 403);
        }

        if (! $examPaper->stored_path || ! Storage::disk('public')->exists($examPaper->stored_path)) {
            return ApiResponse::error('File not found.', 404);
        }

        return Storage::disk('public')->download(
            $examPaper->stored_path,
            $examPaper->original_filename
        );
    }

    private function formatExamPaper(ExamPaper $p): array
    {
        $row = [
            'id' => $p->id,
            'title' => $p->title,
            'notes' => $p->notes,
            'original_filename' => $p->original_filename,
            'mime_type' => $p->mime_type,
            'size_bytes' => $p->size_bytes,
            'created_at' => $p->created_at?->toIso8601String(),
            'exam' => $p->exam
                ? [
                    'id' => $p->exam->id,
                    'title' => $p->exam->title,
                    'exam_date' => $p->exam->exam_date?->toDateString(),
                ]
                : null,
            'subject' => $p->relationLoaded('subject') && $p->subject
                ? [
                    'id' => $p->subject->id,
                    'name' => $p->subject->name,
                    'code' => $p->subject->code,
                ]
                : null,
            'school_class' => $p->relationLoaded('schoolClass') && $p->schoolClass
                ? [
                    'id' => $p->schoolClass->id,
                    'name' => $p->schoolClass->name,
                    'section' => $p->schoolClass->section,
                ]
                : null,
        ];

        if ($p->relationLoaded('teacher') && $p->teacher) {
            $t = $p->teacher;
            $u = $t->user;
            $row['teacher'] = [
                'id' => $t->id,
                'employee_id' => $t->employee_id,
                'name' => $u?->name,
                'email' => $u?->email,
            ];
        }

        return $row;
    }
}
