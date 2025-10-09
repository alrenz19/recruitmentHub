<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public $staffName;
    public $updaterName;
    public $updatedFields;
    public $updateTime;
    public $position;
    public $department;

    /**
     * Create a new message instance.
     */
    public function __construct($staffName, $updaterName, $updatedFields, $updateTime, $position = null, $department = null)
    {
        $this->staffName = $staffName;
        $this->updaterName = $updaterName;
        $this->updatedFields = $updatedFields;
        $this->updateTime = $updateTime;
        $this->position = $position;
        $this->department = $department;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Staff Profile Updated - {$this->staffName}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-updated',
            with: [
                'staffName' => $this->staffName,
                'updaterName' => $this->updaterName,
                'updatedFields' => $this->updatedFields,
                'updateTime' => $this->updateTime,
                'position' => $this->position,
                'department' => $this->department,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}