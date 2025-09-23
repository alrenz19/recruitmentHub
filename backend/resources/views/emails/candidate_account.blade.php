@component('mail::message')
Hello {{ $fullName }},

Congratulations! You have passed the initial screening for a position at Toyoflex.

As part of the next step, you are invited to take our entrance assessment.

**Temporary account credentials:**

Email: {{ $emailAddress }}  
Password: {{ $password }}

@component('mail::button', ['url' => $loginUrl])
Access the Portal
@endcomponent

Kindly log in, take the assessment, and make sure to change your password afterward. Also, please update your application information as needed.

We look forward to seeing your results!


Best regards,  
Recruitment Team  
Toyoflex 

---

This is an automated email from the system. Please do not reply to this message.
@endcomponent
