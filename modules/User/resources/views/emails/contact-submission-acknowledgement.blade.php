<!DOCTYPE html>
<html>
<head>
    <title>We received your message — {{ config('app.name') }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .content {
            padding: 5px 0 20px;
        }

        .message-box {
            background-color: #f7f7f7;
            border-left: 3px solid #FF6B47;
            padding: 12px 16px;
            margin: 10px 0 20px;
            white-space: pre-wrap;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            text-align: center;
            font-size: 0.9em;
            color: #777;
        }
    </style>
</head>

<body>
    <div class="content">
        <p><strong>Hi {{ $contact->name }},</strong></p>

        <p>Thanks for reaching out to <strong>{{ config('app.name') }}</strong>! 🙌</p>

        <p>We've received your message and our team will get back to you as soon as possible.</p>

        <p><strong>Here’s what you sent us:</strong></p>
        <div class="message-box">{{ $contact->message }}</div>

        <p>
            If you need to add anything, just reply to this email and it will reach us at
            <a href="mailto:{{ config('mail.support_address') }}">{{ config('mail.support_address') }}</a>.
        </p>

        <p>Regards,<br>The {{ config('app.name') }} Team</p>
    </div>

    <div class="footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>
</html>
