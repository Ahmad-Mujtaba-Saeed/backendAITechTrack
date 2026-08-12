<!DOCTYPE html>
<html>
<head>
    <title>New User Subscription, {{ $user->name }} — {{ config('app.name') }}</title>
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

    </style>
</head>

<body>
    <div class="content">
        <p><Strong>Hey Admin</Strong></p>

        <p>You've got a new user subscription! 🙌</p>

        <p>Welcome to <strong>{{ config('app.name') }}</strong>!</p>

        <p><strong>Here’s a quick summary:</strong></p>
        <ul>
            <li><strong>User:</strong> {{ $user->name }}</li>
            <li><strong>Plan:</strong> {{ $plan->title }}</li>
            <li><strong>Billing Cycle:</strong> {{ $plan->interval }}</li>
            <li><strong>Subscription Start:</strong> {{ \Carbon\Carbon::parse($subscriptionStartsAt)->format('jS F Y, h:i A') }}</li>
            <li><strong>Next Billing Date:</strong> {{ \Carbon\Carbon::parse($subscriptionEndsAt)->format('jS F Y, h:i A') }}</li>
        </ul>

        <p>
            You can jump straight in and start exploring the user's <a href="https://aicvbuilder.wasimdev.com/">Dashboard</a> (their personal career HQ!)
            <br>
            If you ever need a hand or have a question, our team’s here to help. Just reach out — we’ve got your back.
        </p>

        <p><strong>The {{ config('app.name') }} Team</strong></p>

    </div>



    <div class="footer">
        <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>

</html>