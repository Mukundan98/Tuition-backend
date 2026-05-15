<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_papers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('exam_id')->nullable()->constrained('exams')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_papers');
    }
};
