<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Witamy w ' . config('app.name') }}</title>
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
        <h1>Witaj {{ $user_name }}!</h1>

        <p>Dziękujemy za rejestrację w <strong>{{ $app_name }}</strong>.</p>

        <p>Twój adres email: <strong>{{ $user_email }}</strong></p>

        <p>Cieszymy się, że do nas dołączyłeś! Możesz już korzystać ze wszystkich funkcji naszej platformy.</p>

        <p>Jeśli masz jakiekolwiek pytania, nie wahaj się z nami skontaktować.</p>

        <p>
            Pozdrawiamy,<br>
            Zespół {{ $app_name }}
        </p>

        <div class="footer">
            <p>Ta wiadomość została wysłana automatycznie. Prosimy na nią nie odpowiadać.</p>
            <p>&copy; {{ date('Y') }} {{ $app_name }}. Wszelkie prawa zastrzeżone.</p>
        </div>
    </div>
</body>
</html>
