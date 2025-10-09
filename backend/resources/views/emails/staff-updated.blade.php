<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Update Notification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8f9fa; padding: 20px; text-align: center; border-radius: 5px; }
        .content { background: white; padding: 20px; border-radius: 5px; margin-top: 20px; }
        .staff-info { background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .changes { background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .change-item { margin: 8px 0; padding: 8px; background: white; border-radius: 3px; border-left: 4px solid #007bff; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; }
        .password-change { background: #d4edda; color: #155724; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Staff Profile Update</h1>
        </div>
        
        <div class="content">
            <p>Hello {{ $staffName }},</p>
            
            <p>A staff member's profile has been updated in the system:</p>
            
            <div class="staff-info">
                <h3>Staff Information:</h3>
                <p><strong>Name:</strong> {{ $staffName }}</p>
                @if($position)<p><strong>Position:</strong> {{ $position }}</p>@endif
                @if($department)<p><strong>Department:</strong> {{ $department }}</p>@endif
                <p><strong>Updated By:</strong> {{ $updaterName }}</p>
                <p><strong>Update Time:</strong> {{ $updateTime }}</p>
            </div>
            
            @if(!empty($updatedFields))
            <div class="changes">
                <h3>Changes Made:</h3>
                @foreach($updatedFields as $field => $value)
                    @if($field === 'password')
                    <div class="password-change">
                        <strong>🔒 Password:</strong> Was reset/updated
                    </div>
                    @else
                    <div class="change-item">
                        <strong>{{ ucfirst(str_replace('_', ' ', $field)) }}:</strong> 
                        <span style="color: #007bff;">{{ is_array($value) ? implode(', ', $value) : $value }}</span>
                    </div>
                    @endif
                @endforeach
            </div>
            @else
            <div class="changes">
                <p><em>General profile update</em></p>
            </div>
            @endif
            
            <p>This update has been recorded in the system. Please review if necessary.</p>
        </div>
        
        <div class="footer">
            <p>This is an automated notification from the HR Management System.</p>
            <p>Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>