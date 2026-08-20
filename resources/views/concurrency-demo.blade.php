<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Queue concurrency demo</title>
    <style>
        :root {
            --bg: #f8f8f7;
            --panel: #ffffff;
            --border: #e4e4e0;
            --text: #26251f;
            --muted: #77766e;
            --accent: #d64541;
            --accent-soft: #fbeeed;
            --mono: ui-monospace, SFMono-Regular, Menlo, monospace;
        }

        * { box-sizing: border-box; margin: 0; }

        body {
            font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            height: 100vh;
            display: flex;
            overflow: hidden;
        }

        aside {
            width: 320px;
            flex-shrink: 0;
            border-right: 1px solid var(--border);
            background: var(--panel);
            padding: 1.5rem 1rem;
            display: flex;
            flex-direction: column;
            gap: .35rem;
            overflow-y: auto;
        }

        aside h1 {
            font-size: 1rem;
            padding: 0 .75rem .75rem;
        }

        aside h1 span { color: var(--muted); font-weight: 400; }

        .item {
            display: block;
            width: 100%;
            text-align: left;
            border: 1px solid transparent;
            border-radius: .5rem;
            background: none;
            padding: .6rem .75rem;
            font: inherit;
            cursor: pointer;
        }

        .item:hover { background: var(--bg); }

        .item.active {
            background: var(--accent-soft);
            border-color: color-mix(in srgb, var(--accent) 25%, transparent);
        }

        .item strong {
            display: block;
            font-size: .875rem;
            font-family: var(--mono);
        }

        .item small {
            color: var(--muted);
            font-size: .78rem;
            line-height: 1.4;
            display: block;
            margin-top: .15rem;
        }

        .footnote {
            margin-top: auto;
            padding: .75rem;
            font-size: .8rem;
            color: var(--muted);
            border-top: 1px solid var(--border);
        }

        .footnote a { color: var(--accent); }

        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .statusbar {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            font-size: .85rem;
            color: var(--muted);
            min-height: 3.4rem;
        }

        .statusbar code { font-family: var(--mono); color: var(--text); }

        .badge {
            font-family: var(--mono);
            font-size: .75rem;
            padding: .15rem .5rem;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--panel);
        }

        .badge.ok { color: #1a7f37; border-color: #b6dfc2; background: #f0faf3; }
        .badge.err { color: var(--accent); border-color: #f2c4c2; background: var(--accent-soft); }

        .result {
            flex: 1;
            overflow: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .result h2 {
            font-size: .72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--muted);
            margin-bottom: .5rem;
        }

        .result pre {
            font-family: var(--mono);
            font-size: .85rem;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }

        #source {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: .5rem;
            padding: 1rem 1.25rem;
            overflow-x: auto;
            font-family: var(--mono);
            font-size: .85rem;
            line-height: 1.6;
        }

        #source pre {
            margin: 0;
            font: inherit;
        }

        .placeholder { color: var(--muted); font-size: .9rem; }

        .spinner {
            display: inline-block;
            width: .8rem;
            height: .8rem;
            border: 2px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin .7s linear infinite;
            vertical-align: -0.1em;
            margin-right: .35rem;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <aside>
        <h1>Concurrency <span>queue driver demo</span></h1>

        <button class="item" data-url="/demo">
            <strong>/demo</strong>
            <small>Fan out to queue workers. Wall time stays close to the slowest task, not the sum.</small>
        </button>

        <button class="item" data-url="/demo-sync">
            <strong>/demo-sync</strong>
            <small>Same tasks with runtime targeting <code>onConnection('sync')</code>. Inline, no workers, wall time is the sum.</small>
        </button>

        <button class="item" data-url="/demo-exception">
            <strong>/demo-exception</strong>
            <small>A task throws on the worker. The exception is rebuilt in the caller and the job is recorded as failed.</small>
        </button>

        <button class="item" data-url="/demo-exception-sync">
            <strong>/demo-exception-sync</strong>
            <small>The same failing task inline on the sync connection.</small>
        </button>

        <button class="item" data-url="/demo-timeout">
            <strong>/demo-timeout</strong>
            <small>Tasks go to a queue nobody consumes: after 3s the caller gets a <code>TaskTimedOutException</code> with a diagnostic message, and a cancellation flag stops the jobs from ever running.</small>
        </button>

        <button class="item" data-url="/demo-defer">
            <strong>/demo-defer</strong>
            <small>Fire and forget: <code>defer()</code> dispatches the tasks after the response is sent. Click it, then check the markers below.</small>
        </button>

        <button class="item" data-url="/demo-defer-status">
            <strong>/demo-defer-status</strong>
            <small>Shows the cache markers the deferred tasks wrote on the workers.</small>
        </button>

        <button class="item" data-url="/demo-benchmark">
            <strong>/demo-benchmark</strong>
            <small>The same three 2s tasks on the <code>sync</code>, <code>process</code>, and <code>queue</code> drivers, wall times side by side. Takes around 10 seconds.</small>
        </button>

        <div class="footnote">
            Queue jobs are tagged <code>concurrency</code>.
        </div>
    </aside>

    <main>
        <div class="statusbar" id="statusbar">
            <span class="placeholder">Pick a demo on the left.</span>
        </div>
        <div class="result">
            <section>
                <h2>Code</h2>
                <div id="source" class="placeholder">The route code that runs for the selected demo will show here.</div>
            </section>
            <section>
                <h2>Response</h2>
                <pre id="output" class="placeholder">The endpoint's JSON response will show here.</pre>
            </section>
        </div>
    </main>

    <script>
        const statusbar = document.getElementById('statusbar');
        const output = document.getElementById('output');
        const source = document.getElementById('source');
        const items = Array.from(document.querySelectorAll('.item'));

        async function run(item, push = true) {
            items.forEach((i) => i.classList.remove('active'));
            item.classList.add('active');

            const url = item.dataset.url;

            if (push && window.location.pathname !== url) {
                history.pushState({}, '', url);
            }

            source.textContent = 'Loading...';
            source.classList.add('placeholder');

            fetch('/demo-source?path=' + encodeURIComponent(url), { headers: { Accept: 'application/json' } })
                .then((response) => response.json())
                .then((data) => {
                    if (data.html) {
                        source.innerHTML = data.html;
                    } else {
                        source.textContent = data.source;
                    }

                    source.classList.remove('placeholder');
                })
                .catch(() => {
                    source.textContent = 'Source unavailable.';
                });

            statusbar.innerHTML = `<span class="spinner"></span> <code>GET ${url}</code> running... tasks can take a few seconds`;
            output.innerHTML = '<span class="spinner"></span> Waiting for the response...';
            output.classList.add('placeholder');

            const start = performance.now();

            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                const elapsed = ((performance.now() - start) / 1000).toFixed(2);
                const badge = response.ok ? 'ok' : 'err';

                statusbar.innerHTML = `<code>GET ${url}</code>`
                    + ` <span class="badge ${badge}">HTTP ${response.status}</span>`
                    + ` <span class="badge">${elapsed}s round trip</span>`;

                const text = await response.text();

                try {
                    output.textContent = JSON.stringify(JSON.parse(text), null, 2);
                } catch {
                    output.textContent = text;
                }

                output.classList.remove('placeholder');
            } catch (error) {
                statusbar.innerHTML = `<code>GET ${url}</code> <span class="badge err">failed</span>`;
                output.textContent = String(error);
            }
        }

        items.forEach((item) => item.addEventListener('click', () => run(item)));

        function syncWithLocation() {
            const item = items.find((i) => i.dataset.url === window.location.pathname);

            if (item) {
                run(item, false);
            }
        }

        window.addEventListener('popstate', syncWithLocation);

        syncWithLocation();
    </script>
</body>
</html>
