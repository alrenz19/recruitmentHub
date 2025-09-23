@component('mail::message')
Dear {{ $interviewerName }} san,

You have been scheduled to conduct an interview as part of our hiring process.

**Interview Details:**
- Applicant: {{ $applicantName }}
- Position Applied For: {{ $position }}
- Date & Time: {{ $dateTime }}
- Stage: {{ $stage }}
- Mode: {{ $mode ?: 'Face to Face' }}
@if(!empty($mode) && strtolower($mode) !== 'face to face')
- Link: {{ $link }}
@endif


Please confirm your availability. If you are unable to attend, kindly let us know so we can make alternative arrangements.

Thank you,  
Recruitment Team
---

@endcomponent
