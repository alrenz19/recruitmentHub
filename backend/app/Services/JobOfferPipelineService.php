<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class JobOfferPipelineService
{
    public function updatePipelineNote($applicantId, $status, $currentApproverRole)
    {
        $note = null;

        if ($status === 'rejected') {
            $note = 'proposal rejected urgent';
        } elseif ($status === 'approved') {
            switch ($currentApproverRole) {
                case 'management':
                    $note = 'FM review';
                    break;
                case 'fm':
                    $note = 'administrative review';
                    break;
                case 'admin':
                    $note = 'waiting for applicant';
                    break;
            }
        } elseif ($status === 'applicant_approved') {
            $note = 'job offer accepted';
        } elseif ($status === 'applicant_rejected') {
            $note = 'job offer rejected';
        }

        if ($note) {
            DB::table('applicant_pipeline')
                ->where('applicant_id', $applicantId)
                ->update(['note' => $note, 'updated_at' => now()]);
        }

        return $note;
    }
}
