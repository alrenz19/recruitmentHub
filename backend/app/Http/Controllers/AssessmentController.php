<?php
// app/Http/Controllers/AssessmentController.php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Resources\AssessmentResource;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;

// Models
use App\Models\AssessmentQuestion;
use App\Models\AssessmentOption; // for the options

class AssessmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
    public function index(Request $request)
    {
        // Authorize viewing assessments list
        $this->authorize('viewAny', Assessment::class);

        // Validate and sanitize the limit parameter
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:100'
        ]);
        
        $limit = $request->input('limit', 10);

        // Get assessments based on user role
        $query = Assessment::with('questions.options')
            ->where('removed', 0);

        // If not admin, only show user's own assessments
        if (Auth::user()->role_id !== 1) { // Adjust role check as needed
            $query->where('created_by_user_id', Auth::id());
        }

        $assessments = $query->orderBy('created_at', 'desc')
            ->paginate($limit);

        return AssessmentResource::collection($assessments);
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
            // question_text optional if image exists
            'questions.*.question_text' => 'required_without:questions.*.image|string|nullable',
            'questions.*.question_type' => 'required|string',
            // remove 'file|image' because it's Base64
            'questions.*.image' => 'nullable|string',
            'questions.*.options' => 'required|array|min:1',
            'questions.*.options.*.option_text' => 'required|string',
            'questions.*.options.*.is_correct' => 'required|boolean',
        ];

        $validated = $request->validate($rules);

        // Create assessment
        $assessment = Assessment::create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'time_allocated' => $validated['time_allocated'],
            'time_unit' => $validated['time_unit'],
            'created_by_user_id' => auth()->id(),
        ]);

        // Create questions
        foreach ($validated['questions'] as $q) {
            $imagePath = null;

            if (!empty($q['image'])) {
                // Convert Base64 to actual file
                if (preg_match('/^data:image\/(\w+);base64,/', $q['image'], $type)) {
                    $imageData = substr($q['image'], strpos($q['image'], ',') + 1);
                    $type = strtolower($type[1]); // jpg, png, etc.
                    $imageData = base64_decode($imageData);

                    $fileName = uniqid() . ".$type";
                    $storageDir = storage_path("app/public/assessments");
                    if (!file_exists($storageDir)) {
                        mkdir($storageDir, 0755, true);
                    }
                    
                    $storagePath = $storageDir . "/$fileName";
                    file_put_contents($storagePath, $imageData);

                    $imagePath = "assessments/$fileName";
                }
            }

            $question = AssessmentQuestion::create([
                'assessment_id' => $assessment->id,
                'question_text' => $q['question_text'] ?? null,
                'question_type' => $q['question_type'],
                'image_path' => $imagePath,
                'removed' => 0,
            ]);

            // Save options
            foreach ($q['options'] as $opt) {
                $question->options()->create([
                    'option_text' => $opt['option_text'],
                    'is_correct' => $opt['is_correct'],
                    'removed' => 0,
                ]);
            }
        }

        return response()->json([
            'message' => 'Assessment created successfully',
            'assessment_id' => $assessment->id,
        ]);
    }


    /**
     * Display the specified resource.
     */
    public function show(Assessment $assessment)
    {
        // Authorize viewing this specific assessment
        $this->authorize('view', $assessment);

        // Verify the assessment is not soft deleted
        if ($assessment->removed) {
            return response()->json(['message' => 'Assessment not found'], 404);
        }
        
        return new AssessmentResource($assessment->load('questions.options'));
    }

    /**
     * Update the specified resource in storage.
     */
public function update(Request $request, Assessment $assessment)
{
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
        'questions.*.question_type' => 'required|string',
        'questions.*.image' => 'nullable|string',
        'questions.*.options' => 'required|array|min:1',
        'questions.*.options.*.id' => 'nullable|integer|exists:assessment_question_options,id',
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
        $imagePath = $q['image'] ?? null;

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

        if (!empty($q['id'])) {
            // Update existing question
            $question = AssessmentQuestion::find($q['id']);
            $question->update([
                'question_text' => $q['question_text'] ?? null,
                'question_type' => $q['question_type'],
                'image_path' => $imagePath,
            ]);
        } else {
            // Create new question
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
            if (!empty($opt['id'])) {
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

        // Delete removed options
        $deleteOptionIds = array_diff($existingOptionIds, $sentOptionIds);
        if ($deleteOptionIds) {
            $question->options()->whereIn('id', $deleteOptionIds)->delete();
        }
    }

    // Delete removed questions
    $deleteQuestionIds = array_diff($existingQuestionIds, $sentQuestionIds);
    if ($deleteQuestionIds) {
        AssessmentQuestion::whereIn('id', $deleteQuestionIds)->delete();
    }

    return response()->json([
        'message' => 'Assessment updated successfully',
        'assessment' => $assessment->fresh()->load('questions.options'),
    ]);
}


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Assessment $assessment)
    {
        // Authorize deleting this assessment
        $this->authorize('delete', $assessment);

        // Soft delete assessment
        $assessment->update(['removed' => 1]);

        // Soft delete related questions and options
        $assessment->questions()->update(['removed' => 1]);
        foreach ($assessment->questions as $question) {
            $question->options()->update(['removed' => 1]);
        }

        \Log::info('Assessment soft deleted', [
            'user_id' => Auth::id(),
            'assessment_id' => $assessment->id
        ]);

        return response()->json([
            'message' => 'Assessment deleted successfully'
        ]);
    }

}