<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Login Verification Code</title>
</head>
<body>
    <h2>Hello!</h2>
    
    <p>Your one-time password (OTP) for login is:</p>
    
    <h1 style="font-size: 32px; font-weight: bold; text-align: center; letter-spacing: 5px;">
        {{ $otp }}
    </h1>
    
    <p>This OTP will expire in {{ $expiryMinutes }} minutes.</p>
    
    <p><strong>If you did not request this login, please ignore this email and contact support immediately.</strong></p>
    
    <br>
    <p>Regards,<br>{{ $appName }}</p>
</body>
</html>