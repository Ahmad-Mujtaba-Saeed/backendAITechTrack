<!DOCTYPE html>
<html>
<head>
    <title>New Contact Enquiry — {{ config('app.name') }}</title>
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
        <p><strong>Hey Admin</strong></p>

        <p>A new enquiry just came in through the contact form. 📬</p>

        <p><strong>Here’s a quick summary:</strong></p>
        <ul>
            <li><strong>Name:</strong> {{ $contact->name }}</li>
            <li><strong>Email:</strong> <a href="mailto:{{ $contact->email }}">{{ $contact->email }}</a></li>
            @if ($contact->subject)
                <li><strong>Subject:</strong> {{ $contact->subject }}</li>
            @endif
            <li><strong>Received:</strong> {{ $contact->created_at?->format('jS F Y, h:i A') }}</li>
        </ul>

        <p><strong>Message:</strong></p>
        <div class="message-box">{{ $contact->message }}</div>

        <p>Just hit reply to get back to {{ $contact->name }} directly.</p>
    </div>

    <div class="footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>
</html>
