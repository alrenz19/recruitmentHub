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
        $cacheDuration = 300; // 5 minutes

        $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'page'  => 'nullable|integer|min:1',
            'refresh' => 'nullable|boolean'
        ]);

        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);

        $cacheVersion = Cache::get('assessments_cache_version', 1);
        $cacheKey = "assessments_list_{$limit}_{$page}_v{$cacheVersion}";

        if ($request->boolean('refresh')) {
            Cache::forget($cacheKey);
        }

        // Cache only the raw collection, not the paginator
        $assessmentsData = Cache::remember($cacheKey, $cacheDuration, function () {
            return Assessment::where('removed', 0)
                ->with([
                    'questions' => function ($q) {
                        $q->where('removed', 0)
                        ->select('id', 'assessment_id', 'question_text', 'question_type', 'image_path')
                        ->with([
                            'options' => function ($opt) {
                                $opt->where('removed', 0)
                                    ->select('id', 'question_id', 'option_text', 'is_correct');
                            }
                        ]);
                    },
                    'createdByUser:id,user_email',
                ])
                ->select('id', 'title', 'description', 'time_allocated', 'time_unit', 'created_at', 'created_by_user_id')
                ->orderBy('created_at', 'desc')
                ->get();
        });

        // Paginate after caching
        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $assessmentsData->forPage($page, $limit),
            $assessmentsData->count(),
            $limit,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Return only the transformed data + meta (no unnecessary links)
        return response()->json([
            'data' => AssessmentResource::collection($paginated),
            'meta' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage()
            ]
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
        // $this->authorize('create', Assessment::class);

        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'time_allocated' => 'required|integer|min:1',
            'time_unit' => 'required|in:minutes,hours',
            'questions' => 'required|array|min:1',
            'questions.*.question_text' => 'required_without:questions.*.image|string|nullable',
            'questions.*.question_type' => 'required|string',
            'questions.*.image' => 'nullable|string',
            'questions.*.options' => 'required|array|min:1',
            'questions.*.options.*.option_text' => 'required|string',
            'questions.*.options.*.is_correct' => 'required|boolean',
        ];

        $validated = $request->validate($rules);

        $assessment = Assessment::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'time_allocated' => $validated['time_allocated'],
            'time_unit' => $validated['time_unit'],
            'created_by_user_id' =>  auth()->id() ?? 1,
        ]);

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

            $question = AssessmentQuestion::create([
                'assessment_id' => $assessment->id,
                'question_text' => $q['question_text'] ?? null,
                'question_type' => $q['question_type'],
                'image_path' => $imagePath,
                'removed' => 0,
            ]);

            foreach ($q['options'] as $opt) {
                $question->options()->create([
                    'option_text' => $opt['option_text'],
                    'is_correct' => $opt['is_correct'],
                    'removed' => 0,
                ]);
            }
        }

        // Increment cache version to invalidate old cached lists
        Cache::increment('assessments_cache_version');

        return response()->json([
            'message' => 'Assessment created successfully',
            'assessment_id' => $assessment->id,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */

    public function update(Request $request, $id) // Change this parameter
    {
        try {
        // Find the assessment first
        $assessment = Assessment::findOrFail($id);
        
        // Authorize updating this assessment
        $this->authorize('update', $assessment);

        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'time_allocated' => 'required|integer|min:1',
            'time_unit' => 'required|in:minutes,hours',
            'questions' => 'required|array|min:1',
            'questions.*.id' => 'nullable|integer|exists:assessment_questions,id',
            'questions.*.question_text' => 'required_without:questions.*.image|string|nullable',
            'questions.*.question_type' => 'required|string|in:single_answer,multiple_answer',
            'questions.*.image' => 'nullable|string',
            'questions.*.options' => 'required|array|min:1',
            'questions.*.options.*.id' => 'nullable|integer|exists:assessment_options,id',
            'questions.*.options.*.option_text' => 'required|string',
            'questions.*.options.*.is_correct' => 'required|boolean',
        ];

        $validated = $request->validate($rules);

        // Update main assessment fields
        $assessment->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'time_allocated' => $validated['time_allocated'],
            'time_unit' => $validated['time_unit'],
        ]);

        // Handle questions
        $existingQuestionIds = $assessment->questions()->pluck('id')->toArray();
        $sentQuestionIds = [];

        foreach ($validated['questions'] as $q) {
            // Handle image
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
                $question = AssessmentQuestion::find($q['id']);
                $question->update([
                    'question_text' => $q['question_text'] ?? null,
                    'question_type' => $q['question_type'],
                    'image_path' => $imagePath ?: $question->image_path, // Keep existing if no new image
                ]);
            } else {
                // Create new question (only if it's actually a new question)
                $question = AssessmentQuestion::create([
                    'assessment_id' => $assessment->id,
                    'question_text' => $q['question_text'] ?? null,
                    'question_type' => $q['question_type'],
                    'image_path' => $imagePath,
                    'removed' => 0,
                ]);
            }

            $sentQuestionIds[] = $question->id;

            // Handle options
            $existingOptionIds = $question->options()->pluck('id')->toArray();
            $sentOptionIds = [];

            foreach ($q['options'] as $opt) {
                if (!empty($opt['id']) && in_array($opt['id'], $existingOptionIds)) {
                    // Update existing option
                    $option = $question->options()->find($opt['id']);
                    $option->update([
                        'option_text' => $opt['option_text'],
                        'is_correct' => $opt['is_correct'],
                        'removed' => 0,
                    ]);
                } else {
                    // Create new option
                    $option = $question->options()->create([
                        'option_text' => $opt['option_text'],
                        'is_correct' => $opt['is_correct'],
                        'removed' => 0,
                    ]);
                }
                $sentOptionIds[] = $option->id;
            }

            // Delete removed options (soft delete)
            $deleteOptionIds = array_diff($existingOptionIds, $sentOptionIds);
            if ($deleteOptionIds) {
                $question->options()->whereIn('id', $deleteOptionIds)->update(['removed' => 1]);
            }
        }

        // Delete removed questions (soft delete)
        $deleteQuestionIds = array_diff($existingQuestionIds, $sentQuestionIds);
        if ($deleteQuestionIds) {
            AssessmentQuestion::whereIn('id', $deleteQuestionIds)->update(['removed' => 1]);
        }

        return response()->json([
            'message' => 'Assessment updated successfully',
            'assessment' => $assessment->fresh()->load('questions.options'),
        ]);

    } catch (\Exception $e) {
        \Log::error('Update assessment failed: ' . $e->getMessage(), [
            'exception' => $e,
            'request_data' => $request->all()
        ]);
        
        return response()->json([
            'message' => 'Update failed: ' . $e->getMessage()
        ], 500);
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
