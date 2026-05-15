<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class TeacherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);
        $query = Teacher::query()->with(['user:id,name,email', 'subjects:id,name,code,teacher_id']);

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('employee_id', 'like', "%{$search}%")
                    ->orWhere('qualification', 'like', "%{$search}%")
                    ->orWhere('specialization', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $paginator = $query->orderBy('employee_id')->paginate($perPage);

        return ApiResponse::success([
            'items' => array_map(fn (Teacher $t) => $this->formatTeacher($t), $paginator->items()),
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'employee_id' => ['required', 'string', 'max:50', 'unique:teachers,employee_id'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'phone' => ['nullable', 'string', 'max:30'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $roleId = Role::where('slug', 'teacher')->value('id') ?? throw new \RuntimeException('Teacher role missing.');

        $teacher = DB::transaction(function () use ($data, $roleId): Teacher {
            $username = isset($data['username']) && trim((string) $data['username']) !== '' ? trim((string) $data['username']) : null;

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $username,
                'password' => $data['password'],
                'role_id' => $roleId,
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            return Teacher::create([
                'user_id' => $user->id,
                'employee_id' => $data['employee_id'],
                'qualification' => $data['qualification'] ?? null,
                'specialization' => $data['specialization'] ?? null,
                'joining_date' => $data['joining_date'] ?? null,
                'salary' => $data['salary'] ?? null,
            ]);
        });

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            if ($file && $file->isValid()) {
                $path = $file->store('teachers', 'public');
                $teacher->forceFill(['photo_path' => $path])->save();
            }
        }

        return ApiResponse::success(
            ['teacher' => $this->formatTeacher($teacher->fresh()->load(['user', 'subjects']))],
            'Teacher created',
            201
        );
    }

    public function show(Teacher $teacher): JsonResponse
    {
        $teacher->load(['user', 'subjects.schoolClass']);

        return ApiResponse::success(['teacher' => $this->formatTeacher($teacher, true)]);
    }

    public function update(Request $request, Teacher $teacher): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,'.$teacher->user_id],
            'username' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')->ignore($teacher->user_id)],
            'password' => ['sometimes', 'nullable', 'confirmed', PasswordRule::defaults()],
            'employee_id' => ['sometimes', 'required', 'string', 'max:50', 'unique:teachers,employee_id,'.$teacher->id],
            'qualification' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'joining_date' => ['nullable', 'date'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'phone' => ['nullable', 'string', 'max:30'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $photo = $request->file('photo');

        DB::transaction(function () use ($data, $teacher): void {
            $teacherFields = [];
            foreach (['qualification', 'specialization', 'joining_date', 'salary', 'employee_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $teacherFields[$field] = $data[$field];
                }
            }
            if ($teacherFields !== []) {
                $teacher->fill($teacherFields)->save();
            }

            $user = $teacher->user;
            if ($user !== null) {
                if (isset($data['name'])) {
                    $user->name = $data['name'];
                }
                if (isset($data['email'])) {
                    $user->email = $data['email'];
                }
                if (array_key_exists('username', $data)) {
                    $user->username = isset($data['username']) && trim((string) $data['username']) !== '' ? trim((string) $data['username']) : null;
                }
                if (isset($data['phone'])) {
                    $user->phone = $data['phone'];
                }
                if (! empty($data['password'])) {
                    $user->password = $data['password'];
                }
                $user->save();
            }
        });

        if ($photo && $photo->isValid()) {
            if ($teacher->photo_path && Storage::disk('public')->exists($teacher->photo_path)) {
                Storage::disk('public')->delete($teacher->photo_path);
            }
            $path = $photo->store('teachers', 'public');
            $teacher->forceFill(['photo_path' => $path])->save();
        }

        return ApiResponse::success([
            'teacher' => $this->formatTeacher($teacher->fresh()->load(['user', 'subjects'])),
        ]);
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        DB::transaction(function () use ($teacher): void {
            Subject::where('teacher_id', $teacher->id)->update(['teacher_id' => null]);
            if ($teacher->photo_path && Storage::disk('public')->exists($teacher->photo_path)) {
                Storage::disk('public')->delete($teacher->photo_path);
            }
            $userId = $teacher->user_id;
            $teacher->delete();
            if ($userId !== null) {
                User::whereKey($userId)->delete();
            }
        });

        return ApiResponse::success(null, 'Deleted');
    }

    public function uploadPhoto(Request $request, Teacher $teacher): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'max:2048']]);
        $file = $request->file('photo');
        if ($teacher->photo_path && Storage::disk('public')->exists($teacher->photo_path)) {
            Storage::disk('public')->delete($teacher->photo_path);
        }
        $path = $file->store('teachers', 'public');
        $teacher->forceFill(['photo_path' => $path])->save();

        return ApiResponse::success([
            'teacher' => $this->formatTeacher($teacher->fresh()->load(['user', 'subjects'])),
        ]);
    }

    private function formatTeacher(Teacher $teacher, bool $withClassOnSubjects = false): array
    {
        $subjects = [];
        if ($teacher->relationLoaded('subjects')) {
            foreach ($teacher->subjects as $s) {
                $row = [
                    'id' => $s->id,
                    'name' => $s->name,
                    'code' => $s->code,
                    'class_id' => $s->class_id,
                ];
                if ($withClassOnSubjects && $s->relationLoaded('schoolClass') && $s->schoolClass) {
                    $row['school_class'] = [
                        'id' => $s->schoolClass->id,
                        'name' => $s->schoolClass->name,
                        'section' => $s->schoolClass->section,
                    ];
                }
                $subjects[] = $row;
            }
        }

        return [
            'id' => $teacher->id,
            'user_id' => $teacher->user_id,
            'employee_id' => $teacher->employee_id,
            'qualification' => $teacher->qualification,
            'specialization' => $teacher->specialization,
            'joining_date' => $teacher->joining_date?->format('Y-m-d'),
            'salary' => $teacher->salary,
            'photo_url' => $teacher->photo_path
                ? asset('storage/'.$teacher->photo_path)
                : null,
            'user' => $teacher->relationLoaded('user') && $teacher->user
                ? [
                    'id' => $teacher->user->id,
                    'name' => $teacher->user->name,
                    'email' => $teacher->user->email,
                    'username' => $teacher->user->username,
                    'phone' => $teacher->user->phone,
                ]
                : null,
            'subjects' => $subjects,
            'created_at' => $teacher->created_at?->toISOString(),
        ];
    }
}
