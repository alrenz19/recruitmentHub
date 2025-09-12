<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Examination;
use App\Models\AssessmentQuestion;
use App\Models\AssessmentOption;
use App\Models\AssessmentAnswer;
use App\Models\AssessmentResult;
use App\Models\ApplicantAssessment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

class ExaminationController extends Controller
{
    // Get all assessments with questions & options
    public function index()
    {
        $assessments = Examination::where('removed', 0)
            ->with('questions.options')
            ->get();

        return response()->json($assessments);
    }


    // Show single assessment
    public function show($id)
    {
        $assessment = Examination::where('removed', 0)
            ->with('questions.options')
            ->findOrFail($id);

        // Attach correct_option_ids to each question
        $assessment->questions->transform(function ($q) {
            $q->correct_option_ids = $q->options
                ->where('is_correct', 1)
                ->pluck('id')
                ->toArray();
            return $q;
        });

        return response()->json($assessment);
    }


    // Store new assessment
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'time_allocated' => 'required|integer|min:1',
            'time_unit' => 'required|in:minutes,hours',
            'questions' => 'required|array|min:1',
        ]);

        $assessment = Examination::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'time_allocated' => $data['time_allocated'],
            'time_unit' => $data['time_unit'],
            'created_by_user_id' => Auth::id() ?? 1,
        ]);

        foreach ($data['questions'] as $q) {
            $question = AssessmentQuestion::create([
                'assessment_id' => $assessment->id,
                'question_text' => $q['question_text'],
                'question_type' => $q['question_type'],
                'image_path' => $q['image'] ?? null,
            ]);

            foreach ($q['options'] as $opt) {
                AssessmentOption::create([
                    'question_id' => $question->id,
                    'option_text' => $opt['option_text'],
                    'is_correct' => $opt['is_correct'] ?? 0,
                ]);
            }
        }

        return response()->json($assessment->load('questions.options'), 201);
    }

    public function retrieveAssignedAssessment(Request $request)
    {
        $userId = Auth::id(); // logged-in user
        $applicantId = DB::table('applicants')->where('user_id', $userId)->value('id');

        if (!$applicantId) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $assignedExams = ApplicantAssessment::where('applicant_id', $applicantId)
            ->where('removed', 0)
            ->with(['assessment' => function ($query) {
                $query->where('removed', 0)->with('questions.options');
            }])
            ->get()
            ->map(function ($aa) {
                if ($aa->assessment) {
                    $assessment = $aa->assessment->toArray();
                    // merge attempts_used from applicant_assessments
                    $assessment['attempts_used'] = $aa->attempts_used;
                    return $assessment;
                }
                return null;
            })
            ->filter()
            ->values();

        return response()->json($assignedExams);
    }




    public function submitAll(Request $request)
    {
        $userId = Auth::id();
        $applicant = DB::table('applicants')->where('user_id', $userId)->first();
        $applicantId = $applicant->id ?? null;

        if (!$applicantId) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $data = $request->validate([
            'exams' => 'required|array|min:1',
            'exams.*.assessment_id' => 'required|integer|exists:assessments,id',
            'exams.*.answers' => 'required|array|min:1',
            'exams.*.answers.*.question_id' => 'required|integer|exists:assessment_questions,id',
            'exams.*.answers.*.selected_option_ids' => 'nullable|array',
            'exams.*.answers.*.selected_option_ids.*' => 'integer|exists:assessment_options,id',
        ]);

        $allResults = [];

        DB::beginTransaction();
        try {
            foreach ($data['exams'] as $examData) {
                $examId = $examData['assessment_id'];
                $answers = $examData['answers'];

                $assessment = Examination::with('questions.options')->findOrFail($examId);

                $score = 0;
                $total = $assessment->questions->count();

                foreach ($answers as $ans) {
                    $submittedOptions = $ans['selected_option_ids'] ?? [];

                    // Delete existing answers
                    DB::table('assessment_answers')
                    ->where('applicant_id', $applicantId)
                    ->where('question_id', $ans['question_id'])
                    ->delete();

                    // Insert new answers
                    foreach ($submittedOptions as $optId) {
                        $optionText = DB::table('assessment_options')
                            ->where('id', $optId)
                            ->value('option_text');

                        DB::table('assessment_answers')->insert([
                            'applicant_id'       => $applicantId,
                            'question_id'        => $ans['question_id'],
                            'selected_option_id' => $optId,
                            'answer_text'        => $optionText,
                            'submitted_at'       => now(),
                            'removed'            => 0,
                        ]);
                    }

                    // correctness check
                    $question = $assessment->questions->firstWhere('id', $ans['question_id']);
                    if ($question) {
                        $correctOptions = $question->options->where('is_correct', 1)->pluck('id')->toArray();

                        sort($correctOptions);
                        $submittedCheck = $submittedOptions;
                        sort($submittedCheck);

                        if ($submittedCheck === $correctOptions) {
                            $score++;
                        }
                    }
                }

                // save/update results for this exam
                DB::table('assessment_results')->updateOrInsert(
                    [
                        'applicant_id' => $applicantId,
                        'assessment_id' => $examId,
                    ],
                    [
                        'score' => $score,
                        'status' => ($score >= ($total * 0.5)) ? 'passed' : 'failed',
                        'reviewed_at' => now(),
                    ]
                );

                $allResults[] = [
                    'assessment_id' => $examId,
                    'score' => $score,
                    'total' => $total,
                    'status' => ($score >= ($total * 0.5)) ? 'passed' : 'failed',
                ];
            }

            DB::commit();

            NotificationService::send(null, "Assessment Result", "{$applicant->full_name} has completed their assessment.", 'assessment', '/recruitment-board', 'hr');

            return response()->json([
                'message' => 'All exams submitted successfully',
                'results' => $allResults,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }




    // Update assessment
    public function update(Request $request, $id)
    {
        $assessment = Examination::findOrFail($id);

        $assessment->update($request->only(['title','description','time_allocated','time_unit']));

        // (Optional: update questions/options here — depending on how you want edits to behave)

        return response()->json($assessment->load('questions.options'));
    }

    // Delete assessment (soft remove)
    public function destroy($id)
    {
        $assessment = Examination::findOrFail($id);
        $assessment->removed = 1;
        $assessment->save();

        return response()->json(['message' => 'Assessment removed']);
    }
}
