@component('mail::message')
# Your Job Offer is Ready 🎉

Dear {{ $applicantName }},

We are pleased to inform you that your job offer for the position of **{{ $position }}** is now ready for your review.

Please check the details and confirm your decision:

@component('mail::button', ['url' => $approvalLink])
View & Respond to Job Offer
@endcomponent

We look forward to your response.

Best regards,  
Recruitment Team
@endcomponent
