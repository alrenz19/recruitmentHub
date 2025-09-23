@component('mail::message')
# Assessment Results

Hello {{ $name }},

Thank you for completing your assessment. Here are your results:

- **Score:** {{ $score }} / {{ $total }}
- **Status:** ❌ Failed

We understand this might be disappointing, but don’t worry — you still have **{{ $remainingAttempts }} attempt(s)** left to try again.

@component('mail::button', ['url' => config('app.url') . '/assessments'])
Retake Assessment
@endcomponent

Stay confident and give it your best shot — we believe in your potential!

Best regards,  
**Recruitment Team**  
Toyoflex  

---
@endcomponent
