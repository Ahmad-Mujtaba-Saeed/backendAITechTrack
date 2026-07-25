<!DOCTYPE html>
<html>
<head>
    <title>You’re all set, {{ $user->name }} — your Cv Builder journey starts now 🚀</title>
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
        <p><Strong>Hey {{ $user->name }}</Strong></p>

        <p>Welcome to <strong> Cv Builder</strong>! We're thrilled to have you on board! 🙌</p>

        <p>Your <strong>{{ $plan->interval }} Plan</strong> is now active, which means you’ve unlocked full access to all our premium
            tools to help you build, refine, and track your career journey with confidence.</p>

        <p><strong>Here’s a quick summary:</strong></p>
        <ul>
            <li><strong>Plan:</strong> {{ $plan->title }}</li>
            <li><strong>Billing Cycle:</strong> {{ $plan->interval }}</li>
            <li><strong>Subscription Start:</strong> {{ \Carbon\Carbon::parse($subscriptionStartsAt)->format('jS F Y, h:i A') }}</li>
            <li><strong>Next Billing Date:</strong> {{ \Carbon\Carbon::parse($subscriptionEndsAt)->format('jS F Y, h:i A') }}</li>
        </ul>

        <p>
            You can jump straight in and start exploring your <a href="https://portal.mypathfinder.uk">Dashboard</a> (your personal career HQ!)
            <br>
            If you ever need a hand or have a question, our team’s here to help. Just reach out — we’ve got your back.
        </p>

        <p>
            <strong>Let’s get you one step closer to your next opportunity. 🌟</strong>
            <br>
            See you inside,
        </p>

        <p><strong>The  Cv Builder Team</strong></p>
        <br>
        <p>
            Alex Dobricic
            <br>
            Co-Founder & Product Director
        </p>
        <p>
            This email and any attachments are confidential and may be privileged or protected by law. If you are not
            the intended recipient, please notify the sender immediately and delete this email from your system. Any
            unauthorised use, disclosure, copying, or distribution is prohibited.
            <br>
             Cv Builder Ltd is a company registered in England and Wales with company number 16239837.

        </p>
    </div>



    <div class="footer">
        <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        <p>
            <a href="https://portal.mypathfinder.uk/privacy-policy">Privacy Policy</a> |
            <a href="https://portal.mypathfinder.uk/terms">Terms of Service</a>
        </p>
    </div>
</body>

</html>