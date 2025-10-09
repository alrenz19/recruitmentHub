<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 20px; text-align: center; }
        .content { background: #f9fafb; padding: 30px; }
        .button { 
            background: transparent !important; 
            color: #2563eb !important; 
            border: 2px solid #2563eb !important;
            padding: 12px 24px; 
            text-decoration: none !important; 
            border-radius: 6px; 
            display: inline-block; 
            margin: 20px 0; 
            font-weight: bold;
            text-align: center;
        }
        .footer { text-align: center; margin-top: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Password Reset Request</h1>
        </div>
        <div class="content">
            <h2>Hello {{ $userName ?? 'there' }},</h2>
            <p>You requested to reset your password for your {{ config('app.name') }} recruitment account.</p>
            <p>Click the button below to reset your password:</p>
            <a href="{{ $resetUrl }}" class="button" style="background: transparent; color: #2563eb; border: 2px solid #2563eb; text-decoration: none; padding: 12px 24px; border-radius: 6px; display: inline-block; margin: 20px 0; font-weight: bold;">Reset Password</a>
            <p>Or copy and paste this link in your browser:</p>
            <p><code>{{ $resetUrl }}</code></p>
            <p><strong>This link will expire in 1 hour.</strong></p>
            <p>If you didn't request this reset, please ignore this email.</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>