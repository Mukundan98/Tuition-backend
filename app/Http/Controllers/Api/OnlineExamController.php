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
            'type' => ['required', 'string', 'in:mcq,single_word,long_word'],
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
            'type' => ['sometimes', 'required', 'string', 'in:mcq,single_word,long_word'],
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

    public function uploadQuestions(Request $request, OnlineExam $onlineExam): JsonResponse
    {
        $request->validate([
            'document' => ['required', 'file', 'max:10240', 'mimes:txt,pdf,doc,docx'],
        ]);

        $file = $request->file('document');
        if (!$file) {
            return ApiResponse::error('File not uploaded properly', 400);
        }

        $content = '';
        $ext = strtolower($file->getClientOriginalExtension());
        
        try {
            if ($ext === 'txt') {
                $content = file_get_contents($file->getRealPath());
            } elseif ($ext === 'pdf') {
                $config = new \Smalot\PdfParser\Config();
                $config->setFontSpaceLimit(-15); // Adjust font space limit to keep word boundaries separate
                $parser = new \Smalot\PdfParser\Parser([], $config);
                $pdf = $parser->parseFile($file->getRealPath());
                $content = $pdf->getText();
            } elseif ($ext === 'docx' || $ext === 'doc') {
                $zip = new \ZipArchive;
                if ($zip->open($file->getRealPath()) === true) {
                    $xml = $zip->getFromName('word/document.xml');
                    if ($xml !== false) {
                        $xml = str_replace(['</w:p>', '<w:br/>', '<w:br />'], "\n", $xml);
                        $content = strip_tags($xml);
                    }
                    $zip->close();
                }
            }
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to read the file: ' . $e->getMessage(), 500);
        }

        if (!$content) {
            return ApiResponse::error('Could not extract any text from the document.', 400);
        }

        \Illuminate\Support\Facades\Log::info("=== EXTRACTED EXAM TEXT ===");
        \Illuminate\Support\Facades\Log::info($content);

        $maxOrder = (int) $onlineExam->questions()->max('sort_order');
        $added = 0;

        $lines = preg_split('/\r?\n/', $content);
        $question = '';
        $options = [];
        $correctIndex = 0;
        $foundAnswer = false;
        $currentContext = 'none'; // 'question' or 'option'

        // --- Letter-to-index mapping (English + Tamil) ---
        $letterMap = [
            'A' => 0, 'B' => 1, 'C' => 2, 'D' => 3,
            'a' => 0, 'b' => 1, 'c' => 2, 'd' => 3,
            'அ' => 0, 'ஆ' => 1, 'இ' => 2, 'ஈ' => 3,
        ];

        // --- Regex: option line (A) / a. / A. / A, / (A) etc.) ---
        $optionLetterRegex = '/(?:^|\s+)(?:[\(\[]?\s*([a-dஅஆஇஈA-D])\s*[\)\.\,\]\:]+)/iu';

        // --- Regex: standalone correct-answer line ---
        // Matches many common formats:
        //   "Answer: B", "Ans: C", "Ans. D", "Correct Answer: A", "Correct: B",
        //   "Key: C", "Answer - B", "Answer = A", "Ans:B", "answer:a",
        //   "விடை: அ", "சரியான விடை: ஆ", "(Answer: B)", "Answer : B"
        $correctLineRegex = '/(?:correct\s*answer|answer|ans|key|விடை|சரியான\s*விடை)\s*[\:\.\-\=\s]\s*[\(\[]?\s*([a-dஅஆஇஈ])\s*[\)\]]?\s*$/iu';

        // --- Regex: answer embedded at end of a line (e.g. after last option) ---
        // "D) Option text   Answer: B" or "D) Option text  (Ans: B)"
        $inlineAnswerRegex = '/(?:correct\s*answer|answer|ans|key|விடை|சரியான\s*விடை)\s*[\:\.\-\=\s]\s*[\(\[]?\s*([a-dஅஆஇஈ])\s*[\)\]]?\s*$/iu';

        // --- Regex: asterisk/star marking on an option (e.g. "B) Option *" or "* B) Option") ---
        $asteriskSuffix = '/\s*[\*✓✔⭐★☆]+\s*$/u';
        $asteriskPrefix = '/^[\*✓✔⭐★☆]+\s*/u';

        // Helper: resolve a letter to an index
        $resolveLetterIndex = function (string $letter) use ($letterMap): int {
            $letter = trim($letter);
            return $letterMap[$letter] ?? $letterMap[mb_strtoupper($letter, 'UTF-8')] ?? 0;
        };

        // Helper: extract multiple inline options from a single line
        $extractOptions = function(string $text) use ($optionLetterRegex) {
            if (!preg_match_all($optionLetterRegex, $text, $matches, PREG_OFFSET_CAPTURE)) {
                return [];
            }
            
            $results = [];
            $numMatches = count($matches[0]);
            
            for ($i = 0; $i < $numMatches; $i++) {
                $currentMarker = $matches[0][$i][0];
                $currentOffset = $matches[0][$i][1];
                $currentLetter = $matches[1][$i][0];
                
                // The text for this option starts after the current marker
                $startPos = $currentOffset + strlen($currentMarker);
                
                // And ends before the next option marker (or end of string)
                if ($i < $numMatches - 1) {
                    $nextOffset = $matches[0][$i + 1][1]; // start offset of next marker
                    $length = $nextOffset - $startPos;
                    $optionText = substr($text, $startPos, $length);
                } else {
                    $optionText = substr($text, $startPos);
                }
                
                $results[] = [
                    'letter' => $currentLetter,
                    'text' => trim($optionText)
                ];
            }
            
            return $results;
        };

        // Helper: save a buffered question
        $saveBuffered = function () use (
            &$question, &$options, &$correctIndex, &$foundAnswer,
            &$maxOrder, &$added, $onlineExam
        ) {
            if ($question && count($options) >= 2) {
                // Clamp correctIndex within valid range
                if ($correctIndex < 0 || $correctIndex >= count($options)) {
                    $correctIndex = 0;
                }
                
                \Illuminate\Support\Facades\Log::info("PARSED Q: \"" . Str::limit(trim($question), 80) . "\" | Options: " . count($options) . " | Correct: " . $correctIndex . " (found=" . ($foundAnswer ? 'yes' : 'no') . ")");

                OnlineExamQuestion::create([
                    'online_exam_id' => $onlineExam->id,
                    'sort_order' => ++$maxOrder,
                    'prompt' => trim($question),
                    'options' => array_values($options),
                    'correct_index' => $correctIndex,
                    'points' => 1,
                ]);
                $added++;
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') continue;

            // 1) Detect start of a new question (e.g., "1.", "1)", "1 -", "01.")
            if (preg_match('/^\d+[\.\)\-\:\s]+\s*(.*)$/u', $trim, $m)) {
                $candidateText = trim($m[1]);
                
                // Check if this line is actually an answer line embedded with question number
                if (preg_match($correctLineRegex, $candidateText, $corrMatch)) {
                    $correctIndex = $resolveLetterIndex($corrMatch[1]);
                    $foundAnswer = true;
                    $currentContext = 'none';
                    continue;
                }

                // Save previous question if one is buffered
                $saveBuffered();

                // Start new question
                $question = $candidateText;
                $options = [];
                $correctIndex = 0;
                $foundAnswer = false;
                $currentContext = 'question';
                continue;
            }
            
            // 2) Detect standalone correct-answer line BEFORE option detection
            if (preg_match($correctLineRegex, $trim, $corrMatch)) {
                $correctIndex = $resolveLetterIndex($corrMatch[1]);
                $foundAnswer = true;
                $currentContext = 'none';
                continue;
            }

            // 3) Detect option lines (including multiple options in a single line)
            $detectedOptions = $extractOptions($trim);
            if (!empty($detectedOptions)) {
                foreach ($detectedOptions as $opt) {
                    $optLetter = $opt['letter'];
                    $optText = trim($opt['text']);
                    $markedCorrect = false;

                    // Check for asterisk/checkmark suffix marking correct answer
                    if (preg_match($asteriskSuffix, $optText)) {
                        $optText = preg_replace($asteriskSuffix, '', $optText);
                        $markedCorrect = true;
                    }
                    // Check for asterisk/checkmark prefix on the letter
                    if (preg_match($asteriskPrefix, $optText)) {
                        $optText = preg_replace($asteriskPrefix, '', $optText);
                        $markedCorrect = true;
                    }

                    // Check if the option line has an inline answer at the end
                    if (preg_match($inlineAnswerRegex, $optText, $inlineMatch)) {
                        $optText = trim(preg_replace($inlineAnswerRegex, '', $optText));
                        $correctIndex = $resolveLetterIndex($inlineMatch[1]);
                        $foundAnswer = true;
                    }

                    $options[] = trim($optText);

                    if ($markedCorrect) {
                        $correctIndex = count($options) - 1;
                        $foundAnswer = true;
                    }
                }

                $currentContext = 'option';
                continue;
            }

            // 4) If it's just extra text, append to current context
            if ($currentContext === 'question') {
                $question .= " " . $trim;
            } elseif ($currentContext === 'option' && count($options) > 0) {
                // Check if the continuation line contains an answer marker
                if (preg_match($correctLineRegex, $trim, $corrMatch)) {
                    $correctIndex = $resolveLetterIndex($corrMatch[1]);
                    $foundAnswer = true;
                    $currentContext = 'none';
                } else {
                    $options[count($options) - 1] .= " " . $trim;
                }
            }
        }

        // Save the last buffered question
        $saveBuffered();

        if ($added === 0) {
            return ApiResponse::error('Could not find any properly formatted questions in the document. Please use a structured format like "1. Question... A) Option 1 B) Option 2 Answer: A".', 400);
        }

        return ApiResponse::success([
            'added_count' => $added,
            'exam' => $this->formatExamAdmin($onlineExam->fresh()->load(['schoolClass', 'questions'])),
        ], "$added questions extracted and added successfully!");
    }

    public function updateQuestion(Request $request, OnlineExamQuestion $onlineExamQuestion): JsonResponse
    {
        $data = $request->validate([
            'prompt' => ['sometimes', 'string', 'max:5000'],
            'options' => ['sometimes', 'array', 'min:1', 'max:12'],
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

        $attempt = OnlineExamAttempt::query()->firstOrCreate(
            [
                'online_exam_id' => $onlineExam->id,
                'student_id' => $student->id,
            ],
            [
                'started_at' => now(),
            ]
        );

        return ApiResponse::success([
            'exam' => [
                'id' => $onlineExam->id,
                'title' => $onlineExam->title,
                'type' => $onlineExam->type,
                'description' => $onlineExam->description,
                'duration_minutes' => $onlineExam->duration_minutes,
                'available_from' => $onlineExam->available_from?->toIso8601String(),
                'available_until' => $onlineExam->available_until?->toIso8601String(),
            ],
            'questions' => $questions,
            'attempt' => [
                'started_at' => $attempt->started_at?->toIso8601String(),
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'score' => $attempt->score !== null ? (string) $attempt->score : null,
                'max_score' => $attempt->max_score !== null ? (string) $attempt->max_score : null,
            ],
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
        ]);

        $answers = [];
        foreach ($data['answers'] as $key => $val) {
            $answers[(string) $key] = $val;
        }

        foreach ($questions->keys() as $qid) {
            if (! array_key_exists((string) $qid, $answers) || $answers[(string) $qid] === null || $answers[(string) $qid] === '') {
                $answers[(string) $qid] = null;
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
            if ($chosen === null || $chosen === '') {
                continue;
            }
            if ($onlineExam->type === 'mcq') {
                $chosenIdx = (int) $chosen;
                $opts = $q->options ?? [];
                if ($chosenIdx < 0 || $chosenIdx >= count($opts)) {
                    return ApiResponse::error('Invalid option index for a question.', 422);
                }
                if ($chosenIdx === (int) $q->correct_index) {
                    $score += (float) $q->points;
                }
            } elseif ($onlineExam->type === 'single_word') {
                $correctAnswer = $q->options[0] ?? '';
                if (strcasecmp(trim((string) $chosen), trim($correctAnswer)) === 0) {
                    $score += (float) $q->points;
                }
            } else {
                // Long word/essay answers are saved but not auto-graded (earned points = 0 for now)
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
            
            if ($onlineExam->type === 'mcq') {
                $ok = $chosen !== null && (int) $chosen === (int) $q->correct_index;
            } elseif ($onlineExam->type === 'single_word') {
                $correctAnswer = $q->options[0] ?? '';
                $ok = $chosen !== null && strcasecmp(trim((string) $chosen), trim($correctAnswer)) === 0;
            } else {
                $ok = false;
            }

            $lines[] = [
                'question_id' => $q->id,
                'prompt' => $q->prompt,
                'options' => $q->options,
                'chosen_index' => $onlineExam->type === 'mcq' ? ($chosen !== null ? (int) $chosen : null) : null,
                'chosen_text' => $onlineExam->type === 'mcq' ? null : ($chosen !== null ? (string) $chosen : null),
                'correct_index' => $onlineExam->type === 'mcq' ? (int) $q->correct_index : null,
                'correct_text' => $onlineExam->type === 'mcq' ? null : ($q->options[0] ?? ''),
                'is_correct' => $ok,
                'points' => (string) $q->points,
                'earned' => $ok ? (string) $q->points : '0',
            ];
        }

        return ApiResponse::success([
            'exam' => [
                'id' => $onlineExam->id,
                'title' => $onlineExam->title,
                'type' => $onlineExam->type,
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
            'options' => ['required', 'array', 'min:1', 'max:12'],
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
            'type' => $e->type,
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
            'type' => $e->type,
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
