<?php
// app/Http/Controllers/AssessmentController.php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Http\Resources\AssessmentResource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\DB;

// Models
use App\Models\AssessmentQuestion;
use App\Models\AssessmentOption;

class AssessmentController extends Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $refresh = $request->boolean('refresh', false);

        // Cache versioning (increment if assessments change)
        $cacheVersion = Cache::get('assessments_cache_version', 1);

        $filters = [
            'limit' => $limit,
            'page' => $page,
            'search' => $search,
            'version' => $cacheVersion
        ];

        $cacheKey = 'assessments_list_' . md5(json_encode($filters));

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 300, function () use ($limit, $page, $search) {
            $offset = ($page - 1) * $limit;

            // 1️⃣ Count total assessments
            $total = DB::table('assessments')
                ->where('removed', 0)
                ->when($search, fn($q) => $q->where('title', 'like', "%{$search}%"))
                ->count();

            // 2️⃣ Fetch assessments for current page
            $assessments = DB::table('assessments')
                ->select('id', 'title', 'description', 'time_allocated', 'time_unit', 'created_at')
                ->where('removed', 0)
                ->when($search, fn($q) => $q->where('title', 'like', "%{$search}%"))
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->offset($offset)
                ->get();

            $assessmentIds = $assessments->pluck('id')->toArray();

            // 3️⃣ Fetch all questions for these assessments
            $questions = DB::table('assessment_questions')
                ->select('id', 'assessment_id', 'question_text', 'question_type', 'image_path')
                ->where('removed', 0)
                ->whereIn('assessment_id', $assessmentIds)
                ->get();

            $questionIds = $questions->pluck('id')->toArray();

            // 4️⃣ Fetch all options for these questions
            $options = DB::table('assessment_options')
                ->select('id', 'question_id', 'option_text', 'is_correct')
                ->where('removed', 0)
                ->whereIn('question_id', $questionIds)
                ->get()
                ->groupBy('question_id');

            // 5️⃣ Attach options to questions
            $questionsByAssessment = $questions->groupBy('assessment_id')->map(function ($qs) use ($options) {
                return $qs->map(function ($q) use ($options) {
                    return [
                        'id' => $q->id,
                        'question_text' => $q->question_text,
                        'question_type' => $q->question_type,
                        'image_path' => url('storage/' . $q->image_path),
                        'options' => $options[$q->id] ?? [],
                    ];
                });
            });

            // 6️⃣ Attach questions to assessments
            $data = $assessments->map(function ($a) use ($questionsByAssessment) {
                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'description' => $a->description,
                    'time_allocated' => $a->time_allocated,
                    'time_unit' => $a->time_unit,
                    'created_at' => $a->created_at,
                    'questions' => $questionsByAssessment[$a->id] ?? [],
                ];
            });

            return [
                'data' => $data,
                'total' => $total,
            ];
        });

        return response()->json([
            'data' => $data['data'],
            'meta' => [
                'total' => $data['total'],
                'per_page' => $limit,
                'current_page' => $page,
                'last_page' => ceil($data['total'] / $limit),
            ],
        ]);
    }





    public function retrieveAssessments(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'last_id'  => 'nullable|integer|min:0',
        ]);

        $perPage = $request->input('per_page', 10);
        $lastId = $request->input('last_id', 0);

        // Fetch one extra to check for more
        $data = DB::select(
            'SELECT id, title FROM assessments WHERE removed = ? AND id > ? ORDER BY id ASC LIMIT ?',
            [0, $lastId, $perPage + 1]
        );

        // Check if there are more rows
        $hasMore = count($data) > $perPage;

        // Remove the extra row
        if ($hasMore) {
            array_pop($data);
        }

        $newLastId = count($data) ? end($data)->id : null;

        return response()->json([
            'data' => $data,
            'last_id' => $newLastId,
            'has_more' => $hasMore,
        ]);
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'time_allocated' => 'required|integer|min:1',
            'time_unit' => 'required|in:minutes,hours',
            'questions' => 'required|array|min:1',
            'questions.*.question_text' => 'required_without:questions.*.image|string|nullable',
            'questions.*.question_type' => 'required|in:single_answer,multiple_answer,short_answer,enumeration',
            'questions.*.image' => 'nullable|string',

            'questions.*.options' => 'required|array|min:1',
            'questions.*.options.*.option_text' => 'required|string',
            'questions.*.options.*.is_correct' => 'required|boolean',
        ];

        $validated = $request->validate($rules);

        DB::beginTransaction();

        try {
            // Insert assessment
            DB::insert("
                INSERT INTO assessments (title, description, time_allocated, time_unit, created_by_user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ", [
                $validated['title'],
                $validated['description'] ?? null,
                $validated['time_allocated'],
                $validated['time_unit'],
                auth()->id() ?? 1,
            ]);

            $assessmentId = DB::getPdo()->lastInsertId();

            // Insert questions
            foreach ($validated['questions'] as $q) {
                $imagePath = null;

                if (!empty($q['image']) && preg_match('/^data:image\/(\w+);base64,/', $q['image'], $type)) {
                    $imageData = substr($q['image'], strpos($q['image'], ',') + 1);
                    $type = strtolower($type[1]);
                    $imageData = base64_decode($imageData);

                    $fileName = uniqid() . ".$type";
                    $storageDir = storage_path("app/public/assessments");
                    if (!file_exists($storageDir)) mkdir($storageDir, 0755, true);

                    $storagePath = $storageDir . "/$fileName";
                    file_put_contents($storagePath, $imageData);

                    $imagePath = "assessments/$fileName";
                }

                // Insert question
                DB::insert("
                    INSERT INTO assessment_questions (assessment_id, question_text, question_type, image_path, removed, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 0, NOW(), NOW())
                ", [
                    $assessmentId,
                    $q['question_text'] ?? null,
                    $q['question_type'],
                    $imagePath,
                ]);

                $questionId = DB::getPdo()->lastInsertId();

                // Insert options
                foreach ($q['options'] as $opt) {
                    DB::insert("
                        INSERT INTO assessment_options (question_id, option_text, is_correct, removed, created_at, updated_at)
                        VALUES (?, ?, ?, 0, NOW(), NOW())
                    ", [
                        $questionId,
                        $opt['option_text'],
                        $opt['is_correct'],
                    ]);
                }
            }

            DB::commit();

            // Invalidate cache
            Cache::increment('assessments_cache_version');

            return response()->json([
                'message' => 'Assessment created successfully',
                'assessment_id' => $assessmentId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * Update the specified resource in storage.
     */

    public function update(Request $request, $id)
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'time_allocated' => 'required|integer|min:1',
            'time_unit' => 'required|in:minutes,hours',
            'questions' => 'required|array|min:1',
            'questions.*.id' => 'nullable|integer',
            'questions.*.question_text' => 'required_without:questions.*.image|string|nullable',
            'questions.*.question_type' => 'required|in:single_answer,multiple_answer,short_answer,enumeration',
            'questions.*.image' => 'nullable|string',

            'questions.*.options' => 'required|array|min:1',
            'questions.*.options.*.id' => 'nullable|integer',
            'questions.*.options.*.option_text' => 'required|string',
            'questions.*.options.*.is_correct' => 'required|boolean',
        ];

        $validated = $request->validate($rules);

        DB::beginTransaction();
        try {
            // Update main assessment
            DB::update("
                UPDATE assessments
                SET title = ?, description = ?, time_allocated = ?, time_unit = ?, updated_at = NOW()
                WHERE id = ?
            ", [
                $validated['title'],
                $validated['description'] ?? null,
                $validated['time_allocated'],
                $validated['time_unit'],
                $id,
            ]);

            // Get existing questions
            $existingQuestionIds = DB::table('assessment_questions')
                ->where('assessment_id', $id)
                ->pluck('id')
                ->toArray();

            $sentQuestionIds = [];

            foreach ($validated['questions'] as $q) {
                $imagePath = null;

                if (!empty($q['image']) && preg_match('/^data:image\/(\w+);base64,/', $q['image'], $type)) {
                    $imageData = substr($q['image'], strpos($q['image'], ',') + 1);
                    $type = strtolower($type[1]);
                    $imageData = base64_decode($imageData);

                    $fileName = uniqid() . ".$type";
                    $storageDir = storage_path("app/public/assessments");
                    if (!file_exists($storageDir)) mkdir($storageDir, 0755, true);

                    $storagePath = $storageDir . "/$fileName";
                    file_put_contents($storagePath, $imageData);

                    $imagePath = "assessments/$fileName";
                }

                if (!empty($q['id']) && in_array($q['id'], $existingQuestionIds)) {
                    // Update existing question
                    DB::update("
                        UPDATE assessment_questions
                        SET question_text = ?, question_type = ?, image_path = ?, updated_at = NOW()
                        WHERE id = ?
                    ", [
                        $q['question_text'] ?? null,
                        $q['question_type'],
                        $imagePath ?? DB::table('assessment_questions')->where('id', $q['id'])->value('image_path'),
                        $q['id'],
                    ]);

                    $questionId = $q['id'];
                } else {
                    // Insert new question
                    DB::insert("
                        INSERT INTO assessment_questions (assessment_id, question_text, question_type, image_path, removed, created_at, updated_at)
                        VALUES (?, ?, ?, ?, 0, NOW(), NOW())
                    ", [
                        $id,
                        $q['question_text'] ?? null,
                        $q['question_type'],
                        $imagePath,
                    ]);

                    $questionId = DB::getPdo()->lastInsertId();
                }

                $sentQuestionIds[] = $questionId;

                // Handle options
                $existingOptionIds = DB::table('assessment_options')
                    ->where('question_id', $questionId)
                    ->pluck('id')
                    ->toArray();

                $sentOptionIds = [];

                foreach ($q['options'] as $opt) {
                    if (!empty($opt['id']) && in_array($opt['id'], $existingOptionIds)) {
                        // Update option
                        DB::update("
                            UPDATE assessment_options
                            SET option_text = ?, is_correct = ?, removed = 0, updated_at = NOW()
                            WHERE id = ?
                        ", [
                            $opt['option_text'],
                            $opt['is_correct'],
                            $opt['id'],
                        ]);

                        $optionId = $opt['id'];
                    } else {
                        // Insert option
                        DB::insert("
                            INSERT INTO assessment_options (question_id, option_text, is_correct, removed, created_at, updated_at)
                            VALUES (?, ?, ?, 0, NOW(), NOW())
                        ", [
                            $questionId,
                            $opt['option_text'],
                            $opt['is_correct'],
                        ]);

                        $optionId = DB::getPdo()->lastInsertId();
                    }

                    $sentOptionIds[] = $optionId;
                }

                // Soft delete removed options
                $deleteOptionIds = array_diff($existingOptionIds, $sentOptionIds);
                if ($deleteOptionIds) {
                    DB::table('assessment_options')->whereIn('id', $deleteOptionIds)->update(['removed' => 1]);
                }
            }

            // Soft delete removed questions
            $deleteQuestionIds = array_diff($existingQuestionIds, $sentQuestionIds);
            if ($deleteQuestionIds) {
                DB::table('assessment_questions')->whereIn('id', $deleteQuestionIds)->update(['removed' => 1]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Assessment updated successfully',
                'assessment_id' => $id,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    /**
     * Remove the specified resource from storage.
     */
    // AssessmentController.php
    public function destroy($id)
    {
        $assessment = Assessment::findOrFail($id);
        
        // Authorize deletion
        $this->authorize('delete', $assessment);
        
        // Use soft delete (set removed = 1) instead of hard delete
        $assessment->update(['removed' => 1]);

        return response()->json(['message' => 'Assessment deleted successfully']);
    }

    /**
     * Display the specified resource.
     */
    public function show(Assessment $assessment)
    {
        // Optional: skip authorize here if cached user logic handles access
        if ($assessment->removed) {
            return response()->json(['message' => 'Assessment not found'], 404);
        }

        $assessment->load([
            'questions' => fn($query) => $query->select('id', 'assessment_id', 'question_text', 'question_type', 'image_path')->where('removed', 0),
            'questions.options' => fn($query) => $query->select('id', 'question_id', 'option_text', 'is_correct')->where('removed', 0)
        ]);

        return new AssessmentResource($assessment);
    }
}
