<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }}</title>
    <link rel="icon" type="image/svg+xml" sizes="any" href="{{ $faviconDataUri }}">
    <style>
        :root {
            color-scheme: dark;
            --background: #000;
            --foreground: #fff;
            --track: #202020;
            --logo-w: 64px;
            --logo-h: 32px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: var(--background);
            color: var(--foreground);
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        main {
            width: min(100%, 158px);
        }

        main:has(.reason) {
            width: min(100%, 32rem);
        }

        .logo {
            position: relative;
            width: var(--logo-w);
            height: var(--logo-h);
            margin: 0 auto;
            isolation: isolate;
            contain: layout paint style;
        }

        .logo-track {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            display: block;
        }

        .logo-clip {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            -webkit-mask-image: url("{{ $maskDataUri }}");
            mask-image: url("{{ $maskDataUri }}");
            -webkit-mask-size: 100% 100%;
            mask-size: 100% 100%;
            -webkit-mask-repeat: no-repeat;
            mask-repeat: no-repeat;
            -webkit-mask-position: center;
            mask-position: center;
            pointer-events: none;
        }

        .logo-rotor {
            position: absolute;
            left: 50%;
            top: 50%;
            width: 96px;
            height: 96px;
            margin: 0;
            transform: translate3d(-50%, -50%, 0) rotate(0deg);
            transform-origin: 50% 50%;
            will-change: transform;
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
            isolation: isolate;
            contain: layout style;
            background: conic-gradient(
                from 0deg at 50% 50%,
                rgba(255, 255, 255, 0) 0%,
                rgba(255, 255, 255, 0) {{ $trailStartPct }}%,
                #fff 100%
            );
            animation: orbit-spin 0.325s linear infinite;
        }

        @keyframes orbit-spin {
@foreach ($keyframes as $frame)
            {{ $frame['pct'] }}% {
                transform: translate3d(-50%, -50%, 0) rotate({{ $frame['deg'] }}deg);
            }
@endforeach
        }

        .reason {
            margin: 28px 0 0;
            color: #a1a1aa;
            font-size: 13px;
            line-height: 1.5;
            text-align: center;
        }

        .retry {
            display: block;
            margin-top: 28px;
            color: #a1a1aa;
            font-size: 13px;
            text-align: center;
            text-underline-offset: 3px;
        }
    </style>
    @unless ($failed)
        <script nonce="{{ $scriptNonce }}">
            (function () {
                const uri = @json($refreshUri);
                const headerName = @json($activationStateHeader);
                const pendingState = @json($pendingState);
                const intervalMs = {{ (int) $pollIntervalMs }};

                function schedule() {
                    setTimeout(probe, intervalMs);
                }

                function navigateOnce() {
                    window.location.replace(uri);
                }

                function probe() {
                    fetch(uri, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        redirect: 'manual',
                        headers: {
                            Accept: 'text/html',
                        },
                    })
                        .then(function (response) {
                            const state = response.headers.get(headerName);

                            if (response.type !== 'opaqueredirect' && state === pendingState) {
                                schedule();

                                return;
                            }

                            navigateOnce();
                        })
                        .catch(function () {
                            schedule();
                        });
                }

                schedule();
            })();
        </script>
    @endunless
</head>
<body>
    <main>
        <div class="logo" role="img" aria-label="Orbit">
            <svg class="logo-track" viewBox="0 25 100 50" fill="none" aria-hidden="true">
                <path d="{{ $markPath }}" fill="#202020" />
            </svg>
            <div class="logo-clip">
                <div class="logo-rotor"></div>
            </div>
        </div>

        @if ($failed)
            @if ($failureReason !== null)
                <p class="reason">{{ $failureReason }}</p>
            @endif
            <a class="retry" href="{{ $retryUri }}">Try again</a>
        @endif
    </main>
</body>
</html>
