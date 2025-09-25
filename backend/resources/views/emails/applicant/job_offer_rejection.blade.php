@component('mail::message')
# Job Offer Proposal Rejected

Dear HR Team,

The job offer proposal for **{{ $applicantName }}** for the position of **{{ $position }}** 
has been **rejected** by management.

Please review and update the proposal accordingly.

@component('mail::button', ['url' => $approvalLink])
View Proposal
@endcomponent

Best regards,  
Recruitment Team
@endcomponent
