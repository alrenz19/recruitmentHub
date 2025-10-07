<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Candidate Update Notification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8f9fa; padding: 20px; text-align: center; border-radius: 5px; }
        .content { background: white; padding: 20px; border-radius: 5px; margin-top: 20px; }
        .changes { background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .change-item { margin: 8px 0; padding: 5px; background: white; border-radius: 3px; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Candidate Information Updated</h1>
        </div>
        
        <div class="content">
            <p>Hello Team,</p>
            
            <p>The following candidate information has been updated:</p>
            
            <div class="candidate-info">
                <p><strong>Candidate:</strong> {{ $candidateName }}</p>
                <p><strong>Updated By:</strong> {{ $updaterName }}</p>
                <p><strong>Update Time:</strong> {{ $updateTime }}</p>
            </div>
            
            @if(!empty($updatedFields))
            <div class="changes">
                <h3>Changes Made:</h3>
                @foreach($updatedFields as $field => $value)
                <div class="change-item">
                    <strong>{{ ucfirst(str_replace('_', ' ', $field)) }}:</strong> 
                    {{ is_array($value) ? implode(', ', $value) : $value }}
                </div>
                @endforeach
            </div>
            @else
            <div class="changes">
                <p><em>General information update</em></p>
            </div>
            @endif
            
            <p>Please log in to the system to view the complete candidate details.</p>
        </div>
        
        <div class="footer">
            <p>This is an automated notification from the Recruitment System.</p>
            <p>Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>