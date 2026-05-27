<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="3; url=ella-pos/">
    <title>Redirecting...</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #12080a;
            --panel: #1f1114;
            --accent: #e5212e;
            --accent-deep: #a90f1a;
            --accent-soft: rgba(229, 33, 46, 0.22);
            --text: #f7f8fb;
            --muted: #c8adb2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            overflow: hidden;
            background:
                radial-gradient(circle at 20% 20%, rgba(229, 33, 46, 0.22), transparent 28rem),
                radial-gradient(circle at 80% 75%, rgba(255, 116, 89, 0.16), transparent 24rem),
                var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .redirect {
            width: min(90vw, 420px);
            padding: 40px 32px;
            text-align: center;
            background: color-mix(in srgb, var(--panel) 88%, transparent);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35);
            animation: enter 600ms ease-out both;
        }

        .logo {
            position: relative;
            width: 92px;
            height: 92px;
            margin: 0 auto 24px;
        }

        .ring,
        .pulse {
            position: absolute;
            inset: 0;
            border-radius: 50%;
        }

        .ring {
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top-color: var(--accent);
            animation: spin 1s linear infinite;
        }

        .pulse {
            background: var(--accent-soft);
            animation: pulse 1.6s ease-out infinite;
        }

        .mark {
            position: absolute;
            inset: 16px;
            overflow: hidden;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 0 32px rgba(229, 33, 46, 0.45);
        }

        .mark img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 8px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: clamp(1.5rem, 5vw, 2rem);
            line-height: 1.15;
            letter-spacing: 0;
        }

        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        a {
            color: var(--accent);
            font-weight: 700;
            text-decoration: none;
        }

        .bar {
            height: 4px;
            margin-top: 28px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
        }

        .bar span {
            display: block;
            width: 100%;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--accent-deep), var(--accent), #ff7459);
            transform-origin: left;
            animation: load 3s ease-in-out forwards;
        }

        @keyframes enter {
            from {
                opacity: 0;
                transform: translateY(18px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes pulse {
            from {
                opacity: 0.65;
                transform: scale(0.72);
            }
            to {
                opacity: 0;
                transform: scale(1.45);
            }
        }

        @keyframes load {
            from {
                transform: scaleX(0);
            }
            to {
                transform: scaleX(1);
            }
        }
    </style>
</head>
<body>
    <main class="redirect" aria-live="polite">
        <div class="logo" aria-hidden="true">
            <span class="pulse"></span>
            <span class="ring"></span>
            <span class="mark"><img src="logo.png" alt=""></span>
        </div>
        <h1>Opening Ella POS</h1>
        <p>Redirecting you to <a href="ella-pos/">ella-pos</a>.</p>
        <div class="bar" aria-hidden="true"><span></span></div>
    </main>

    <script>
        window.setTimeout(function () {
            window.location.href = 'ella-pos/';
        }, 3000);
    </script>
</body>
</html>
