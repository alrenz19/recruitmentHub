<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\JobOfferApprovalMail;
use App\Mail\JobOfferRejectionMail;
use App\Mail\ApplicantJobOfferRejectedMail;
use App\Mail\ApplicantJobOfferAcceptedMail;
use App\Mail\ApplicantJobOfferNotificationMail;


class JobOfferNotificationService
{
    public function notify(
        $jobOfferId,
        $status,
        $currentApproverRole,
        $applicant,
        $hrStaff
    )
    {
        $offerDetails = json_decode($applicant->offer_details, true) ?? [];
        $position = $offerDetails['position'];
        $department = $offerDetails['department'];

        if ($status === 'rejected') {
            // 🔴 Notify HR only
            Mail::to($hrStaff->email)
                ->queue(new JobOfferRejectionMail(
                    $applicant->full_name,
                    $position ?? '',
                    frontend_url('recruitment-board', ['id' => $applicant->id])
                ));
        } elseif ($status === 'approved') {
            // 🟢 Notify next approver
            switch ($currentApproverRole) {
                case 'management':
                    $id = DB::table('job_offers')->where('id', $jobOfferId)->value('management_id');
                    $nextEmail = DB::table('hr_staff')->where('id', $id)->value('contact_email');
                    break;
                case 'fm':
                    $nextEmail = DB::table('hr_staff')->where('role', 'admin')->value('email');
                    break;
                case 'admin':
                    // Admin approved → Waiting for applicant, no email yet
                    $nextEmail = null;
                        // ✅ Send email to applicant
                    if ($applicant->email) {
                        Mail::to($applicant->email)
                            ->queue(new ApplicantJobOfferNotificationMail(
                                $applicant->full_name,
                                $position ?? '',
                                $department ?? '',
                                frontend_url('job-offer-status', ['id' => $jobOfferId])
                            ));
                    }
                    break;
            }

            if ($nextEmail) {
                Mail::to($nextEmail)
                    ->queue(new JobOfferApprovalMail(
                        $applicant->full_name,
                        $position?? '',
                        $department ?? '',
                        $hrStaff->full_name ?? 'HR Staff',
                        $jobOfferId,
                        frontend_url('job-offer-status', ['id' => $applicant->id])
                    ));
            }
        } elseif ($status === 'applicant_approved') {
            // ✅ Notify HR
            Mail::to($hrStaff->email)
                ->queue(new ApplicantJobOfferAcceptedMail(
                    $applicant->full_name,
                    $position ?? '',
                    $department ?? '',
                    frontend_url('recruitment-board', ['id' => $applicant->id])
                ));
        } elseif ($status === 'applicant_rejected') {
            // ❌ Notify HR
            Mail::to($hrStaff->email)
                ->queue(new ApplicantJobOfferRejectedMail(
                    $applicant->full_name, 
                    $position?? '',
                    $department ?? '',
                    frontend_url('recruitment-board', ['id' => $applicant->id])
                ));
        }
    }
}
