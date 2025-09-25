<?php
namespace App\Jobs;

use App\Services\JobOfferPipelineService;
use App\Services\JobOfferNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessJobOfferPipelineAndNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $jobOfferId;
    protected string $status;
    protected string $actor;
    protected int $userId;

    public function __construct($jobOfferId, $status, $actor, $userId)
    {
        $this->jobOfferId = (int) $jobOfferId;
        $this->status     = $status;
        $this->actor      = $actor;
        $this->userId     = (int) $userId;
    }

    public function handle(
        JobOfferPipelineService $pipelineService,
        JobOfferNotificationService $notificationService
    ): void {
        // ✅ Single query for applicant + job_offer details
        $applicant = DB::selectOne("
            SELECT a.id,
                   a.full_name,
                   jo.id  AS job_offer_id,
                   jo.offer_details
            FROM job_offers jo
            INNER JOIN applicants a ON jo.applicant_id = a.id
            WHERE jo.id = ?
            LIMIT 1
        ", [$this->jobOfferId]);

        if (!$applicant) {
            // Nothing to process
            return;
        }

        // ✅ Single query for HR staff
        $hrStaff = DB::selectOne("
            SELECT id, full_name, contact_email AS email FROM hr_staff WHERE user_id = ? LIMIT 1
        ", [$this->userId]);

        // ✅ Update pipeline (business logic inside service)
        $pipelineService->updatePipelineNote(
            $applicant->id,
            $this->status,
            $this->actor
        );

        // ✅ Send notifications
        $notificationService->notify(
            $this->jobOfferId,
            $this->status,
            $this->actor,
            $applicant,
            $hrStaff
        );
    }
}
