@component('mail::message')
Hello {{ $fullName }},

Congratulations! You are selected to proceed to the next step of the application process.  
We are pleased to invite you to the **{{ $stage }}**.

**Details of the Interview:**
- Date: {{ $date }}
- Time: {{ $time }}
- Mode: {{ $mode ?: 'Face to Face' }}
@if(!empty($mode) && strtolower($mode) !== 'face to face')
- Link: {{ $link }}
@endif
- Interviewer(s): {{ implode(', ', $participants) }}

If the proposed schedule is not convenient, please let us know your preferred time so we can make adjustments through our system.

We look forward to speaking with you.

Best regards,  
Recruitment Team  
Toyoflex  

---

This is an automated email from the system. Please do not reply to this message.
@endcomponent
