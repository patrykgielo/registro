<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Appointment Confirmation - ' . config('app.name') }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #ffffff;
        }
        h1 {
            color: #2c3e50;
            font-size: 24px;
            margin-bottom: 20px;
        }
        p {
            margin-bottom: 15px;
        }
        .appointment-details {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .appointment-details strong {
            color: #2c3e50;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #777;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Hello {{ $customer_name }}!</h1>

        <p>Your appointment for <strong>{{ $service_name }}</strong> has been confirmed.</p>

        <div class="appointment-details">
            <p><strong>Date:</strong> {{ $appointment_date }}</p>
            <p><strong>Time:</strong> {{ $appointment_time }}</p>
            <p><strong>Location:</strong> {{ $location_address }}</p>
        </div>

        <p>We look forward to serving you!</p>

        <p>If you need to reschedule or have any questions, please don't hesitate to contact us.</p>

        <p>
            Best regards,<br>
            The {{ $app_name }} Team
        </p>

        <div class="footer">
            <p>This is an automated message. Please do not reply to this email.</p>
            <p>&copy; {{ date('Y') }} {{ $app_name }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
