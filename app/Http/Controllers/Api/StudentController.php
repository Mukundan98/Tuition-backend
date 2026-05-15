<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 15), 1), 50);
        $query = Student::query()->with(['schoolClass:id,name,section', 'user:id,name,email']);

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('admission_number', 'like', "%{$search}%")
                    ->orWhere('parent_name', 'like', "%{$search}%")
                    ->orWhere('parent_phone', 'like', "%{$search}%")
                    ->orWhere('parent_email', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('class_id')) {
            $query->where('class_id', $request->integer('class_id'));
        }

        $paginator = $query->orderBy('name')->paginate($perPage);

        return ApiResponse::success([
            'items' => array_map(fn (Student $s) => $this->formatStudent($s), $paginator->items()),
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:students,email'],
            'username' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'admission_number' => ['required', 'string', 'max:50', 'unique:students,admission_number'],
            'class_id' => ['nullable', 'exists:classes,id'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'blood_group' => ['nullable', 'string', 'max:10'],
            'parent_name' => ['nullable', 'string', 'max:255'],
            'parent_phone' => ['nullable', 'string', 'max:30'],
            'parent_email' => ['nullable', 'email', 'max:255'],
            'parent_occupation' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ]);

        $username = isset($data['username']) && trim((string) $data['username']) !== '' ? trim((string) $data['username']) : null;

        $photo = $request->file('photo');

        $student = DB::transaction(function () use ($data, $username, $photo): Student {
            $roleId = Role::query()->where('slug', 'student')->value('id') ?? throw new \RuntimeException('Student role missing.');

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $username,
                'password' => $data['password'],
                'role_id' => $roleId,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $fill = collect($data)->except(['photo', 'username', 'password', 'password_confirmation'])->all();
            $fill['user_id'] = $user->id;

            return Student::create($fill);
        });

        if ($photo && $photo->isValid()) {
            $path = $photo->store('students', 'public');
            $student->forceFill(['photo_path' => $path])->save();
        }

        return ApiResponse::success(
            ['student' => $this->formatStudent($student->load(['schoolClass', 'user']))],
            'Student created',
            201
        );
    }

    public function show(Student $student): JsonResponse
    {
        return ApiResponse::success([
            'student' => $this->formatStudent($student->load(['schoolClass', 'user'])),
        ]);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:students,email,'.$student->id],
            'admission_number' => ['sometimes', 'required', 'string', 'max:50', 'unique:students,admission_number,'.$student->id],
            'class_id' => ['nullable', 'exists:classes,id'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'blood_group' => ['nullable', 'string', 'max:10'],
            'parent_name' => ['nullable', 'string', 'max:255'],
            'parent_phone' => ['nullable', 'string', 'max:30'],
            'parent_email' => ['nullable', 'email', 'max:255'],
            'parent_occupation' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ];

        if ($student->user_id !== null) {
            $rules['email'][] = Rule::unique('users', 'email')->ignore($student->user_id);
            $rules['username'] = ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')->ignore($student->user_id)];
            $rules['password'] = ['nullable', 'confirmed', PasswordRule::defaults()];
        }

        $data = $request->validate($rules);

        $photo = $request->file('photo');

        $username = array_key_exists('username', $data) && trim((string) $data['username']) !== '' ? trim((string) $data['username']) : null;
        if (array_key_exists('username', $data)) {
            $data['username'] = $username;
        }

        DB::transaction(function () use ($data, $student): void {
            $userFields = ['username', 'password', 'password_confirmation'];
            $student->update(collect($data)->except([...$userFields, 'photo'])->all());

            if ($student->user_id === null) {
                return;
            }

            $user = User::query()->whereKey($student->user_id)->first();
            if ($user === null) {
                return;
            }

            if (isset($data['name'])) {
                $user->name = $data['name'];
            }
            if (isset($data['email'])) {
                $user->email = $data['email'];
            }
            if (array_key_exists('username', $data)) {
                $user->username = $data['username'];
            }
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
        });

        if ($photo && $photo->isValid()) {
            if ($student->photo_path && Storage::disk('public')->exists($student->photo_path)) {
                Storage::disk('public')->delete($student->photo_path);
            }
            $path = $photo->store('students', 'public');
            $student->forceFill(['photo_path' => $path])->save();
        }

        return ApiResponse::success([
            'student' => $this->formatStudent($student->fresh()->load(['schoolClass', 'user'])),
        ]);
    }

    public function destroy(Student $student): JsonResponse
    {
        if ($student->photo_path && Storage::disk('public')->exists($student->photo_path)) {
            Storage::disk('public')->delete($student->photo_path);
        }
        $userId = $student->user_id;
        $student->delete();
        if ($userId !== null) {
            User::query()->whereKey($userId)->delete();
        }

        return ApiResponse::success(null, 'Deleted');
    }

    public function uploadPhoto(Request $request, Student $student): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'max:2048']]);
        $file = $request->file('photo');
        if ($student->photo_path && Storage::disk('public')->exists($student->photo_path)) {
            Storage::disk('public')->delete($student->photo_path);
        }
        $path = $file->store('students', 'public');
        $student->forceFill(['photo_path' => $path])->save();

        return ApiResponse::success([
            'student' => $this->formatStudent($student->fresh()->load(['schoolClass', 'user'])),
        ]);
    }

    private function formatStudent(Student $student): array
    {
        return [
            'id' => $student->id,
            'user_id' => $student->user_id,
            'class_id' => $student->class_id,
            'name' => $student->name,
            'email' => $student->email,
            'admission_number' => $student->admission_number,
            'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
            'gender' => $student->gender,
            'address' => $student->address,
            'blood_group' => $student->blood_group,
            'parent_name' => $student->parent_name,
            'parent_phone' => $student->parent_phone,
            'parent_email' => $student->parent_email,
            'parent_occupation' => $student->parent_occupation,
            'photo_url' => $student->photo_path
                ? asset('storage/'.$student->photo_path)
                : null,
            'user' => $student->relationLoaded('user') && $student->user
                ? [
                    'id' => $student->user->id,
                    'name' => $student->user->name,
                    'email' => $student->user->email,
                    'username' => $student->user->username,
                ]
                : null,
            'school_class' => $student->relationLoaded('schoolClass') && $student->schoolClass
                ? [
                    'id' => $student->schoolClass->id,
                    'name' => $student->schoolClass->name,
                    'section' => $student->schoolClass->section,
                ]
                : null,
            'created_at' => $student->created_at?->toISOString(),
        ];
    }
}
