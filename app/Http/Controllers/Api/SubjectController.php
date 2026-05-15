<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);
        $page = max(1, (int) $request->get('page', 1));

        if (! $request->boolean('aggregate')) {
            $query = Subject::query()->with(['schoolClass:id,name,section', 'teacher.user:id,name']);
            $this->applySubjectIndexFilters($request, $query);

            $paginator = $query->orderBy('code')->orderBy('id')->paginate($perPage);

            return ApiResponse::success([
                'items' => array_map(fn (Subject $s) => $this->formatSubject($s), $paginator->items()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        }

        $base = Subject::query();
        $this->applySubjectIndexFilters($request, $base);

        $totalGroups = (int) DB::query()
            ->fromSub(
                $base->clone()
                    ->selectRaw('code, name, COALESCE(teacher_id, -1) as tid')
                    ->groupBy('code', 'name', DB::raw('COALESCE(teacher_id, -1)')),
                'uniq_groups'
            )
            ->count();

        $offset = ($page - 1) * $perPage;
        $representativeIds = $base->clone()
            ->selectRaw('MIN(id) as representative_id')
            ->groupBy('code', 'name', DB::raw('COALESCE(teacher_id, -1)'))
            ->orderByRaw('MIN(code)')
            ->orderByRaw('MIN(name)')
            ->offset($offset)
            ->limit($perPage)
            ->pluck('representative_id');

        $items = [];
        foreach ($representativeIds as $rid) {
            $root = Subject::query()->find($rid);
            if (! $root) {
                continue;
            }
            $siblings = Subject::query()
                ->where('code', $root->code)
                ->where('name', $root->name)
                ->where(function ($q) use ($root) {
                    if ($root->teacher_id === null) {
                        $q->whereNull('teacher_id');
                    } else {
                        $q->where('teacher_id', $root->teacher_id);
                    }
                })
                ->with(['schoolClass:id,name,section', 'teacher.user:id,name'])
                ->orderBy('class_id')
                ->get();
            if ($siblings->isEmpty()) {
                continue;
            }
            $items[] = $this->formatSubjectGroup($siblings);
        }

        $lastPage = max(1, (int) ceil($totalGroups / $perPage));

        return ApiResponse::success([
            'items' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $totalGroups,
            ],
        ]);
    }

    private function applySubjectIndexFilters(Request $request, Builder $query): void
    {
        if ($request->filled('class_id')) {
            $query->where('class_id', $request->integer('class_id'));
        }

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->integer('teacher_id'));
        }

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_ids' => ['nullable', 'array'],
            'class_ids.*' => ['integer', 'exists:classes,id'],
            'class_id' => ['nullable', 'integer', 'exists:classes,id'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:30'],
        ]);

        $classIds = $data['class_ids'] ?? [];
        if (($data['class_id'] ?? null) !== null && count($classIds) === 0) {
            $classIds = [(int) $data['class_id']];
        }
        $classIds = array_values(array_unique(array_map('intval', $classIds)));
        if (count($classIds) === 0) {
            throw ValidationException::withMessages([
                'class_ids' => ['Select at least one class.'],
            ]);
        }

        unset($data['class_ids'], $data['class_id']);

        $data['code'] = strtoupper(trim($data['code']));

        foreach ($classIds as $cid) {
            if (Subject::where('class_id', $cid)->where('code', $data['code'])->exists()) {
                return ApiResponse::error('Subject code already used in one of the selected classes.', 422, [
                    'class_ids' => ['Code must be unique per class. Remove classes where this code is already taken.'],
                ]);
            }
        }

        $created = DB::transaction(function () use ($data, $classIds) {
            $rows = [];
            foreach ($classIds as $cid) {
                $rows[] = Subject::create(array_merge($data, ['class_id' => $cid]));
            }

            return $rows;
        });

        $load = ['schoolClass', 'teacher.user'];
        foreach ($created as $row) {
            $row->load($load);
        }
        $first = $created[0];

        return ApiResponse::success(
            [
                'subject' => $this->formatSubject($first),
                'subjects' => array_map(fn (Subject $s) => $this->formatSubject($s), $created),
            ],
            count($created) > 1 ? 'Subjects created' : 'Subject created',
            201
        );
    }

    public function show(Subject $subject): JsonResponse
    {
        $formatted = $this->formatSubject($subject->load(['schoolClass', 'teacher.user']));
        $formatted['class_ids'] = $this->linkedClassIdsForSubject($subject);

        return ApiResponse::success([
            'subject' => $formatted,
        ]);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        $data = $request->validate([
            'class_ids' => ['sometimes', 'array', 'min:1'],
            'class_ids.*' => ['integer', 'exists:classes,id'],
            'teacher_id' => ['nullable', 'exists:teachers,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:30'],
        ]);

        /** Rows that belong to the same multi-class subject before this request (code + name + teacher). */
        $linkedSiblingIds = Subject::query()
            ->where('code', $subject->code)
            ->where('name', $subject->name)
            ->where(function ($q) use ($subject) {
                if ($subject->teacher_id === null) {
                    $q->whereNull('teacher_id');
                } else {
                    $q->where('teacher_id', $subject->teacher_id);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
            $duplicate = Subject::where('class_id', $subject->class_id)
                ->where('code', $data['code'])
                ->where('id', '!=', $subject->id)
                ->exists();
            if ($duplicate) {
                return ApiResponse::error('Subject code already used in this class.', 422, [
                    'code' => ['Code must be unique per class.'],
                ]);
            }
        }

        $classIds = null;
        if ($request->has('class_ids')) {
            $classIds = array_values(array_unique(array_map('intval', $data['class_ids'])));
            if (! in_array((int) $subject->class_id, $classIds, true)) {
                return ApiResponse::error('Selection must include this subject\'s current class.', 422, [
                    'class_ids' => ['Include the class this row belongs to, or cancel and edit another copy.'],
                ]);
            }
        }

        unset($data['class_ids']);

        $subject->update($data);
        $subject->refresh();

        if ($classIds !== null) {
            $finalName = $subject->name;
            $finalCode = $subject->code;
            $finalTeacher = $subject->teacher_id;

            foreach ($classIds as $cid) {
                if ((int) $cid === (int) $subject->class_id) {
                    continue;
                }

                $existingSibling = Subject::query()
                    ->where('class_id', $cid)
                    ->whereIn('id', $linkedSiblingIds)
                    ->first();

                if ($existingSibling) {
                    $existingSibling->update([
                        'name' => $finalName,
                        'code' => $finalCode,
                        'teacher_id' => $finalTeacher,
                    ]);

                    continue;
                }

                $existing = Subject::where('class_id', $cid)->where('code', $finalCode)->first();
                if ($existing) {
                    if ($existing->name === $finalName && $existing->teacher_id == $finalTeacher) {
                        continue;
                    }

                    return ApiResponse::error('Subject code already used in another selected class.', 422, [
                        'class_ids' => ['That code is already taken by a different subject in one of the selected classes.'],
                    ]);
                }

                Subject::create([
                    'class_id' => $cid,
                    'name' => $finalName,
                    'code' => $finalCode,
                    'teacher_id' => $finalTeacher,
                ]);
            }
        }

        return ApiResponse::success([
            'subject' => $this->formatSubject($subject->fresh()->load(['schoolClass', 'teacher.user'])),
        ]);
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $subject->delete();

        return ApiResponse::success(null, 'Deleted');
    }

    /** Subjects assigned to the signed-in teacher (for exam paper uploads, etc.). */
    public function forCurrentTeacher(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('teacherProfile');
        $tid = $user->teacherProfile?->id;
        if (! $tid) {
            return ApiResponse::success(['items' => []]);
        }

        $items = Subject::query()
            ->where('teacher_id', $tid)
            ->with(['schoolClass:id,name,section'])
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'items' => $items->map(fn (Subject $s) => $this->formatSubject($s))->values()->all(),
        ]);
    }

    private function formatSubject(Subject $subject): array
    {
        return [
            'id' => $subject->id,
            'class_id' => $subject->class_id,
            'teacher_id' => $subject->teacher_id,
            'name' => $subject->name,
            'code' => $subject->code,
            'school_class' => $subject->relationLoaded('schoolClass') && $subject->schoolClass
                ? [
                    'id' => $subject->schoolClass->id,
                    'name' => $subject->schoolClass->name,
                    'section' => $subject->schoolClass->section,
                ]
                : null,
            'teacher' => $subject->relationLoaded('teacher') && $subject->teacher
                ? [
                    'id' => $subject->teacher->id,
                    'name' => $subject->teacher->relationLoaded('user') && $subject->teacher->user
                        ? $subject->teacher->user->name : null,
                ]
                : null,
        ];
    }

    /**
     * One list row per logical subject (same code, name, teacher), with compact class labels.
     *
     * @param  Collection<int, Subject>  $siblings
     */
    private function formatSubjectGroup(Collection $siblings): array
    {
        /** @var Subject $rep */
        $rep = $siblings->sortBy('id')->first();
        $ids = $siblings->pluck('id')->sort()->values()->all();

        $tokens = [];
        foreach ($siblings as $sub) {
            $nm = $sub->relationLoaded('schoolClass') ? $sub->schoolClass?->name : null;
            $tok = $this->classListDisplayToken($nm);
            if ($tok !== null) {
                $tokens[] = $tok;
            }
        }
        $tokens = array_values(array_unique($tokens));
        $allNumeric = $tokens !== [] && collect($tokens)->every(fn ($t) => ctype_digit((string) $t));
        if ($allNumeric) {
            usort($tokens, fn ($a, $b) => (int) $a <=> (int) $b);
        } else {
            sort($tokens, SORT_NATURAL | SORT_FLAG_CASE);
        }
        $classesLabel = $tokens !== [] ? implode(', ', $tokens) : null;

        $row = $this->formatSubject($rep);
        $row['id'] = (int) min($ids);
        $row['subject_ids'] = array_map('intval', $ids);
        $row['classes_label'] = $classesLabel;
        $row['school_class'] = null;

        return $row;
    }

    /** Short token for the Class column (e.g. "6"); skips cohorts named "common". */
    private function classListDisplayToken(?string $className): ?string
    {
        if ($className === null) {
            return null;
        }
        if (strtolower(trim($className)) === 'common') {
            return null;
        }
        if (preg_match('/(\d+)/', $className, $m)) {
            return (string) (int) $m[1];
        }

        return trim($className);
    }

    /** @return list<int> */
    private function linkedClassIdsForSubject(Subject $subject): array
    {
        $q = Subject::query()
            ->where('code', $subject->code)
            ->where('name', $subject->name);
        if ($subject->teacher_id === null) {
            $q->whereNull('teacher_id');
        } else {
            $q->where('teacher_id', $subject->teacher_id);
        }

        return $q->pluck('class_id')->unique()->sort()->values()->all();
    }
}
