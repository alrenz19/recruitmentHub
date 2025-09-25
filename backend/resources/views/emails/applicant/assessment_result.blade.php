@component('mail::message')
# Assessment Result

Hello {{ $name }},

Your assessment results are now available:

- **Score:** {{ $score }} / {{ $total }}
- **Status:** **{{ $status }}**

@if ($status === 'Passed')
We are thrilled to inform you that you have successfully passed the assessment. 🎉  
Our recruitment team will be in touch soon to schedule your **initial interview**.  

We look forward to seeing you in the next stage of the hiring process!
@else
Thank you sincerely for completing the assessment. While you did not reach the minimum passing score this time, we truly appreciate the effort and dedication you put into the process.  

We encourage you to continue developing your skills and to explore future opportunities with us.
@endif

Best regards,  
Recruitment Team  
Toyoflex  

---
@endcomponent
