@component('mail::message')
# Job Offer Submitted for Approval

Dear Management,

The HR staff **{{ $hrName }}** has submitted a job offer proposal that requires your review and approval.

**Applicant Details:**
- **Name:** {{ $applicantName }}
- **Position:** {{ $position }}
- **Department:** {{ $department }}

Please review the details and take the appropriate action:

@component('mail::button', ['url' => $approvalLink])
Review & Approve
@endcomponent

Your timely response will help us proceed smoothly with the hiring process.

Best regards,
Recruitment Team

---
@endcomponent