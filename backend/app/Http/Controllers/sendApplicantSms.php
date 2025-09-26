<?php
use App\Services\SmsService;

// public function sendApplicantSms($applicantId)
// {
//     $applicant = Applicant::findOrFail($applicantId);

//     $sms = new SmsService();
//     $message = "Toyoflex Cebu: Hello {$applicant->full_name}, your application is received.";

//     $response = $sms->sendSms($applicant->phone, $message);

//     if(isset($response['success']) && $response['success']){
//         return response()->json(['status' => 'SMS sent successfully']);
//     }

//     return response()->json(['status' => 'Failed to send SMS', 'error' => $response]);
// }
// ?>