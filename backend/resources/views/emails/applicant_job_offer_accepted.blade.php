@component('mail::message')
# Job Offer Accepted 🎉

Dear HR Team,

The applicant **{{ $applicantName }}** has **accepted** the job offer.

**Offer Details:**
- **Position:** {{ $position }}
- **Department:** {{ $department }}

You may now proceed with the next steps in onboarding.

@component('mail::button', ['url' => $approvalLink])
View Job Offer
@endcomponent

Toyoflex Cebu
@endcomponent
