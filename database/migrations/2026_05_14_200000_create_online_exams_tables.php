<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->timestamps();
        });

        Schema::create('online_exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('online_exam_id')->constrained('online_exams')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('prompt');
            $table->json('options');
            $table->unsignedTinyInteger('correct_index');
            $table->decimal('points', 8, 2)->default(1);
            $table->timestamps();
        });

        Schema::create('online_exam_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('online_exam_id')->constrained('online_exams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->json('responses')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['online_exam_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_exam_attempts');
        Schema::dropIfExists('online_exam_questions');
        Schema::dropIfExists('online_exams');
    }
};
