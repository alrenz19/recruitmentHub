<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\DB;


// Model
use App\Models\Examination;
use App\Models\AssessmentQuestion;
use App\Models\AssessmentOption;
use App\Models\AssessmentAnswer;
use App\Models\AssessmentResult;
use App\Models\ApplicantAssessment;


// Jobs
use App\Jobs\FinalizePipelineJob;



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


    public function retrieveAssignedAssessment(Request $request)
    {
        $userId = Auth::id();
        $applicantId = DB::table('applicants')->where('user_id', $userId)->value('id');

        if (!$applicantId) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $usedTimeRow = DB::selectOne("
            SELECT used_time
            FROM applicant_assessments
            WHERE applicant_id = :applicantId
            AND removed = 0
            AND used_time IS NOT NULL
            ORDER BY used_time DESC
            LIMIT 1
        ", ['applicantId' => $applicantId]);

        $usedTime = $usedTimeRow->used_time ?? 0;

        $rows = DB::select("
            SELECT 
                aa.id AS applicant_assessment_id,
                aa.applicant_id,
                aa.status AS applicant_status,
                aa.attempts_used,
                a.id AS assessment_id,
                a.title AS assessment_title,
                a.description AS assessment_description,
                a.time_allocated,
                a.time_unit,
                q.id AS question_id,
                q.question_text,
                q.image_path,
                q.question_type,
                o.id AS option_id,
                o.option_text,
                o.is_correct
            FROM applicant_assessments aa
            JOIN assessments a 
                ON aa.assessment_id = a.id AND a.removed = 0
            LEFT JOIN assessment_questions q 
                ON q.assessment_id = a.id AND q.removed = 0
            LEFT JOIN assessment_options o 
                ON o.question_id = q.id AND o.removed = 0
            WHERE aa.applicant_id = :applicantId
            AND aa.removed = 0
            ORDER BY a.id, q.id, o.id
        ", ['applicantId' => $applicantId]);

        // Rebuild nested JSON
        $assessments = [];
        foreach ($rows as $row) {
            $aId = $row->assessment_id;

            if (!isset($assessments[$aId])) {
                $assessments[$aId] = [
                    'id' => $aId,
                    'title' => $row->assessment_title,
                    'description' => $row->assessment_description,
                    'time_allocated' => $row->time_allocated,
                    'time_unit' => $row->time_unit,
                    'attempts_used' => $row->attempts_used,
                    'questions' => []
                ];
            }

            if ($row->question_id) {
                $qId = $row->question_id;

                // Hide question_text for enumeration or short_answer
                $questionText = in_array($row->question_type, ['enumeration', 'short_answer'])
                    ? ''
                    : $row->question_text;

                if (!isset($assessments[$aId]['questions'][$qId])) {
                    $assessments[$aId]['questions'][$qId] = [
                        'id' => $qId,
                        'question_text' => $questionText,
                        'image_path' => $row->image_path,
                        'question_type' => $row->question_type,
                        'options' => [],
                    ];
                }

                // Keep options but remove is_correct
                if ($row->option_id) {
                    $assessments[$aId]['questions'][$qId]['options'][] = [
                        'id' => $row->option_id,
                        'option_text' => $questionText,
                    ];
                }
            }
        }

        // Update applicant pipeline status
        DB::update("
            UPDATE applicant_pipeline
            SET note = 'In progress', updated_at = NOW()
            WHERE applicant_id = :id AND removed = 0
            LIMIT 1
        ", ['id' => $applicantId]);

        // Reset numeric indexes for JSON
        foreach ($assessments as &$a) {
            $a['questions'] = array_values($a['questions']);
        }

        return response()->json([
            'used_time' => $usedTime,              
            'assessments' => array_values($assessments)
        ]);
    }




public function submitAll(Request $request)
{
    $userId = Auth::id();

    return DB::transaction(function () use ($request, $userId) {

        // 1️⃣ Get applicant
        $applicant = DB::selectOne("
            SELECT id, full_name
            FROM applicants
            WHERE user_id = :user_id
            LIMIT 1
        ", ['user_id' => $userId]);

        if (!$applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $applicantId = $applicant->id;

        // 2️⃣ Validate request
        $data = $request->validate([
            'exams' => 'required|array|min:1',
            'exams.*.assessment_id' => 'required|integer|exists:assessments,id',
            'exams.*.answers' => 'required|array|min:1',
            'exams.*.answers.*.question_id' => 'required|integer|exists:assessment_questions,id',
            'exams.*.answers.*.selected_option_ids' => 'nullable|array',
            'exams.*.answers.*.selected_option_ids.*' => 'integer|exists:assessment_options,id',
            'exams.*.answers.*.short_answer' => 'nullable|string',
            'exams.*.answers.*.enumeration_answers' => 'nullable|array',
            'exams.*.answers.*.enumeration_answers.*' => 'string|nullable',
        ]);

        $allResults = [];
        $totalScore = 0;
        $totalQuestions = 0;

        foreach ($data['exams'] as $examData) {
            $examId = $examData['assessment_id'];
            $answers = $examData['answers'];

            // 3️⃣ Get all questions + options + question type for this assessment
            $rows = DB::select("
                SELECT 
                    q.id AS question_id, 
                    q.question_type,
                    o.id AS option_id, 
                    o.is_correct, 
                    o.option_text
                FROM assessment_questions q
                LEFT JOIN assessment_options o ON o.question_id = q.id
                WHERE q.assessment_id = :aid
            ", ['aid' => $examId]);

            $groupedQuestions = collect($rows)->groupBy('question_id');
            $optionTextMap = collect($rows)->pluck('option_text', 'option_id')->toArray();

            // 4️⃣ Compute total possible score correctly per question type
            $total = 0;
            foreach ($groupedQuestions as $questionId => $options) {
                $questionType = $options[0]->question_type ?? 'single_answer';
                $correctIds = collect($options)->where('is_correct', 1)->pluck('option_id')->toArray();

                if (in_array($questionType, ['enumeration', 'multiple_answer'])) {
                    $total += count($correctIds); // one point per correct option
                } else {
                    $total += 1; // single-answer or short-answer counts as 1
                }
            }

            $score = 0;
            $insertValues = [];
            $bindings = [];

            foreach ($answers as $ans) {
                $submittedOptions = $ans['selected_option_ids'] ?? [];
                $submittedText = $ans['short_answer'] ?? null;
                $submittedEnum = $ans['enumeration_answers'] ?? [];

                $questionOptions = $groupedQuestions[$ans['question_id']] ?? [];
                $correctIds = collect($questionOptions)->where('is_correct', 1)->pluck('option_id')->toArray();
                $correctTexts = collect($questionOptions)->where('removed', 0)
                                    ->pluck('option_text')
                                    ->map(fn($txt) => strtolower(trim($txt)))
                                    ->toArray();

                // Score for multiple/single choice
                $score += count(array_intersect($submittedOptions, $correctIds));

                // Score for enumeration
                foreach ($submittedEnum as $enumAnswer) {
                    if (in_array(strtolower(trim($enumAnswer)), $correctTexts)) {
                        $score++;
                    }
                }

                // Score for short answer
                if ($submittedText && in_array(strtolower(trim($submittedText)), $correctTexts)) {
                    $score++;
                }

                // Insert selected options in bulk
                foreach ($submittedOptions as $optId) {
                    $insertValues[] = "(?, ?, ?, ?, NOW(), 0)";
                    $bindings[] = $applicantId;
                    $bindings[] = $ans['question_id'];
                    $bindings[] = $optId;
                    $bindings[] = $optionTextMap[$optId] ?? null;
                }
            }

            // 5️⃣ Insert/update answers in bulk
            if (!empty($insertValues)) {
                DB::statement("
                    INSERT INTO assessment_answers (
                        applicant_id, question_id, selected_option_id,
                        answer_text, submitted_at, removed
                    )
                    VALUES " . implode(',', $insertValues) . "
                    ON DUPLICATE KEY UPDATE
                        selected_option_id = VALUES(selected_option_id),
                        answer_text        = VALUES(answer_text),
                        submitted_at       = NOW(),
                        removed            = 0
                ", $bindings);
            }

            // 6️⃣ Determine pass/fail (60% passing)
            $passingScore = ceil($total * 0.6);
            $status = ($score >= $passingScore) ? 'passed' : 'failed';

            // 7️⃣ Insert/update assessment_results
            DB::statement("
                INSERT INTO assessment_results (applicant_id, assessment_id, score, status, reviewed_at)
                VALUES (:applicant_id, :assessment_id, :score, :status, NOW())
                ON DUPLICATE KEY UPDATE
                    score       = VALUES(score),
                    status      = VALUES(status),
                    reviewed_at = VALUES(reviewed_at)
            ", [
                'applicant_id' => $applicantId,
                'assessment_id' => $examId,
                'score' => $score,
                'status' => $status,
            ]);

            // 8️⃣ Reset attempts
            DB::update("
                UPDATE applicant_assessments
                SET attempts_used = 0
                WHERE applicant_id = :aid AND assessment_id = :assid
            ", [
                'aid' => $applicantId,
                'assid' => $examId,
            ]);

            $allResults[] = [
                'assessment_id' => $examId,
                'score' => $score,
                'total' => $total,
                'status' => $status,
            ];

            $totalScore += $score;
            $totalQuestions += $total;
        }

        // 9️⃣ Upsert pipeline score
        $pipeline = DB::selectOne("
            SELECT id FROM applicant_pipeline
            WHERE applicant_id = :aid
            LIMIT 1
        ", ['aid' => $applicantId]);

        if ($pipeline) {
            DB::statement("
                INSERT INTO applicant_pipeline_score (
                    applicant_pipeline_id, raw_score, overall_score,
                    type, removed, created_at, updated_at
                ) VALUES (
                    :pid, :raw, :overall,
                    'exam_score', 0, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    raw_score     = VALUES(raw_score),
                    overall_score = VALUES(overall_score),
                    updated_at    = NOW()
            ", [
                'pid' => $pipeline->id,
                'raw' => $totalScore,
                'overall' => $totalQuestions,
            ]);
        }

        // 10️⃣ Dispatch pipeline finalization
        $attempts = DB::selectOne("
            SELECT MIN(attempts_used) as min_attempts
            FROM applicant_assessments
            WHERE applicant_id = :aid
        ", ['aid' => $applicantId]);

        FinalizePipelineJob::dispatch(
            $applicantId,
            $totalScore,
            $totalQuestions,
            $attempts
        );

        return response()->json([
            'message' => 'All exams submitted successfully (partial scoring enabled)',
            'results' => $allResults,
        ]);
    });
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

    public function attempt()
    {
        $userId = Auth::id();

        // Get applicant ID
        $applicant = DB::selectOne("
            SELECT id
            FROM applicants
            WHERE user_id = :uid LIMIT 1
        ", ['uid' => $userId]);

        if (!$applicant) {
            return response()->json(['error' => 'Applicant not found'], 404);
        }

        $applicantId = $applicant->id;

        // Decrease attempts_used for all assessments of this applicant
        DB::update("
            UPDATE applicant_assessments
            SET attempts_used = attempts_used - 1
            WHERE applicant_id = :aid
            AND removed = 0
        ", [
            'aid' => $applicantId,
        ]);

        // Return a simple success message
        return response()->json([
            'message' => 'Attempts updated for all assessments of the applicant.'
        ]);
    }

    public function saveUsedTime(Request $request)
    {
        $request->validate([
            'used_time' => 'required|integer|min:0',
        ]);

        $userId = Auth::id();
        $usedTime = (int) $request->used_time;

        $updated = DB::update(
            "UPDATE applicant_assessments
            SET used_time = :used_time, updated_at = NOW()
            WHERE applicant_id = (SELECT id FROM applicants WHERE user_id = :uid)
            AND removed = 0
            AND (used_time IS NULL OR used_time != :used_time_check)
            LIMIT 1",
            [
                'used_time'       => $usedTime,
                'used_time_check' => $usedTime, // second placeholder
                'uid'             => $userId,
            ]
        );

        return response()->json([
            'message'   => $updated ? 'Used time updated' : 'No change needed',
        ]);
    }


}
