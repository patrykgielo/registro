<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Konto tymczasowo zawieszone</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 60%, #0f3460 100%);
            color: #ffffff;
            padding: 1.5rem;
        }

        .card {
            width: 100%;
            max-width: 560px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 1.25rem;
            padding: 2.5rem 2rem;
            text-align: center;
        }

        .icon-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .icon-wrap svg {
            width: 72px;
            height: 72px;
            color: rgba(255, 255, 255, 0.7);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: rgba(234, 179, 8, 0.2);
            border: 1px solid rgba(234, 179, 8, 0.4);
            color: #fde68a;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 0.375rem 1rem;
            border-radius: 9999px;
            margin-bottom: 1.25rem;
        }

        h1 {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 700;
            line-height: 1.25;
            margin-bottom: 1rem;
            color: #ffffff;
        }

        .org-name {
            color: rgba(255, 255, 255, 0.65);
            font-style: italic;
        }

        p {
            font-size: 0.9375rem;
            line-height: 1.65;
            color: rgba(255, 255, 255, 0.75);
            margin-bottom: 0;
        }

        .divider {
            border: none;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            margin: 1.75rem 0;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 0.875rem;
            transition: color 0.15s ease;
        }

        .back-link:hover,
        .back-link:focus-visible {
            color: #ffffff;
        }

        .back-link:focus-visible {
            outline: 2px solid rgba(255,255,255,0.6);
            outline-offset: 3px;
            border-radius: 4px;
        }

        .footer {
            margin-top: 2.5rem;
            font-size: 0.8125rem;
            color: rgba(255, 255, 255, 0.35);
        }

        @media (prefers-reduced-motion: reduce) {
            * { transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body>

    <main class="card" role="main" aria-labelledby="page-heading">

        <div class="icon-wrap" aria-hidden="true">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.25"
                    d="M3 9.75L12 3l9 6.75V20a1 1 0 01-1 1H4a1 1 0 01-1-1V9.75z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.25"
                    d="M9 21V12h6v9"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M12 9v4m0 4h.01"/>
            </svg>
        </div>

        <div class="badge" role="status">
            <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd"
                    d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                    clip-rule="evenodd"/>
            </svg>
            Tymczasowo niedostępne
        </div>

        <h1 id="page-heading">
            Konto tymczasowo zawieszone
        </h1>

        @if(!empty($organizationName))
            <p class="org-name" aria-label="Nazwa firmy: {{ $organizationName }}">
                {{ $organizationName }}
            </p>
            <br>
        @endif

        <p>
            Działalność tej firmy jest tymczasowo niedostępna.
            Prosimy spróbować ponownie później lub skontaktować się bezpośrednio z usługodawcą.
        </p>

        <hr class="divider">

        <a href="{{ config('app.url') }}" class="back-link" aria-label="Przejdź do strony głównej platformy">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Przejdź do strony głównej
        </a>

    </main>

    <footer class="footer" role="contentinfo">
        &copy; {{ date('Y') }} {{ config('app.name') }}. Wszelkie prawa zastrzeżone.
    </footer>

</body>
</html>
