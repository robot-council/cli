export const meta = {
  name: 'security-audit',
  description: 'Fan out security finders across domains for the robot-council Laravel package, adversarially verify each finding against the package trust model, then dedup and severity-rank the confirmed set.',
  phases: [
    { title: 'Find', detail: 'one agent per security domain sweeps the scope' },
    { title: 'Verify', detail: 'two adversarial lenses (sink + reachability) refute each finding' },
    { title: 'Triage', detail: 'dedup, re-rank, completeness critic' },
  ],
}

// ---------------------------------------------------------------------------
// Scope (passed by the /security-audit skill via Workflow args)
//   args.mode    : 'full' | 'paths' | 'diff'
//   args.files   : string[] of first-party paths to focus on (paths/diff modes)
//   args.baseRef : git ref the diff was taken against (diff mode, for the prose)
// ---------------------------------------------------------------------------
const mode = (args && args.mode) || 'full'
const files = (args && args.files) || []
const baseRef = (args && args.baseRef) || 'main'

const FIRST_PARTY = [
  'src/',
  'config/',
  'database/',
  'resources/views/',
  'routes/',
]

const scopeDescription =
  mode === 'diff'
    ? `Files changed on this branch vs \`${baseRef}\` (first-party only). Audit these and follow their data flow into related first-party code:\n${files.map((f) => '- ' + f).join('\n')}`
    : mode === 'paths'
      ? `Restrict the audit to these paths (and code they call into):\n${files.map((f) => '- ' + f).join('\n')}`
      : `Full first-party sweep. In scope:\n${FIRST_PARTY.map((f) => '- ' + f).join('\n')}\nExplicitly OUT of scope: \`vendor/\` (third-party dependencies), \`workbench/\`, \`build/\`, \`.claude/\`.`

// ---------------------------------------------------------------------------
// Shared context — the quality lever. Every finder and verifier reasons from
// this trust model so we surface real issues and reject framework false-positives.
// ---------------------------------------------------------------------------
const SHARED_CONTEXT = `PACKAGE UNDER TEST: \`robot-council/core\`, a Laravel package (namespace \`RobotCouncil\\\`, PHP ^8.4, Laravel 13 via \`illuminate/contracts\`) built on \`spatie/laravel-package-tools\`. It has no application of its own: it is installed into consuming Laravel applications, and \`src/RobotCouncilServiceProvider.php\` (\`configurePackage()\`) declares everything it registers — config file, views, view components, migrations, commands, routes, translations, assets. The package is young, so several domains may have no surface at all; confirm a surface exists before hunting for flaws in it.

TRUST BOUNDARIES — decide WHO controls each input before you rate severity:
- END USER OF A CONSUMING APPLICATION (anonymous, or authenticated in that app) — reaches package code ONLY through what the package registers: routes and their controllers, middleware, Blade views and components rendered with request or stored data, jobs and listeners fed by user actions, and commands that process user-stored data. Controls request input, headers, uploaded files, and anything they stored that the package later reads back. Highest concern; anonymous outranks authenticated. Judge reachability under the package's SHIPPED DEFAULTS — the package cannot assume the consuming app puts auth or any other middleware in front of what it registers.
- CONSUMING-APPLICATION DEVELOPER — TRUSTED. Controls config values, published views and migrations (once published, the copy is theirs), container bindings, any middleware the package lets them configure, and the arguments they pass to the package's PHP API and component props. Not an attacker. The package still owns its shipped defaults: a default in a shipped \`config/\` file that exposes a route without auth or disables a check is a package finding, because consumers run defaults.
- OPERATOR running the package's Artisan commands (console, scheduler, CI) — TRUSTED; arguments and options are operator-controlled. Lower DIRECT concern, but still audit what a command does with data the operator did not supply (rows end users wrote, remote responses, files) for shell/SQL injection, SSRF, path traversal, or unsafe deserialization pivoting through that data; state the trust assumption explicitly rather than ignoring it.

FRAMEWORK FALSE-POSITIVE TRAPS — do NOT report these unless you prove the protection is bypassed:
- Blade \`{{ }}\` ESCAPES: it compiles to \`e()\`, which is \`htmlspecialchars\` with ENT_QUOTES. A plain \`{{ $value }}\` in HTML body text or a QUOTED attribute is not an XSS sink. \`{!! !!}\` does NOT escape and is a real sink for attacker data. \`{{ }}\` still does not protect: an \`Htmlable\` value (\`HtmlString\` and friends — \`e()\` prints \`toHtml()\` verbatim), a \`javascript:\` URL in \`href\`/\`src\`/\`action\`, an unquoted attribute, or a \`<script>\`/inline-handler JS context (safe form: \`@js\` / \`Js::from\`).
- Eloquent and the query builder bind VALUES. SQL injection needs a raw method (\`DB::raw\`, \`whereRaw\`, \`orderByRaw\`, \`selectRaw\`, \`havingRaw\`), \`DB::statement\`/\`DB::select\`/\`DB::unprepared\` with interpolation, or string concatenation into a query — OR input used as an IDENTIFIER (a column or table name, e.g. \`orderBy($request->input('sort'))\`), which PDO cannot bind.
- Config values are developer-controlled. \`config('robot-council.*')\` flowing into a sink is not an end-user injection path; only the shipped default is the package's responsibility.
- A package route is reachable only if the service provider registers its route file (\`hasRoute\`/\`hasRoutes\`/\`loadRoutesFrom\`); a route file nothing registers is not reachable. A registered one carries ONLY the middleware the route file itself (or a config default it reads) applies: \`loadRoutesFrom\` just requires the file, so there is no \`web\` group, session, CSRF, or auth unless the package declares it. Do not credit the consuming app's middleware the package cannot assume.
- Package PHP API arguments and Blade component props are set by the consuming developer — UNLESS package code binds them to request input itself, or the API exists to process user-supplied data. Classify by who really controls the value.
- Artisan commands are not reachable over HTTP unless code calls \`Artisan::call\` with request data.
- \`Process::run\`/\`Process::start\` with an ARRAY command is not shell-interpreted; a STRING command goes through \`Process::fromShellCommandline\` and is.

METHOD: start by reading the service provider to enumerate what the package actually registers; a class nothing registers or calls is not reachable by an end user. Read whole functions and trace data flow source → sink; don't judge from a single line. Use Grep/Glob to sweep the scope for every instance of the class, following the sweep instructions for your domain. Work entirely through the Read / Grep / Glob tools (they behave identically on Windows and macOS, and forward-slash paths work on both) — do NOT depend on OS-specific shell commands (\`grep\`, \`find\`, \`Select-String\`), since the host OS varies. READ-ONLY — do not modify files, run tests or the package's commands, or make network requests. An empty findings array is the correct answer for a domain with no surface.`

// ---------------------------------------------------------------------------
// Security domains. Each finder follows sweep instructions (patterns and file
// classes), since the package is too young for named anchors.
// ---------------------------------------------------------------------------
const DOMAINS = [
  {
    key: 'xss',
    title: 'Cross-site scripting & output encoding',
    cwe: 'CWE-79 / CWE-116',
    guidance:
      'Find request/stored data that reaches an UNescaped HTML, attribute, or JS sink. Map every raw-output sink first, then trace what flows into it.',
    hotspots: [
      'resources/views/**/*.blade.php — every `{!! ... !!}`, `@php echo`, `<?php echo`, and `<?=`; trace what flows into each',
      'grep src/ for `HtmlString`, `Htmlable`, `toHtml(`, `Str::markdown(`, `Str::inlineMarkdown(` — HTML built in PHP bypasses `{{ }}` escaping; `Str::markdown` passes raw HTML and unsafe links through unless `html_input`/`allow_unsafe_links` are set',
      'Blade components — classes extending `Illuminate\\View\\Component` (e.g. src/View/Components/) and anonymous components under resources/views/components/: props and `$attributes` printed into URL attributes, `<script>`, or inline event handlers',
      'grep views for `href="{{`, `src="{{`, `action="{{`, `<script`, and `on*=` handlers — `{{ }}` does not stop a `javascript:` URL or a JS-context injection; look for `@js`/`Js::from` where data enters JS',
      'grep src/ for `response(`, `Response::make(`, `->setContent(` fed by HTML strings concatenated with input',
    ],
  },
  {
    key: 'ssti',
    title: 'Server-side template injection & dynamic rendering',
    cwe: 'CWE-1336 / CWE-94',
    guidance:
      'Find places where user-controlled strings are compiled as Blade or evaluated as PHP, or where input selects which template renders.',
    hotspots: [
      'grep src/ for `Blade::render(`, `Blade::compileString(`, `->compileString(` — Blade compiles to PHP, so a user- or database-supplied template string is code execution, not just XSS',
      'grep for `view(`, `View::make(`, `@include(`, `@each(`, `@component(` whose view NAME is a variable — a name drawn from input selects arbitrary views',
      'grep for `eval(`, `assert(`, and `include`/`require` with a variable path',
      'mailables and notifications in src/ that render markdown or views from user-supplied content',
    ],
  },
  {
    key: 'path-traversal',
    title: 'Path traversal & arbitrary file read/write',
    cwe: 'CWE-22 / CWE-23',
    guidance:
      'Find filesystem paths built from input without canonicalization or an allowlist, plus archive extraction (zip-slip).',
    hotspots: [
      'grep src/ for `Storage::`, `File::`, `file_get_contents(`, `file_put_contents(`, `fopen(`, `unlink(`, `copy(`, `rename(`, `glob(` with a variable path; check for `basename`, a `realpath` prefix check, or an allowlist',
      'grep for `response()->download(`, `response()->file(`, `streamDownload(`, `Storage::download(` with a path derived from input',
      'grep for `getClientOriginalName(`, `getClientOriginalExtension(`, `storeAs(`, `->move(` — client-supplied filenames used in a destination path',
      'grep for `ZipArchive`, `extractTo(`, `PharData`, `phar://` — archive extraction (zip-slip) and phar stream wrappers',
      'src/*ServiceProvider.php and src/Commands/ — publish, load, and write paths; normally constant, a finding only if built from runtime data',
    ],
  },
  {
    key: 'ssrf',
    title: 'SSRF & unsafe outbound fetch',
    cwe: 'CWE-918',
    guidance:
      'Find outbound HTTP/file fetches whose URL is influenced by end-user input or stored data, with no scheme/host allowlist and no block on internal addresses.',
    hotspots: [
      'grep src/ for `Http::`, `GuzzleHttp`, `new Client(`, `curl_`, `get_headers(`, `SoapClient`, and `file_get_contents(`/`fopen(` on URLs, with a non-constant URL',
      'separate developer-controlled URLs (config values, constants — trusted) from URLs taken from request input or stored end-user data (webhook targets, callback, image, or feed URLs)',
      'for user-influenced URLs, check scheme/host allowlisting and blocking of loopback, link-local (169.254.169.254), and private ranges, IPv6 included; the Laravel HTTP client follows redirects by default (Guzzle `allow_redirects`), so a pre-request host check without `withoutRedirecting()` or per-hop revalidation is bypassable',
      'jobs, listeners, and src/Commands/ that fetch remote resources named by stored data',
    ],
  },
  {
    key: 'xxe-dos',
    title: 'XML external entities & parser / decompression DoS',
    cwe: 'CWE-611 / CWE-776 / CWE-409',
    guidance:
      'Audit every XML/archive/document parser for external-entity loading and for unbounded expansion (billion-laughs, zip/decompression bombs), plus request-sized work with no cap.',
    hotspots: [
      'grep src/ for `simplexml_load_string(`, `simplexml_load_file(`, `DOMDocument`, `loadXML(`, `XMLReader`, `LIBXML_NOENT`, `LIBXML_DTDLOAD`, `LIBXML_PARSEHUGE` — entity substitution needs a flag such as LIBXML_NOENT; PARSEHUGE lifts the expansion limits',
      'grep for `ZipArchive`, `gzdecode(`, `gzinflate(`, `gzuncompress(` applied to user-supplied data — decompression bombs',
      'work sized by request input with no cap: `per_page`/`limit`/`take(` from the request, `range(` or loops bounded by input, `json_decode` of unbounded bodies',
      'upload rules (`max:` size) on every route that accepts files',
    ],
  },
  {
    key: 'authz',
    title: 'Authentication, authorization & access control',
    cwe: 'CWE-285 / CWE-639 / CWE-862',
    guidance:
      'Find missing/incorrect permission checks, IDOR, privilege boundaries, and weak auth flows. Map every registered route to the middleware, gate, or policy the PACKAGE applies to it.',
    hotspots: [
      'src/*ServiceProvider.php — which route files are registered (`hasRoute`/`hasRoutes`/`loadRoutesFrom`), and every `Gate::define`/`Gate::policy`/`Gate::before` (a `before` callback returning true grants everything)',
      'routes/*.php — for every route, the middleware the file itself applies; if middleware comes from a config value, what the shipped default is. No `web` group, session, CSRF, or auth is applied automatically',
      'controllers under src/ (e.g. src/Http/Controllers/) — `authorize(`, `Gate::`, `can(`, `can:` middleware before every read and mutation; route model binding that loads a record without scoping it to the current user (IDOR)',
      'grep for `hasValidSignature`, `signed` middleware, `URL::signedRoute`, `hash_equals`, and `==`/`===` comparisons of tokens or secrets (timing-unsafe), `Auth::login(`, `loginUsingId(`',
      'middleware the package ships (e.g. src/Http/Middleware/) — does it fail closed?',
    ],
  },
  {
    key: 'injection',
    title: 'SQL / command / regex / XPath injection',
    cwe: 'CWE-89 / CWE-78 / CWE-1333 / CWE-643',
    guidance:
      'Find input concatenated into SQL, shell commands, regex patterns, or XPath/CSS-selector queries.',
    hotspots: [
      'grep src/ and database/ for `DB::raw(`, `whereRaw(`, `orWhereRaw(`, `orderByRaw(`, `selectRaw(`, `havingRaw(`, `groupByRaw(`, `DB::statement(`, `DB::select(`, `DB::unprepared(` — interpolation or concatenation instead of `?` bindings',
      'grep for `orderBy(`, `groupBy(`, `select(`, `where(`, `pluck(` whose COLUMN argument comes from request input — identifiers are not bound',
      'grep for `Process::`, `fromShellCommandline`, `exec(`, `shell_exec(`, `passthru(`, `system(`, `proc_open(`, `popen(`, and backticks — a string command is shell-interpreted; check `escapeshellarg` on every interpolated value or use an array command',
      'grep for `preg_match(`, `preg_replace(`, `preg_split(` whose PATTERN includes input without `preg_quote` (regex injection / ReDoS), and `DOMXPath` queries built from input',
      'src/Commands/ — arguments, options, or stored data interpolated into shell commands or SQL',
    ],
  },
  {
    key: 'secrets',
    title: 'Secrets exposure & information disclosure',
    cwe: 'CWE-200 / CWE-532 / CWE-209',
    guidance:
      'Find secrets/PII leaking to responses, views, JS, logs, or console output, and over-verbose errors or debug surfaces.',
    hotspots: [
      'grep src/ for `Log::`, `logger(`, `report(`, and command output (`->info(`, `->line(`, `->table(`) — request payloads, tokens, or credentials written to logs or the console',
      'models in src/ — `$hidden` on token/secret/password columns; JSON responses, `toArray()`, or API resources that serialize them',
      'config/*.php (none yet) — shipped defaults must not contain real credentials; secrets belong in `env(` calls inside config; grep for `env(` outside config/ (it returns null once config is cached, which invites hard-coded fallbacks)',
      'grep for output and debugging calls in src/ that the Pest php() and security() arch presets in tests/ArchTest.php do not list (see vendor/pestphp/pest/src/ArchPresets/); the presets do not cover database/migrations stubs or config/',
      'exception messages and validation errors that echo secrets, config values, or internal paths back to end users',
    ],
  },
  {
    key: 'deserialization',
    title: 'Unsafe deserialization & dynamic dispatch',
    cwe: 'CWE-502 / CWE-470',
    guidance:
      'Find unserialize/eval on input and variable method/property/class dispatch driven by input.',
    hotspots: [
      'grep src/ for `unserialize(` (check `allowed_classes`), `eval(`, `call_user_func(`, `call_user_func_array(`, variable-variables `$$`, `new $`, `->{$`, and `__call`/`__callStatic`/`__get` that forward to input-chosen targets',
      'grep for `app(`, `resolve(`, `App::make(`, `->make(` with a class name taken from input — container resolution of arbitrary classes',
      '`Crypt::decrypt(`/`decrypt(` unserialize by default but only after MAC verification with the consuming app key — not a sink on its own',
      'queued jobs, listeners, and cache entries in src/ — payloads or cache keys built from input (keys that collide across users)',
    ],
  },
  {
    key: 'validation-massassign',
    title: 'Input-validation gaps & mass assignment',
    cwe: 'CWE-20 / CWE-915',
    guidance:
      'Find request input that reaches a sink without validation, and writes that accept attacker-chosen keys/fields.',
    hotspots: [
      'grep src/ for `->all(`, `->input(`, `->except(`, `->collect(` on the request flowing into `create(`, `update(`, `fill(`, `forceFill(`, `forceCreate(` without `validate(`, a FormRequest, or `validated()`',
      'models in src/ — `$guarded = []`, `Model::unguard(`, and `$fillable` lists that include role, owner, or foreign-key columns',
      'file inputs — `mimes:`/`mimetypes:`/`max:` rules present; trust placed in `getClientMimeType()` or `getClientOriginalExtension()`',
      'database/migrations/*.php.stub (published into consuming apps) — unique constraints or foreign keys an authorization or validation rule relies on, and plaintext columns for tokens or secrets',
      'database/factories/ — factory defaults that could leak into non-test use (low concern; confirm they are test-only)',
    ],
  },
]

// ---------------------------------------------------------------------------
// Structured-output schemas
// ---------------------------------------------------------------------------
const SEVERITY = ['critical', 'high', 'medium', 'low', 'info']

const FINDINGS_SCHEMA = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          title: { type: 'string', description: 'Imperative, specific. e.g. "Escape the label prop instead of printing it with {!! !!}"' },
          file: { type: 'string', description: 'Repo-relative path of the sink' },
          line: { type: 'integer', description: 'Best-effort line of the sink (0 if unknown)' },
          severity: { type: 'string', enum: SEVERITY },
          cwe: { type: 'string' },
          summary: { type: 'string', description: 'What is wrong and why it matters, 1-2 sentences' },
          dataFlow: { type: 'string', description: 'source → ... → sink trace with file:line steps' },
          exploitScenario: { type: 'string', description: 'Concrete attacker steps for a realistic actor' },
          preconditions: { type: 'string', description: 'What must be true: actor role, config, feature enabled' },
          recommendation: { type: 'string', description: 'Fix direction (no need to write the patch)' },
          confidence: { type: 'string', enum: ['high', 'medium', 'low'] },
        },
        required: ['title', 'file', 'severity', 'summary', 'dataFlow', 'exploitScenario', 'recommendation', 'confidence'],
        additionalProperties: false,
      },
    },
  },
  required: ['findings'],
  additionalProperties: false,
}

const VERDICT_SCHEMA = {
  type: 'object',
  properties: {
    status: { type: 'string', enum: ['confirmed', 'refuted', 'uncertain'] },
    adjustedSeverity: { type: 'string', enum: SEVERITY },
    confidence: { type: 'string', enum: ['high', 'medium', 'low'] },
    sinkAnalysis: { type: 'string', description: 'Does the tainted data reach a dangerous sink un-neutralized? Cite the code you read.' },
    reachability: { type: 'string', description: 'Exactly who can trigger this and under what preconditions.' },
    falsePositiveReason: { type: 'string', description: 'If refuted, the specific protection or trust boundary that defeats it.' },
    notes: { type: 'string' },
  },
  required: ['status', 'adjustedSeverity', 'confidence', 'sinkAnalysis', 'reachability'],
  additionalProperties: false,
}

const REPORT_SCHEMA = {
  type: 'object',
  properties: {
    summary: { type: 'string', description: '2-4 sentence executive summary' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          title: { type: 'string' },
          severity: { type: 'string', enum: SEVERITY },
          status: { type: 'string', enum: ['confirmed', 'uncertain'] },
          domain: { type: 'string' },
          file: { type: 'string' },
          line: { type: 'integer' },
          cwe: { type: 'string' },
          summary: { type: 'string' },
          dataFlow: { type: 'string' },
          exploitScenario: { type: 'string' },
          preconditions: { type: 'string' },
          recommendation: { type: 'string' },
          confidence: { type: 'string', enum: ['high', 'medium', 'low'] },
        },
        required: ['title', 'severity', 'status', 'file', 'summary', 'recommendation'],
        additionalProperties: false,
      },
    },
    gaps: { type: 'array', items: { type: 'string' }, description: 'Surfaces under-covered or needing manual/dynamic testing' },
  },
  required: ['summary', 'findings', 'gaps'],
  additionalProperties: false,
}

// ---------------------------------------------------------------------------
// Prompt builders
// ---------------------------------------------------------------------------
function findPrompt(d) {
  return `You are a senior application security engineer performing a STATIC code audit.

${SHARED_CONTEXT}

SCOPE:
${scopeDescription}

YOUR DOMAIN: ${d.title} (${d.cwe})
${d.guidance}

SWEEP INSTRUCTIONS (patterns and file classes to search, not known issues — many will match nothing or only defended code; also look BEYOND them):
${d.hotspots.map((h) => '- ' + h).join('\n')}

TASK:
1. Run each sweep within the scope, read the full function around every match, and trace data flow from an attacker-influenced source to the sink.
2. Sweep the scope with Grep/Glob for OTHER instances of this vulnerability class — the instructions are a starting point, not the boundary.
3. Report ONLY findings where you can name (a) a concrete source, (b) a concrete dangerous sink, and (c) a realistic actor from the trust model who reaches it. Rate severity by impact AND trust boundary. Set confidence honestly. Do NOT pad with theoretical or already-defended cases — an empty findings array is a perfectly good answer for a clean domain.

Return via the StructuredOutput schema.`
}

const LENSES = [
  {
    key: 'sink',
    instruction:
      'SINK ANALYSIS. Open the cited file(s) and determine whether the tainted input actually reaches a dangerous sink UN-neutralized. Account for Blade `{{ }}` escaping through `e()` (a protection in HTML body and quoted-attribute contexts, but NOT for `Htmlable` values, `javascript:` URLs, unquoted attributes, or JS contexts), `{!! !!}` not escaping at all, Eloquent/query-builder VALUE binding (which does not cover raw methods, concatenation, or identifiers), array vs string `Process` commands, and upstream validation. If the value is genuinely escaped, bound, or sanitized before the sink, set status=refuted and name the protection; if it prints through `{!! !!}` or an `HtmlString`, that is NOT a protection.',
  },
  {
    key: 'reach',
    instruction:
      'REACHABILITY ANALYSIS. Determine exactly who can trigger this and under what preconditions — anonymous end user of a consuming app, authenticated end user, the consuming-app developer (config, published files, bindings, API arguments), or an operator running Artisan commands. Check whether the service provider registers the route, view, component, or command at all; which middleware, gate, or policy the PACKAGE itself applies (package routes get no middleware group automatically, and the consuming app\'s middleware cannot be assumed); and the shipped config defaults. If only a trusted developer or operator can reach it by design, set status=refuted or downgrade adjustedSeverity; if an end user reaches it under the shipped defaults, confirm reachability.',
  },
]

function verifyPrompt(f, d, lens) {
  return `You are an ADVERSARIAL security reviewer. Your job is to REFUTE the finding below — assume it is WRONG until the actual code proves otherwise. Default to skepticism; a plausible-sounding finding that the framework already defends is a false positive and must be refuted.

${SHARED_CONTEXT}

FINDING UNDER REVIEW (domain: ${d.title}):
${JSON.stringify(f, null, 2)}

YOUR LENS — ${lens.key}:
${lens.instruction}

Open the cited file(s) and any callers yourself; do NOT trust the finding's claims. Then return a verdict:
- status=refuted — the data is neutralized before the sink, the sink is not actually dangerous, or no realistic actor reaches it (give the specific reason in falsePositiveReason).
- status=confirmed — the vulnerability is real and exploitable essentially as described.
- status=uncertain — genuinely undecidable by static analysis alone (say what dynamic check would settle it).
Set adjustedSeverity to the REAL impact given the trust boundary (an issue only a consuming-app developer or Artisan operator can trigger is not an anonymous critical RCE). Return via the schema.`
}

function synthPrompt(reviewed) {
  return `You are the security lead compiling the FINAL audit report for \`robot-council/core\`, a Laravel package (PHP 8.4, Laravel 13) installed into consuming applications.

${SHARED_CONTEXT}

ADVERSARIALLY-VERIFIED FINDINGS (confirmed + uncertain only; refuted ones were already dropped):
${JSON.stringify(reviewed, null, 2)}

TASKS:
1. DEDUPLICATE — merge findings with the same root cause or same file:line into one; keep the clearest description and the union of their evidence.
2. RE-RANK severity holistically and consistently (impact x exploitability x trust boundary, CVSS-style judgment). An issue an anonymous end user reaches under the shipped defaults outranks an equivalent one that needs an authenticated end user, which outranks one only a developer or operator can trigger.
3. Ensure each final finding carries: title, severity, status (confirmed|uncertain), domain, file, line, cwe, summary, dataFlow, exploitScenario, preconditions, recommendation, confidence.
4. COMPLETENESS CRITIC — in gaps[], list surfaces/domains that look under-covered or that only a manual or dynamic test can settle.
5. Write a 2-4 sentence executive summary.

Order findings severity-first (critical → info). Return via the schema.`
}

// ---------------------------------------------------------------------------
// Verdict consensus across the two lenses
// ---------------------------------------------------------------------------
function consensus(verdicts) {
  const v = verdicts.filter(Boolean)
  if (!v.length) return { status: 'uncertain', adjustedSeverity: 'low', confidence: 'low' }
  // A confident refutation from either lens kills the finding.
  const hardRefute = v.find((x) => x.status === 'refuted' && x.confidence !== 'low')
  if (hardRefute) return hardRefute
  // Otherwise prefer a confirmation; pick the highest-severity confirming verdict.
  const confirmed = v.filter((x) => x.status === 'confirmed')
  if (confirmed.length) {
    return confirmed.sort((a, b) => SEVERITY.indexOf(a.adjustedSeverity) - SEVERITY.indexOf(b.adjustedSeverity))[0]
  }
  // No confirm, no hard refute → uncertain (surface for manual review, don't drop).
  const softRefute = v.find((x) => x.status === 'refuted')
  return softRefute ? { ...softRefute, status: 'uncertain' } : v[0]
}

function slim(f) {
  return {
    title: f.title,
    domain: f.domain,
    file: f.file,
    line: f.line || 0,
    cwe: f.cwe || '',
    severity: f.verdict ? f.verdict.adjustedSeverity : f.severity,
    status: f.verdict ? f.verdict.status : 'confirmed',
    summary: f.summary,
    dataFlow: f.dataFlow,
    exploitScenario: f.exploitScenario,
    preconditions: f.preconditions || '',
    recommendation: f.recommendation,
    confidence: f.verdict ? f.verdict.confidence : f.confidence,
    sinkAnalysis: f.verdict ? f.verdict.sinkAnalysis : '',
    reachability: f.verdict ? f.verdict.reachability : '',
  }
}

// ---------------------------------------------------------------------------
// Run: pipeline find → verify per domain (no barrier), then a single triage pass.
// ---------------------------------------------------------------------------
log(`Security audit — mode=${mode}, ${DOMAINS.length} domains, 2-lens adversarial verification`)

const perDomain = await pipeline(
  DOMAINS,
  (d) => agent(findPrompt(d), { label: `find:${d.key}`, phase: 'Find', schema: FINDINGS_SCHEMA }),
  (review, d) => {
    const findings = review && review.findings ? review.findings : []
    if (!findings.length) return []
    log(`${d.key}: ${findings.length} candidate(s) → verifying`)
    return parallel(
      findings.map((f, i) => () =>
        parallel(
          LENSES.map((lens) => () =>
            agent(verifyPrompt(f, d, lens), { label: `verify:${d.key}:${i}:${lens.key}`, phase: 'Verify', schema: VERDICT_SCHEMA }),
          ),
        ).then((verdicts) => ({ ...f, domain: d.key, verdict: consensus(verdicts) })),
      ),
    )
  },
)

const all = perDomain.flat().filter(Boolean)
const confirmed = all.filter((f) => f.verdict.status === 'confirmed')
const uncertain = all.filter((f) => f.verdict.status === 'uncertain')
const refutedCount = all.filter((f) => f.verdict.status === 'refuted').length

log(`Verified: ${confirmed.length} confirmed, ${uncertain.length} uncertain, ${refutedCount} refuted`)

const reviewed = [...confirmed, ...uncertain].map(slim)
const counts = { candidates: all.length, confirmed: confirmed.length, uncertain: uncertain.length, refuted: refutedCount }

// Nothing survived verification — return a clean bill without spending a triage agent.
if (!reviewed.length) {
  return {
    summary: 'No findings survived adversarial verification across the audited scope.',
    findings: [],
    gaps: ['Static-only pass; dynamic testing of the registered routes, components, and commands inside a consuming application is recommended as a follow-up.'],
    counts,
    mode,
  }
}

phase('Triage')
const report = await agent(synthPrompt(reviewed), { label: 'triage:synthesize', phase: 'Triage', schema: REPORT_SCHEMA })

// Defensive fallback if the triage agent dies on a terminal error.
if (!report) {
  return {
    summary: 'Triage agent unavailable; returning verified findings without synthesis.',
    findings: reviewed.map((f) => ({ ...f, status: f.status === 'uncertain' ? 'uncertain' : 'confirmed' })),
    gaps: ['Triage/dedup pass did not run — review findings for duplicates manually.'],
    counts,
    mode,
  }
}

return { ...report, counts, mode }
