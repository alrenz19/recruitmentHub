@component('mail::message')
# Job Offer Rejected ❌

Dear HR Team,

The applicant **{{ $applicantName }}** has **declined** the job offer.  

**Offer Details:**
- **Position:** {{ $position }}
- **Department:** {{ $department }}

Please review and take necessary action.

@component('mail::button', ['url' => $approvalLink])
View Job Offer
@endcomponent


Toyoflex Cebu 
@endcomponent
