<?php

namespace App\Http\Controllers\Api;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return ApiResponse::success([
                'students' => [],
                'teachers' => [],
                'classes' => [],
            ]);
        }

        $like = '%'.addcslashes($q, '%_\\').'%';

        $students = Student::query()
            ->where(function ($qb) use ($like): void {
                $qb->where('name', 'like', $like)
                    ->orWhere('admission_number', 'like', $like)
                    ->orWhere('email', 'like', $like);
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'admission_number']);

        $teachers = Teacher::query()
            ->with(['user:id,name,email'])
            ->where(function ($qb) use ($like): void {
                $qb->where('employee_id', 'like', $like)
                    ->orWhere('specialization', 'like', $like)
                    ->orWhereHas('user', function ($u) use ($like): void {
                        $u->where('name', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            })
            ->orderBy('employee_id')
            ->limit(8)
            ->get(['id', 'employee_id', 'user_id']);

        $classes = SchoolClass::query()
            ->where(function ($qb) use ($like): void {
                $qb->where('name', 'like', $like)
                    ->orWhere('section', 'like', $like)
                    ->orWhere('academic_year', 'like', $like);
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'section', 'academic_year']);

        return ApiResponse::success([
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'admission_number' => $s->admission_number,
            ])->values()->all(),
            'teachers' => $teachers->map(fn (Teacher $t) => [
                'id' => $t->id,
                'employee_id' => $t->employee_id,
                'name' => $t->user?->name,
            ])->values()->all(),
            'classes' => $classes->map(fn (SchoolClass $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'section' => $c->section,
                'academic_year' => $c->academic_year,
            ])->values()->all(),
        ]);
    }
}
