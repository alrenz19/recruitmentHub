<?php

use Illuminate\Support\Facades\Mail;

Route::get('/test-email', function () {
    Mail::raw('This is a test email.', function ($message) {
        $message->to('almahsoltabigue19@gmail.com')
                ->subject('Test Email from Laravel');
    });

    return 'Email sent!';
});