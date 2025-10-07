<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ApproverBoard;
use Illuminate\Support\Facades\DB;

class ApproverBoardController extends Controller
{

   public function index()
{
    // Get all candidate IDs first
    $candidateIds = DB::table('applicant_pipeline')
        ->join('applicants', 'applicant_pipeline.applicant_id', '=', 'applicants.id')
        ->where('applicant_pipeline.current_stage_id', 3)
        ->where('applicant_pipeline.note', 'Pending')
        ->pluck('applicants.id')
        ->toArray();

    if (empty($candidateIds)) {
        return response()->json(['candidates' => []]);
    }

    // Batch load all data in single queries
    $candidatesData = $this->getCandidatesData($candidateIds);
    $scoresData = $this->getScoresData($candidateIds);
    $resumesData = $this->getResumesData($candidateIds);
    $participantsData = $this->getParticipantsData($candidateIds);
    $assessmentsData = $this->getAssessmentsData($candidateIds);
    $educationData = $this->getEducationData($candidateIds);
    $licensesData = $this->getLicensesData($candidateIds);

    // Process and combine all data
    $result = collect($candidatesData)->map(function ($candidate) use (
        $scoresData, 
        $resumesData, 
        $participantsData, 
        $assessmentsData,
        $educationData,
        $licensesData
    ) {
        $candidateId = $candidate->id;

        return [
            'id'           => $candidateId,
            'name'         => $candidate->full_name,
            'position'     => $candidate->position_desired,
            'avatar'       => $candidate->profile_picture ? url('storage/' . $candidate->profile_picture) : null,
            'schedule_date'=> $candidate->schedule_date,
            'score'        => $scoresData[$candidateId] ?? 0,
            'assessments'  => $assessmentsData[$candidateId] ?? [],
            'participants' => $participantsData[$candidateId] ?? [],
            'resumeUrl'    => isset($resumesData[$candidateId]) ? url('storage/' . $resumesData[$candidateId]->file_path) : null,
            'resumeName'   => $resumesData[$candidateId]->file_name ?? null,
            'highest_education' => $educationData[$candidateId] ?? null,
            'license' => $licensesData[$candidateId] ?? null,
            'salary' => $candidate->desired_salary,
            'pipelineId' => $candidate->pipelineId
        ];
    });

    return response()->json([
        'candidates' => $result
    ]);
}

/**
 * Get main candidate data
 */
private function getCandidatesData(array $candidateIds)
{
    return DB::table('applicant_pipeline')
        ->join('applicants', 'applicant_pipeline.applicant_id', '=', 'applicants.id')
        ->where('applicant_pipeline.current_stage_id', 3)
        ->where('applicant_pipeline.note', 'Pending')
        ->whereIn('applicants.id', $candidateIds)
        ->select(
            'applicant_pipeline.id as pipelineId',
            'applicants.desired_salary',
            'applicants.id as id',
            'applicants.full_name',
            'applicants.profile_picture',
            'applicants.position_desired',
            'applicants.desired_salary',
            'applicant_pipeline.schedule_date'
        )
        ->get();
}

/**
 * Get average scores for all candidates
 */
private function getScoresData(array $candidateIds)
{
    return DB::table('assessment_results')
        ->whereIn('applicant_id', $candidateIds)
        ->select('applicant_id', DB::raw('AVG(score) as avg_score'))
        ->groupBy('applicant_id')
        ->pluck('avg_score', 'applicant_id')
        ->toArray();
}

/**
 * Get resumes for all candidates
 */
private function getResumesData(array $candidateIds)
{
    return DB::table('applicant_files')
        ->whereIn('applicant_id', $candidateIds)
        ->where('file_name', 'resume')
        ->select('applicant_id', 'file_name', 'file_path')
        ->get()
        ->keyBy('applicant_id');
}

/**
 * Get participants for all candidates
 */
private function getParticipantsData(array $candidateIds)
{
    $participants = DB::table('participants')
        ->whereIn('applicant_pipeline_id', $candidateIds)
        ->select('applicant_pipeline_id', 'name', )
        ->get();

    return $participants->groupBy('applicant_pipeline_id')
        ->map(function ($group) {
            return $group->pluck('name');
        })
        ->toArray();
}

/**
 * Get assessments and scores for all candidates
 */
private function getAssessmentsData(array $candidateIds)
{
    // Get all assessments for candidates
    $assessments = DB::table('applicant_assessments')
        ->join('assessments', 'applicant_assessments.assessment_id', '=', 'assessments.id')
        ->whereIn('applicant_assessments.applicant_id', $candidateIds)
        ->select(
            'applicant_assessments.applicant_id',
            'assessments.id as assessment_id',
            'assessments.title'
        )
        ->get();

    $assessmentIds = $assessments->pluck('assessment_id')->unique()->toArray();
    
    // Get total CORRECT OPTIONS per assessment (this is the key change)
    $correctOptionsCount = DB::table('assessment_questions')
        ->join('assessment_options', 'assessment_questions.id', '=', 'assessment_options.question_id')
        ->whereIn('assessment_questions.assessment_id', $assessmentIds)
        ->where('assessment_options.is_correct', 1)
        ->select('assessment_questions.assessment_id', DB::raw('COUNT(*) as total_correct_options'))
        ->groupBy('assessment_questions.assessment_id')
        ->pluck('total_correct_options', 'assessment_questions.assessment_id')
        ->toArray();

    // Get all assessment results (sum of correct answers identified by applicant)
    $assessmentResults = DB::table('assessment_results')
        ->whereIn('applicant_id', $candidateIds)
        ->whereIn('assessment_id', $assessmentIds)
        ->select('applicant_id', 'assessment_id', DB::raw('SUM(score) as correct_answers'))
        ->groupBy('applicant_id', 'assessment_id')
        ->get()
        ->groupBy('applicant_id');

    // Build the assessments data structure
    $result = [];

    foreach ($assessments as $assessment) {
        $candidateId = $assessment->applicant_id;
        $assessmentId = $assessment->assessment_id;
        
        $totalCorrectOptions = $correctOptionsCount[$assessmentId] ?? 0; // This will be 15 in your example
        $correctAnswers = $assessmentResults[$candidateId][$assessmentId]->correct_answers ?? 0; // This will be 9 in your example

        if (!isset($result[$candidateId])) {
            $result[$candidateId] = [];
        }

        $result[$candidateId][] = [
            'assessment_id' => $assessmentId,
            'title'         => $assessment->title,
            'correct'       => $correctAnswers,       // 9
            'total'         => $totalCorrectOptions,  // 15 (not 10!)
        ];
    }

    return $result;
}

/**
 * Get highest educational attainment for all candidates
 */
/**
 * Get highest educational attainment for all candidates (single record per candidate)
 */
/**
 * Get highest educational attainment for all candidates
 */
private function getEducationData(array $candidateIds)
{
    // Define the order of educational levels (highest to lowest) with actual IDs
    $educationLevelsPriority = [
        27 => 1, // Graduate School (highest)
        26 => 2, // College
        25 => 3, // Vocational
        24 => 4, // Senior High School  
        23 => 5, // High School
        22 => 6  // Elementary (lowest)
    ];

    // Convert education levels to SQL CASE statement
    $levelCase = "CASE al.id ";
    foreach ($educationLevelsPriority as $levelId => $priority) {
        $levelCase .= "WHEN {$levelId} THEN {$priority} ";
    }
    $levelCase .= "ELSE 999 END";

    // Get only the highest education record per candidate
    $highestEducations = DB::table('educational_background as eb')
        ->join('academic_level as al', 'eb.academic_level_id', '=', 'al.id') // Note: academic_level (singular)
        ->whereIn('eb.applicant_id', $candidateIds)
        ->where('eb.removed', 0)
        ->select(
            'eb.applicant_id',
            'al.name as level',
            'eb.degree_major',
        )
        ->whereIn('al.id', array_keys($educationLevelsPriority)) // Only include known levels
        ->orderByRaw($levelCase) // Order by priority (lower number = higher education)
        ->orderBy('eb.from_date', 'desc') // Secondary sort by most recent
        ->distinct('eb.applicant_id') // Get only one record per applicant
        ->get()
        ->keyBy('applicant_id');

    $result = [];

    foreach ($highestEducations as $candidateId => $education) {
        $result[$candidateId] = [
            'level' => $education->level,
            'degree_major' => $education->degree_major,
        ];
    }

    return $result;
}

/**
 * Get licenses for all candidates
 */
private function getLicensesData(array $candidateIds)
{
    $licenses = DB::table('applicant_achievements')
        ->whereIn('applicant_id', $candidateIds)
        ->whereNotNull('license_no')
        ->select('applicant_id', 'licensure_exam', 'license_no')
        ->get()
        ->groupBy('applicant_id');

    $result = [];

    foreach ($licenses as $candidateId => $licenseGroup) {
        $license = $licenseGroup->first(); // Take the first license if multiple
        $result[$candidateId] = [
            'exam' => $license->licensure_exam,
            'license_no' => $license->license_no
        ];
    }

    return $result;
}


}
