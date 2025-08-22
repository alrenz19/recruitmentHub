<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssessmentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'questions'   => 'required|array|min:1',
            'questions.*.question_text' => 'required|string',
            'questions.*.question_type' => 'required|string',
            'questions.*.options'       => 'required|array|min:2',
            'questions.*.options.*.option_text' => 'required|string',
            'questions.*.options.*.is_correct'  => 'required|boolean',
        ]);

        return DB::transaction(function () use ($request) {
            // Create the assessment
            $assessment = Assessment::create([
                'title'             => $request->title,
                'description'       => $request->description,
                'created_by_user_id'=> Auth::id(),
                'removed'           => 0,
            ]);

            // Loop through questions to create
            foreach ($request->questions as $q) {
                $question = $assessment->questions()->create([
                    'question_text' => $q['question_text'],
                    'question_type' => $q['question_type'],
                    'removed'       => 0,
                ]);

                // Loop through options to create
                foreach ($q['options'] as $opt) {
                    $question->options()->create([
                        'option_text' => $opt['option_text'],
                        'is_correct'  => $opt['is_correct'],
                        'removed'     => 0,
                    ]);
                }
            }

            return response()->json([
                'message'    => 'Assessment created successfully',
                'assessment' => $assessment->load('questions.options'),
            ], 201);
        });
    }

    public function show($id)
    {
        $assessment = Assessment::with(['questions.options'])
            ->where('removed', 0)
            ->findOrFail($id);

        return response()->json($assessment);
    }

    public function index(Request $request)
    {
        $limit = $request->input('limit', 10);

        $assessments = Assessment::with('questions.options')
            ->where('removed', 0)
            ->paginate($limit);

        return response()->json([
            'total_count'   => $assessments->total(), 
            'current_count' => $assessments->count(), 
            'per_page'      => $assessments->perPage(), 
            'current_page'  => $assessments->currentPage(),
            'last_page'     => $assessments->lastPage(),
            'data'          => $assessments->items(),
        ]);
    }

}
