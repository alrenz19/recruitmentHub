<x-mail::message>
# Welcome to {{ config('app.name') }}
This is your credentials for the portal.

Email: {{ $user_email }}  
Password: {{ $password }}

<x-mail::button :url="$loginUrl">
Login
</x-mail::button>

Kindly log in and make sure to change your password afterward.
Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
