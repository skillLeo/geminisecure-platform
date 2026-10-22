<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{--
        THE WEBSITE SAYS NOTHING ABOUT THE PRODUCT (M24, D-223). These pages carry the client's company name and logo,
        so they read as part of his site, and nothing else: no product name, no description, no navigation. None is
        meant to be found by a search engine, so none may be indexed.
    --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title === 'Gemini Security Limited' ? $title : $title.' · Gemini Security Limited' }}</title>
    <link rel="icon" href="/favicon.ico">
    {{-- Self-contained on purpose: no build step and no script, so they load on a slow phone. --}}
    <style>
        :root { --ink:#111827; --body:#4B5563; --quiet:#6B7280; --line:#E5E7EB; --white:#FFFFFF; --blue:#2563EB; --purple:#9333EA; --soft:#EFF6FF; --red:#B91C1C; --amber-bg:#FFF7E6; --amber-line:#F5B544; --amber-ink:#7A4B00; }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; color: var(--ink); background: linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 50%, #FAF5FF 100%); font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Arial, sans-serif; }
        a { color: var(--blue); }
        header { background: rgba(255,255,255,.85); border-bottom: 1px solid var(--line); }
        header .in, main, footer .in { width: 100%; max-width: 720px; margin: 0 auto; padding: 0 20px; }
        header .in { display: flex; align-items: center; min-height: 76px; }
        .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand img { width: 44px; height: 44px; border-radius: 50%; object-fit: contain; background: var(--white); }
        .brand span { font-weight: 700; font-size: 19px; background: linear-gradient(90deg, var(--blue), var(--purple)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        main { flex: 1; padding-top: 40px; padding-bottom: 56px; }
        h1 { font-size: 30px; line-height: 1.25; margin: 0 0 24px; }
        h2 { font-size: 19px; line-height: 1.35; margin: 0 0 16px; }
        h3 { font-size: 17px; margin: 24px 0 6px; }
        p, li { color: var(--body); }
        strong { color: var(--ink); }
        .lead { font-size: 17px; margin: -8px 0 24px; }
        .card { background: var(--white); border: 1px solid var(--line); border-radius: 18px; padding: 24px; margin: 0 0 18px; box-shadow: 0 1px 2px rgba(17,24,39,.04), 0 8px 24px rgba(37,99,235,.06); }
        .door { max-width: 480px; }
        .draft { background: var(--amber-bg); border: 1px solid var(--amber-line); color: var(--amber-ink); border-radius: 12px; padding: 12px 16px; margin: 0 0 24px; font-size: 14.5px; }
        .draft strong { color: var(--amber-ink); }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; font-size: 15px; }
        th, td { text-align: left; vertical-align: top; padding: 10px 12px; border-bottom: 1px solid var(--line); }
        th { background: var(--soft); }
        td { color: var(--body); }
        label { display: block; font-weight: 600; font-size: 14px; color: var(--ink); margin: 0 0 8px; }
        input[type=text], input[type=email], input[type=password], select, textarea { width: 100%; min-height: 50px; border: 1px solid var(--line); border-radius: 12px; padding: 10px 14px; font: inherit; color: var(--ink); background: var(--white); }
        input.code { font-size: 22px; letter-spacing: 6px; }
        textarea { min-height: 120px; resize: vertical; }
        input:focus, select:focus, textarea:focus { outline: 3px solid rgba(37,99,235,.25); border-color: var(--blue); }
        .field { margin: 0 0 18px; }
        .hint { font-size: 14px; color: var(--quiet); margin: 6px 0 0; }
        .error { color: var(--red); font-size: 14px; margin: 6px 0 0; }
        .notice { background: var(--soft); border-radius: 12px; padding: 12px 16px; margin: 0 0 18px; color: var(--ink); font-size: 15px; }
        button, a.button { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 52px; margin: 8px 0 0; padding: 0 24px; border: 0; border-radius: 14px; background: linear-gradient(90deg, var(--blue), var(--purple)); color: var(--white); font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        button:hover, a.button:hover { filter: brightness(1.06); }
        button:focus-visible, a.button:focus-visible, a:focus-visible { outline: 3px solid rgba(147,51,234,.45); outline-offset: 2px; }
        button.danger { background: var(--red); }
        button.quiet { width: auto; min-height: 44px; margin: 0; padding: 0; background: none; color: var(--blue); font-weight: 600; text-decoration: underline; }
        .row-links { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; margin-top: 18px; }
        .row-links form { margin: 0; }
        .row-links a { font-weight: 600; }
        .choices { list-style: none; padding: 0; margin: 0; }
        .choices li { margin: 0 0 12px; }
        button.choice { margin: 0; justify-content: flex-start; background: var(--white); color: var(--ink); border: 1px solid var(--line); }
        button.choice:hover { border-color: var(--blue); filter: none; }
        .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .ref { font: 700 24px/1.2 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: 1.5px; color: var(--ink); }
        footer { border-top: 1px solid var(--line); background: rgba(255,255,255,.7); }
        footer .in { padding-top: 18px; padding-bottom: 22px; font-size: 14px; color: var(--quiet); }
        footer p { margin: 0; color: var(--quiet); }
        @media (max-width: 420px) { h1 { font-size: 26px; } .card { padding: 20px; } .brand span { font-size: 17px; } }
    </style>
</head>
<body>
<header>
    <div class="in">
        <a class="brand" href="/"><img src="/brand/logo.png" alt="Gemini Security Limited"><span>Gemini Security Limited</span></a>
    </div>
</header>
<main>
    @yield('content')
</main>
<footer>
    <div class="in">
        <p>&copy; {{ date('Y') }} Gemini Security Limited. All rights reserved.</p>
    </div>
</footer>
</body>
</html>
