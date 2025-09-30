<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApproverBoard;
use Illuminate\Support\Facades\DB;

class ApproverBoardController extends Controller
{
    public function index()
{
    $candidates = DB::table('applicant_pipeline')
        ->join('applicants', 'applicant_pipeline.applicant_id', '=', 'applicants.id')
        ->where('applicant_pipeline.current_stage_id', 3)
        ->where('applicant_pipeline.note', 'Pending')
        ->select(
            'applicants.id as id',
            'applicants.full_name',
            'applicants.profile_picture',
            'applicants.position_desired',
            'applicant_pipeline.schedule_date'
        )
        ->get();

    $result = $candidates->map(function ($candidate) {
        // ✅ Score (average across results)
        $score = DB::table('assessment_results')
            ->where('applicant_id', $candidate->id)
            ->avg('score');

        // ✅ Resume file
        $resume = DB::table('applicant_files')
            ->where('applicant_id', $candidate->id)
            ->where('file_name', 'resume')
            ->select('file_name', 'file_path')
            ->first();

        // ✅ Avatar file
        $avatar = DB::table('applicants')
            ->where('id', $candidate->id)
            ->select('profile_picture', 'profile_picture')
            ->first();

        // ✅ Participants
        $participants = DB::table('participants')
            ->where('applicant_pipeline_id', $candidate->id)
            ->pluck('name');

        // ✅ Assessments assigned to applicant
        $assessments = DB::table('applicant_assessments')
        ->join('assessments', 'applicant_assessments.assessment_id', '=', 'assessments.id')
        ->where('applicant_assessments.applicant_id', $candidate->id)
        ->select('assessments.id as assessment_id', 'assessments.title')
        ->get()
        ->map(function ($assessment) use ($candidate) {
            // Total questions in this assessment
            $totalQuestions = DB::table('assessment_questions')
                ->where('assessment_id', $assessment->assessment_id)
                ->count();

            // Correct answers for this applicant
            $correctAnswers = DB::table('assessment_results')
                ->where('assessment_id', $assessment->assessment_id)
                ->where('applicant_id', $candidate->id)
                ->sum('score'); // assuming `score` = 1 per correct

            return [
                'assessment_id' => $assessment->assessment_id,
                'title'         => $assessment->title,
                'correct'       => $correctAnswers,
                'total'         => $totalQuestions,
                'percentage'    => $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100) : 0,
            ];
        });


        return [
            'id'           => $candidate->id,
            'name'         => $candidate->full_name,
            'position'     => $candidate->position_desired,
            'avatar'       => $avatar ? url('storage/' . $avatar->profile_picture) : null,
            'schedule_date'=> $candidate->schedule_date,
            'score'        => $score ?? 0,
            'assessments'  => $assessments,
            'participants' => $participants,
            'resumeUrl'    => $resume ? url('storage/' . $resume->file_path) : null,
            'resumeName'   => $resume ? $resume->file_name : null,
        ];
    });

    return response()->json([
        'candidates' => $result
    ]);
}


}
