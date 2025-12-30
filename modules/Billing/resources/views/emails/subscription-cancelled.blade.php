<!DOCTYPE html>
<html>
<head>
    <title>Subscription Cancellation - {{ config('app.name') }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            text-align: center;
            padding: 20px 0;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
        }
        .content {
            padding: 20px 0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 0.9em;
            color: #777;
        }
        .button {
            display: inline-block;
            padding: 10px 20px;
            margin: 20px 0;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .cancellation-details {
            background-color: #f8f9fa;
            border-left: 4px solid #dc3545;
            padding: 15px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h2>We're Sorry to See You Go</h2>
    </div>
    
    <div class="content">
        <p>Hello {{ $user->name }},</p>
        
        <p>We've processed your request to cancel your subscription to our <strong>{{ $plan->title ?? 'Premium' }}</strong> plan. We're sorry to see you go!</p>
        
        <div class="cancellation-details">
            <p><strong>Cancellation Details:</strong></p>
            <ul>
                <li><strong>Plan:</strong> {{ $plan->title ?? 'Premium' }}</li>
                <li><strong>Cancellation Date:</strong> {{ $cancellationDate->format('F j, Y') }}</li>
                <li><strong>Access Until:</strong> {{ $cancellationDate->format('F j, Y') }}</li>
            </ul>
        </div>

        <p>Your access to premium features will remain active until the end of your current billing period.</p>
        
        <p>We'd love to know why you decided to cancel. Your feedback is valuable to us and helps us improve our service.</p>
        
        <div style="text-align: center; margin: 30px 0;
            <a href="{{ url('/contact') }}" class="button">Share Your Feedback</a>
        </div>
        
        <p>If you change your mind, you can reactivate your subscription at any time by visiting your account settings.</p>
        
        <p>Thank you for being a part of {{ config('app.name') }}. We hope to see you again soon!</p>
        
        <p>Best regards,<br>The {{ config('app.name') }} Team</p>
    </div>
    
    <div class="footer">
        <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        <p>
            <a href="{{ url('/privacy') }}" style="color: #4CAF50; text-decoration: none;">Privacy Policy</a> | 
            <a href="{{ url('/terms') }}" style="color: #4CAF50; text-decoration: none;">Terms of Service</a>
        </p>
    </div>
</body>
</html>
