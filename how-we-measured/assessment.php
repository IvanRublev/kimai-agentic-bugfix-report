<?php

declare(strict_types=1);

/**
 * Version 2.0.0
 *
 * Measures an increment on all three measures of the methodology: Production Fan out, banded against the
 * TIOBE/TÜViT Trusted Product Maintainability model, Production Cognitive complexity and Test unit
 * dependency, both reported as magnitudes and never banded (*The three measures*, *Output*).
 *
 * Beneath the measures it prints the five probes, each under the measure whose reading it settles, and the two
 * question blocks the probes and the figures resolve together (*Grounding the probes*, *Output*). A probe
 * reports no figure per side and carries no band: it exists to part outcomes the measures leave open, and it
 * prints only where they leave one open.
 *
 * Every figure is read over the files and methods the increment touched, once at each side, so it reports what
 * the increment did to the code it landed in. Each measure reports a figure per side; the difference between
 * two printed figures is the reader's subtraction. Every number is about two commits — the increment and its
 * baseline — and about nothing else (*Input*): no pin, no reference population, no cache. Two measures read
 * those commits directly; cognitive complexity reads an analysis of each of them, which is a thing that has to
 * exist beside the repository, not a figure taken over anything wider.
 *
 * Usage:
 *   php assessment.php scan <sha>                         analyse one commit, so its figures can be read
 *   php assessment.php increment <sha> [<baseline-sha>]   measure one increment, baseline against increment
 *   php assessment.php repo [<sha>]                       fan out over every src/ file at one sha
 *   php assessment.php selftest                           the counting rules, the probes and the two questions
 *
 * Either side may be absent: an increment touching no src/ PHP still reports its touched test files, and one
 * touching no test class still reports its production transitions.
 *
 * Fan out and test unit dependency are counted here, from the syntax tree of each blob; cognitive complexity is
 * not. It is read from the SonarQube analysis of each side — sonar-php is the metric's reference
 * implementation — which is why an increment can only be measured once both its commits have been scanned, and
 * why the two probes that read per-method figures read that analysis too (*The three measures*).
 *
 * Needs nikic/php-parser, and a SonarQube server holding an analysis of each side. `SONAR_HOST`, `SONAR_TOKEN`
 * and `SONAR_PROJECT_PREFIX` say where and under what name; `SONAR_ANALYSIS_FILE` reads an analysis from a file
 * instead, which is how a run is reproduced without a server.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

// The app the increment lives in — the sibling checkout this spec repository sits beside. `ASSESSMENT_REPO`
// points it elsewhere, which is how the worked examples of the methodology are reproduced against a fixture
// rather than against Kimai.
$kimai = dirname(__DIR__) . '/kimai';
$app = getenv('ASSESSMENT_REPO') ?: $kimai;
$mode = $argv[1] ?? '';

// `selftest` reads no commit, so it asks for no checkout — it parses its own snippets and feeds the question
// blocks figures of its own.
if ($mode !== 'selftest' && !is_dir($app . '/.git')) {
    fwrite(STDERR, "no git checkout at {$app}\n"
        . "  clone Kimai beside this repository:  git clone https://github.com/kimai/kimai.git " . escapeshellarg($kimai) . "\n"
        . "  or point ASSESSMENT_REPO at an existing checkout\n");
    exit(1);
}

// nikic/php-parser comes from the app's own vendor directory — it is already a PHPUnit dependency there, so
// nothing is installed for the assessment itself. A fixture repository has no vendor of its own and falls back
// to the Kimai checkout beside this one.
$autoload = null;
foreach ([$app . '/vendor/autoload.php', $kimai . '/vendor/autoload.php', __DIR__ . '/vendor/autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "nikic/php-parser not found\n"
        . "  install the app's dependencies:  composer install --working-dir=" . escapeshellarg($kimai) . "\n"
        . "  (clone it first with:  git clone https://github.com/kimai/kimai.git " . escapeshellarg($kimai) . ")\n");
    exit(1);
}
require $autoload;

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$nodeFinder = new NodeFinder();

// Cognitive complexity is not computed here: it is read from the SonarQube analysis of the commit, sonar-php
// being the reference implementation of the metric (*The three measures*). The server, the token and the
// project naming come from the environment; `scan` is what creates an analysis, `increment` only reads one.
$sonarHost = rtrim(getenv('SONAR_HOST') ?: 'http://localhost:9000', '/');
$sonarToken = getenv('SONAR_TOKEN') ?: '';
$sonarPrefix = getenv('SONAR_PROJECT_PREFIX') ?: 'assessment-';
$sonarImage = getenv('SONAR_SCANNER_IMAGE') ?: 'sonarsource/sonar-scanner-cli';

// The seam the self test replaces: per-method figures for one blob, keyed `Class::method`. Production reads
// the analysis of that sha; the self test hands back what a fixture commit declares its analysis would say.
// An analysis can also be handed in as a file — `md5(blob) => {Class::method: figure}` — which is what lets a
// run be reproduced without a server, and what the self test hands its own fixture.
$sonarFile = getenv('SONAR_ANALYSIS_FILE') ?: '';
$cognitiveSource = $sonarFile === ''
    ? static fn (string $sha, string $path, array $ast): array => complexitiesFor($ast, sonarRows($sha, $path))
    : analysisFromFile($sonarFile);

function git(string $args): string
{
    global $app;

    return (string) shell_exec('cd ' . escapeshellarg($app) . ' && git ' . $args . ' 2>/dev/null');
}

function blob(string $sha, string $path): ?string
{
    $out = git('show ' . escapeshellarg($sha . ':' . $path));

    return $out === '' ? null : $out;
}

/** @return list<Node\Stmt>|null */
function parseCode(string $code): ?array
{
    global $parser;
    try {
        $ast = $parser->parse($code);
    } catch (Throwable) {
        return null;
    }

    return $ast === null ? null : (new NodeTraverser(new NameResolver()))->traverse($ast);
}

/**
 * Fan out for one file: distinct type references that leave it, split internal against external as TPM's
 * formula requires. References are counted where they are *used*, and `use` statements are skipped, so
 * moving a name between an import and an inline FQCN does not move the score (*The three measures*, Fan
 * out).
 *
 * TPM counts a dependency between *files* — "how many different other files are used by a certain file"
 * (TPM §4.5) — so three kinds of name are skipped for referring to no type at all:
 *
 *  - the file's own `namespace` declaration, which is itself and would otherwise weigh as an internal
 *    reference in every file in the repository;
 *  - a function call, since `count()` and `array_map()` are the language, not files;
 *  - a constant fetch, since `null`, `true` and `E_USER_DEPRECATED` are the language too.
 *
 * A class the platform provides — `DateTime`, `Throwable` — is counted, as external. TPM's own example scores
 * the JDK that way, counting `java.io.IOException` and `java.util.Map` as external fan out (TPM §4.5).
 *
 * ponytail: skips every function call, not only the built-in ones. `use function` does not occur in `src/`,
 * so nothing real is lost; narrow this to non-`App\` callees if a namespaced function ever lands there.
 *
 * @return array{internal: int, external: int}
 */
function fanOut(array $ast): array
{
    global $nodeFinder;

    $skip = [];
    foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\Use_::class) as $use) {
        foreach ($nodeFinder->findInstanceOf($use, Node\Name::class) as $name) {
            $skip[spl_object_id($name)] = true;
        }
    }
    foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\GroupUse::class) as $use) {
        foreach ($nodeFinder->findInstanceOf($use, Node\Name::class) as $name) {
            $skip[spl_object_id($name)] = true;
        }
    }
    foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\Namespace_::class) as $namespace) {
        if ($namespace->name !== null) {
            $skip[spl_object_id($namespace->name)] = true;
        }
    }
    foreach ($nodeFinder->findInstanceOf($ast, Node\Expr\FuncCall::class) as $call) {
        if ($call->name instanceof Node\Name) {
            $skip[spl_object_id($call->name)] = true;
        }
    }
    foreach ($nodeFinder->findInstanceOf($ast, Node\Expr\ConstFetch::class) as $fetch) {
        $skip[spl_object_id($fetch->name)] = true;
    }
    $own = [];
    foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
        if ($classLike->namespacedName !== null) {
            $own[(string) $classLike->namespacedName] = true;
        }
    }

    $internal = [];
    $external = [];
    foreach ($nodeFinder->findInstanceOf($ast, Node\Name::class) as $name) {
        if (isset($skip[spl_object_id($name)])) {
            continue;
        }
        $fqn = (string) $name;
        if ($fqn === '' || isset($own[$fqn])) {
            continue;
        }
        if (in_array(strtolower($fqn), ['self', 'static', 'parent'], true)) {
            continue;
        }
        if (str_starts_with($fqn, 'App\\')) {
            $internal[$fqn] = true;
        } else {
            $external[$fqn] = true;
        }
    }

    return ['internal' => count($internal), 'external' => count($external)];
}

/**
 * Sites where a class is named at runtime — `new $class`, `$class::make()`, `$class::class`. The parser cannot
 * say what they point at, so each one is a dependency the counts above miss. They are reported per side as a
 * guard on the two static measures (*What the figures do not say*). A count that moves is a positive result:
 * the increment added code this static pass cannot see, and the transition is not evidence. A count that holds is
 * only the absence of that warning — it says the *number* of such sites did not change, which one site
 * removed and one added also satisfies, and it never fixes the missed *share*.
 *
 * ponytail: syntax only — a container lookup or a class name held in a string leaves no dynamic site here and
 * stays invisible on both sides. Widen to string literals shaped like class names if that miss ever matters.
 *
 * @param array<Node>|Node $nodes
 */
function unresolvedSites(array|Node $nodes): int
{
    global $nodeFinder;

    return count($nodeFinder->find($nodes, static fn (Node $node): bool => isDynamicSite($node)));
}

/**
 * Whether one node names its class at runtime. Five node types can, and an anonymous class is none of them: it
 * is a definition, not a reference the parser failed to resolve.
 */
function isDynamicSite(Node $node): bool
{
    if (!$node instanceof Node\Expr\New_ && !$node instanceof Node\Expr\StaticCall
        && !$node instanceof Node\Expr\StaticPropertyFetch && !$node instanceof Node\Expr\ClassConstFetch
        && !$node instanceof Node\Expr\Instanceof_) {
        return false;
    }

    return !$node->class instanceof Node\Name && !$node->class instanceof Node\Stmt\Class_;
}

/**
 * Nodes of one type reachable from here without leaving the scope that holds them: the walk stops at a nested
 * class-like, whose bodies belong to that class and not to this one, and at a named function, which has its
 * own scope and no `$this` of the method's. A closure is not a boundary — it keeps the method's `$this` and
 * reads into the method that holds it.
 *
 * A recursive `findInstanceOf` crosses both boundaries, which is how a method of an anonymous class came to be
 * counted twice and a trait it used came to be attributed to the class holding it.
 *
 * A sub-node is not always a node — a name, a flag, a line number — so anything else walks to nothing.
 *
 * @return list<Node>
 */
function findInScope(mixed $nodes, string $type): array
{
    if (is_array($nodes)) {
        $found = [];
        foreach ($nodes as $child) {
            $found = array_merge($found, findInScope($child, $type));
        }

        return $found;
    }
    if (!$nodes instanceof Node) {
        return [];
    }
    // A named function has its own scope and no `$this` of this method's, so it is a boundary like a class-like.
    if ($nodes instanceof Node\Stmt\ClassLike || $nodes instanceof Node\Stmt\Function_) {
        return [];
    }

    $found = $nodes instanceof $type ? [$nodes] : [];
    foreach ($nodes->getSubNodeNames() as $name) {
        $found = array_merge($found, findInScope($nodes->$name, $type));
    }

    return $found;
}


/**
 * The analysis of one commit: the `src/` files it read, and the figures it reported in them, as
 * `['files' => list<string>, 'rows' => array<string, list<array{line: int, cc: int}>>]` — read once per sha
 * and kept for the rest of the run.
 *
 * The analysis is looked up by project key, never created here: `scan` makes one, `increment` reads one. A sha
 * with no analysis is not an increment with nothing to read — it is a question the server cannot answer — so it
 * stops the run rather than printing a report that looks measured (*The three measures*, Cognitive complexity).
 *
 * @return array{files: list<string>, rows: array<string, list<array{line: int, cc: int}>>}
 */
function sonarAnalysis(string $sha): array
{
    global $sonarHost, $sonarToken, $sonarPrefix;
    static $memo = [];
    if (isset($memo[$sha])) {
        return $memo[$sha];
    }
    $project = $sonarPrefix . $sha;
    // What the analysis covered, and what it found in it: a file it never read is not a file with nothing to
    // report, so the two are asked for apart. Silence only means nought inside a file that was read.
    $files = sonarFiles($project);
    $rows = [];
    $allowed = [];
    $unread = 0;
    $page = 1;
    do {
        $body = sonarGet('/api/issues/search?' . http_build_query([
            'componentKeys' => $project, 'rules' => 'php:S3776', 'ps' => 500, 'p' => $page]));
        if (!isset($body['issues'])) {
            // Only the first page can say the analysis is not there; a page after it that the server would
            // not give is a limit of the search, and scanning again would not move it.
            fwrite(STDERR, $page === 1
                ? "no analysis for {$sha} on {$sonarHost} (project {$project})\n"
                    . "  create one:  php assessment.php scan {$sha}\n"
                : "the server would not give page {$page} of the analysis of " . substr($sha, 0, 8) . "\n"
                    . "  its search answers " . (($page - 1) * 500) . " findings at most, and this analysis"
                    . " holds more\n");
            exit(1);
        }
        // Read from the analysis rather than from the profile as it stands now: the profile can have moved
        // since, and what the figures were taken at is a fact about them and not about the server today.
        $page1 = figuresIn($body['issues'], $project);
        foreach ($page1['rows'] as $path => $found) {
            $rows[$path] = array_merge($rows[$path] ?? [], $found);
        }
        $allowed += $page1['allowed'];
        $unread += $page1['unread'];
        $total = (int) ($body['paging']['total'] ?? 0);
        ++$page;
    } while (($page - 1) * 500 < $total);

    if ($files === []) {
        // Told apart because they need different things done: a project the server does not hold, and one it
        // holds an analysis for that read no PHP at all.
        $known = sonarGet('/api/components/show?component=' . urlencode($project));
        fwrite(STDERR, isset($known['component'])
            ? "the analysis of " . substr($sha, 0, 8) . " read no PHP file under src/\n"
                . "  the analyser may not have been installed when it ran, or read somewhere else;"
                . " scan {$sha} again\n"
            : "no analysis for {$sha} on {$sonarHost} (project {$project})\n"
                . "  create one:  php assessment.php scan {$sha}\n");
        exit(1);
    }
    // A rule reporting only the methods above a limit answers, method for method, exactly like a tree holding
    // no control flow — silently, and at nought — so what the analysis was allowed to report is read before
    // what it did report. An analysis that reported something says so itself; one that reported nothing has
    // nothing to say, and the profile it used is asked instead.
    $short = substr($sha, 0, 8);
    // Each fault is read off the analysis, never off the profile as it stands: what the figures were taken at
    // is a fact about them and not about the server today. The profile is looked up for the message alone,
    // where it can say what to change.
    $fault = analysisFault($unread, $allowed, count($files));
    if ($fault === 'unread') {
        fwrite(STDERR, "the analysis of {$short} reports {$unread} finding(s) in a shape this run cannot read\n"
            . "  it expects the wording sonar-php writes — `from <figure> to the <threshold> allowed`\n"
            . "  a newer analyser may word it otherwise; the version this was written against is in the"
            . " methodology's Tools\n");
        exit(1);
    }
    if ($fault === 'silent') {
        $profile = ruleThresholdFor($project);
        fwrite(STDERR, "the analysis of {$short} read " . count($files) . " file(s) and reported nothing, so it"
            . " cannot say what it was taken at\n"
            . '  the profile that project uses now has php:S3776 ' . ($profile === null
                ? "at no threshold this run could read" : "at threshold {$profile}") . "\n"
            . "  set it to 0 and scan {$sha} again; a tree that truly holds no control flow has nothing to"
            . " measure on that side either way\n");
        exit(1);
    }
    if ($fault === 'threshold') {
        fwrite(STDERR, "the analysis of {$short} was taken with php:S3776 at threshold "
            . implode(', ', array_keys($allowed)) . ", so it reports only the methods above it\n"
            . "  every method at or under it would read as nought; set the threshold to 0 and scan {$sha}"
            . " again\n");
        exit(1);
    }

    return $memo[$sha] = ['files' => $files, 'rows' => $rows];
}

/**
 * What is wrong with an analysis, read off the analysis alone: `unread` where it reported findings in a shape
 * this run cannot read, `silent` where it read files and reported nothing, `threshold` where it was taken
 * above 0 and so reported only part of what it read — or null where it is sound.
 *
 * `silent` is a fault and not a figure of nought. An analysis that reported nothing cannot say what it was
 * taken at, and the profile it used can have moved since, so nought there would be a figure nobody gave. An
 * analysis that read no file at all is not at fault: there was nothing for it to report.
 *
 * @param array<string, true> $allowed the thresholds the findings were taken at
 */
function analysisFault(int $unread, array $allowed, int $files): ?string
{
    return match (true) {
        $unread > 0 => 'unread',
        $files === 0 => null,
        $allowed === [] => 'silent',
        $allowed !== ['0' => true] => 'threshold',
        default => null,
    };
}

/**
 * What one page of an analysis carries: the figures its messages name, keyed by path of the measured set, the
 * thresholds those messages were taken at, and how many it could not read.
 *
 * Every message the rule writes names both figures — `from N to the M allowed` — so the analysis states what it
 * found and what it was allowed to report. A message in any other shape is one this run cannot read, and it is
 * counted rather than skipped: an analyser whose wording moved would otherwise read as an analysis that found
 * nothing, which is a figure of nought for every method it covered.
 *
 * @param list<array{component?: string, line?: int, message?: string}> $issues
 *
 * @return array{rows: array<string, list<array{line: int, cc: int}>>, allowed: array<string, true>, unread: int}
 */
function figuresIn(array $issues, string $project): array
{
    $rows = [];
    $allowed = [];
    $unread = 0;
    foreach ($issues as $issue) {
        if (!isset($issue['line'])
            || preg_match('/from (\d+) to the (\d+) allowed/', (string) ($issue['message'] ?? ''), $match) !== 1) {
            ++$unread;
            continue;
        }
        $allowed[$match[2]] = true;
        // `<project>:<path>` — the scan runs over `src/`, so the path is relative to it.
        $rows['src/' . substr((string) ($issue['component'] ?? ''), strlen($project) + 1)][] = [
            'line' => (int) $issue['line'], 'cc' => (int) $match[1]];
    }

    return ['rows' => $rows, 'allowed' => $allowed, 'unread' => $unread];
}

/**
 * The `src/` files one analysis read, as paths of the measured set. The scan runs over `src/`, so what the
 * server lists is relative to it.
 *
 * @return list<string>
 */
function sonarFiles(string $project): array
{
    $paths = [];
    $page = 1;
    do {
        $body = sonarGet('/api/components/tree?' . http_build_query([
            'component' => $project, 'qualifiers' => 'FIL', 'ps' => 500, 'p' => $page]));
        if (!isset($body['components'])) {
            // A page the server would not give leaves a listing that is short without saying so, and every
            // file missing from it would read as one the analysis never touched.
            fwrite(STDERR, $page === 1 ? "" : "the server would not give page {$page} of the file listing for"
                . " {$project}\n  its search answers " . (($page - 1) * 500) . " files at most, and this"
                . " analysis read more\n");
            if ($page > 1) {
                exit(1);
            }
        }
        foreach ($body['components'] ?? [] as $component) {
            $paths[] = 'src/' . $component['path'];
        }
        $total = (int) ($body['paging']['total'] ?? 0);
        ++$page;
    } while (($page - 1) * 500 < $total);

    return $paths;
}

/**
 * A cognitive source backed by a file rather than a server: `md5(blob) => {Class::method: figure}`, which is
 * what reproduces a run without one. A blob the file says nothing about was not analysed, and nought is not
 * what that means — an analysed blob whose methods hold no control flow is declared all the same, as an entry
 * with nothing in it. The same rule the server side reads with `wasAnalysed`.
 */
function analysisFromFile(string $file): callable
{
    // Read once, here: a file that cannot be opened or does not hold an analysis is a fault of the file, and
    // saying so from inside the per-blob lookup would name a blob that was never looked for.
    $raw = is_file($file) ? @file_get_contents($file) : false;
    $declared = $raw === false ? null : json_decode($raw, true);
    if (!is_array($declared)) {
        fwrite(STDERR, "no analysis to read at {$file}\n"
            . "  SONAR_ANALYSIS_FILE has to name a readable file holding `md5(blob) => {Class::method: figure}`\n");
        exit(1);
    }

    return static function (string $sha, string $path, array $ast) use ($file, $declared): array {
        $blob = md5((string) blob($sha, $path));
        if (!array_key_exists($blob, $declared)) {
            fwrite(STDERR, "the analysis in {$file} says nothing about {$path} at " . substr($sha, 0, 8) . "\n");
            exit(1);
        }
        $figures = $declared[$blob];
        if (!is_array($figures)) {
            fwrite(STDERR, "what the analysis in {$file} holds for {$path} is not a set of figures\n"
                . "  each entry is `{\"Class::method\": figure}`, and an analysed blob with none is `{}`\n");
            exit(1);
        }
        $methods = array_fill_keys(array_keys(methodNodes($ast)), 0);
        // A figure keyed to a method the blob has not got is an analysis of some other blob: admitting it
        // would put a method in the union that the tree never declared.
        $stranger = array_diff_key($figures, $methods);
        if ($stranger !== []) {
            fwrite(STDERR, "the analysis in {$file} names " . implode(', ', array_keys($stranger))
                . " in {$path}, which that blob does not declare\n");
            exit(1);
        }
        $notFigures = array_keys(array_filter($figures, static fn (mixed $figure): bool => !is_int($figure)));
        if ($notFigures !== []) {
            fwrite(STDERR, "what the analysis in {$file} holds for " . implode(', ', $notFigures)
                . " is no figure\n  a cognitive complexity is a whole number of increments\n");
            exit(1);
        }

        return array_replace($methods, $figures);
    };
}

/**
 * The threshold the rule is active at in an `/api/rules/search` answer asked with `f=actives`, or null where
 * the answer says it is not active at all. The threshold is what parts an analysis that reports every method
 * from one that reports only the methods above a limit — and the second reads, method for method, exactly like
 * a tree with no control flow in it.
 */
function activeThreshold(array $body): ?string
{
    foreach ($body['actives']['php:S3776'] ?? [] as $active) {
        foreach ($active['params'] ?? [] as $param) {
            if (($param['key'] ?? '') === 'threshold') {
                return (string) $param['value'];
            }
        }
    }

    return null;
}

/**
 * Whether the rule that reports cognitive complexity is active in the profile a project uses. It is what parts
 * the two readings of an analysis that found nothing: a tree with no control flow in it, and a profile that
 * would not have reported any.
 */
function ruleThresholdFor(string $project): ?string
{
    $profiles = sonarGet('/api/qualityprofiles/search?' . http_build_query(['project' => $project]));
    foreach ($profiles['profiles'] ?? [] as $profile) {
        if (($profile['language'] ?? '') !== 'php') {
            continue;
        }

        return activeThreshold(sonarGet('/api/rules/search?' . http_build_query([
            'activation' => 'true', 'qprofile' => $profile['key'], 'rule_key' => 'php:S3776',
            'f' => 'actives', 'ps' => 1])));
    }

    return null;
}

/** Whether one file was among those an analysis read. */
function wasAnalysed(array $files, string $path): bool
{
    return in_array($path, $files, true);
}

/**
 * The rows the analysis of one commit reported for one file. A file the analysis never read stops the run:
 * nought there would be a figure the analysis never gave, and the report would read as measured.
 */
function sonarRows(string $sha, string $path): array
{
    global $sonarHost;
    $analysis = sonarAnalysis($sha);
    if (!wasAnalysed($analysis['files'], $path)) {
        fwrite(STDERR, "the analysis of " . substr($sha, 0, 8) . " on {$sonarHost} never read {$path}\n"
            . "  it covers " . count($analysis['files']) . " file(s); scan that commit again if its tree has moved\n");
        exit(1);
    }

    return $analysis['rows'][$path] ?? [];
}

/** The status an answer carries, off its first header line; nought where it carries no line at all. */
function httpStatus(array $headers): int
{
    // A redirect chain leaves every response's header block in the list, oldest first, so the status that
    // matters is the last one: reading the first would report the 30x that forwarded and never the answer.
    $status = 0;
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', (string) $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return $status;
}

/**
 * One call to the server, decoded. A transport failure is a stop, and so is an answer the server refused to
 * give: a run that reads a refusal as an empty analysis would send the reader off to scan a commit that is
 * already scanned.
 */
function sonarGet(string $path): array
{
    global $sonarHost, $sonarToken;
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'ignore_errors' => true, 'timeout' => 30,
        'header' => 'Authorization: Bearer ' . $sonarToken . "\r\n"]]);
    $body = @file_get_contents($sonarHost . $path, false, $context);
    if ($body === false) {
        fwrite(STDERR, "cannot reach SonarQube at {$sonarHost}\n  set SONAR_HOST, and SONAR_TOKEN for a token\n");
        exit(1);
    }
    $status = httpStatus($http_response_header ?? []);
    if ($status === 401 || $status === 403) {
        fwrite(STDERR, "SonarQube at {$sonarHost} refused the credentials ({$status})\n"
            . "  SONAR_TOKEN has to be a token the server knows, and one that may read the project\n");
        exit(1);
    }
    // An answer the server could not give is not an answer about the analysis: reading a 503 as an empty one
    // would name a commit unscanned while it is starting up or being proxied.
    if ($status >= 500) {
        fwrite(STDERR, "SonarQube at {$sonarHost} could not answer ({$status})\n"
            . "  the server may still be starting; the analysis it holds is unchanged either way\n");
        exit(1);
    }
    $decoded = json_decode((string) $body, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Cognitive complexity per method for one file, keyed `Class::method`, from the figures the analysis of that
 * commit reported for it.
 *
 * The analysis reports one figure per method, addressed by a line at or after its declaration, and folds a
 * closure it declares into that figure rather than reporting it apart; the method a figure belongs to is the
 * one whose declaration encloses that line, which the syntax tree of the same blob supplies.
 *
 * ponytail: where more than one figure lands in a method's lines, the outermost — the lowest line — is taken.
 * No analysis of Kimai's `src/` produces that (1,483 figures over 591 files, never two in one method), so it
 * is a guard against an analyser that reports a nested declaration in its own right, not a rule read off one.
 *
 * A method the analysis said nothing about carries nought: silence is how it reports a method with no control
 * flow, the rule being reported only where there is something to report.
 *
 * @param list<Node\Stmt>            $ast
 * @param list<array{line: int, cc: int}> $rows
 *
 * @return array<string, int>
 */
function complexitiesFor(array $ast, array $rows): array
{
    $figures = [];
    foreach (methodNodes($ast) as $key => $method) {
        $inside = array_values(array_filter($rows, static fn (array $row): bool => $row['line'] >= $method->getStartLine()
            && $row['line'] <= $method->getEndLine()));
        usort($inside, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        $figures[$key] = $inside === [] ? 0 : $inside[0]['cc'];
    }

    return $figures;
}

/**
 * The methods of one file that have a body, keyed `Class::method`.
 *
 * An interface or abstract declaration has no body, so there is no control flow in it to read. Admitting it as
 * a 0 would enlarge n without an increment writing any code (*The three measures*, Cognitive complexity), and
 * the probes that read a method read the same set.
 *
 * @return array<string, Node\Stmt\ClassMethod>
 */
function methodNodes(array $ast): array
{
    global $nodeFinder;

    $methods = [];
    foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
        // An anonymous class has no name to key its methods under, so what the analysis says about them is
        // read under no method of its own. Only the methods the class-like declares itself are taken, so one
        // nested in another is never counted twice.
        // ponytail: a class-like or function declared inside a method body sits in that method's lines, so a
        // figure the analysis reports for it is read into the holder as well — mis-attributed where the class
        // is anonymous, counted twice where it is named and keyed under its own name too. `src/` declares
        // neither at this checkout; key the figures by declaration rather than by line range if one appears.
        if ($classLike->namespacedName === null) {
            continue;
        }
        foreach ($classLike->getMethods() as $method) {
            if ($method->stmts === null) {
                continue;
            }
            $methods[(string) $classLike->namespacedName . '::' . $method->name->toString()] = $method;
        }
    }

    return $methods;
}

/**
 * The class-likes one file declares, keyed by name.
 *
 * @return array<string, Node\Stmt\ClassLike>
 */
function classLikesIn(array $ast): array
{
    global $nodeFinder;

    $classes = [];
    foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
        if ($classLike->namespacedName !== null) {
            $classes[(string) $classLike->namespacedName] = $classLike;
        }
    }

    return $classes;
}

// ---------------------------------------------------------------- probes
//
// A probe reports no figure per side, carries no band and takes no state: it settles an outcome the three
// measures leave open, and only where they leave one open (*Grounding the probes*). Two read figures the
// measures already compute — attribution and control flow leaving, both off the per-method complexities — and
// three read the syntax tree for a shape.

/**
 * Delegation ratio — of the classes the increment touched, how many method bodies do nothing but forward, and
 * whether what they forward to is own production code or something outside `App\`.
 *
 * Read over every class of the touched production files rather than over the added ones alone, which is what
 * the methodology asks for — "every touched production class, not only the ones the increment added". Its
 * worked example turns on exactly that: the row it names is the pre-existing service the extraction left
 * delegating, which is also where the shape actually lands. An extraction leaves the middle man behind on the
 * class that was already there.
 *
 * The shape is Fowler's *Middle Man* and *Lazy Element*; the ratio over an added class's methods and the
 * `App\` split on what they forward to are this assessment's own (*Grounding the probes*). A class every method
 * of which forwards to a collaborator under `App\` stands between two of your own types — `pass-through`.
 * One forwarding to a type outside `App\` confines an external dependency — `adapter`.
 *
 * The constructor is not read: assigning what was injected is not forwarding.
 *
 * ponytail: misses methods reached through a trait, and `__call`. Named in the methodology as the probe's own
 * blind spot; widen only if a `__call` façade ever lands in `src/`.
 *
 * @param array<string, Node\Stmt\ClassLike> $classes the class-likes of the touched production files
 *
 * @return list<array{class: string, forwards: int, methods: int, collaborator: string, state: string}>
 */
function delegationProbe(array $classes): array
{
    $rows = [];
    foreach ($classes as $name => $classLike) {
        if (!$classLike instanceof Node\Stmt\Class_) {
            continue;
        }
        $types = declaredPropertyTypes($classLike);
        $methods = 0;
        $forwards = 0;
        $collaborators = [];
        foreach ($classLike->getMethods() as $method) {
            // A body that is empty forwards nothing and asks nothing of the reader, so it weighs on neither
            // side of the ratio — the same reading a declaration without a body gets.
            if ($method->stmts === null || $method->stmts === []
                || strtolower($method->name->toString()) === '__construct') {
                continue;
            }
            ++$methods;
            $collaborator = forwardedCollaborator($method, $types, $name);
            if ($collaborator === null) {
                continue;
            }
            ++$forwards;
            $collaborators[$collaborator] = true;
        }
        if ($forwards === 0) {
            continue;
        }
        $internal = [];
        foreach (array_keys($collaborators) as $collaborator) {
            $internal[str_starts_with($collaborator, 'App\\') ? 'internal' : 'external'] = true;
        }
        // Both kinds at once names both, rather than borrowing `mixed` — which question 1 already uses for an
        // outcome, and one word carries one meaning here (*Grounding the probes*).
        $collaborator = count($internal) === 1 ? array_key_first($internal) : 'internal and external';
        // `adapter` is the shape the methodology names: a class confining something outside `App\`. A class
        // forwarding only some of its methods is neither that nor a middle man, and says so as `partial`.
        $rows[] = ['class' => $name, 'forwards' => $forwards, 'methods' => $methods,
            'collaborator' => $collaborator,
            'state' => match (true) {
                $forwards !== $methods => 'partial',
                $collaborator === 'internal' => 'pass-through',
                default => 'adapter',
            }];
    }

    return $rows;
}

/**
 * The declared type of each property one class holds, promoted constructor parameters included — what a
 * forwarding body's `$this->foo` resolves against.
 *
 * @return array<string, string>
 */
function declaredPropertyTypes(Node\Stmt\Class_ $class): array
{
    $types = [];
    foreach ($class->getProperties() as $property) {
        $type = declaredTypeName($property->type);
        if ($type !== null) {
            foreach ($property->props as $prop) {
                $types[$prop->name->toString()] = $type;
            }
        }
    }
    $constructor = $class->getMethod('__construct');
    foreach ($constructor?->params ?? [] as $param) {
        // Only a promoted parameter is a property of the class. One the constructor merely takes is a local.
        $type = $param->flags === 0 ? null : declaredTypeName($param->type);
        if ($type !== null && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
            $types[$param->var->name] = $type;
        }
    }

    return $types;
}

/**
 * The one type a declaration names, `?Dep` read as `Dep` — a nullable collaborator is the same collaborator.
 * A union or an intersection names more than one and resolves to none of them: there is no single collaborator
 * for the probes to attribute a forward to.
 */
function declaredTypeName(?Node $type): ?string
{
    if ($type instanceof Node\NullableType) {
        $type = $type->type;
    }
    // `Dep|null` spells what `?Dep` spells — php-parser reads a built-in type as an `Identifier` under its
    // canonical lower-case name, whatever the source wrote. A union naming two types of its own names no
    // single collaborator.
    if ($type instanceof Node\UnionType) {
        $named = array_values(array_filter($type->types,
            static fn (Node $one): bool => !($one instanceof Node\Identifier && $one->name === 'null')));
        $type = count($named) === 1 ? $named[0] : null;
    }

    return $type instanceof Node\Name ? (string) $type : null;
}

/**
 * The collaborator one method body forwards to, or null where the body does anything else. A forwarding body
 * is one statement, and that statement is a call: `return $this->calculator->calculate($x);` or the same
 * without the `return`.
 *
 * @param array<string, string> $propertyTypes
 */
function forwardedCollaborator(Node\Stmt\ClassMethod $method, array $propertyTypes, string $own = ''): ?string
{
    $stmts = $method->stmts ?? [];
    if (count($stmts) !== 1) {
        return null;
    }
    $statement = $stmts[0];
    $expr = match (true) {
        $statement instanceof Node\Stmt\Return_ => $statement->expr,
        $statement instanceof Node\Stmt\Expression => $statement->expr,
        default => null,
    };
    if ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name) {
        return namesOwnClass((string) $expr->class, $own) ? null : (string) $expr->class;
    }
    if (!$expr instanceof Node\Expr\MethodCall && !$expr instanceof Node\Expr\NullsafeMethodCall) {
        return null;
    }
    $receiver = $expr->var;
    if ($receiver instanceof Node\Expr\PropertyFetch && $receiver->var instanceof Node\Expr\Variable
        && $receiver->var->name === 'this' && $receiver->name instanceof Node\Identifier) {
        $type = $propertyTypes[$receiver->name->toString()] ?? null;

        return $type !== null && namesOwnClass($type, $own) ? null : $type;
    }

    return null;
}

/**
 * Whether a name is the class the body already sits in or the chain it inherits from, rather than a collaborator
 * it stands between — the same reading fan out gives `self`, `static` and `parent`, and the same reading its own
 * name deserves, a class delegating to itself being no Middle Man.
 */
function namesOwnClass(string $name, string $own = ''): bool
{
    return in_array(strtolower($name), ['self', 'static', 'parent'], true)
        || ($own !== '' && strtolower($name) === strtolower($own));
}

/**
 * Implementations — for each abstraction the increment introduced, the class-likes at the increment that
 * implement, extend or use it, discounting any whose methods are empty or return a constant.
 *
 * *Speculative Generality* is what an abstraction with one case is called; counting implementations and
 * discounting an empty one are this assessment's rules, not the source's (*Grounding the probes*). One real
 * implementation is `single`, two or more `polymorphic`.
 *
 * ponytail: counts implementations under `src/` only, so one living in `tests/` or in a bundle is missed.
 *
 * @param array<string, Node\Stmt\ClassLike> $abstractions
 *
 * @return list<array{abstraction: string, real: int, discounted: int, state: string}>
 */
function implementationsProbe(array $abstractions, string $incrementSha): array
{
    return $abstractions === []
        ? []
        : implementationsIn(srcClassLikes($incrementSha), array_keys($abstractions));
}

/**
 * Whether one class-like reaches an abstraction through what it declares, however many names apart the two
 * are: a class extending a base that implements the interface implements it as well, and the reader who opens
 * the abstraction finds it there. Only the population is walked, so a chain leaving `src/` ends where it
 * leaves.
 *
 * @param array<string, array{parents: list<string>, trivial: bool}> $population
 * @param array<string, true>                                        $seen
 */
function reachesAbstraction(array $population, string $candidate, string $name, array $seen = []): bool
{
    // A declaration cycle is illegal PHP, but the population is read from source and not from a running class
    // hierarchy, so the walk carries what it has already stood on.
    if (isset($seen[$candidate])) {
        return false;
    }
    $seen[$candidate] = true;
    foreach ($population[$candidate]['parents'] ?? [] as $parent) {
        if ($parent === $name || reachesAbstraction($population, $parent, $name, $seen)) {
            return true;
        }
    }

    return false;
}

/**
 * The counting behind the implementations probe, over a population read from anywhere.
 *
 * @param array<string, array{parents: list<string>, trivial: bool}> $population
 * @param list<string>                                               $abstractions
 *
 * @return list<array{abstraction: string, real: int, discounted: int, state: string}>
 */
function implementationsIn(array $population, array $abstractions): array
{
    $rows = [];
    foreach ($abstractions as $name) {
        $real = 0;
        $discounted = 0;
        foreach ($population as $candidate => $facts) {
            if ($candidate === $name || !reachesAbstraction($population, $candidate, $name)) {
                continue;
            }
            $facts['trivial'] ? ++$discounted : ++$real;
        }
        $rows[] = ['abstraction' => $name, 'real' => $real, 'discounted' => $discounted,
            'state' => $real >= 2 ? 'polymorphic' : 'single'];
    }

    return $rows;
}

/**
 * Mock seam — the classes a touched test file hands to a mock builder, split by whether the class is own
 * production code or sits at a boundary outside `App\`.
 *
 * The test code quality model names test doubles as a solution to the coupling it measures, which is why the
 * measure never counts a mocked class as a production call; the probe reports what was mocked, so a boundary
 * can be told from own production code (*Grounding the probes*).
 *
 * ponytail: reads the whole test class, so a double built in `setUp()` is seen — but only where the class is
 * named as `Foo::class`, never where it arrives through a variable.
 *
 * @return list<array{class: string, state: string}>
 */
function mockSeamProbe(string $code): array
{
    global $nodeFinder;

    $ast = parseCode($code);
    if ($ast === null) {
        return [];
    }
    $builders = ['createmock', 'createstub', 'createpartialmock', 'createconfiguredmock', 'getmockbuilder',
        'createmockforintersectionofinterfaces', 'prophesize'];

    $mocked = [];
    foreach ([Node\Expr\MethodCall::class, Node\Expr\StaticCall::class] as $nodeType) {
        foreach ($nodeFinder->findInstanceOf($ast, $nodeType) as $call) {
            if (!$call->name instanceof Node\Identifier
                || !in_array(strtolower($call->name->toString()), $builders, true)) {
                continue;
            }
            $argument = $call->args[0] ?? null;
            if (!$argument instanceof Node\Arg || !$argument->value instanceof Node\Expr\ClassConstFetch
                || !$argument->value->class instanceof Node\Name) {
                continue;
            }
            $mocked[(string) $argument->value->class] = true;
        }
    }

    $rows = [];
    foreach (array_keys($mocked) as $class) {
        $rows[] = ['class' => $class, 'state' => str_starts_with($class, 'App\\') ? 'internal' : 'boundary'];
    }

    return $rows;
}

function band(float $score): string
{
    return match (true) {
        $score >= 90 => 'A',
        $score >= 80 => 'B',
        $score >= 70 => 'C',
        $score >= 50 => 'D',
        $score >= 40 => 'E',
        default => 'F',
    };
}

function fanOutScore(float $internal, float $external): float
{
    return 100 / 2 ** ((8 * $internal + 2 * $external) / 100);
}

/**
 * Every production class-like at one sha, with what it declares itself against: the parents, interfaces and
 * traits it names, and whether its own bodies do anything. One pass over `src/`, memoised per sha, and two
 * readings take from it — the class-like names Test unit dependency counts calls into (*The three measures*,
 * Test unit dependency), and the population the implementations probe counts an abstraction's implementations
 * over (*Grounding the probes*).
 *
 * `trivial` is the probe's discount: a class-like whose every method body is empty or returns a constant is
 * not a second implementation of anything, it is a placeholder.
 *
 * @return array<string, array{parents: list<string>, trivial: bool}>
 */
function srcClassLikes(string $sha): array
{
    static $memo = [];
    if (isset($memo[$sha])) {
        return $memo[$sha];
    }
    global $nodeFinder;

    $classes = [];
    foreach (srcBlobs($sha) as $code) {
        $ast = parseCode($code);
        if ($ast === null) {
            continue;
        }
        foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $classLike) {
            if ($classLike->namespacedName === null) {
                continue;
            }
            $classes[(string) $classLike->namespacedName] = [
                'parents' => declaredParents($classLike),
                'trivial' => isTrivialImplementation($classLike),
            ];
        }
    }

    return $memo[$sha] = $classes;
}

/**
 * The names one class-like declares itself against — `extends`, `implements` and every `use` of a trait in its
 * body. The implementations probe reads an abstraction's implementations off these, so an interface extending
 * another interface counts as one of its implementations too (*Grounding the probes*).
 *
 * @return list<string>
 */
function declaredParents(Node\Stmt\ClassLike $classLike): array
{
    $parents = [];
    if ($classLike instanceof Node\Stmt\Class_ && $classLike->extends !== null) {
        $parents[] = (string) $classLike->extends;
    }
    // An enum implements interfaces the way a class does, and reads as an implementation of them.
    if ($classLike instanceof Node\Stmt\Class_ || $classLike instanceof Node\Stmt\Enum_) {
        foreach ($classLike->implements as $interface) {
            $parents[] = (string) $interface;
        }
    }
    if ($classLike instanceof Node\Stmt\Interface_) {
        foreach ($classLike->extends as $interface) {
            $parents[] = (string) $interface;
        }
    }
    // Only the traits this class-like uses itself. One used by an anonymous class inside a method body belongs
    // to that class and not to this one.
    foreach ($classLike->stmts as $statement) {
        if ($statement instanceof Node\Stmt\TraitUse) {
            foreach ($statement->traits as $trait) {
                $parents[] = (string) $trait;
            }
        }
    }

    return $parents;
}

/**
 * Whether every method body of one class-like is empty or returns a constant — the discount the implementations
 * probe applies, so a placeholder returning zero does not make an abstraction polymorphic (*Grounding the
 * probes*). A class-like with no method body at all is trivial by the same reading.
 */
/**
 * Whether an expression is resolved where it is written rather than built from what the object holds: a literal
 * with or without a sign in front of it, a named or class constant, an empty list, or nothing returned at all.
 *
 * An interpolated string is none of them — it reads the object — and neither is a populated list, however short
 * it reads.
 */
function isConstantExpression(?Node $expr): bool
{
    if ($expr instanceof Node\Expr\UnaryMinus || $expr instanceof Node\Expr\UnaryPlus) {
        $expr = $expr->expr;
    }

    return $expr === null
        || ($expr instanceof Node\Scalar && !$expr instanceof Node\Scalar\InterpolatedString)
        || $expr instanceof Node\Expr\ConstFetch
        || $expr instanceof Node\Expr\ClassConstFetch
        || ($expr instanceof Node\Expr\Array_ && $expr->items === []);
}

function isTrivialImplementation(Node\Stmt\ClassLike $classLike): bool
{
    // The methods this class-like declares itself. A body held by an anonymous class inside one of them is
    // that class's, and reading it here would make a placeholder look like a real implementation.
    foreach ($classLike->getMethods() as $method) {
        $stmts = $method->stmts;
        if ($stmts === null || $stmts === []) {
            continue;
        }
        if (count($stmts) === 1 && $stmts[0] instanceof Node\Stmt\Return_
            && isConstantExpression($stmts[0]->expr)) {
            continue;
        }

        return false;
    }

    return true;
}

/**
 * The production class-like names at one sha. Test unit dependency counts calls that land on one of these,
 * and the set is read per side — at the baseline for its value, at the increment for its own — so a
 * test calling into a class the increment itself added counts as a dependency (*The three measures*, Test unit
 * dependency).
 *
 * @return array<string, true>
 */
function productionNames(string $sha): array
{
    return array_map(static fn (): bool => true, srcClassLikes($sha));
}

/**
 * Every `src/**.php` blob at one sha, read through a single `git cat-file --batch`. One `git show` per file
 * costs a process per file and dominates the run; this is the same content in one call.
 *
 * @return list<string>
 */
function srcBlobs(string $sha): array
{
    global $app;

    $shas = [];
    foreach (explode("\n", trim(git('ls-tree -r ' . escapeshellarg($sha) . ' -- src/'))) as $line) {
        if (preg_match('/^\S+ blob (\S+)\t(.+\.php)$/', $line, $match) === 1) {
            $shas[] = $match[1];
        }
    }
    if ($shas === []) {
        return [];
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open('git cat-file --batch', $descriptors, $pipes, $app);
    if (!is_resource($process)) {
        return [];
    }
    fwrite($pipes[0], implode("\n", $shas) . "\n");
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    // Each object comes back as "<sha> blob <size>\n<content>\n".
    $blobs = [];
    $offset = 0;
    while (($newline = strpos($out, "\n", $offset)) !== false) {
        $header = explode(' ', substr($out, $offset, $newline - $offset));
        if (count($header) < 3) {
            break;
        }
        $size = (int) $header[2];
        $blobs[] = substr($out, $newline + 1, $size);
        $offset = $newline + 1 + $size + 1;
    }

    return $blobs;
}

function isTestMethod(Node\Stmt\ClassMethod $method): bool
{
    if (!$method->isPublic()) {
        return false;
    }
    if (str_starts_with(strtolower($method->name->toString()), 'test')) {
        return true;
    }
    foreach ($method->getAttrGroups() as $group) {
        foreach ($group->attrs as $attribute) {
            if (strtolower($attribute->name->getLast()) === 'test') {
                return true;
            }
        }
    }
    $doc = $method->getDocComment();

    return $doc !== null && preg_match('/@test\b/', $doc->getText()) === 1;
}

/**
 * Unique outgoing calls from one test method to production units — Test unit dependency's numerator
 * (*The three measures*). A call is keyed `Class::method`, resolving the receiver from a parameter type or
 * from a `new`. A `new` is itself a call, on `__construct`.
 *
 * Only calls count, because that is what the paper's metric is: "the number of unique outgoing calls
 * (fan-out) from a test code unit to production code units" (§3.2.5). A bare `Foo::class` — handed to a mock
 * builder, say — is a reference and not a call, and counting it would invert the model twice over: it would
 * overshoot a definition the method claims to follow, and it would score a test double as coupling when the
 * paper names test doubles as "a solution to avoid this coupling" (§3.2.5).
 *
 * The count is not a bound in either direction (*What the figures do not say*). It undershoots on receivers a
 * static pass cannot resolve, and it overshoots on two known cases:
 *
 * ponytail: `$varTypes` is read in source order, so a reassignment keys the calls before it to what the name
 * held then — but it is flow-insensitive: a type assigned inside an `if` or a loop body is carried out of it,
 * so a call after the branch is keyed to a type it may not hold. Track the branches if that ever misleads.
 *
 * ponytail: a method inherited from outside `App\` is still keyed to the production class that received the
 * call. Resolving the declaring class needs the full class hierarchy, which this pass does not build.
 *
 * The same walk counts the call sites it could **not** attribute — a receiver whose type never resolves, a
 * class named at runtime. That count is the guard of *What the figures do not say*, reported per side and
 * never mixed into the dependency figure. A receiver the walk did resolve to a type outside production is not
 * one of them: it makes no call to count and it is no miss either.
 *
 * @param array<string, true> $production
 *
 * @return array{calls: int, unresolved: int}
 */
function unitDependency(Node\Stmt\ClassMethod $method, array $production): array
{
    $varTypes = [];
    seedParamTypes($method->params, $varTypes);

    $targets = [];
    // The dynamic sites are counted once here, over the method's own scope: a class named at runtime is a miss
    // wherever it sits in it, and the walk below never counts one twice. The guard stops where that walk stops
    // — inside a nested class-like or function the bodies are their own, calls and misses alike.
    $unresolved = count(array_filter(findInScope($method->stmts ?? [], Node::class),
        static fn (Node $node): bool => isDynamicSite($node)));
    unitDependencyWalk($method->stmts ?? [], $varTypes, $production, $targets, $unresolved);

    return ['calls' => count($targets), 'unresolved' => $unresolved];
}

/**
 * The receiver types a parameter list declares, laid over what the scope already holds: a declared type names
 * the receiver, and a parameter without one shadows whatever the enclosing scope held under that name — a
 * closure's `$s` is its own variable, not the method's (*The three measures*, How a receiver is resolved).
 *
 * @param list<Node\Param>      $params
 * @param array<string, string> $types
 */
function seedParamTypes(array $params, array &$types): void
{
    foreach ($params as $param) {
        if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
            continue;
        }
        // `?Svc` is the same declared type as `Svc`; a union naming two types of its own resolves to neither.
        $type = declaredTypeName($param->type);
        if ($type === null) {
            unset($types[$param->var->name]);

            continue;
        }
        $types[$param->var->name] = $type;
    }
}

/**
 * Drop the type every variable a binding writes to held before it: the target of an assignment, a `foreach`
 * key or value, a destructuring of either. What the name holds after the binding is what the binding put
 * there, and only a `new` says what that is.
 *
 * @param array<string, string> $types
 */
function clearBoundNames(mixed $target, array &$types): void
{
    if (is_array($target)) {
        foreach ($target as $one) {
            clearBoundNames($one, $types);
        }

        return;
    }
    if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
        unset($types[$target->name]);

        return;
    }
    // A destructuring binds every name it lists, nested lists included — php-parser reads `[$a, $b] = …` and
    // `list($a, $b) = …` alike as a `List_`. Anything else — a property, an element of an array — is written
    // through a receiver that goes on holding what it held.
    if ($target instanceof Node\Expr\List_) {
        foreach ($target->items as $item) {
            clearBoundNames($item?->value, $types);
        }
    }
}

/**
 * One walk over a test method in source order, carrying the types its variables hold at that point.
 *
 * Assignment is read last-assign-wins, which is what the reader of the test sees. A closure or an arrow
 * function is walked with a **copy** of the types, so what it assigns stays inside it while the calls it makes
 * still count — a call made from a closure the test wrote is still the test reaching into production. A nested
 * class-like is not walked at all: its bodies are its own.
 *
 * @param array<string, string> $varTypes
 * @param array<string, true>   $production
 * @param array<string, true>   $targets
 */
function unitDependencyWalk(mixed $node, array &$varTypes, array $production, array &$targets, int &$unresolved): void
{
    if (is_array($node)) {
        foreach ($node as $child) {
            unitDependencyWalk($child, $varTypes, $production, $targets, $unresolved);
        }

        return;
    }
    if (!$node instanceof Node || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) {
        return;
    }
    // A closure carries the types it inherited, and what it assigns stays inside it. Its own parameters are
    // its own variables: they name their declared type, and shadow the enclosing name where they declare none.
    if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
        $scoped = $varTypes;
        seedParamTypes($node->params, $scoped);
        foreach ($node->getSubNodeNames() as $name) {
            unitDependencyWalk($node->$name, $scoped, $production, $targets, $unresolved);
        }

        return;
    }

    // Last assign wins, and the right-hand side is read before the assignment lands, so a call on the variable
    // being reassigned is still a call on what it held. A `new` names the receiver's type; an assignment from
    // anything else — a mock builder, a factory call — leaves it holding something this pass cannot name, and
    // the name it held before is not that thing.
    // ponytail: `foreach` and plain assignment are the binding forms a test writes; a reference assignment and
    // a `catch` variable still keep whatever the name held before. Clear those too if one ever misleads.
    if ($node instanceof Node\Expr\Assign) {
        foreach ($node->getSubNodeNames() as $name) {
            unitDependencyWalk($node->$name, $varTypes, $production, $targets, $unresolved);
        }
        clearBoundNames($node->var, $varTypes);
        if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)
            && $node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name) {
            $varTypes[$node->var->name] = (string) $node->expr->class;
        }

        return;
    }
    if ($node instanceof Node\Stmt\Foreach_) {
        unitDependencyWalk($node->expr, $varTypes, $production, $targets, $unresolved);
        clearBoundNames([$node->keyVar, $node->valueVar], $varTypes);
        unitDependencyWalk($node->stmts, $varTypes, $production, $targets, $unresolved);

        return;
    }
    if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name
        && isset($production[(string) $node->class])) {
        $targets[(string) $node->class . '::__construct'] = true;
    }
    // A method named at runtime keys no call, whatever its receiver resolved to: it is a site this pass could
    // not attribute, which is what the guard counts (*What the figures do not say*).
    if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
        && isset($production[(string) $node->class])) {
        $node->name instanceof Node\Identifier
            ? $targets[(string) $node->class . '::' . $node->name->toString()] = true
            : ++$unresolved;
    }
    // A nullsafe call reaches production the same way an ordinary one does.
    if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
        $receiver = callReceiverType($node, $varTypes, $production);
        if ($receiver !== null) {
            // The receiver is production, so this is a call into it; a method named at runtime is one the pass
            // cannot key, which is a site it could not attribute and not a call it can count.
            $node->name instanceof Node\Identifier
                ? $targets[$receiver . '::' . $node->name->toString()] = true
                : ++$unresolved;
        } elseif (!isReceiverResolved($node, $varTypes)) {
            ++$unresolved;
        }
    }

    foreach ($node->getSubNodeNames() as $name) {
        unitDependencyWalk($node->$name, $varTypes, $production, $targets, $unresolved);
    }
}

/**
 * Whether the pass knows what a call's receiver is, even where that is nothing it counts: `$this`, a variable
 * it typed to something outside production, or a construction — including one whose class is named at runtime,
 * which `unresolvedSites` has already counted once.
 *
 * @param array<string, string> $varTypes
 */
function isReceiverResolved(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, array $varTypes): bool
{
    if ($call->var instanceof Node\Expr\Variable && is_string($call->var->name)) {
        return $call->var->name === 'this' || isset($varTypes[$call->var->name]);
    }

    return $call->var instanceof Node\Expr\New_;
}

/**
 * The production type a call's receiver resolves to, or null where it resolves to nothing or to something
 * outside production: a variable the test typed or constructed, or a construction used as the receiver itself,
 * `(new Svc())->calculate()`.
 *
 * @param array<string, string> $varTypes
 * @param array<string, true>   $production
 */
function callReceiverType(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, array $varTypes, array $production): ?string
{
    if ($call->var instanceof Node\Expr\Variable && is_string($call->var->name)) {
        $type = $varTypes[$call->var->name] ?? null;

        return $type !== null && isset($production[$type]) ? $type : null;
    }
    if ($call->var instanceof Node\Expr\New_ && $call->var->class instanceof Node\Name
        && isset($production[(string) $call->var->class])) {
        return (string) $call->var->class;
    }

    return null;
}

/**
 * Calls per test method for each test class in one blob: unique production calls summed over the class's test
 * methods, divided by the number of them. A class with no test method has no value (*The three measures*).
 *
 * @param array<string, true> $production
 *
 * @return array<string, array{methods: int, calls: int, unresolved: int, callsPerMethod: float}>
 */
function callsPerTestMethod(string $code, array $production): array
{
    global $nodeFinder;

    $ast = parseCode($code);
    if ($ast === null) {
        return [];
    }
    $classes = [];
    foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class) as $class) {
        // Name resolution gives every declared class a namespaced name; an anonymous one has none, and its
        // methods are its own (*The three measures*, Test unit dependency).
        if ($class->namespacedName === null) {
            continue;
        }
        $name = (string) $class->namespacedName;
        $methods = 0;
        $calls = 0;
        $unresolved = 0;
        foreach ($class->getMethods() as $method) {
            // A declaration with no body makes no call and is no test method to divide by — the same reading
            // the production side gives it, and for the same reason: admitting it would move `n` without a
            // test being written (*The three measures*).
            if ($method->stmts === null || !isTestMethod($method)) {
                continue;
            }
            ++$methods;
            $counts = unitDependency($method, $production);
            $calls += $counts['calls'];
            $unresolved += $counts['unresolved'];
        }
        if ($methods > 0) {
            $classes[$name] = ['methods' => $methods, 'calls' => $calls, 'unresolved' => $unresolved,
                'callsPerMethod' => $calls / $methods];
        }
    }

    return $classes;
}

/**
 * The paths one increment changed against its baseline: a status code, the path at the increment and the path
 * at the baseline, which differ only for a rename.
 *
 * Read as a diff between the two commits rather than as the increment's own patch. A merge commit is a patch
 * against no single parent, so `git show` prints no file for one and the whole increment would read as empty;
 * and where a baseline is named explicitly, the file set has to be the one the contents are read against.
 *
 * @return list<array{code: string, new: string, old: string}>
 */
function changedPaths(string $incrementSha, string $baselineSha): array
{
    $entries = [];
    $diff = git('diff --name-status -M ' . escapeshellarg($baselineSha) . ' ' . escapeshellarg($incrementSha));
    foreach (explode("\n", trim($diff)) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line);
        $entries[] = ['code' => $parts[0][0], 'new' => $parts[count($parts) - 1], 'old' => $parts[1]];
    }

    return $entries;
}

/**
 * The baseline an increment is read against where none was named: its parent, or the empty tree where it has
 * none. A root commit added every file it names, and each of them has an increment side to read.
 */
function defaultBaseline(string $incrementSha): string
{
    $parent = trim(git('rev-parse --verify --quiet ' . escapeshellarg($incrementSha . '^')));

    return $parent === '' ? trim(git('hash-object -t tree /dev/null')) : $parent;
}

/** The tests/**Test.php files a commit touched. Deletions leave the touched test files entirely (*Input*). */
function touchedTestFiles(string $incrementSha, string $baselineSha): array
{
    $paths = [];
    foreach (changedPaths($incrementSha, $baselineSha) as $entry) {
        if ($entry['code'] !== 'D' && str_starts_with($entry['new'], 'tests/')
            && str_ends_with($entry['new'], 'Test.php')) {
            $paths[] = $entry;
        }
    }

    return $paths;
}

/**
 * Test unit dependency over the increment's touched test files, aggregated per side exactly as the production
 * measures are: unique production calls summed over those files, divided by the test methods that made them,
 * read once at the baseline and once at the increment (*The three measures*, *Output*).
 *
 * Pooling over the touched test files rather than averaging the per-class figures is what lets an added class
 * count. A class the increment added exists on the increment side only, so it raises or lowers that side's
 * calls per method and is judged against the rest of them — never against zero, and never set aside for having
 * no baseline of its own. A class the increment deleted from a surviving file leaves the increment side the
 * same way. This is the rule the production measures already use: an added file lands in the increment-side fan
 * out rate alone, and an added method lands in the cognitive complexity total alone, which is why `n` is
 * printed per side there and here.
 *
 * Per-class rows come back beside the aggregate as detail. They are printed, never summed — the measure is
 * the aggregate.
 *
 * The mock seam probe reads the same touched test files, at the increment, and prints beneath this measure
 * (*Grounding the probes*).
 *
 * @return array{
 *     baseline: float, increment: float, state: string,
 *     nBaseline: int, nIncrement: int, methodsBaseline: int, methodsIncrement: int,
 *     callsBaseline: int, callsIncrement: int, unresolvedBaseline: int, unresolvedIncrement: int,
 *     rows: list<array{class: string, baseline: ?float, increment: ?float, state: string}>,
 *     mockSeam: list<array{class: string, state: string}>
 * }|null
 */
function measureTestFiles(string $incrementSha, string $baselineSha): ?array
{
    $files = touchedTestFiles($incrementSha, $baselineSha);
    if ($files === []) {
        return null;
    }

    $sides = ['baseline' => [], 'increment' => []];
    $mocked = [];
    foreach ($files as $entry) {
        $incrementCode = blob($incrementSha, $entry['new']);
        if ($incrementCode !== null) {
            foreach (callsPerTestMethod($incrementCode, productionNames($incrementSha)) as $class => $counts) {
                $sides['increment'][$class] = $counts;
            }
            foreach (mockSeamProbe($incrementCode) as $row) {
                $mocked[$row['class']] = $row;
            }
        }
        $baselineCode = $entry['code'] === 'A' ? null : blob($baselineSha, $entry['old']);
        if ($baselineCode !== null) {
            foreach (callsPerTestMethod($baselineCode, productionNames($baselineSha)) as $class => $counts) {
                $sides['baseline'][$class] = $counts;
            }
        }
    }
    if ($sides['increment'] === [] && $sides['baseline'] === []) {
        return null;
    }

    // A side with no test method has no rate to take, the way a side with no production file has none: a 0.00
    // there would read as a rate that held, and the group that reads `calls per test method` would settle on a
    // figure the pass never took (*The three measures*, Test unit dependency). The total beside it is still a
    // total, and the state is read off that.
    $pooled = static function (array $classes): ?float {
        $methods = array_sum(array_column($classes, 'methods'));

        return $methods === 0 ? null : array_sum(array_column($classes, 'calls')) / $methods;
    };
    $baseline = $pooled($sides['baseline']);
    $increment = $pooled($sides['increment']);
    $callsBaseline = (int) array_sum(array_column($sides['baseline'], 'calls'));
    $callsIncrement = (int) array_sum(array_column($sides['increment'], 'calls'));

    // Detail only: every class of the increment side, plus its own transition where it has one.
    $rows = [];
    foreach ($sides['increment'] as $class => $now) {
        $was = $sides['baseline'][$class] ?? null;
        // Carried at the precision the row prints at, so the state beside the two figures is read off the two
        // figures the reader sees and never off a difference the report withheld.
        $rows[] = ['class' => $class,
            'baseline' => $was === null ? null : round($was['callsPerMethod'], 2),
            'increment' => round($now['callsPerMethod'], 2),
            'state' => $was === null ? 'added' : stateOf($was['callsPerMethod'], $now['callsPerMethod'])];
    }

    return [
        'baseline' => $baseline === null ? null : round($baseline, 2),
        'increment' => $increment === null ? null : round($increment, 2),
        // The total leads the measure, so it is the total the state is read off: `calls / methods` divides by a
        // count the increment itself moves (*The three measures*, Test unit dependency).
        'state' => $callsBaseline === $callsIncrement ? 'unmoved' : 'live',
        'nBaseline' => count($sides['baseline']), 'nIncrement' => count($sides['increment']),
        'methodsBaseline' => (int) array_sum(array_column($sides['baseline'], 'methods')),
        'methodsIncrement' => (int) array_sum(array_column($sides['increment'], 'methods')),
        'callsBaseline' => $callsBaseline,
        'callsIncrement' => $callsIncrement,
        'unresolvedBaseline' => (int) array_sum(array_column($sides['baseline'], 'unresolved')),
        'unresolvedIncrement' => (int) array_sum(array_column($sides['increment'], 'unresolved')),
        'rows' => $rows,
        'mockSeam' => array_values($mocked),
    ];
}

/** Whether one path is in the measured set at all — production PHP under `src/`, or a test class (*Input*). */
function isMeasuredPath(string $path): bool
{
    return (str_starts_with($path, 'src/') && str_ends_with($path, '.php'))
        || (str_starts_with($path, 'tests/') && str_ends_with($path, 'Test.php'));
}

/**
 * What the measured set leaves out, so the unmeasured share of the increment stays visible: every other
 * path the increment touched with its line count at the increment, and every path it deleted, which is
 * named and not scored.
 *
 * @return array{unmeasured: list<array{path: string, lines: int}>, deleted: list<string>}
 */
function unmeasuredFiles(string $incrementSha, string $baselineSha): array
{
    $unmeasured = [];
    $deleted = [];
    foreach (changedPaths($incrementSha, $baselineSha) as $entry) {
        $path = $entry['new'];
        if ($entry['code'] === 'D') {
            $deleted[] = $path;
            continue;
        }
        // A rename that carries a file out of the measured set leaves that set the way a deletion does: the
        // path is named and not scored, and the file it became is unmeasured like any other path.
        if ($entry['code'] === 'R' && isMeasuredPath($entry['old']) && !isMeasuredPath($path)) {
            $deleted[] = $entry['old'];
        }
        if (isMeasuredPath($path)) {
            continue;
        }
        $code = blob($incrementSha, $path);
        // A last line without a newline behind it is still a line.
        $unmeasured[] = ['path' => $path, 'lines' => match (true) {
            $code === null => 0,
            str_ends_with($code, "\n") => substr_count($code, "\n"),
            default => substr_count($code, "\n") + 1,
        }];
    }

    return ['unmeasured' => $unmeasured, 'deleted' => $deleted];
}

/**
 * The src files a commit touched, deletions excluded — there is nothing left to score in a deleted file.
 *
 * A file renamed into `src/` from outside it has no baseline side within the measured set: at the baseline it
 * was not production code, so it reads as added rather than as a file whose figures moved (*Input*).
 */
function touchedSrcFiles(string $incrementSha, string $baselineSha): array
{
    $paths = [];
    foreach (changedPaths($incrementSha, $baselineSha) as $entry) {
        $path = $entry['new'];
        if ($entry['code'] === 'D' || !str_starts_with($path, 'src/') || !str_ends_with($path, '.php')) {
            continue;
        }
        $old = $entry['old'];
        $paths[] = str_starts_with($old, 'src/') && str_ends_with($old, '.php')
            ? ['code' => $entry['code'], 'new' => $path, 'old' => $old]
            : ['code' => 'A', 'new' => $path, 'old' => $path];
    }

    return $paths;
}

/**
 * Score one increment. Both measures are computed over the same touched production files, at the parent
 * commit and at the increment, and reported as a transition plus a state (*Output*).
 */
function measureIncrement(string $incrementSha, string $baselineSha): ?array
{
    global $cognitiveSource;

    $files = touchedSrcFiles($incrementSha, $baselineSha);
    if ($files === []) {
        return null;
    }

    $sides = [];
    foreach (['baseline' => $baselineSha, 'increment' => $incrementSha] as $side => $sha) {
        $internal = $external = $fileCount = $unresolved = 0;
        $complexities = [];
        $classes = [];
        foreach ($files as $entry) {
            $path = $side === 'baseline' ? $entry['old'] : $entry['new'];
            $code = blob($sha, $path);
            $ast = $code === null ? null : parseCode($code);
            if ($ast === null) {
                continue;
            }
            $out = fanOut($ast);
            $internal += $out['internal'];
            $external += $out['external'];
            $unresolved += unresolvedSites($ast);
            ++$fileCount;
            $complexities += $cognitiveSource($sha, $path, $ast);
            $classes += classLikesIn($ast);
        }
        $sides[$side] = [
            'files' => $fileCount,
            // No file on this side is no rate on this side. A zero would read as a figure it never had.
            'internal' => $fileCount > 0 ? $internal / $fileCount : null,
            'external' => $fileCount > 0 ? $external / $fileCount : null,
            'unresolved' => $unresolved,
            'methods' => $complexities,
            'classes' => $classes,
        ];
    }

    // Cognitive complexity is read over the touched methods, pooled across every touched production file and
    // summed rather than averaged. The measured population is the union of both sides: a method whose complexity
    // moved, a method the increment added, and a method it deleted or renamed away, which lives on the baseline
    // side with nothing opposite it. A method left alone is in neither side — it tells us nothing about its author.
    //
    // The total is deliberate, and it is what makes a relocated abstraction visible. Any per-method rate divides
    // by a count the increment itself moves, so it can fall while reading cost rises: four trivial methods added
    // beside one method doubled read as an improvement, and an extraction into small methods reads as one by
    // construction (*What the figures do not say*). A total cannot invert, at the price of being a magnitude
    // rather than a rate — it grows with the size of the increment, which is why n is printed beside it, with
    // what it counts.
    $baselineTouched = [];
    $incrementTouched = [];
    $attribution = [];
    $fallExisting = $fallDeleted = $riseAdded = $riseExisting = 0;
    $names = array_keys($sides['baseline']['methods'] + $sides['increment']['methods']);
    foreach ($names as $name) {
        $was = $sides['baseline']['methods'][$name] ?? null;
        $now = $sides['increment']['methods'][$name] ?? null;
        if ($was === $now) {
            continue;
        }
        if ($was !== null) {
            $baselineTouched[] = $was;
        }
        if ($now !== null) {
            $incrementTouched[] = $now;
        }
        // Attribution, and the figures control flow leaving reads off the same union: where a fall landed and
        // whether the method it fell in is still there, against what the added methods brought (*Grounding the
        // probes*). A renamed method reads as one deleted and one added.
        $attribution[] = ['method' => $name, 'baseline' => $was, 'increment' => $now, 'state' => match (true) {
            $was === null => 'added',
            $now === null => 'deleted',
            $now < $was => 'fell',
            default => 'rose',
        }];
        match (true) {
            $was === null => $riseAdded += $now,
            $now === null => $fallDeleted += $was,
            $now < $was => $fallExisting += $was - $now,
            default => $riseExisting += $now - $was,
        };
    }

    // The classes and abstractions the increment added among the files it touched — what the delegation ratio
    // reads over, and what the implementations probe counts implementations of.
    $addedClasses = array_diff_key($sides['increment']['classes'], $sides['baseline']['classes']);
    // A trait is an abstraction the same way: nothing instantiates it, and what a class does with it is name
    // it in a `use` clause — which is why the probe counts implementations "implementing, extending or using".
    $addedAbstractions = array_filter($addedClasses, static fn (Node\Stmt\ClassLike $classLike): bool => $classLike instanceof Node\Stmt\Interface_
        || $classLike instanceof Node\Stmt\Trait_
        || ($classLike instanceof Node\Stmt\Class_ && $classLike->isAbstract()));

    // A side with no file has no rate to feed the formula. Reading its 0.00 references as a score would hand it
    // a perfect 100 and an `A`, and every increment whose touched production files are all new would read as
    // having got worse across files. There is nothing to read on that side, which is what `absent` means.
    $fanBaseline = $sides['baseline']['files'] > 0
        ? fanOutScore($sides['baseline']['internal'], $sides['baseline']['external']) : null;
    $fanIncrement = $sides['increment']['files'] > 0
        ? fanOutScore($sides['increment']['internal'], $sides['increment']['external']) : null;
    $rate = static fn (?float $figure): ?float => $figure === null ? null : round($figure, 2);
    // The rate is the two kinds added as the reader adds them, from the figures printed beside it, so the line
    // holds together: parts that print 12.88 and 9.88 total 22.76 and never 22.75 (*Output*).
    $refsBaseline = $sides['baseline']['internal'] === null
        ? null : $rate($sides['baseline']['internal']) + $rate($sides['baseline']['external']);
    $refsIncrement = $sides['increment']['internal'] === null
        ? null : $rate($sides['increment']['internal']) + $rate($sides['increment']['external']);

    // Both measures print a figure per side: the difference of two printed figures is the reader's
    // subtraction, not a measurement of its own (*Output*). n is printed per side too, never as one
    // number — a file the increment added has no baseline-side fan out, and a method it added has no
    // baseline-side complexity, so each side runs over a different set.
    return [
        'sha' => $incrementSha,
        'files' => $sides['increment']['files'],
        'fan' => [
            'refsBaseline' => $refsBaseline, 'refsIncrement' => $refsIncrement,
            'internalBaseline' => $rate($sides['baseline']['internal']),
            'externalBaseline' => $rate($sides['baseline']['external']),
            'internalIncrement' => $rate($sides['increment']['internal']),
            'externalIncrement' => $rate($sides['increment']['external']),
            'scoreBaseline' => $rate($fanBaseline),
            'scoreIncrement' => $rate($fanIncrement),
            // The band is read off the score as printed: a reader applying TPM's cuts to the printed 80.00
            // reads `B`, and a band from under the cut would contradict the figure beside it.
            'bandBaseline' => $fanBaseline === null ? null : band($rate($fanBaseline)),
            'bandIncrement' => $fanIncrement === null ? null : band($rate($fanIncrement)),
            'nBaseline' => $sides['baseline']['files'], 'nIncrement' => $sides['increment']['files'],
            'state' => stateOf($fanBaseline, $fanIncrement),
        ],
        'cognitive' => [
            'nBaseline' => count($baselineTouched), 'nIncrement' => count($incrementTouched),
            'totalBaseline' => array_sum($baselineTouched), 'totalIncrement' => array_sum($incrementTouched),
            // The state is read off the figure the measure reports, which is the total: two sides that differ
            // are `live`, two that agree are `unmoved` — not the same as reading zero — and nothing to read on
            // either side is `absent` (*Output*).
            'state' => match (true) {
                $baselineTouched === [] && $incrementTouched === [] => 'absent',
                array_sum($baselineTouched) === array_sum($incrementTouched) => 'unmoved',
                default => 'live',
            },
            'attribution' => $attribution,
            'leaving' => ['fallExisting' => $fallExisting, 'fallDeleted' => $fallDeleted,
                // ponytail: the label does not weigh the two against each other, so a fall of 1 beside a rise
                // of 40 still reads relocated. The figures are printed; weigh them if that ever misleads.
                'riseAdded' => $riseAdded, 'riseExisting' => $riseExisting,
                'state' => $fallExisting > 0 ? 'relocated' : 'written'],
        ],
        'delegation' => delegationProbe($sides['increment']['classes']),
        'implementations' => implementationsProbe($addedAbstractions, $incrementSha),
        'unresolved' => [
            'baseline' => $sides['baseline']['unresolved'],
            'increment' => $sides['increment']['unresolved'],
        ],
    ];
}

// ---------------------------------------------------------------- the two questions

/**
 * The state a measure carries between its two sides: `absent` where a side had nothing to read, `unmoved`
 * where the two sides print the same figure, `live` where they do not (*Output*). Read through `direction`,
 * so a state and the block that reads the same two figures can never disagree.
 */
function stateOf(?float $baseline, ?float $increment): string
{
    return match (true) {
        $baseline === null || $increment === null => 'absent',
        direction($baseline, $increment) === 'unmoved' => 'unmoved',
        default => 'live',
    };
}

/** Which way a figure went between the two sides. */
function direction(float $baseline, float $increment): string
{
    // Read at the precision the figure is reported at. Every figure here prints to the cent, and the difference
    // between two printed figures is the reader's subtraction (*Output*) — so two sides that print the same
    // have not moved, and a state or a group chosen off a difference the report never showed would be a
    // reading of its own.
    $was = round($baseline, 2);
    $now = round($increment, 2);

    return match (true) {
        $now > $was => 'up',
        $now < $was => 'down',
        default => 'unmoved',
    };
}

/**
 * Question 1 — did the increment add an abstraction it did not need? Read `n` touched production files against
 * the cognitive complexity total, then the test measure against both, and let the probes part the outcomes
 * those figures carry more than one of (*Question 1 — an abstraction that was not needed*).
 *
 * The block is not a figure: it names what the measures showed, which probes were read against them, and the
 * outcome that leaves. Where a group needs the test measure and the test measure is absent, it is not read.
 *
 * @return array{figures: string, probes: string, outcome: string}
 */
function questionOne(?array $result, ?array $test): array
{
    if ($result === null) {
        return ['figures' => 'no production file touched', 'probes' => '', 'outcome' => 'not readable — the production measures are absent'];
    }

    $filesDirection = direction($result['fan']['nBaseline'], $result['fan']['nIncrement']);
    // No method to read is no transition to read: a measure that was never read is `absent`, which is not the
    // `unmoved` of two sides that were read and held (*Output*). Every group names one direction or the other.
    $cognitiveDirection = $result['cognitive']['state'] === 'absent'
        ? 'absent'
        : direction($result['cognitive']['totalBaseline'], $result['cognitive']['totalIncrement']);
    // A side that read no file carries no rate to compare, which the fifth group needs on both sides.
    $internalDirection = $result['fan']['internalBaseline'] === null || $result['fan']['internalIncrement'] === null
        ? 'absent'
        : direction($result['fan']['internalBaseline'], $result['fan']['internalIncrement']);
    $leaving = $result['cognitive']['leaving'];

    // A side with no test method took no rate, so there is no transition to read on that half — the same
    // reading a side with no production file gets on fan out.
    $methodsDirection = $test === null ? null : direction($test['methodsBaseline'], $test['methodsIncrement']);
    $callsDirection = match (true) {
        $test === null => null,
        $test['baseline'] === null || $test['increment'] === null => 'absent',
        default => direction($test['baseline'], $test['increment']),
    };
    $testClause = $test === null
        ? '; test measure absent'
        : sprintf('; test methods %s, calls per test method %s', $methodsDirection, $callsDirection);
    // The fifth group is the one that reads the reference rate, and the table names it between the two
    // production figures rather than after the test side.
    $figuresWith = static fn (string $middle): string => sprintf('n production files %s%s, cognitive complexity total %s',
        $filesDirection, $middle, $cognitiveDirection) . $testClause;
    $figures = $figuresWith('');


    // The fourth group is told apart on the production side alone, so it is read before the test measure is
    // asked for (*Question 1*).
    if ($filesDirection === 'up' && $cognitiveDirection === 'down') {
        $probes = sprintf('control flow leaving — fall %d in method(s) that still exist, fall %d in method(s) deleted',
            $leaving['fallExisting'], $leaving['fallDeleted']);

        return ['figures' => $figures, 'probes' => $probes, 'outcome' => $leaving['fallExisting'] > 0
            ? 'real decomposition — the split removed reading cost'
            : 'deletion, not decomposition'];
    }

    if ($filesDirection === 'unmoved' && $internalDirection === 'up' && $cognitiveDirection === 'up') {
        return ['figures' => $figuresWith(', internal references per touched production file up'),
            'probes' => 'none applies — no file was added, so there is no abstraction that could have been unnecessary',
            'outcome' => 'ordinary work — a fix or a feature reaching further'];
    }

    if ($filesDirection !== 'up') {
        return ['figures' => $figures, 'probes' => '', 'outcome' => 'not detected'];
    }
    // Every group left needs the cognitive total, so a measure that read nothing leaves them unreadable. The
    // groups above needed no more than the production file count, which was read, so their answer stands.
    if ($cognitiveDirection === 'absent') {
        return ['figures' => $figures, 'probes' => '',
            'outcome' => 'not readable — the cognitive complexity total is absent'];
    }
    // The rate is read by the three groups below and by nothing else, and each of them pins the test-method
    // direction too. So a rate that was never taken leaves the block unreadable only where the figures beside
    // it would have reached one of those groups; anywhere else the question is answered without it.
    $rateWouldBeRead = ($cognitiveDirection === 'unmoved' && $methodsDirection === 'unmoved')
        || ($cognitiveDirection === 'up' && ($methodsDirection === 'up' || $methodsDirection === 'unmoved'));
    if ($methodsDirection === null || ($callsDirection === 'absent' && $rateWouldBeRead)) {
        return ['figures' => $figures, 'probes' => '', 'outcome' => 'not readable — the test measure is absent'];
    }

    // First group: names added, nothing simplified — unless one of the three probes points the other way.
    if ($cognitiveDirection === 'unmoved' && $methodsDirection === 'unmoved' && $callsDirection === 'up') {
        return questionOneFirstGroup($figures, $result, $test);
    }

    // Second and third group share their production figures and are told apart on the test side alone.
    if ($cognitiveDirection === 'up' && $methodsDirection === 'up' && $callsDirection === 'unmoved') {
        // What this group reads is whether control flow was written or moved, so what counts is a fall on the
        // baseline side — in a method that survives or in one the increment took out, both being control flow
        // that was there before (*Question 1*). Which of the two it was is the fourth group's question.
        $probes = sprintf('attribution — rise %d in method(s) added, rise %d in method(s) already there; control flow leaving — fall %d in method(s) that still exist, fall %d in method(s) deleted',
            $leaving['riseAdded'], $leaving['riseExisting'], $leaving['fallExisting'], $leaving['fallDeleted']);

        // Ceremony is the reading where the added files stay thin and the rise sits in what was already there.
        // A rise on both sides of that split is neither row: the added files carry something, and so does the
        // unit that was there — which is two readings and not one, so the block says so rather than picking.
        return ['figures' => $figures, 'probes' => $probes, 'outcome' => match (true) {
            $leaving['fallExisting'] + $leaving['fallDeleted'] > 0 => 'mixed — a refactor and a feature in one increment',
            $leaving['riseAdded'] > 0 && $leaving['riseExisting'] === 0 => 'real work — the new control flow is new behaviour',
            $leaving['riseAdded'] > 0 => 'not settled — the rise landed on both sides of the attribution split',
            default => 'ceremony — the new files carry nothing',
        }];
    }

    if ($cognitiveDirection === 'up' && $methodsDirection === 'unmoved' && $callsDirection === 'up') {
        return questionOneThirdGroup($figures, $result);
    }

    return ['figures' => $figures, 'probes' => '', 'outcome' => 'not detected'];
}

/**
 * The first group — files added, no control flow with them, the same test methods reaching further. Any one
 * probe pointing the other way is enough for real work, whatever the others had to read; ceremony is the
 * reading that needs all three, being the claim that nothing points anywhere else. A `partial` row is neither
 * shape and enters neither side of the agreement (*Question 1*).
 *
 * @return array{figures: string, probes: string, outcome: string}
 */
function questionOneFirstGroup(string $figures, array $result, array $test): array
{
    $read = [];
    $agree = [];
    foreach ([['delegation ratio', $result['delegation'], 'pass-through'],
        ['implementations', $result['implementations'], 'single'],
        ['mock seam', $test['mockSeam'], 'internal']] as [$probe, $rows, $ceremony]) {
        if ($rows === []) {
            $read[] = $probe . ' had nothing to read';
            continue;
        }
        // `partial` is the delegation ratio's neither-shape label: a class forwarding some of its bodies and
        // not others is no Middle Man and no adapter, so the row neither agrees nor disagrees (*Question 1*).
        // Counting it as disagreement would let any touched class that happens to forward once decide the group.
        $states = array_values(array_diff(array_unique(array_column($rows, 'state')), ['partial']));
        if ($states === []) {
            $read[] = $probe . ' partial only, neither shape';
            continue;
        }
        $agree[] = count($states) === 1 && $states[0] === $ceremony;
        $read[] = $probe . ' ' . implode('/', $states);
    }
    $probes = implode(', ', $read);
    // Any one probe pointing the other way is enough, and it says so whatever the others had to read: the
    // adapter or the polymorphism it found is there either way. Ceremony is the reading that needs all three,
    // being the claim that nothing points anywhere else (*Question 1*).
    if (in_array(false, $agree, true)) {
        return ['figures' => $figures, 'probes' => $probes,
            'outcome' => 'real work — the new file earns its place'];
    }
    if (count($agree) < 3) {
        return ['figures' => $figures, 'probes' => $probes, 'outcome' => 'not settled — a probe this group needs had nothing to read'];
    }

    return ['figures' => $figures, 'probes' => $probes . ' — all three agree',
        'outcome' => 'ceremony — names added, nothing simplified'];
}

/**
 * The third group — files added, control flow added with them, and the same test methods reaching further.
 * Ceremony that branches only where the rise came in with the methods the increment added and there is one
 * implementation to select among; a rise in a method that was already there is an existing unit taking on a
 * rule (*Question 1*).
 *
 * @return array{figures: string, probes: string, outcome: string}
 */
function questionOneThirdGroup(string $figures, array $result): array
{
    $implementations = array_unique(array_column($result['implementations'], 'state'));
    $single = $implementations === ['single'];

    // Attribution says where the rise landed: in a method the increment added, or in one that was already
    // there (*Question 1*).
    $inAdded = false;
    $inExisting = false;
    foreach ($result['cognitive']['attribution'] as $row) {
        // A method carrying no control flow is no rise, whichever side it landed on.
        if (($row['state'] !== 'rose' && $row['state'] !== 'added') || ($row['increment'] ?? 0) === 0) {
            continue;
        }
        $row['state'] === 'added' ? $inAdded = true : $inExisting = true;
    }

    $probes = sprintf('implementations %s, attribution %s',
        $implementations === [] ? 'had nothing to read' : implode('/', $implementations),
        match (true) {
            $inAdded && !$inExisting => 'rise in a method the increment added',
            $inExisting && !$inAdded => 'rise in a method that was already there',
            $inAdded => 'rise in both',
            default => 'had nothing to read',
        });

    return ['figures' => $figures, 'probes' => $probes, 'outcome' => $single && $inAdded && !$inExisting
        ? 'ceremony that branches — a factory or a dispatcher, not a feature'
        : 'real work — a fix or a rule the existing test methods already assert'];
}

/**
 * Question 2 — did the increment leave the code harder to read? Cognitive complexity answers the
 * inside-a-method half, fan out the across-files half, and each is read against its own polarity: fan out is
 * on TPM's score, where higher is better (*Question 2 — code that is harder to read*).
 *
 * @return array{figures: string, outcome: string}
 */
function questionTwo(?array $result): array
{
    if ($result === null) {
        return ['figures' => 'no production file touched', 'outcome' => 'not readable — the production measures are absent'];
    }
    // No method to read leaves the inside-a-method half unread, the same way a side with no file leaves the
    // across-files half unread. Neither is a transition, and neither is `unmoved`.
    $cognitive = $result['cognitive']['state'] === 'absent'
        ? 'absent'
        : direction($result['cognitive']['totalBaseline'], $result['cognitive']['totalIncrement']);
    $fan = $result['fan']['scoreBaseline'] === null || $result['fan']['scoreIncrement'] === null
        ? 'absent'
        : direction($result['fan']['scoreBaseline'], $result['fan']['scoreIncrement']);

    return [
        'figures' => sprintf('cognitive complexity total %s, fan out score %s', $cognitive, $fan),
        // A half that was read is read, whatever became of the other one: the reading is one-sided, so an
        // absent half only ever withholds what it would have added. Only where the half that moved is the
        // one that was never read is there nothing to say.
        'outcome' => match (true) {
            $cognitive === 'up' && $fan === 'down' => 'harder inside the methods and across the files both',
            $cognitive === 'up' => 'harder inside the methods — deeper nesting, more breaks in the linear flow',
            $fan === 'down' => 'harder across files — the same logic, in more files to open',
            $cognitive === 'absent' && $fan === 'absent' => 'not readable — neither half was read',
            $cognitive === 'absent' => 'not readable — the inside-a-method half is absent',
            $fan === 'absent' => 'not readable — the across-files half is absent',
            default => 'not detected — no measure here reads size',
        },
    ];
}

// ---------------------------------------------------------------- self test
//
// One case per behaviour the script decides, and each behaviour in exactly one place: the counting rules of the
// two measures counted here, the way an analysis is keyed onto methods, the shapes the five probes look for,
// and every outcome the two question blocks can reach. Snippet cases read no commit; the cases that do build a
// fixture repository, which declares per commit what its analysis would report, so nothing here asks a server.
//
// What it does not cover: the counting rules of cognitive complexity, which are the analyser's and not this
// script's, and the transport that fetches them — a server that answers is exercised by running the thing.

/** @var list<string> */
$failures = [];
/** @var array<string, array<string, int>> the figures the fixture declares its analysis would report */
$fixtureFigures = [];
$checks = 0;

function check(mixed $expected, mixed $actual, string $behaviour): void
{
    global $failures, $checks;

    ++$checks;
    if ($expected !== $actual) {
        $failures[] = sprintf('%s: expected %s, got %s', $behaviour,
            json_encode($expected), json_encode($actual));
    }
}

/** @return list<Node\Stmt> */
function parseSnippet(string $code): array
{
    return parseCode("<?php\n" . $code) ?? [];
}


/** One method of a snippet, by its `Class::method` key. */
function methodOf(string $code, string $key): Node\Stmt\ClassMethod
{
    return methodNodes(parseSnippet($code))[$key];
}

/** One class-like of a snippet, by its name. */
function classOf(string $code, string $name): Node\Stmt\ClassLike
{
    return classLikesIn(parseSnippet($code))[$name];
}

/**
 * Figures for the question blocks, shaped as `measureIncrement` returns them. Only what a block reads is
 * filled in; every case names the figures it is about and takes the rest from here.
 */
function figures(array $over = []): array
{
    $result = [
        'files' => 1,
        'fan' => ['nBaseline' => 1, 'nIncrement' => 1, 'internalBaseline' => 1.0, 'internalIncrement' => 1.0,
            'externalBaseline' => 1.0, 'externalIncrement' => 1.0, 'refsBaseline' => 2.0, 'refsIncrement' => 2.0,
            'scoreBaseline' => 80.0, 'scoreIncrement' => 80.0, 'bandBaseline' => 'B', 'bandIncrement' => 'B',
            'state' => 'unmoved'],
        'cognitive' => ['nBaseline' => 1, 'nIncrement' => 1, 'totalBaseline' => 5, 'totalIncrement' => 5,
            'state' => 'unmoved', 'attribution' => [],
            'leaving' => ['fallExisting' => 0, 'fallDeleted' => 0, 'riseAdded' => 0, 'riseExisting' => 0,
                'state' => 'written']],
        'delegation' => [], 'implementations' => [],
        'unresolved' => ['baseline' => 0, 'increment' => 0],
    ];
    foreach ($over as $key => $value) {
        $result[$key] = is_array($value) && isset($result[$key]) && $key !== 'delegation'
            && $key !== 'implementations'
            ? array_merge($result[$key], $value)
            : $value;
    }

    return $result;
}

/** Test-side figures, shaped as `measureTestFiles` returns them. */
function testFigures(int $methodsBaseline, int $methodsIncrement, ?float $callsBaseline, ?float $callsIncrement, array $mockSeam = [], array $over = []): array
{
    return array_merge(['methodsBaseline' => $methodsBaseline, 'methodsIncrement' => $methodsIncrement,
        'baseline' => $callsBaseline, 'increment' => $callsIncrement, 'mockSeam' => $mockSeam,
        'callsBaseline' => 4, 'callsIncrement' => 6, 'nBaseline' => 1, 'nIncrement' => 1, 'state' => 'live',
        'rows' => [], 'unresolvedBaseline' => 0, 'unresolvedIncrement' => 0], $over);
}

function selfTest(): int
{
    global $failures;

    // --- Fan out: what counts as a reference, and which side of the internal/external split it lands on.
    check(['internal' => 1, 'external' => 1],
        fanOut(parseSnippet('namespace App\A; use App\B\Beta; use Vendor\Gamma; class T { public function m(Beta $b, Gamma $g): void {} }')),
        'fan out splits App\ from everything else, and never counts the use statement itself');
    check(['internal' => 0, 'external' => 0],
        fanOut(parseSnippet('namespace App\A; use App\B\Unused; class T {}')),
        'fan out drops an import the file never uses');
    check(['internal' => 1, 'external' => 0],
        fanOut(parseSnippet('namespace App\A; class T extends \App\A\P { public function m(): void { self::x(); static::y(); parent::z(); } }')),
        'fan out skips self, static and parent, each already named by the class it sits in');
    check(['internal' => 1, 'external' => 0],
        fanOut(parseSnippet('namespace App\A; class T extends \App\A\P { public function m(): void { SELF::x(); STATIC::y(); Parent::z(); } }')),
        'and skips them however they are spelled, a class name being case-insensitive in PHP — only the extends clause counts');
    check(['internal' => 0, 'external' => 1],
        fanOut(parseSnippet('namespace App\A; use AppBundle\Legacy; class T { public function m(Legacy $l): void {} }')),
        'the internal split is on the namespace App\ and not on the three letters it starts with');
    check(['internal' => 0, 'external' => 0],
        fanOut(parseSnippet('namespace App\A; class T { public function m(): void { $a = T::class; } }')),
        'fan out skips the class the file declares itself');
    check(['internal' => 0, 'external' => 0],
        fanOut(parseSnippet('namespace App\A; class T { public function m(): void { count([]); } }')),
        'fan out skips a function call, which names no file');
    check(['internal' => 0, 'external' => 0],
        fanOut(parseSnippet('namespace App\A; class T { public function m(): void { $b = null; $c = E_USER_DEPRECATED; } }')),
        'fan out skips a constant, which names no file either');
    check(['internal' => 0, 'external' => 0], fanOut(parseSnippet('namespace App\A; class T {}')),
        'fan out skips the namespace the file declares, which would otherwise weigh in every file');
    check(['internal' => 1, 'external' => 0],
        fanOut(parseSnippet('namespace App\A; class T { use \App\A\Helper; }')),
        'fan out counts a trait the class uses');
    check(['internal' => 1, 'external' => 1],
        fanOut(parseSnippet('namespace App\A; use App\B\Beta; use Vendor\Gamma; class T { public function m(Beta $b, Gamma $g): Beta { return new Beta(); } }')),
        'fan out counts each type once however often the file names it — it is a dependency between files');
    check(['internal' => 0, 'external' => 1],
        fanOut(parseSnippet('namespace App\A; class T { public function m(): void { $d = new \DateTime(); } }')),
        'fan out counts a platform class, inline and as external');
    check(['internal' => 1, 'external' => 0],
        fanOut(parseSnippet('namespace App\A; use App\B\{Beta, Unused}; class T { public function m(Beta $b): void {} }')),
        'fan out reads a grouped import the same way, and never counts the group itself');
    check(null, parseCode('<?php class T {'), 'a file that does not parse yields nothing to read');

    // --- Cognitive complexity comes from the analysis of the commit, keyed onto the methods of this checkout.
    $rows = static fn (array ...$pairs): array => array_map(static fn (array $p): array => ['line' => $p[0], 'cc' => $p[1]], $pairs);
    $two = "namespace App;\nclass T\n{\n    public function m()\n    {\n        if (\$a) {}\n    }\n    public function n()\n    {\n        if (\$b) {}\n    }\n}";
    $closure = "namespace App;\nclass T\n{\n    public function m()\n    {\n        \$f = function () { if (\$a) {} };\n    }\n}";

    check([null, 'unread', 'silent', 'silent'], [
        analysisFault(0, ['0' => true], 12), analysisFault(3, ['0' => true], 12),
        analysisFault(0, [], 12), analysisFault(0, [], 1)],
        'an analysis is at fault where it reported findings this run cannot read, and where it read files and reported nothing at all');
    check(null, analysisFault(0, [], 0),
        'but an analysis that read no file has nothing to report and nothing to be at fault for');
    check('threshold', analysisFault(0, ['15' => true], 12),
        'and one taken above threshold 0 reports only part of what it read, which is a fault of its own');

    $issues = static fn (string ...$messages): array => array_map(
        static fn (string $m): array => ['component' => 'p:A.php', 'line' => 7, 'message' => $m], $messages);
    check([['src/A.php' => [['line' => 7, 'cc' => 8]]], ['0' => true], 0],
        array_values(figuresIn($issues('Refactor this function to reduce its Cognitive Complexity from 8 to the 0 allowed.'), 'p')),
        'a finding names the figure it found and the threshold it was allowed, and lands under the file it was found in');
    check([[], [], 2], array_values(figuresIn($issues('Cognitive Complexity of this function is 5, above the limit of 0.',
        'Refactor this function.'), 'p')),
        'a finding in a shape this run cannot read is counted and never skipped, since skipping it would read as an analysis that found nothing');
    check([['15' => true, '0' => true], 0], [figuresIn($issues(
        'Cognitive Complexity from 20 to the 15 allowed', 'Cognitive Complexity from 2 to the 0 allowed'), 'p')['allowed'],
        figuresIn($issues('Cognitive Complexity from 2 to the 0 allowed'), 'p')['unread']],
        'and every threshold the messages were taken at is carried, so an analysis mixing two is not read as one');

    check(['0', '15', null, null], [
        activeThreshold(['actives' => ['php:S3776' => [['params' => [['key' => 'threshold', 'value' => '0']]]]]]),
        activeThreshold(['actives' => ['php:S3776' => [['params' => [['key' => 'threshold', 'value' => '15']]]]]]),
        activeThreshold(['actives' => ['php:S3776' => [['params' => []]]]]),
        activeThreshold(['actives' => []])],
        'the threshold a profile has the rule active at is read off the answer, and an answer saying it is not active names none');

    check([true, false, false], [wasAnalysed(['src/A.php', 'src/B.php'], 'src/A.php'),
        wasAnalysed(['src/A.php'], 'src/B.php'), wasAnalysed([], 'src/A.php')],
        'a file the analysis listed was analysed, and one it did not list was not — an empty listing analysed nothing');
    check(['App\T::m' => 5, 'App\Inner::q' => 5],
        complexitiesFor(parseSnippet("namespace App;\nclass T\n{\n    public function m()\n    {\n        class Inner { public function q() { if (\$b) {} } }\n    }\n}"),
            [['line' => 7, 'cc' => 5]]),
        'a class-like declared in a method body lands in the holder lines too, so one figure is counted under both names');

    check([401, 200, 0], [httpStatus(['HTTP/1.1 401 Unauthorized', 'Content-Type: application/json']),
        httpStatus(['HTTP/2 200', 'X: y']), httpStatus([])],
        'the status of an answer is read off its header block, and a block with no status line has none');
    check([401, 503], [httpStatus(['HTTP/1.1 302 Found', 'Location: /x', 'HTTP/1.1 401 Unauthorized']),
        httpStatus(['HTTP/1.1 301 Moved', 'HTTP/1.1 302 Found', 'HTTP/1.1 503 Service Unavailable'])],
        'and it is the last status in the block, so a redirect chain reports what answered and not what forwarded');

    check(['App\T::m' => 4, 'App\T::n' => 0], complexitiesFor(parseSnippet($two), $rows([5, 4])),
        'a figure the analysis reports inside a method is that method figure, and the method beside it takes none of it');
    check(['App\T::m' => 0, 'App\T::n' => 0], complexitiesFor(parseSnippet($two), $rows()),
        'a method the analysis said nothing about carries nought, which is what silence reports');
    check(['App\T::m' => 2, 'App\T::n' => 5], complexitiesFor(parseSnippet($two), $rows([5, 2], [9, 5])),
        'each method takes the figure reported inside its own body, however many the file carries');
    check(['App\T::m' => 6], complexitiesFor(parseSnippet($closure), $rows([5, 6], [7, 2])),
        'where two figures land in one method, the outermost is the method own and the other is nested in it');
    check(['App\T::m' => 6], complexitiesFor(parseSnippet($closure), $rows([7, 2], [5, 6])),
        'whichever order they arrive in');
    check(['App\T::m' => 6], complexitiesFor(parseSnippet($closure), $rows([5, 6], [7, 9])),
        'and outermost means the lowest line, never whichever figure happens to be the larger');

    check(['App\T'], array_keys(classLikesIn(parseSnippet('namespace App; class T { public function m() { $x = new class {}; } }'))),
        'an anonymous class is no class-like the probes can read');

    // --- Unresolved sites: the guard beside the two static measures.
    check(1, unresolvedSites(parseSnippet('namespace App; class T { public function m() { $x = new $class(); } }')),
        'a class named at runtime leaves an unresolved site');
    check(0, unresolvedSites(parseSnippet('namespace App; class T { public function m() { $x = new \DateTime(); } }')),
        'a class named in source leaves none');
    check(1, unresolvedSites(parseSnippet('namespace App; class T { public function m() { $x = $class::make(); } }')),
        'a static call on a runtime class name leaves one');
    check(0, unresolvedSites(parseSnippet('namespace App; class T { public function m() { $x = new class {}; } }')),
        'an anonymous class is a definition and not an unresolved reference');
    check(1, unresolvedSites(parseSnippet('namespace App; class T { public function m() { $x = $y instanceof $class; } }')),
        'an instanceof against a runtime class name leaves one');
    check(1, unresolvedSites(parseSnippet('namespace App; class T { public function m() { $x = $class::$property; } }')),
        'so does a static property fetch on one');
    check(1, unresolvedSites(parseSnippet('namespace App; class T { public function m() { $x = $class::NAME; } }')),
        'and a class constant fetch on one');

    selfTestTestMeasure();
    selfTestProbes();
    selfTestBands();
    selfTestQuestions();
    selfTestRepository();

    return count($failures);
}

/**
 * What the snippets above cannot reach: everything that reads a commit. A repository is written into a
 * temporary directory, three increments are committed into it, and the git-facing readings run against that —
 * which files an increment touched, a blob at one sha, the whole-`src/` population, the unmeasured and deleted
 * paths, and both measures end to end down to the question block they resolve.
 *
 * The directory is removed on the way out, and `$app` is put back where it was.
 */
function selfTestRepository(): void
{
    global $app, $cognitiveSource, $fixtureFigures;

    if (trim((string) shell_exec('git --version 2>/dev/null')) === '') {
        check(true, false, 'the repository cases need git on the path — skipped');

        return;
    }

    $was = $app;
    $wasSource = $cognitiveSource;
    $repository = writeFixtureRepository();
    $app = $repository;
    // The analysis the fixture declares for itself, read through the source that ships — so these cases prove
    // the code a run without a server uses, rather than a third implementation of it.
    $analysis = $repository . '/analysis.json';
    file_put_contents($analysis, (string) json_encode($fixtureFigures));
    $cognitiveSource = analysisFromFile($analysis);
    try {
        [$baseline, $increment, $rename, $configuration, $broken, $pair, $dropped, $rowStates, $noTestClass,
            $deletedTest, $withLegacy, $risenAndDeleted, $testUnmoved, $addedOnly, $dispatcher, $unbranched,
            $renamedIn, $renamedOut, $outsideSet, $brokenParse, $runtimeClass, $implemented, $emptyCommit] = array_map(
                static fn (int $back): string => trim(git('rev-parse ' . escapeshellarg('HEAD~' . $back))),
                [23, 22, 21, 20, 19, 18, 17, 16, 15, 14, 13, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1]);
        $merge = trim(git('rev-parse HEAD'));

        // Which paths an increment touched, and which it only names.
        check(['src/Timesheet/TimesheetCalculator.php', 'src/Timesheet/TimesheetCalculatorInterface.php',
            'src/Timesheet/TimesheetService.php'],
            array_values(array_map(static fn (array $entry): string => $entry['new'], touchedSrcFiles($increment, $increment . '^'))),
            'the touched production files are what the increment added and modified, never what it deleted');
        check(['tests/Timesheet/TimesheetServiceTest.php'],
            array_column(touchedTestFiles($increment, $increment . '^'), 'new'), 'the touched test files are the Test.php under tests/');
        check(['unmeasured' => [['path' => 'config/services.yaml', 'lines' => 3]],
            'deleted' => ['src/Legacy/Old.php']], unmeasuredFiles($increment, $increment . '^'),
            'a path outside the measured set is named with its line count, and a deleted one is named and not scored');

        check(['src/Legacy/Old.php', 'src/Timesheet/TimesheetService.php'],
            array_column(touchedSrcFiles($baseline, defaultBaseline($baseline)), 'new'),
            'a root commit is read against the empty tree, every file it names having been added by it');
        check(['src/Timesheet/Merged.php'],
            array_column(touchedSrcFiles($merge, $merge . '^'), 'new'),
            'a merge commit is read as a diff against its baseline, where its own patch names no file at all');

        [$paths, $renames] = array_map(static fn (string $tag): string => trim(git('rev-parse ' . escapeshellarg($tag))),
            ['extras-paths', 'extras-renames']);
        check([], touchedTestFiles($paths, $paths . '^'),
            'a PHP file under tests/ that is no Test.php is no test file of the measured set');
        check(['docs/a.txt' => 1, 'src/notes.txt' => 1, 'tests/Timesheet/Helper.php' => 3],
            array_column(unmeasuredFiles($paths, $paths . '^')['unmeasured'], 'lines', 'path'),
            'it is unmeasured like any other path outside the set, as is a file under src/ that is no PHP');
        check([['code' => 'A', 'new' => 'src/Notes.php', 'old' => 'src/Notes.php'],
            ['code' => 'R', 'new' => 'src/Timesheet/MergedAgain.php', 'old' => 'src/Timesheet/Merged.php']],
            touchedSrcFiles($renames, $renames . '^'),
            'a rename into src/ from a path that was no production file reads as added, where one within src/ keeps its baseline side');
        check([], unmeasuredFiles($renames, $renames . '^')['deleted'],
            'and neither a rename within the measured set nor one entirely outside it leaves a path named as deleted');
        check([], touchedSrcFiles($paths, $paths . '^'),
            'a file under src/ that is no PHP is no production file of the measured set');
        check([], array_values(array_filter(srcBlobs($paths),
            static fn (string $blob): bool => str_contains($blob, 'a note beside the code'))),
            'and no blob of it is read into the population either, whatever sits beside the code');
        check(['tests/Timesheet/ClockTest.php'], array_column(touchedTestFiles($renames, $renames . '^'), 'new'),
            'and a Test.php outside tests/ is no test file of it either');
        check([], unmeasuredFiles($deletedTest, $deletedTest . '^')['unmeasured'],
            'a path the increment deleted is named there and nowhere else, never counted a second time as unmeasured');

        [$measured, $pooled, $quiet, $reaching, $testRenamed] = array_map(
            static fn (string $tag): string => trim(git('rev-parse ' . escapeshellarg($tag))),
            ['extras-measure', 'extras-pooled', 'extras-quiet', 'extras-reaching', 'extras-testrename']);

        $clock = measureIncrement($measured, $measured . '^');
        check([0.0, 1.0, 1.0], [$clock['fan']['internalIncrement'], $clock['fan']['externalIncrement'],
            $clock['fan']['refsIncrement']], 'the reference rate is both kinds together, where the split beside it is each on its own');
        $clockTest = measureTestFiles($measured, $measured . '^');
        check([0, 4], [$clockTest['callsBaseline'], $clockTest['callsIncrement']],
            'the production population is read per side, so a call into a class the increment added lands on that side alone');
        $addedTests = measureTestFiles($renames, $renames . '^');
        check([0, null], [$addedTests['methodsBaseline'], $addedTests['baseline']],
            'a side with no test method to read carries no rate, the way a side with no production file carries none');
        check('not readable — the test measure is absent',
            questionOne(figures(['fan' => ['nIncrement' => 3], 'cognitive' => ['totalIncrement' => 9]]),
                testFigures(0, 2, null, 3.0))['outcome'],
            'and a rate that was never taken is no figure for a group to read');
        check('not readable — the test measure is absent',
            questionOne(figures(['fan' => ['nIncrement' => 3]]), testFigures(2, 2, 3.0, null))['outcome'],
            'and the increment side reads absent the way the baseline side does, neither being a rate to compare');
        check(['not detected', 'not detected'], [
            questionOne(figures(['fan' => ['nIncrement' => 3]]), testFigures(2, 4, null, 3.0))['outcome'],
            questionOne(figures(['fan' => ['nIncrement' => 3], 'cognitive' => ['totalIncrement' => 9]]),
                testFigures(4, 2, null, 3.0))['outcome']],
            'but a rate no group would have read leaves the block readable, the way an unread cognitive total does');

        $fewer = measureTestFiles($pooled, $pooled . '^');
        check([4, 4, 'unmoved'], [$fewer['callsBaseline'], $fewer['callsIncrement'], $fewer['state']],
            'the test measure takes its state from the total, which a test method dropped beside it does not move');
        check([4, 3], [$fewer['methodsBaseline'], $fewer['methodsIncrement']],
            'and each side counts the test methods of its own blob, a method dropped landing on the increment side alone');
        check([1.0, 1.33], [$fewer['baseline'], $fewer['increment']],
            'while the rate beside it moves, carried to the cent the way every rate here is');

        check([['class' => 'App\Tests\Timesheet\ClockTest', 'baseline' => 1.0, 'increment' => 1.33, 'state' => 'live']],
            $fewer['rows'],
            'a class row carries the rate of each side under the side it belongs to, at the precision it prints at');

        $guard = measureTestFiles($quiet, $quiet . '^');
        check([1.33, 2.0], [$guard['baseline'], $guard['increment']],
            'the pooled rate is carried to the cent on the baseline side as on the increment side');
        check([1, 0], [$guard['unresolvedBaseline'], $guard['unresolvedIncrement']],
            'each side carries the unresolved sites of its own blob, so a runtime class name dropped is a guard that fell');
        check([], measureIncrement($quiet, $quiet . '^')['implementations'],
            'an abstraction the baseline already declared is no abstraction the increment introduced');

        $reached = measureIncrement($reaching, $reaching . '^');
        check(['A', 'D'], [$reached['fan']['bandBaseline'], $reached['fan']['bandIncrement']],
            'each side is banded off its own score, the two being read apart');
        check([1.0, 11.0], [$reached['fan']['refsBaseline'], $reached['fan']['refsIncrement']],
            'and each side sums both kinds of reference into its rate, the baseline no differently from the increment');
        check(98.62, $reached['fan']['scoreBaseline'],
            'the score is carried to the cent on the baseline side as on the increment side');
        check(56.64, $reached['fan']['scoreIncrement'],
            'and to the cent on the increment side, both sides being read by the one rule');
        check([0, 0, 'absent'], [$reached['cognitive']['nBaseline'], $reached['cognitive']['nIncrement'],
            $reached['cognitive']['state']],
            'a touched file whose methods hold the same control flow as before enters neither side of the cognitive union');

        $trait = trim(git('rev-parse extras-trait'));
        check([], array_filter(array_keys(srcClassLikes($trait)), static fn (string $name): bool => $name === ''),
            'an anonymous class under src/ has no name to be counted under, so it never enters the population');
        check([['abstraction' => 'App\Timesheet\RoundingInterface', 'real' => 1, 'discounted' => 0, 'state' => 'single']],
            implementationsProbe(['App\Timesheet\RoundingInterface' => classOf('namespace App\Timesheet; interface RoundingInterface {}', 'App\Timesheet\RoundingInterface')], $trait),
            'and it is no implementation of what it implements either, the population being what the probe counts over');
        check([0.67, 0.67], array_map(static fn (string $key): ?float => measureIncrement($trait, $trait . '^')['fan'][$key],
            ['internalIncrement', 'refsIncrement']),
            'a reference rate is carried to the cent as well, over however many files the increment touched');
        check([['abstraction' => 'App\Timesheet\RoundingTrait', 'real' => 1, 'discounted' => 0, 'state' => 'single']],
            measureIncrement($trait, $trait . '^')['implementations'],
            'a trait the increment added is an abstraction like any other, the classes using it being what implements it');

        $methodDropped = trim(git('rev-parse extras-dropped'));
        $withoutMethod = measureIncrement($methodDropped, $methodDropped . '^')['cognitive'];
        check(['fallExisting' => 0, 'fallDeleted' => 3, 'riseAdded' => 0, 'riseExisting' => 0, 'state' => 'written'],
            $withoutMethod['leaving'],
            'a method taken out of a surviving file takes the whole figure it held with it, and not one unit of it');
        check([1, 1], [$withoutMethod['nBaseline'], $withoutMethod['nIncrement']],
            'and it counts on the baseline side alone, the side it has, where the method added beside it counts on the other');

        $moved = measureTestFiles($testRenamed, $testRenamed . '^');
        check([2, 2], [$moved['methodsBaseline'], $moved['methodsIncrement']],
            'a test file renamed within the measured set is read at its old path on the baseline side');

        // Reading one file, and the whole population, at one sha.
        check(true, str_contains((string) blob($baseline, 'src/Timesheet/TimesheetService.php'), 'foreach'),
            'a blob is read at the sha it is asked for');
        check(null, blob($baseline, 'src/Timesheet/TimesheetCalculator.php'),
            'a file that does not exist at that sha reads as nothing');
        check(['App\Legacy\Old', 'App\Timesheet\TimesheetService'], array_keys(srcClassLikes($baseline)),
            'the population at a sha is every class-like under src/ at that sha');
        check([['abstraction' => 'App\Timesheet\TimesheetCalculatorInterface', 'real' => 1, 'discounted' => 0,
            'state' => 'single']],
            implementationsProbe(['App\Timesheet\TimesheetCalculatorInterface' => classOf('namespace App\Timesheet; interface TimesheetCalculatorInterface {}', 'App\Timesheet\TimesheetCalculatorInterface')], $increment),
            'the implementations probe counts over the population of the increment sha');

        // Both measures, end to end, over the increment the methodology works through.
        $result = measureIncrement($increment, $baseline);
        check([1, 3], [$result['fan']['nBaseline'], $result['fan']['nIncrement']],
            'a file the increment added lands on the increment side of fan out alone');
        check('unmoved', $result['cognitive']['state'],
            'control flow only relocated leaves the total unmoved with methods on both sides');
        check([7, 7], [$result['cognitive']['totalBaseline'], $result['cognitive']['totalIncrement']],
            'the total counts control flow wherever it sits');
        check(['fell', 'added', 'added', 'added'], array_column($result['cognitive']['attribution'], 'state'),
            'attribution reads the union of the two sides');
        check(['fallExisting' => 7, 'fallDeleted' => 0, 'riseAdded' => 7, 'riseExisting' => 0, 'state' => 'relocated'],
            $result['cognitive']['leaving'], 'control flow leaving puts the fall where the increment left it');
        check([['class' => 'App\Timesheet\TimesheetService', 'forwards' => 2, 'methods' => 2,
            'collaborator' => 'internal', 'state' => 'pass-through']], $result['delegation'],
            'the extraction leaves a pass-through behind on the class that was already there');

        $test = measureTestFiles($increment, $baseline);
        check([4, 6], [$test['callsBaseline'], $test['callsIncrement']],
            'the test measure pools unique production calls over the touched test files, per side');
        check([2, 2], [$test['methodsBaseline'], $test['methodsIncrement']],
            'test methods that did not change say the behaviour did not either');
        check([['class' => 'App\Timesheet\TimesheetCalculatorInterface', 'state' => 'internal']], $test['mockSeam'],
            'the mock seam finds own production code behind the double');
        check('ceremony — names added, nothing simplified', questionOne($result, $test)['outcome'],
            'the measures and the probes of a real increment resolve to the outcome the methodology works through');

        // A rename is one file on each side, and never a file added.
        check([['code' => 'R', 'new' => 'src/Timesheet/DurationCalculator.php',
            'old' => 'src/Timesheet/TimesheetCalculator.php']], touchedSrcFiles($rename, $rename . '^'),
            'a renamed file is read at its old path on the baseline side and its new one at the increment');
        $renamed = measureIncrement($rename, $increment);
        check([1, 1], [$renamed['fan']['nBaseline'], $renamed['fan']['nIncrement']],
            'a rename adds no file to either side');
        check('unmoved', $renamed['fan']['state'],
            'and a file whose content the rename left alone reads unmoved rather than live');

        // An increment with no PHP in it at all: both measures have nothing to read, and all of it is unmeasured.
        check([null, null], [measureIncrement($configuration, $rename), measureTestFiles($configuration, $rename)],
            'an increment touching no src/ or tests/ PHP leaves both measures with nothing to read');
        check(['config/services.yaml'], array_column(unmeasuredFiles($configuration, $configuration . '^')['unmeasured'], 'path'),
            'the whole of such an increment is its unmeasured share');

        // A file that does not parse is skipped, on both sides, and never aborts the run.
        $unparseable = measureIncrement($broken, $configuration);
        check([0, 0], [$unparseable['fan']['nBaseline'], $unparseable['fan']['nIncrement']],
            'a file that does not parse enters neither side of fan out');
        check('absent', $unparseable['cognitive']['state'],
            'and leaves the cognitive measure with nothing to read');
        check([], array_keys(srcClassLikes($broken)['App\Broken'] ?? []),
            'nor does it enter the population read at that sha');

        // A test class that exists only at the baseline is pooled into that side and gets no row of its own.
        $dropping = measureTestFiles($dropped, $pair);
        check([2, 1], [$dropping['nBaseline'], $dropping['nIncrement']],
            'a class with no test method is no test class, and one the increment removed still counts at the baseline');
        check(['App\Tests\Timesheet\PairTest'], array_column($dropping['rows'], 'class'),
            'only the increment side gets a row');
        $added = measureTestFiles($pair, $broken);
        check([0, 2], [$added['nBaseline'], $added['nIncrement']],
            'a test file the increment added has no baseline side to read');

        // One row per state the detail can take, beside the transition the ceremony increment already showed.
        $states = measureTestFiles($rowStates, $dropped);
        check(['added', 'unmoved'], array_column($states['rows'], 'state'),
            'a class the increment added reads added, and one whose calls held reads unmoved');
        check('live', $states['state'],
            'and the class it added moves the pooled total, which is what pooling is for');
        check([['class' => 'App\Timesheet\TimesheetCalculatorInterface', 'state' => 'internal']], $states['mockSeam'],
            'a class mocked in more than one touched test file is one seam, not one per file');

        // Touched test files that yield no test class leave the measure with nothing to read at all.
        check(null, measureTestFiles($noTestClass, $rowStates),
            'a touched test file holding no test class leaves the test measure absent');
        check([], touchedTestFiles($deletedTest, $deletedTest . '^'),
            'a deleted test file leaves the touched test files entirely');
        check(['tests/Timesheet/PairTest.php'], unmeasuredFiles($deletedTest, $deletedTest . '^')['deleted'],
            'and is named among the deleted paths instead');

        // The attribution arms and the accumulators no earlier increment reaches.
        $moved = measureIncrement($risenAndDeleted, $withLegacy);
        check(['rose', 'deleted'], array_column($moved['cognitive']['attribution'], 'state'),
            'a method whose complexity grew reads rose, and one taken out of a surviving file reads deleted');
        check(['fallExisting' => 0, 'fallDeleted' => 1, 'riseAdded' => 0, 'riseExisting' => 3, 'state' => 'written'],
            $moved['cognitive']['leaving'],
            'the fall sits in the method the increment deleted, and the rise in one that was already there');
        check('live', $moved['cognitive']['state'],
            'a total that moved reads live, from a measurement rather than from figures handed to it');
        check([['abstraction' => 'App\Timesheet\AbstractRounding', 'real' => 0, 'discounted' => 0, 'state' => 'single']],
            $moved['implementations'],
            'an abstract class the increment added is an abstraction the probe counts implementations of');
        check('n production files up, cognitive complexity total up; test measure absent',
            questionOne($moved, measureTestFiles($risenAndDeleted, $withLegacy))['figures'],
            'and the question block reads those figures off the measurement rather than off figures handed to it');

        // A touched test file whose calls hold on both sides, and the fan out of a side that read nothing.
        $held = measureTestFiles($testUnmoved, $risenAndDeleted);
        check(['unmoved', 'unmoved'], [$held['state'], $held['rows'][0]['state']],
            'a test file touched without moving a call reads unmoved, measure and row alike');
        check([null, 0], [$added['baseline'], $added['callsBaseline']],
            'a side with no test method pools to no rate at all, where the total beside it is still a total');
        $allNew = measureIncrement($addedOnly, $testUnmoved);
        check([0, 1], [$allNew['fan']['nBaseline'], $allNew['fan']['nIncrement']],
            'an increment whose production files are all new has no baseline side to read');
        check([null, null, 'absent'],
            [$allNew['fan']['scoreBaseline'], $allNew['fan']['bandBaseline'], $allNew['fan']['state']],
            'so that side carries no score and no band, and the measure says absent rather than a perfect 100');
        check('cognitive complexity total up, fan out score absent', questionTwo($allNew)['figures'],
            'and question 2 reads the across-files half as absent rather than as having got worse');
        check([null, null, null], [$allNew['fan']['refsBaseline'], $allNew['fan']['internalBaseline'],
            $allNew['fan']['externalBaseline']], 'nor does that side carry a rate for a reader to subtract');
        check([0, 'live'], [$allNew['cognitive']['nBaseline'], $allNew['cognitive']['state']],
            'and cognitive complexity is read all the same, one side holding every touched method and the other none');

        // The increment side reading nothing, where the baseline side read something.
        $broke = measureIncrement($brokenParse, $outsideSet);
        check([1, 0], [$broke['fan']['nBaseline'], $broke['fan']['nIncrement']],
            'a production file that stops parsing leaves the increment side with nothing to read');
        check([null, null, 'absent'],
            [$broke['fan']['scoreIncrement'], $broke['fan']['bandIncrement'], $broke['fan']['state']],
            'so the increment side carries no score and no band, and the measure says absent');
        check(true, str_ends_with(questionTwo($broke)['figures'], 'fan out score absent'),
            'and question 2 reads it as absent from either side');
        check([null, null, null], [$broke['fan']['refsIncrement'], $broke['fan']['internalIncrement'],
            $broke['fan']['externalIncrement']], 'nor does that side carry a rate, which would read as nought references');

        // The probes, read from a measurement rather than from a snippet.
        check([['abstraction' => 'App\Timesheet\RoundingInterface', 'real' => 1, 'discounted' => 0, 'state' => 'single']],
            measureIncrement($implemented, $runtimeClass)['implementations'],
            'the implementations probe counts a real implementation from the population at that sha');

        // The guard, counted from a measurement on both sides.
        check([0, 1], array_values(measureIncrement($runtimeClass, $brokenParse)['unresolved']),
            'a class named at runtime in production is counted by the guard, from the measurement');

        // Renames across the boundary of the measured set.
        check([['code' => 'A', 'new' => 'src/Moved.php', 'old' => 'src/Moved.php']], touchedSrcFiles($renamedIn, $renamedIn . '^'),
            'a file renamed into src has no baseline side, so it reads as added');
        check([0, 1], array_map(static fn (string $side): int => measureIncrement($renamedIn, $unbranched)['fan'][$side],
            ['nBaseline', 'nIncrement']), 'and lands on the increment side alone');
        check([[], ['src/Moved.php']], [touchedSrcFiles($renamedOut, $renamedOut . '^'), unmeasuredFiles($renamedOut, $renamedOut . '^')['deleted']],
            'a file renamed out of it is scored on neither side and named among the deleted paths');
        check(['docs/Moved.php'], array_column(unmeasuredFiles($renamedOut, $renamedOut . '^')['unmeasured'], 'path'),
            'while the file it became is unmeasured like any other path outside the set');

        // Paths that look measured and are not, and the two line counts.
        check(['docs/empty.txt' => 0, 'docs/nonewline.txt' => 2, 'src/Resources/config.yaml' => 2,
            'tests/Fixtures/Helper.php' => 3],
            array_column(unmeasuredFiles($outsideSet, $outsideSet . '^')['unmeasured'], 'lines', 'path'),
            'a yaml under src, a php under tests that is no test class, an empty file and one without a trailing newline');

        selfTestReport($increment, $baseline);

        // The bootstrap, run as the shell runs it: a directory that is no checkout stops the script before it
        // reads anything, and says what to do about it.
        //
        // The branch beside it — nikic/php-parser nowhere to be found — cannot be reached from a checkout that
        // has it installed beside this repository, so nothing here exercises it.
        mkdir($repository . '/outside');
        $output = (string) shell_exec('ASSESSMENT_REPO=' . escapeshellarg($repository . '/outside') . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' increment ' . escapeshellarg($increment)
            . ' 2>&1; printf "exit:%d" "$?"');
        check(true, str_contains($output, 'no git checkout at'), 'a directory that is no checkout stops the run');
        check(true, str_contains($output, 'git clone https://github.com/kimai/kimai.git'),
            'and names the clone that would fix it');
        check(true, str_contains($output, 'exit:1'), 'and fails rather than reporting nothing');

        // The other two modes, and the increment that has nothing for either measure, run the way the shell
        // runs them.
        // The child processes read the same analysis file this one was handed.
        $run = static fn (string $arguments): string => (string) shell_exec(
            'ASSESSMENT_REPO=' . escapeshellarg($repository) . ' SONAR_ANALYSIS_FILE=' . escapeshellarg($analysis)
            . ' ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__FILE__) . ' ' . $arguments . ' 2>&1; printf "exit:%d" "$?"');

        $whole = $run('repo ' . escapeshellarg($increment));
        check(true, str_contains($whole, 'Fan out over src/'),
            'repo reads fan out over every src/ file at one sha');
        check(true, str_contains($whole, '0.67 refs/production file (internal 0.67, external 0.00), score 96.37, band A, n 3 production file(s)'),
            'and prints that one figure with the score and the band its own formula and cuts give it');
        check(true, str_contains($whole, 'exit:0'), 'and succeeds');
        check(true, str_starts_with($whole, 'repo ' . $increment),
            'and names the sha it read, so a figure over the whole tree is never read under the wrong commit');
        preg_match('/([\d.]+) refs\/production file \(internal ([\d.]+), external ([\d.]+)\)/',
            $run('repo ' . escapeshellarg(trim(git('rev-parse extras-trait')))), $split);
        check([true, true], [(float) $split[3] > 0.0, abs((float) $split[1] - ((float) $split[2] + (float) $split[3])) < 0.005],
            'and it prints a rate whose two kinds are both there to add, read at a sha where the external kind is not nought');

        $nothing = $run('increment ' . escapeshellarg($configuration));
        check(true, str_contains($nothing, 'Production                      no src/ file touched  [absent]')
            && str_contains($nothing, 'config/services.yaml'),
            'an increment with nothing for either measure still reports its unmeasured share');
        check(true, str_contains($nothing, 'exit:0'), 'and succeeds, there being something to report');
        $onlyDeleted = $run('increment ' . escapeshellarg($deletedTest));
        check(true, str_contains($onlyDeleted, 'Deleted paths, not scored       tests/Timesheet/PairTest.php')
            && str_contains($onlyDeleted, 'exit:0'),
            'an increment whose only measured content is a deletion is reported by the paths it named, not dismissed');
        $root = $run('increment ' . escapeshellarg($baseline));
        check(true, str_contains($root, 'n 0 -> 2 production file(s)') && str_contains($root, 'exit:0'),
            'a root commit is reported against the empty tree rather than read as an increment that touched nothing');
        $missing = $run('increment ' . escapeshellarg('deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'));
        check(true, str_contains($missing, 'no such increment in the repository') && str_contains($missing, 'exit:1'),
            'and a ref that resolves to nothing is named as the miss it is, never reported as a quiet increment');
        $empty = $run('increment ' . escapeshellarg($emptyCommit));
        check(true, str_contains($empty, 'no src/ or tests/ PHP touched by'),
            'an increment that touched nothing at all says so');
        check(true, str_contains($empty, 'exit:1'), 'and fails rather than printing an empty report');

        $unknown = $run('');
        check(true, str_contains($unknown, 'usage: php assessment.php increment')
            && str_contains($unknown, 'php assessment.php scan <sha>'),
            'a mode the script does not know prints what it does know, scanning among it');
        $unscanned = (string) shell_exec('ASSESSMENT_REPO=' . escapeshellarg($repository)
            . ' SONAR_HOST=http://127.0.0.1:9 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' increment ' . escapeshellarg($increment) . ' 2>&1; printf "exit:%d" "$?"');
        check(true, str_contains($unscanned, 'cannot reach SonarQube') && str_contains($unscanned, 'exit:1'),
            'a server that cannot be reached stops the run, where a quiet report would read as measured');
        $blank = $repository . '/blank.json';
        file_put_contents($blank, '{}');
        foreach ([['scalar', '{"' . md5((string) blob($increment . '^', 'src/Timesheet/TimesheetService.php')) . '": 7}',
            'is not a set of figures'],
            ['stranger', '{"' . md5((string) blob($increment . '^', 'src/Timesheet/TimesheetService.php'))
                . '": {"App\\\\Nope::gone": 99}}', 'names App\\Nope::gone'],
            ['unfigure', '{"' . md5((string) blob($increment . '^', 'src/Timesheet/TimesheetService.php'))
                . '": {"App\\\\Timesheet\\\\TimesheetService::sumDuration": "seven"}}',
                'is no figure']] as [$label, $json, $needle]) {
            $bad = $repository . '/' . $label . '.json';
            file_put_contents($bad, $json);
            $out = (string) shell_exec('ASSESSMENT_REPO=' . escapeshellarg($repository)
                . ' SONAR_ANALYSIS_FILE=' . escapeshellarg($bad) . ' ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg(__FILE__) . ' increment ' . escapeshellarg($increment) . ' 2>&1; printf "exit:%d" "$?"');
            check(true, str_contains($out, $needle) && str_contains($out, 'exit:1') && !str_contains($out, 'Fatal'),
                'an analysis entry that is no figure set, or names a method the blob has not got, is named and stops the run');
        }
        // The three faults an analysis can carry, each run against a server that answers the way that fault
        // looks. What decides them is asserted above; this is the wiring that acts on it.
        $port = stubServer($repository);
        foreach ([
            ['unread', 'in a shape this run cannot read'],
            ['silent', 'reported nothing, so it cannot say what it was taken at'],
            ['threshold', 'was taken with php:S3776 at threshold 15'],
        ] as [$fault, $needle]) {
            $out = (string) shell_exec('ASSESSMENT_REPO=' . escapeshellarg($repository)
                . ' SONAR_HOST=' . escapeshellarg('http://127.0.0.1:' . $port . '/' . $fault)
                . ' SONAR_TOKEN=stub ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
                . ' increment ' . escapeshellarg($increment) . ' 2>&1; printf "exit:%d" "$?"');
            check(true, str_contains($out, $needle) && str_contains($out, 'exit:1')
                && !str_contains($out, 'Production Cognitive'),
                "an analysis that is {$fault} stops the run rather than reporting the figures it did not give");
        }

        $blankRun = (string) shell_exec('ASSESSMENT_REPO=' . escapeshellarg($repository)
            . ' SONAR_ANALYSIS_FILE=' . escapeshellarg($blank) . ' ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__FILE__) . ' increment ' . escapeshellarg($increment) . ' 2>&1; printf "exit:%d" "$?"');
        $missingFile = (string) shell_exec('ASSESSMENT_REPO=' . escapeshellarg($repository)
            . ' SONAR_ANALYSIS_FILE=' . escapeshellarg($repository . '/nowhere.json') . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' increment '
            . escapeshellarg($increment) . ' 2>&1; printf "exit:%d" "$?"');
        check(true, str_contains($missingFile, 'no analysis to read at') && str_contains($missingFile, 'exit:1')
            && !str_contains($missingFile, 'says nothing about'),
            'an analysis file that cannot be read is named as the file it is, never as a blob it failed to hold');
        $notJson = $repository . '/notjson.json';
        file_put_contents($notJson, 'this is not an analysis');
        $malformed = (string) shell_exec('ASSESSMENT_REPO=' . escapeshellarg($repository)
            . ' SONAR_ANALYSIS_FILE=' . escapeshellarg($notJson) . ' ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(__FILE__) . ' increment ' . escapeshellarg($increment) . ' 2>&1; printf "exit:%d" "$?"');
        check(true, str_contains($malformed, 'no analysis to read at') && str_contains($malformed, 'exit:1')
            && !str_contains($malformed, 'says nothing about') && !str_contains($malformed, 'Warning'),
            'and a file holding no analysis is the same fault of the file, named without a warning leaking through');
        check(true, str_contains($blankRun, 'the analysis in') && str_contains($blankRun, 'exit:1'),
            'and an analysis that says nothing about a blob it was asked for stops the run the same way, where nought would read as a figure');
        check(true, str_contains((string) shell_exec('ASSESSMENT_REPO=' . escapeshellarg($repository) . ' '
            . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' scan HEAD 2>&1'),
            'SONAR_TOKEN is not set'),
            'and scanning without a token says so rather than asking the server to refuse it');
        check(true, str_contains($unknown, 'exit:1'), 'and fails');

        // The defaults the shell relies on, and the report an increment prints end to end.
        $defaulted = $run('increment ' . escapeshellarg($increment));
        check(true, str_contains($defaulted, 'ceremony — names added, nothing simplified'),
            'an increment run without a baseline reads its parent, and reaches the same outcome');
        check(true, str_contains($defaulted, 'exit:0'), 'and succeeds');
        $figuresOf = static fn (string $output): string => substr($output, (int) strpos($output, "\n"));
        check($figuresOf($run('repo ' . escapeshellarg(trim(git('rev-parse HEAD'))))), $figuresOf($run('repo')),
            'repo without a sha prints the figures of the commit HEAD names');
        check(true, str_contains($run('repo ' . escapeshellarg($baseline . '^')), 'no src/ PHP at')
            && str_contains($run('repo ' . escapeshellarg($baseline . '^')), 'exit:1'),
            'and a sha with no src/ PHP at all fails rather than dividing by nothing');
    } finally {
        $app = $was;
        $cognitiveSource = $wasSource;
        removeDirectory($repository);
    }
}

/**
 * What the report prints, over the increment the two measures were just read on: every line of *Output*, in
 * the order the methodology shows them, and the two lines a measure prints instead when it has nothing to read.
 */
function selfTestReport(string $incrementSha, string $baselineSha): void
{
    $result = measureIncrement($incrementSha, $baselineSha);
    $test = measureTestFiles($incrementSha, $baselineSha);

    ob_start();
    reportIncrement($incrementSha, $result, $test, unmeasuredFiles($incrementSha, $baselineSha));
    $lines = explode("\n", (string) ob_get_clean());

    $line = static fn (string $needle): string => trim(implode('', array_filter($lines,
        static fn (string $printed): bool => str_contains($printed, $needle))));

    check(substr($incrementSha, 0, 8), trim(explode(' ', $lines[0])[1]), 'the report names the increment it read');
    check('3 touched production file(s)', trim($lines[1]), 'and how many production files it touched');
    check(true, str_contains($line('Production Fan out'), 'A -> A  score 100.00 -> 96.37 (higher is better)'),
        'the fan out line carries the band, the score, its polarity and the references behind it');
    check(true, str_contains($line('Production Cognitive'), 'total 7 -> 7 over the touched methods (higher is worse), n 1 -> 4 touched method(s)  [unmoved]'),
        'the cognitive line carries the total per side, n per side, and the state');
    check(true, str_contains($line('TimesheetService::sumDuration'), 'attribution') && str_contains($line('TimesheetService::sumDuration'), '7 -> 0  [fell]'),
        'a probe prints beneath the measure whose reading it settles');
    check(true, str_contains($line('control flow leaving'), 'fall 7 in method(s) that still exist') && str_contains($line('control flow leaving'), '[relocated]'),
        'control flow leaving prints its four figures and its label');
    check(true, str_contains($line('Test unit dependency'), 'total 4 -> 6 production call(s) (2.00 -> 3.00 per test method'),
        'the test line leads with the total and carries the rate beside it');
    check(true, str_contains($line('mock seam'), 'TimesheetCalculatorInterface') && str_contains($line('mock seam'), '[internal]'),
        'the mock seam prints beneath the test measure');
    check('Unmeasured paths                1 path(s), 3 line(s)', $line('Unmeasured paths'),
        'the unmeasured share prints with its line count');
    check('Deleted paths, not scored       src/Legacy/Old.php, src/Legacy/Older.php',
        trim(implode('', array_filter(explode("\n", (string) (static function (): string {
            ob_start();
            reportIncrement('0000000', null, null,
                ['unmeasured' => [], 'deleted' => ['src/Legacy/Old.php', 'src/Legacy/Older.php']]);

            return (string) ob_get_clean();
        })()), static fn (string $one): bool => str_contains($one, 'Deleted paths')))),
        'more than one deleted path is named on one line, each parted from the next');
    check('Deleted paths, not scored       src/Legacy/Old.php', $line('Deleted paths'),
        'a deleted path is named and not scored');
    check('measures live: 2 of 3', $line('measures live'), 'the live measures are counted');
    check(true, str_contains($line('Question 1  figures'), 'n production files up, cognitive complexity total unmoved'),
        'question 1 prints the figures it read');
    check(true, str_contains($line('outcome  ceremony'), 'ceremony — names added, nothing simplified'),
        'and the outcome they leave');
    check(true, str_contains($line('              probes'), 'delegation ratio pass-through, implementations single, mock seam internal — all three agree'),
        'and the probes it was read against, the evidence behind the outcome being part of the block');
    check('Question 2  figures  cognitive complexity total unmoved, fan out score down', $line('Question 2  figures'),
        'question 2 prints the figures it read, under its own name');
    check('outcome  harder across files — the same logic, in more files to open', $line('harder across files'),
        'and the outcome they leave, beneath them');

    // The same report where a measure has nothing to read at all.
    ob_start();
    reportIncrement($incrementSha, null, null, ['unmeasured' => [], 'deleted' => []]);
    $absent = (string) ob_get_clean();
    check(true, str_contains($absent, 'Production                      no src/ file touched  [absent]'),
        'a production side with nothing to read prints absent rather than a figure');
    check(true, str_contains($absent, 'Test unit dependency            no test class touched  [absent]'),
        'and so does the test side');
    check(true, str_contains($absent, 'measures live: 0 of 3'), 'neither counts as live');

    // The guard beside the measures. A count that moves says the increment added code this pass cannot see, and
    // the figures beside it stop being evidence — the only reading in the report that never takes `absent`.
    ob_start();
    reportIncrement($incrementSha, figures(['unresolved' => ['baseline' => 0, 'increment' => 2]]),
        testFigures(2, 2, 2.0, 3.0, [], ['unresolvedBaseline' => 0, 'unresolvedIncrement' => 2]),
        ['unmeasured' => [], 'deleted' => []]);
    $moved = (string) ob_get_clean();
    check(true, str_contains($moved, 'Unresolved sites (production)   0 -> 2  [live — the fan out figures are not evidence]'),
        'a production guard that moved says the fan out figures are not evidence');
    check(true, str_contains($moved, 'Unresolved sites (test files)   0 -> 2  [live — the test figures are not evidence]'),
        'and a test guard that moved says the same of the test figures');

    // The rows the ceremony increment does not produce: a method that rose, one the increment deleted, a test
    // class added, and the second row of a probe that has more than one.
    ob_start();
    reportIncrement('0000000', figures([
        'fan' => ['internalBaseline' => 3.0, 'internalIncrement' => 3.0, 'refsBaseline' => 4.0, 'refsIncrement' => 4.0],
        'cognitive' => ['totalIncrement' => 9, 'state' => 'live', 'attribution' => [
            ['method' => 'App\A::rose', 'baseline' => 3, 'increment' => 5, 'state' => 'rose'],
            ['method' => 'App\A::gone', 'baseline' => 4, 'increment' => null, 'state' => 'deleted'],
            ['method' => 'App\B::fresh', 'baseline' => null, 'increment' => 6, 'state' => 'added']],
            'leaving' => ['fallExisting' => 1, 'fallDeleted' => 4, 'riseAdded' => 6, 'riseExisting' => 2,
                'state' => 'written']],
        'delegation' => [
            ['class' => 'App\First', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'internal', 'state' => 'pass-through'],
            ['class' => 'App\Second', 'forwards' => 1, 'methods' => 3, 'collaborator' => 'external', 'state' => 'partial']],
        'implementations' => [['abstraction' => 'App\I', 'real' => 2, 'discounted' => 1, 'state' => 'polymorphic'],
            ['abstraction' => 'App\J', 'real' => 1, 'discounted' => 0, 'state' => 'single']],
    ]), testFigures(2, 3, 2.0, 3.0, [], ['nIncrement' => 2, 'rows' => [
        ['class' => 'App\Tests\AddedTest', 'baseline' => null, 'increment' => 3.0, 'state' => 'added']]]),
        ['unmeasured' => [], 'deleted' => []]);
    $rows = explode("\n", (string) ob_get_clean());
    $printed = static fn (string $needle): string => trim(implode('', array_filter($rows,
        static fn (string $one): bool => str_contains($one, $needle))));

    check(true, str_contains($printed('App\A::rose'), '3 -> 5  [rose]'), 'a method that rose prints its two sides');
    check(true, str_contains($printed('App\A::gone'), 'deleted at 4  [deleted]'),
        'a method the increment deleted prints the side it had');
    check(true, str_contains($printed('AddedTest'), 'added at 3.00/test method  [added]'),
        'a test class the increment added prints the side it has');
    check(true, str_starts_with($printed('App\Second'), 'App\Second'),
        'the second row of a probe prints under a blank label, the probe being named once');
    check(true, str_starts_with($printed('App\A::gone'), 'App\A::gone'),
        'which holds for the attribution rows as well, however many methods the union carries');
    check('App\Second                                     1 of 3 method(s) forward, collaborator external  [partial]',
        $printed('App\Second'),
        'the delegation row prints how many bodies forward against how many there are, what they forward to, and the shape that leaves');
    check(true, str_contains($printed('Test unit dependency'), 'n 1 -> 2 test class(es), 2 -> 3 test method(s)'),
        'and the test line prints each count per side, baseline before increment');
    ob_start();
    reportIncrement('0000000', null, testFigures(0, 2, null, 3.0, [], ['callsBaseline' => 0, 'nBaseline' => 0]),
        ['unmeasured' => [], 'deleted' => []]);
    check(true, str_contains((string) ob_get_clean(),
        'total 0 -> 6 production call(s) (absent -> 3.00 per test method'),
        'a test side that took no rate prints absent, never a nought that would read as a rate that held');

    check(['', ''], [$printed('Unmeasured paths'), $printed('Deleted paths')],
        'an increment with nothing outside the measured set prints neither block, where a nought would read as a measurement');
    check('', $printed('              probes'),
        'and a question block with no probe to name prints no line for one');
    check(true, str_contains($printed('Production Cognitive'), 'total 5 -> 9 over the touched methods'),
        'the cognitive line prints the baseline total before the increment one');
    check(true, str_contains($printed('Production Fan out'), '(internal 3.00 -> 3.00, external 1.00 -> 1.00)'),
        'and the fan out line prints each kind of reference under the name it was counted as');
    check(true, str_contains($printed('control flow leaving'),
        'fall 1 in method(s) that still exist, fall 4 in method(s) deleted, rise 6 in method(s) added, rise 2 in method(s) already there  [written]'),
        'the control flow leaving row prints its four figures against the labels they were read under, and its own state');
    check(true, str_starts_with($printed('App\J'), 'App\J'),
        'and the second row of the implementations probe prints under a blank label like every other probe');
    check(true, str_contains($printed('App\I '), '2 real, 1 discounted  [polymorphic]'),
        'the implementations probe prints its count per abstraction');
    check(true, str_contains($printed('App\B::fresh'), 'added at 6  [added]'),
        'a method the increment added prints the side it has');
    check(true, str_contains(implode('', array_filter($rows,
        static fn (string $one): bool => str_contains($one, 'App\I '))), 'implementations '),
        'and the implementations probe is named on its first row, where the second prints under a blank label');
    check(true, str_contains($printed('Unresolved sites (production)'), '0 -> 0  [unmoved]'),
        'a guard that held prints unmoved, which is only the absence of the warning');
    check(true, str_contains($printed('Unresolved sites (test files)'), '0 -> 0  [unmoved]'),
        'on the test side as well');
    check(true, str_contains($printed('measures live'), 'measures live: 2 of 3'),
        'a measure that did not move is not counted live, on either side of the report');

    // A second mocked class prints under a blank label, and a moved test class prints its two sides.
    ob_start();
    reportIncrement('0000000', null, testFigures(2, 2, 2.0, 3.0,
        [['class' => 'App\Svc', 'state' => 'internal'], ['class' => 'Vendor\Client', 'state' => 'boundary']],
        ['rows' => [['class' => 'App\Tests\MovedTest', 'baseline' => 2.0, 'increment' => 3.0, 'state' => 'live']]]),
        ['unmeasured' => [], 'deleted' => []]);
    $testOnly = explode("\n", (string) ob_get_clean());
    $onTest = static fn (string $needle): string => trim(implode('', array_filter($testOnly,
        static fn (string $one): bool => str_contains($one, $needle))));

    check(true, str_contains($onTest('MovedTest'), '2.00 -> 3.00/test method  [live]'),
        'a test class whose rate moved prints both sides');
    check(true, str_contains($onTest('Vendor\Client'), 'mocked  [boundary]')
        && !str_contains($onTest('Vendor\Client'), 'mock seam'),
        'the second mocked class prints under a blank label');
    check(true, str_contains($onTest('measures live'), 'measures live: 1 of 3'),
        'and the test measure alone counts as one live measure');

    // A production side that was read, where no method changed complexity: the measure says absent, and the
    // probes that read the per-method figures have nothing to print.
    ob_start();
    reportIncrement('0000000', figures(['cognitive' => ['state' => 'absent']]), null,
        ['unmeasured' => [], 'deleted' => []]);
    $quiet = (string) ob_get_clean();
    check(true, str_contains($quiet, 'Production Cognitive            no method changed complexity  [absent]'),
        'a cognitive measure with nothing to read says so instead of printing a total');
    check(false, str_contains($quiet, 'control flow leaving'),
        'and control flow leaving prints nothing, there being no reading of it to settle');

    // A fan out side that read nothing prints as absent, band and rate alike.
    ob_start();
    reportIncrement('0000000', figures(['fan' => ['scoreBaseline' => null, 'bandBaseline' => null,
        'refsBaseline' => null, 'internalBaseline' => null, 'externalBaseline' => null, 'nBaseline' => 0,
        'state' => 'absent']]), null, ['unmeasured' => [], 'deleted' => []]);
    $half = (string) ob_get_clean();
    check(true, str_contains($half, 'absent -> B  score absent -> 80.00 (higher is better); absent -> 2.00 refs/production file (internal absent -> 1.00, external absent -> 1.00), n 0 -> 1 production file(s)  [absent]'),
        'the side with nothing to read prints absent for its band, its score and every rate behind them');
}

/**
 * The fixture repository: a baseline, the extraction the methodology works through as its ceremony example,
 * a rename, an increment with no PHP in it, a file that does not parse, and a pair of test classes one later
 * increment takes one of.
 */
function writeFixtureRepository(): string
{
    $directory = sys_get_temp_dir() . '/assessment-selftest-' . getmypid();
    removeDirectory($directory);
    mkdir($directory, 0o777, true);

    $service = <<<'PHP'
        <?php
        namespace App\Timesheet;
        class TimesheetService
        {
            public function sumDuration(array $records): int
            {
                $total = 0;
                foreach ($records as $record) {
                    if ($record->isBillable()) {
                        if ($record->getEnd() !== null) {
                            $total += $record->getDuration();
                        } else {
                            $total += 0;
                        }
                    }
                }
                return $total;
            }
        }
        PHP;
    writeFixture($directory, 'src/Timesheet/TimesheetService.php', $service,
        ['App\\Timesheet\\TimesheetService::sumDuration' => 7]);
    writeFixture($directory, 'src/Legacy/Old.php', "<?php\nnamespace App\\Legacy;\nclass Old {}\n");
    writeFixture($directory, 'config/services.yaml', "services:\n  _defaults:\n    autowire: true\n");
    writeFixture($directory, 'tests/Timesheet/TimesheetServiceTest.php', <<<'PHP'
        <?php
        namespace App\Tests\Timesheet;
        use App\Timesheet\TimesheetService;
        class TimesheetServiceTest
        {
            public function testSumsDuration(): void
            {
                $service = new TimesheetService();
                $service->sumDuration([]);
            }
            public function testSumsNothing(): void
            {
                $service = new TimesheetService();
                $service->sumDuration([]);
            }
        }
        PHP);
    fixtureGit($directory, 'init -q .');
    fixtureGit($directory, 'config user.email selftest@example.com');
    fixtureGit($directory, 'config user.name selftest');
    fixtureCommit($directory, 'baseline');

    writeFixture($directory, 'src/Timesheet/TimesheetCalculatorInterface.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        interface TimesheetCalculatorInterface
        {
            public function calculate(array $records): int;
        }
        PHP);
    writeFixture($directory, 'src/Timesheet/TimesheetCalculator.php',
        str_replace(['class TimesheetService', 'sumDuration'],
            ['class TimesheetCalculator implements TimesheetCalculatorInterface', 'calculate'], $service),
        ['App\\Timesheet\\TimesheetCalculator::calculate' => 7]);
    writeFixture($directory, 'src/Timesheet/TimesheetService.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class TimesheetService
        {
            public function __construct(private TimesheetCalculatorInterface $calculator)
            {
            }
            public function sumDuration(array $records): int
            {
                return $this->calculator->calculate($records);
            }
            public function sumBillable(array $records): int
            {
                return $this->calculator->calculate($records);
            }
        }
        PHP);
    writeFixture($directory, 'tests/Timesheet/TimesheetServiceTest.php', <<<'PHP'
        <?php
        namespace App\Tests\Timesheet;
        use App\Timesheet\TimesheetCalculatorInterface;
        use App\Timesheet\TimesheetService;
        class TimesheetServiceTest
        {
            public function testSumsDuration(): void
            {
                $service = new TimesheetService($this->createMock(TimesheetCalculatorInterface::class));
                $service->sumDuration([]);
                $service->sumBillable([]);
            }
            public function testSumsNothing(): void
            {
                $service = new TimesheetService($this->createMock(TimesheetCalculatorInterface::class));
                $service->sumDuration([]);
                $service->sumBillable([]);
            }
        }
        PHP);
    writeFixture($directory, 'config/services.yaml', "services:\n  _defaults:\n    autowire: false\n");
    unlink($directory . '/src/Legacy/Old.php');
    fixtureCommit($directory, 'extract calculator');

    rename($directory . '/src/Timesheet/TimesheetCalculator.php', $directory . '/src/Timesheet/DurationCalculator.php');
    fixtureCommit($directory, 'rename calculator');

    // An increment that touches no PHP at all — both measures have nothing to read, and the whole of it is
    // the unmeasured share.
    writeFixture($directory, 'config/services.yaml', "services:\n  _defaults:\n    autowire: true\n");
    fixtureCommit($directory, 'configuration only');

    // A file that does not parse. It is skipped on both sides rather than aborting the run.
    writeFixture($directory, 'src/Broken/Broken.php', "<?php\nnamespace App\\Broken;\nclass Broken {\n");
    fixtureCommit($directory, 'unparseable file');

    // Two test classes in one file, so the next increment can take one away and leave the other.
    writeFixture($directory, 'tests/Timesheet/PairTest.php', <<<'PHP'
        <?php
        namespace App\Tests\Timesheet;
        use App\Timesheet\TimesheetService;
        class PairTest
        {
            public function testOne(): void
            {
                $service = new TimesheetService($this->createMock(\App\Timesheet\TimesheetCalculatorInterface::class));
                $service->sumDuration([]);
            }
        }
        class LegacyPairTest
        {
            public function testTwo(): void
            {
                $service = new TimesheetService($this->createMock(\App\Timesheet\TimesheetCalculatorInterface::class));
                $service->sumBillable([]);
            }
        }
        class PairHelper
        {
            public function build(): void
            {
            }
        }
        PHP);
    fixtureCommit($directory, 'add a pair of test classes');

    writeFixture($directory, 'tests/Timesheet/PairTest.php', <<<'PHP'
        <?php
        namespace App\Tests\Timesheet;
        use App\Timesheet\TimesheetService;
        class PairTest
        {
            public function testOne(): void
            {
                $service = new TimesheetService($this->createMock(\App\Timesheet\TimesheetCalculatorInterface::class));
                $service->sumDuration([]);
            }
        }
        PHP);
    fixtureCommit($directory, 'drop one of the pair');

    // A touched test file whose calls do not move, beside a test class the increment adds: one row per state.
    writeFixture($directory, 'tests/Timesheet/TimesheetServiceTest.php',
        "<?php\n// The behaviour under test did not change.\n" . substr((string) file_get_contents($directory . '/tests/Timesheet/TimesheetServiceTest.php'), 6));
    writeFixture($directory, 'tests/Timesheet/AddedTest.php', <<<'PHP'
        <?php
        namespace App\Tests\Timesheet;
        use App\Timesheet\TimesheetService;
        class AddedTest
        {
            public function testAdded(): void
            {
                $service = new TimesheetService($this->createMock(\App\Timesheet\TimesheetCalculatorInterface::class));
                $service->sumDuration([]);
            }
        }
        PHP);
    fixtureCommit($directory, 'add a test class beside an unchanged one');

    // A touched test file holding no test class at all.
    writeFixture($directory, 'tests/Timesheet/NoClassTest.php', <<<'PHP'
        <?php
        namespace App\Tests\Timesheet;
        class NoClassTest
        {
            public function helper(): void
            {
            }
        }
        PHP);
    fixtureCommit($directory, 'add a test file with no test class');

    unlink($directory . '/tests/Timesheet/PairTest.php');
    fixtureCommit($directory, 'delete a test file');

    // A method holding control flow, so the increment after this one can take it away again.
    writeFixture($directory, 'src/Timesheet/DurationCalculator.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class DurationCalculator implements TimesheetCalculatorInterface
        {
            public function calculate(array $records): int
            {
                $total = 0;
                foreach ($records as $record) {
                    if ($record->isBillable()) {
                        $total += $record->getDuration();
                    }
                }
                return $total;
            }
            public function legacy(array $records): int
            {
                if ($records === []) {
                    return 1;
                }
                return 0;
            }
        }
        PHP, ['App\\Timesheet\\DurationCalculator::calculate' => 3, 'App\\Timesheet\\DurationCalculator::legacy' => 1]);
    fixtureCommit($directory, 'add a method holding control flow');

    // The shapes the fixture had not produced: a method that rose, one deleted from a surviving file, and an
    // abstract class added beside them.
    writeFixture($directory, 'src/Timesheet/DurationCalculator.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class DurationCalculator implements TimesheetCalculatorInterface
        {
            public function calculate(array $records): int
            {
                $total = 0;
                foreach ($records as $record) {
                    if ($record->isBillable()) {
                        if ($record->getEnd() !== null) {
                            $total += $record->getDuration();
                        }
                    }
                }
                return $total;
            }
        }
        PHP, ['App\\Timesheet\\DurationCalculator::calculate' => 6]);
    writeFixture($directory, 'src/Timesheet/AbstractRounding.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        abstract class AbstractRounding
        {
            abstract public function round(int $seconds): int;
        }
        PHP);
    fixtureCommit($directory, 'raise one method and delete another');

    // A touched test file whose calls do not move at all.
    writeFixture($directory, 'tests/Timesheet/AddedTest.php',
        "<?php\n// A comment, and nothing the measure reads.\n" . substr((string) file_get_contents($directory . '/tests/Timesheet/AddedTest.php'), 6));
    fixtureCommit($directory, 'touch a test file without moving its calls');

    // An increment whose touched production files are all new: the baseline side has nothing to read at all.
    writeFixture($directory, 'src/Timesheet/Rounder.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class Rounder
        {
            public function round(int $seconds): int
            {
                if ($seconds < 0) {
                    return 0;
                }
                return $seconds;
            }
        }
        PHP, ['App\\Timesheet\\Rounder::round' => 1]);
    fixtureCommit($directory, 'add a production file and nothing else');

    // A dispatcher the increment adds, and an abstract base with a real implementation beside it, so the
    // implementations probe has a row to produce from a measurement rather than from a snippet.
    writeFixture($directory, 'src/Timesheet/CalculatorFactory.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class CalculatorFactory
        {
            public function __construct(private DurationCalculator $calculator)
            {
            }
            public function for(string $kind): TimesheetCalculatorInterface
            {
                return match ($kind) {
                    'duration' => $this->calculator,
                    default => new NullCalculator(),
                };
            }
        }
        PHP);
    writeFixture($directory, 'src/Timesheet/NullCalculator.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class NullCalculator implements TimesheetCalculatorInterface
        {
            public function calculate(array $records): int
            {
                return 0;
            }
        }
        PHP);
    writeFixture($directory, 'src/Timesheet/ConcreteRounding.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class ConcreteRounding extends AbstractRounding
        {
            public function round(int $seconds): int
            {
                if ($seconds < 0) {
                    return 0;
                }
                return $seconds - ($seconds % 60);
            }
        }
        PHP, ['App\\Timesheet\\ConcreteRounding::round' => 1]);
    writeFixture($directory, 'docs/Moved.php', "<?php\nnamespace App\\Docs;\nclass Moved {}\n");
    fixtureCommit($directory, 'add a dispatcher and an implementation');

    // The same method with its branch taken out: a method whose complexity fell writes no arm to read.
    writeFixture($directory, 'src/Timesheet/CalculatorFactory.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class CalculatorFactory
        {
            public function __construct(private DurationCalculator $calculator)
            {
            }
            public function for(string $kind): TimesheetCalculatorInterface
            {
                return $this->calculator;
            }
        }
        PHP);
    fixtureCommit($directory, 'take the branch back out of the dispatcher');

    fixtureGit($directory, 'mv docs/Moved.php src/Moved.php');
    fixtureCommit($directory, 'rename a file into src');

    fixtureGit($directory, 'mv src/Moved.php docs/Moved.php');
    fixtureCommit($directory, 'rename a file out of src');

    // Paths just outside the measured set, and the two line counts.
    writeFixture($directory, 'src/Resources/config.yaml', "one: 1\ntwo: 2\n");
    writeFixture($directory, 'tests/Fixtures/Helper.php', "<?php\nnamespace App\\Tests\\Fixtures;\nclass Helper {}\n");
    writeFixture($directory, 'docs/empty.txt', '');
    writeFixture($directory, 'docs/nonewline.txt', "one\ntwo");
    fixtureCommit($directory, 'add paths outside the measured set');

    // A production file that stops parsing: the increment side reads nothing where the baseline read something.
    writeFixture($directory, 'src/Timesheet/Rounder.php', "<?php\nnamespace App\\Timesheet;\nclass Rounder {\n");
    fixtureCommit($directory, 'break a production file');

    // A class named at runtime in production, so the guard has something to count from a measurement.
    writeFixture($directory, 'src/Timesheet/Resolver.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class Resolver
        {
            public function make(string $class): object
            {
                return new $class();
            }
        }
        PHP);
    fixtureCommit($directory, 'name a class at runtime');

    // An abstraction and a real implementation of it in one increment, so the implementations probe counts
    // one from a measurement and not only from a population handed to it.
    writeFixture($directory, 'src/Timesheet/RoundingInterface.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        interface RoundingInterface
        {
            public function round(int $seconds): int;
        }
        PHP);
    writeFixture($directory, 'src/Timesheet/MinuteRounding.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class MinuteRounding implements RoundingInterface
        {
            public function round(int $seconds): int
            {
                return $seconds - ($seconds % 60);
            }
        }
        PHP);
    fixtureCommit($directory, 'add an abstraction and an implementation of it');

    fixtureGit($directory, '-c commit.gpgsign=false commit -q --allow-empty -m ' . escapeshellarg('touch nothing at all'));

    // A merge: the file set has to be read as a diff against the baseline, because a merge commit is a patch
    // against no single parent and `git show` prints nothing for one.
    $mainline = trim((string) shell_exec('cd ' . escapeshellarg($directory) . ' && git rev-parse --abbrev-ref HEAD 2>/dev/null'));
    fixtureGit($directory, 'checkout -q -b side HEAD~1');
    writeFixture($directory, 'src/Timesheet/Merged.php', "<?php\nnamespace App\\Timesheet;\nclass Merged { public function m(): void { if (true) {} } }\n",
        ['App\\Timesheet\\Merged::m' => 1]);
    fixtureCommit($directory, 'add a file on a side branch');
    fixtureGit($directory, 'checkout -q ' . escapeshellarg($mainline));
    fixtureGit($directory, '-c commit.gpgsign=false merge -q --no-ff -m ' . escapeshellarg('merge the side branch') . ' side');

    // Off the mainline and reached by tag, so the commits above keep their distance from HEAD: paths the
    // measured set has to sort, and the four renames it has to tell apart.
    fixtureGit($directory, 'checkout -q -b extras');
    writeFixture($directory, 'tests/Timesheet/Helper.php', "<?php\nnamespace App\\Tests\\Timesheet;\nclass Helper {}\n");
    writeFixture($directory, 'src/notes.txt', "a note beside the code\n");
    writeFixture($directory, 'docs/a.txt', "one line\n");
    fixtureCommit($directory, 'add paths outside the measured set');
    fixtureGit($directory, 'tag extras-paths');
    fixtureGit($directory, 'mv src/notes.txt src/Notes.php');
    fixtureGit($directory, 'mv src/Timesheet/Merged.php src/Timesheet/MergedAgain.php');
    fixtureGit($directory, 'mv docs/a.txt docs/b.txt');
    writeFixture($directory, 'docs/SomethingTest.php', "<?php\nnamespace App\\Docs;\nclass SomethingTest {}\n");
    writeFixture($directory, 'tests/Timesheet/ClockTest.php', testFixture([
        'testNow' => '$c = new Clock(); $c->now();',
        'testAgain' => '$c = new Clock(); $c->now();',
        'testRuntime' => '$x = new $name();']));
    fixtureCommit($directory, 'rename into, within and outside the measured set');
    fixtureGit($directory, 'tag extras-renames');

    // A production class the test file above already calls, so the call lands on the increment side alone:
    // the population is read per side, and at the baseline this class is not in it.
    writeFixture($directory, 'src/Timesheet/Clock.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class Clock
        {
            public function now(): \DateTime
            {
                return new \DateTime();
            }
        }
        PHP);
    writeFixture($directory, 'tests/Timesheet/ClockTest.php', testFixture([
        'testNow' => '$c = new Clock(); $c->now();',
        'testAgain' => '$c = new Clock(); $c->now();',
        'testRuntime' => '$x = new $name();',
        'testQuiet' => '$x = 1;']));
    fixtureCommit($directory, 'add the production class the tests already name');
    fixtureGit($directory, 'tag extras-measure');

    // The same calls over fewer test methods: the total holds where the rate moves, which is the transition
    // the measure is read on.
    writeFixture($directory, 'tests/Timesheet/ClockTest.php', testFixture([
        'testNow' => '$c = new Clock(); $c->now();',
        'testAgain' => '$c = new Clock(); $c->now();',
        'testRuntime' => '$x = new $name();']));
    fixtureCommit($directory, 'drop a test method that called nothing');
    fixtureGit($directory, 'tag extras-pooled');

    // A guard that fell, and a touched file declaring an abstraction that was there at the baseline.
    writeFixture($directory, 'tests/Timesheet/ClockTest.php', testFixture([
        'testNow' => '$c = new Clock(); $c->now();',
        'testAgain' => '$c = new Clock(); $c->now();']));
    writeFixture($directory, 'src/Timesheet/RoundingInterface.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        // touched, and an abstraction the baseline already had
        interface RoundingInterface
        {
            public function round(int $seconds): int;
        }
        PHP);
    fixtureCommit($directory, 'drop the runtime class name from the tests');
    fixtureGit($directory, 'tag extras-quiet');

    // The same file reaching much further, so the two sides fall in bands of their own.
    writeFixture($directory, 'src/Timesheet/Clock.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class Clock
        {
            public function now(Alpha $a, Beta $b, Gamma $c, Delta $d, Epsilon $e, Zeta $f, Eta $g, Theta $h,
                Iota $i, Kappa $j): \DateTime
            {
                return new \DateTime();
            }
        }
        PHP);
    fixtureCommit($directory, 'reach ten more classes from one file');
    fixtureGit($directory, 'tag extras-reaching');

    fixtureGit($directory, 'mv tests/Timesheet/ClockTest.php tests/Timesheet/ClockRenamedTest.php');
    fixtureCommit($directory, 'rename a test file within the measured set');
    fixtureGit($directory, 'tag extras-testrename');

    // A method that grows and is then taken out of a file that survives: the fall carries the figure it held.
    writeFixture($directory, 'src/Timesheet/MergedAgain.php',
        "<?php\nnamespace App\\Timesheet;\nclass Merged { public function m(): void { if (\$a) { if (\$b) {} } } }\n",
        ['App\\Timesheet\\Merged::m' => 3]);
    fixtureCommit($directory, 'grow the control flow of one method');
    fixtureGit($directory, 'tag extras-grown');
    writeFixture($directory, 'src/Timesheet/MergedAgain.php',
        "<?php\nnamespace App\\Timesheet;\nclass Merged { public function k(): void {} }\n");
    fixtureCommit($directory, 'take that method out of the file');
    fixtureGit($directory, 'tag extras-dropped');

    // An abstraction a class reaches by using it rather than by implementing it.
    writeFixture($directory, 'src/Timesheet/RoundingTrait.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        trait RoundingTrait
        {
            public function round(int $seconds): int
            {
                return $seconds - ($seconds % 60);
            }
        }
        PHP);
    writeFixture($directory, 'src/Timesheet/Holder.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class Holder
        {
            public function make(): RoundingInterface
            {
                return new class implements RoundingInterface {
                    public function round(int $seconds): int
                    {
                        return $seconds;
                    }
                };
            }
        }
        PHP);
    writeFixture($directory, 'src/Timesheet/RoundedClock.php', <<<'PHP'
        <?php
        namespace App\Timesheet;
        class RoundedClock
        {
            use RoundingTrait;

            public function at(int $seconds): int
            {
                return $this->round($seconds) + 1;
            }
        }
        PHP);
    fixtureCommit($directory, 'add a trait and a class using it');
    fixtureGit($directory, 'tag extras-trait');
    fixtureGit($directory, 'checkout -q ' . escapeshellarg($mainline));

    return $directory;
}

/**
 * One test class for the fixture repository, its methods given as body by name.
 *
 * @param array<string, string> $methods
 */
function testFixture(array $methods): string
{
    $code = "<?php\nnamespace App\\Tests\\Timesheet;\nuse App\\Timesheet\\Clock;\nclass ClockTest\n{\n";
    foreach ($methods as $name => $body) {
        $code .= "    public function {$name}(): void\n    {\n        {$body}\n    }\n";
    }

    return $code . "}\n";
}

/**
 * One file of the fixture repository, and — where its methods carry any — the figures the analysis of the
 * commit holding it would report for them. The fixture declares those rather than computing them: the figure
 * is what the assessment reads from outside, so the self test has to hand it in from outside too.
 *
 * @param array<string, int> $figures
 */
/**
 * A server that answers the way one fault of an analysis looks, so the stops that read it can be run against
 * something. The path names the fault — `/unread`, `/silent`, `/threshold` — and the port is picked free.
 *
 * The self test owns it for the length of the repository cases and shuts it down with them.
 */
function stubServer(string $directory): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
    fclose($probe);
    writeFixture($directory, 'stub.php', <<<'PHP'
        <?php
        [$fault] = array_slice(explode('/', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), 1);
        $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        header('Content-Type: application/json');
        if (str_contains($path, '/api/components/tree')) {
            echo json_encode(['paging' => ['total' => 1], 'components' => [['path' => 'Timesheet/TimesheetService.php']]]);
        } elseif (str_contains($path, '/api/issues/search')) {
            $message = match ($fault) {
                'unread' => 'Cognitive Complexity of this function is 9, above the limit.',
                'threshold' => 'Refactor this function to reduce its Cognitive Complexity from 20 to the 15 allowed.',
                default => null,
            };
            echo json_encode(['paging' => ['total' => $message === null ? 0 : 1],
                'issues' => $message === null ? [] : [['component' => 'x:Timesheet/TimesheetService.php',
                    'line' => 6, 'message' => $message]]]);
        } elseif (str_contains($path, '/api/qualityprofiles/search')) {
            echo json_encode(['profiles' => [['language' => 'php', 'key' => 'p1', 'name' => 'stub']]]);
        } elseif (str_contains($path, '/api/rules/search')) {
            echo json_encode(['actives' => ['php:S3776' => [['params' => [['key' => 'threshold', 'value' => '0']]]]]]);
        } else {
            echo json_encode([]);
        }
        PHP);
    $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' '
        . escapeshellarg($directory . '/stub.php') . ' >/dev/null 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($command));
    for ($waited = 0; $waited < 50; ++$waited) {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $code, $error, 0.1);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }
    register_shutdown_function(static function () use ($pid): void {
        if ($pid > 0) {
            shell_exec('kill ' . $pid . ' 2>/dev/null');
        }
    });

    return $port;
}

function writeFixture(string $directory, string $path, string $code, array $figures = []): void
{
    global $fixtureFigures;
    // Every file registers, whether or not it carries a figure: the entry is what says the blob was analysed.
    $fixtureFigures[md5($code)] = $figures;
    $full = $directory . '/' . $path;
    if (!is_dir(dirname($full))) {
        mkdir(dirname($full), 0o777, true);
    }
    file_put_contents($full, $code);
}

function fixtureGit(string $directory, string $args): void
{
    shell_exec('cd ' . escapeshellarg($directory) . ' && git ' . $args . ' 2>/dev/null');
}

function fixtureCommit(string $directory, string $message): void
{
    fixtureGit($directory, 'add -A');
    fixtureGit($directory, '-c commit.gpgsign=false commit -q -m ' . escapeshellarg($message));
}

/**
 * Remove the fixture directory, and nothing else: the path has to be the one this file builds under the
 * system temporary directory before a single file is unlinked.
 */
function removeDirectory(string $directory): void
{
    $mine = [sys_get_temp_dir() . '/assessment-selftest-', sys_get_temp_dir() . '/assessment-scan-'];
    if (!is_dir($directory) || array_filter($mine, static fn (string $prefix): bool => str_starts_with($directory, $prefix)) === []) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}

/** Test unit dependency: what counts as a call into production, and what the same walk cannot attribute. */
function selfTestTestMeasure(): void
{
    $production = ['App\Svc' => true];
    $case = static fn (string $body): array => unitDependency(
        methodOf("namespace App\Tests; use App\Svc; class TTest { public function testX() { {$body} } }", 'App\Tests\TTest::testX'),
        $production);

    $typed = static fn (string $body): array => unitDependency(
        methodOf("namespace App\Tests; use App\Svc; class TTest { public function testX(Svc \$s) { {$body} } }", 'App\Tests\TTest::testX'),
        $production);

    check(['calls' => 1, 'unresolved' => 0], $case('$s = new Svc();'),
        'a construction is a call on __construct');
    check(['calls' => 2, 'unresolved' => 0], $typed('$s->a(); $s->b();'),
        'two methods of one production class are two calls');
    check(['calls' => 1, 'unresolved' => 0], $typed('$s->a(); $s->a();'),
        'the same call twice is one unique call');
    check(['calls' => 1, 'unresolved' => 0], $case('Svc::make();'),
        'a static call into production is a call on the method it names');
    check(['calls' => 0, 'unresolved' => 0], $case('$this->assertTrue(true);'),
        'a call on $this reaches the test own helpers and is never production');
    check(['calls' => 0, 'unresolved' => 0], $case('$m = $this->createMock(Svc::class);'),
        'a class handed to a mock builder is a reference and not a call');
    check(['calls' => 0, 'unresolved' => 1], $case('$x->foo();'),
        'a receiver the pass cannot resolve is counted as unresolved and never as a call');
    check(['calls' => 1, 'unresolved' => 0], unitDependency(
        methodOf('namespace App\Tests; use App\Svc; class TTest { public function testY(Svc $s) { $s->a(); } }', 'App\Tests\TTest::testY'),
        $production), 'a receiver typed as a parameter resolves');
    check(['calls' => 0, 'unresolved' => 0], $case('$s = new \Vendor\Other(); $s->a();'),
        'a call into a class outside production counts on neither side');

    $isTest = static fn (string $declaration): bool => isTestMethod(methodOf(
        "namespace App\Tests; class TTest { {$declaration} }", 'App\Tests\TTest::' . preg_replace('/^.*function (\w+).*$/s', '$1', $declaration)));

    check(true, $isTest('public function testOne() {}'), 'a method named test is a test method');
    check(true, $isTest('#[Test] public function two() {}'), 'so is one carrying the Test attribute');
    check(true, $isTest('/** @test */ public function three() {}'), 'so is one annotated @test');
    check(false, $isTest('public function helper() {}'), 'a plain helper is not');
    check(false, $isTest('/** @testWith [1] */ public function four() {}'),
        'and neither is one carrying an annotation that merely starts with the word');
    check(false, $isTest('public function doTestSetup() {}'),
        'nor is one whose name merely holds the word — the convention is a prefix');
    check(false, $isTest('private function testHidden() {}'), 'and neither is a method that is not public');

    check(['calls' => 0, 'unresolved' => 1], $case('$this->service->doThing();'),
        'a receiver held in a property is not resolved, so the call it makes is a miss and not a call');
    check(['calls' => 2, 'unresolved' => 0], $case('(new Svc())->a();'),
        'a receiver constructed on the spot is resolved, and its construction is a call of its own');
    check(['calls' => 1, 'unresolved' => 0], unitDependency(
        methodOf('namespace App\Tests; class TTest { public function testX(\Vendor\Other $o) { $o->a(); $s = new \App\Svc(); } }', 'App\Tests\TTest::testX'),
        $production), 'a parameter typed outside production resolves to nothing to count and to no miss either');
    check(['calls' => 0, 'unresolved' => 0], $case('\Vendor\Other::make();'),
        'a static call outside production counts on neither side');
    check(['calls' => 0, 'unresolved' => 0], $case('(new \Vendor\Other())->a();'),
        'nor does a receiver constructed outside production');
    check(['calls' => 0, 'unresolved' => 1], unitDependency(
        methodOf('namespace App\Tests; class TTest { public function testX(int $n) { $n->foo(); } }', 'App\Tests\TTest::testX'),
        $production), 'a parameter declared as a scalar names no class, so the call on it is a miss');
    check(['calls' => 2, 'unresolved' => 0], $case('$s = new Svc(); $s?->a();'),
        'a nullsafe call reaches production the way an ordinary one does');
    check(['calls' => 1, 'unresolved' => 1], $case('$s = new Svc(); $s->$m();'),
        'a method named at runtime is a site this pass could not attribute, and a miss even where the receiver resolved');
    check(['calls' => 1, 'unresolved' => 1], $case('$s = new Svc(); $s->{"calc"}();'),
        'and so is one named by an expression, whatever that expression would evaluate to');
    check(['calls' => 0, 'unresolved' => 1], $case('Svc::$m();'),
        'a static call names its method at runtime the same way, and is the same miss');
    check(['calls' => 0, 'unresolved' => 0], $case('$o = new \Vendor\Other(); $o->$m();'),
        'but a receiver resolved outside production makes no call to miss, whatever names the method');
    check(['calls' => 0, 'unresolved' => 0], $case('$this->$m();'),
        'and neither does the test class calling through itself');
    check(['calls' => 0, 'unresolved' => 1], $case('$c = "App\Svc"; (new $c())->$m();'),
        'a construction from a runtime class name is one miss whether or not the method is named at runtime too');
    check(['calls' => 0, 'unresolved' => 1], $case('$c = "App\Svc"; (new $c())->calc();'),
        'a construction from a runtime class name is one miss and not two');
    check(['calls' => 0, 'unresolved' => 1], $case('$x = new $class();'),
        'a class named at runtime in a test method is a miss, counted by the same guard the production side uses');
    check(['calls' => 1, 'unresolved' => 1], unitDependency(
        methodOf('namespace App\Tests; use App\Svc; class TTest { public function testX() { $f = function () { $s = new Svc(); }; $s->zzz(); } }', 'App\Tests\TTest::testX'),
        $production), 'a variable typed inside a closure is not the variable outside it, which stays a miss');
    check(['calls' => 4, 'unresolved' => 0], unitDependency(
        methodOf('namespace App\Tests; use App\Svc; use App\Other; class TTest { public function testX() { $s = new Svc(); $s->a(); $s = new Other(); $s->a(); } }', 'App\Tests\TTest::testX'),
        ['App\Svc' => true, 'App\Other' => true]),
        'a receiver reassigned mid-method keys its earlier calls to what it held then, so one call name lands on two classes');
    check(['calls' => 1, 'unresolved' => 0], unitDependency(
        methodOf('namespace App\Tests; use App\Svc; class TTest { public function testX() { $f = function (Svc $s) { $s->a(); }; } }', 'App\Tests\TTest::testX'),
        $production), 'a closure parameter declares its receiver the way a test method parameter does');
    check(['calls' => 1, 'unresolved' => 0], unitDependency(
        methodOf('namespace App\Tests; use App\Svc; class TTest { public function testX() { $f = fn (Svc $s) => $s->a(); } }', 'App\Tests\TTest::testX'),
        $production), 'and so does an arrow function parameter');
    check(['calls' => 1, 'unresolved' => 0], unitDependency(
        methodOf('namespace App\Tests; use App\Svc; class TTest { public function testX() { $s = new Svc(); $f = function (\Vendor\Row $s) { $s->a(); }; } }', 'App\Tests\TTest::testX'),
        $production), 'a closure parameter shadows what the method holds under that name, so the call inside it is none of the method\'s');
    check(['calls' => 2, 'unresolved' => 0], unitDependency(
        methodOf('namespace App\Tests; use App\Svc; class TTest { public function testX() { $s = new Svc(); $f = function () use ($s) { $s->a(); }; } }', 'App\Tests\TTest::testX'),
        $production), 'a call a closure makes on a receiver typed outside it is still the test reaching into production');
    check(['calls' => 1, 'unresolved' => 1], $case('$s = new Svc(); $s = $this->createMock(Svc::class); $s->a();'),
        'a receiver reassigned from anything but a construction holds a type the pass cannot name, so the call on it is a miss');
    check(['calls' => 2, 'unresolved' => 0], $case('$s = new Svc(); $s = $s->wrap();'),
        'the right-hand side is read before the assignment lands, so a call on the variable being reassigned is a call on what it held');
    check(['calls' => 1, 'unresolved' => 1], $case('$s = new Svc(); foreach ($rows as $s) { $s->a(); }'),
        'a loop variable is bound by the loop and no longer holds what the method assigned it');
    check(['calls' => 1, 'unresolved' => 1], $case('$s = new Svc(); foreach ($rows as $s => $v) { $s->a(); }'),
        'and so is the key it binds beside the value');
    check(['calls' => 2, 'unresolved' => 0], $case('$s = new Svc(); foreach ($s->all() as $s) {}'),
        'the subject of a loop is read before the loop binds anything, so a call in it is a call on what the name held');
    check(['calls' => 1, 'unresolved' => 1], $case('$s = new Svc(); [$s] = $rows; $s->a();'),
        'a destructuring binds every name it lists');
    check(['calls' => 1, 'unresolved' => 0], $case('$$name = new Svc();'),
        'a variable named at runtime binds no name this pass can read, and is no reason to stop reading the method');
    check(['calls' => 2, 'unresolved' => 0], $case('$s = new Svc(); $s->count = 1; $s->a();'),
        'a write to a property goes through the receiver, which holds what it held');
    check(['calls' => 2, 'unresolved' => 0], $case('$s = new Svc(); $s[0] = 1; $s->a();'),
        'and so does a write to an element of it');
    check(['calls' => 1, 'unresolved' => 1], unitDependency(
        methodOf('namespace App\Tests; use App\Svc; class TTest { public function testX() { $s = new Svc(); $f = function ($s) { $s->a(); }; } }', 'App\Tests\TTest::testX'),
        $production), 'a closure parameter declaring no type shadows the enclosing name all the same, holding what the pass cannot say');
    check(['methods' => 2, 'calls' => 3, 'unresolved' => 0, 'callsPerMethod' => 1.5],
        callsPerTestMethod('<?php namespace App\Tests; use App\Svc; class TTest { public function testX() { $s = new Svc(); $s->a(); } public function testY() { Svc::make(); } }', $production)['App\Tests\TTest'],
        'a class rate is its calls over its test methods, both counted over the class and not over one method');
    check(['calls' => 0, 'unresolved' => 0], $case('$x = new class { public function m() { $s = new Svc(); $s->a(); } };'),
        'a class-like declared inside the test method is not read at all, calls and misses alike');
    check(['calls' => 0, 'unresolved' => 0], $case('$x = new class { public function m() { return new $c(); } };'),
        'and the guard stops where the walk stops, so a runtime class name in its body is none of this method\'s misses');
    check(['calls' => 0, 'unresolved' => 0], $case('function helper() { $s = new Svc(); return new $c(); }'),
        'a named function declared inside the test method is its own scope the same way');

    check([], callsPerTestMethod('<?php class TTest {', $production),
        'a test file that does not parse yields no test class to read');
    check([], callsPerTestMethod('<?php namespace App\Tests; $t = new class { public function testX() {} };', $production),
        'an anonymous test class has no name to be read under');
    check(['methods' => 1, 'calls' => 2, 'unresolved' => 0, 'callsPerMethod' => 2],
        callsPerTestMethod('<?php namespace App\Tests; use App\Svc; abstract class TTest { abstract public function testShape(); public function testX() { $s = new Svc(); $s->a(); } }', $production)['App\Tests\TTest'],
        'a declaration with no body is no test method to divide by, the same reading the production side gives it');
}

/** The five probes: the shape each one looks for, and the shape it must not mistake for it. */
function selfTestProbes(): void
{
    // Delegation ratio, and the forwarding body it rests on.
    $delegation = static fn (string $code, string $name): array => delegationProbe([$name => classOf($code, $name)]);
    check([['class' => 'App\S', 'forwards' => 2, 'methods' => 2, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; class S { public function __construct(private Dep $d) {} public function a() { return $this->d->a(); } public function b() { $this->d->b(); } }', 'App\S'),
        'every method forwarding to a collaborator under App\ is a pass-through, return or not');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'external', 'state' => 'adapter']],
        $delegation('namespace App; class S { public function __construct(private \Vendor\Client $c) {} public function a() { return $this->c->a(); } }', 'App\S'),
        'forwarding to a type outside App\ is an adapter');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 2, 'collaborator' => 'internal', 'state' => 'partial']],
        $delegation('namespace App; class S { public function __construct(private Dep $d) {} public function a() { return $this->d->a(); } public function b() { $x = 1; return $x; } }', 'App\S'),
        'a class that forwards some of its methods is neither a middle man nor an adapter');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; class S { public function __construct(private Dep $d) {} public function a() { return $this->d->a(); } public function noop(): void {} }', 'App\S'),
        'an empty body forwards nothing and weighs on neither side of the ratio');
    check([], $delegation('namespace App; class S { public function __construct(private Dep $d) { $this->d->init(); } }', 'App\S'),
        'a class whose only method is its constructor forwards nothing, however that constructor is written');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; class S { public function __construct(private Dep $d) { $this->d->init(); } public function a() { return $this->d->a(); } }', 'App\S'),
        'and a constructor that does forward is still no method of the ratio — wiring is not delegation');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 2, 'collaborator' => 'external', 'state' => 'partial']],
        $delegation('namespace App; class S { public function __construct(private \Vendor\Client $c) {} public function a() { return $this->c->a(); } public function b() { $x = 1; return $x; } }', 'App\S'),
        'partial outranks adapter: forwarding only some methods is neither shape, whatever they forward to');
    check([], $delegation('namespace App; interface I { public function a(); }', 'App\I'),
        'an interface has no body to forward');
    check(null, forwardedCollaborator(methodOf('namespace App; class S { public function a() { $this->d->a(); return 1; } }', 'App\S::a'), ['d' => 'App\Dep']),
        'a body of more than one statement is not forwarding, whatever its first statement does');
    check(null, forwardedCollaborator(methodOf('namespace App; class S { public function a() { return self::build(); } }', 'App\S::a'), []),
        'self names the class the body already sits in, so a call through it forwards to no collaborator');
    check(null, forwardedCollaborator(methodOf('namespace App; class S { public function a() { return static::build(); } }', 'App\S::a'), []),
        'and neither does static');
    check(null, forwardedCollaborator(methodOf('namespace App; class S { public function a() { return parent::build(); } }', 'App\S::a'), []),
        'nor parent, which the extends clause already names');
    check([], $delegation('namespace App; class S { public function a() { return self::build(); } }', 'App\S'),
        'a class calling through itself is neither a pass-through nor an adapter, and gets no row at all');
    check([], $delegation('namespace App; class S { public function a() { return S::build(); } }', 'App\S'),
        'and calling through its own name is the same call, whatever the source spells it');
    check([], $delegation('namespace App; class S { private S $next; public function a() { return $this->next->a(); } }', 'App\S'),
        'a property typed as the class itself names no collaborator either, under its own name as under self');
    check('App\Dep', forwardedCollaborator(methodOf('namespace App; class S { public function a() { return $this->d?->a(); } }', 'App\S::a'), ['d' => 'App\Dep']),
        'a nullsafe call forwards the same way');
    check('App\Registry', forwardedCollaborator(methodOf('namespace App; class S { public function a() { return Registry::get(); } }', 'App\S::a'), []),
        'a static call forwards to the class it names');
    check(null, forwardedCollaborator(methodOf('namespace App; class S { public function a() { return $this->name; } }', 'App\S::a'), ['name' => 'App\Dep']),
        'an ordinary getter returns a property rather than calling through it, and forwards nothing');
    check(null, forwardedCollaborator(methodOf('namespace App; class S { public function a($x) { return $x->a(); } }', 'App\S::a'), []),
        'a call on a local receiver forwards to no collaborator of the class');
    check(null, forwardedCollaborator(methodOf('namespace App; class S { public function a($x) { return $x->d->a(); } }', 'App\S::a'), ['d' => 'App\Dep']),
        'and a property of some other object is that object property, never resolved against this class own');
    check(null, forwardedCollaborator(methodOf('namespace App; class S { public function a() { if ($x) { return 1; } } }', 'App\S::a'), []),
        'and a single statement that is neither a return nor an expression is no forward');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; class S { private Dep $d; public function a() { return $this->d->a(); } }', 'App\S'),
        'a property declared in the class body resolves as well as a promoted parameter');
    check([], $delegation('namespace App; class S { private self $next; public function a() { return $this->next->a(); } }', 'App\S'),
        'a property typed as the class itself names no collaborator, whichever of the three names it is typed with');
    check([['class' => 'App\S', 'forwards' => 2, 'methods' => 2, 'collaborator' => 'internal and external', 'state' => 'adapter']],
        $delegation('namespace App; class S { public function __construct(private Dep $d, private \Vendor\Client $c) {} public function a() { return $this->d->a(); } public function b() { return $this->c->b(); } }', 'App\S'),
        'forwarding to both sides of the split is no pass-through, and names both kinds rather than borrowing the outcome word mixed');
    check([], $delegation('namespace App; class S { public function a() { $x = 1; return $x; } }', 'App\S'),
        'a class that forwards nothing gets no row at all');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; abstract class S { public function __construct(private Dep $d) {} abstract public function b(); public function a() { return $this->d->a(); } }', 'App\S'),
        'a declaration with no body is no method to forward, and never counts against the ratio');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; class S { public function __construct(private ?Dep $d) {} public function a() { return $this->d->a(); } }', 'App\S'),
        'a nullable collaborator is the same collaborator');
    check([], $delegation('namespace App; class S { public function __construct(private Dep|\Vendor\Client $d) {} public function a() { return $this->d->a(); } }', 'App\S'),
        'a union names more than one collaborator, so the forward is attributed to none of them');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; class S { public function __construct(private Dep|null $d) {} public function a() { return $this->d->a(); } }', 'App\S'),
        'a union with null in it names one collaborator, which is what the nullable spelling names too');
    check([], $delegation('namespace App; class S { public function __construct(private Dep|int $d) {} public function a() { return $this->d->a(); } }', 'App\S'),
        'a union of a class and a scalar names no single collaborator either — only null drops out of one');
    check([['class' => 'App\S', 'forwards' => 2, 'methods' => 2, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; class S { private Dep $a, $b; public function x() { return $this->a->x(); } public function y() { return $this->b->y(); } }', 'App\S'),
        'one declaration naming two properties types both of them');
    check([['class' => 'App\S', 'forwards' => 1, 'methods' => 1, 'collaborator' => 'internal', 'state' => 'pass-through']],
        $delegation('namespace App; class S { public function __CONSTRUCT(private Dep $d) { $this->d->init(); } public function a() { return $this->d->a(); } }', 'App\S'),
        'a method name is case-insensitive in PHP, so the constructor is left out of the ratio however it is spelled');
    check([], $delegation('namespace App; class S { public function __construct(Dep $d) { $this->d = $d; } public function a() { return $this->d->a(); } }', 'App\S'),
        'a constructor parameter that was never promoted is no property of the class');

    // Implementations, over a population handed in rather than read from a checkout.
    $population = [
        'App\Real' => ['parents' => ['App\I'], 'trivial' => false],
        'App\Second' => ['parents' => ['App\I'], 'trivial' => false],
        'App\Placeholder' => ['parents' => ['App\I'], 'trivial' => true],
        'App\Elsewhere' => ['parents' => ['App\J'], 'trivial' => false],
    ];
    check([['abstraction' => 'App\I', 'real' => 2, 'discounted' => 1, 'state' => 'polymorphic']],
        implementationsIn($population, ['App\I']), 'two real implementations are polymorphic, and a placeholder is discounted');
    check([['abstraction' => 'App\J', 'real' => 1, 'discounted' => 0, 'state' => 'single']],
        implementationsIn($population, ['App\J']), 'one real implementation is single');
    check([['abstraction' => 'App\Contract', 'real' => 2, 'discounted' => 1, 'state' => 'polymorphic']],
        implementationsIn([
            'App\Middle' => ['parents' => ['App\Contract'], 'trivial' => true],
            'App\First' => ['parents' => ['App\Middle'], 'trivial' => false],
            'App\Second' => ['parents' => ['App\Middle'], 'trivial' => false]], ['App\Contract']),
        'a class reaching an abstraction through a base of its own implements it too, however many names apart they are');
    check([['abstraction' => 'App\A', 'real' => 1, 'discounted' => 0, 'state' => 'single']],
        implementationsIn([
            'App\A' => ['parents' => ['App\B'], 'trivial' => false],
            'App\B' => ['parents' => ['App\A'], 'trivial' => false]], ['App\A']),
        'a class its own parent names back is still an implementation of it');
    check([['abstraction' => 'App\Elsewhere', 'real' => 0, 'discounted' => 0, 'state' => 'single']],
        implementationsIn([
            'App\A' => ['parents' => ['App\B'], 'trivial' => false],
            'App\B' => ['parents' => ['App\A'], 'trivial' => false]], ['App\Elsewhere']),
        'and a chain that turns back on itself is walked once and answered, not walked forever');
    check([['abstraction' => 'App\K', 'real' => 0, 'discounted' => 0, 'state' => 'single']],
        implementationsIn($population, ['App\K']), 'an abstraction nothing implements is single');
    check(['App\Base'], declaredParents(classOf('namespace App; class C extends Base {}', 'App\C')),
        'a class declares itself against what it extends');
    check(['App\Contract'], declaredParents(classOf('namespace App; class C implements Contract {}', 'App\C')),
        'and against what it implements');
    check(['App\Helper'], declaredParents(classOf('namespace App; class C { use Helper; }', 'App\C')),
        'and against the traits it uses');
    check(['App\Contract'], declaredParents(classOf('namespace App; interface I extends Contract {}', 'App\I')),
        'an interface declares itself against the interfaces it extends');
    check(['App\Contract'], declaredParents(classOf('namespace App; enum E: string implements Contract { case One = "one"; }', 'App\E')),
        'an enum declares itself against the interfaces it implements');
    check([], declaredParents(classOf('namespace App; class C { public function m() { $x = new class { use Helper; }; } }', 'App\C')),
        'a trait used by an anonymous class inside a method belongs to that class and not to the one holding it');

    $trivial = static fn (string $body): bool => isTrivialImplementation(
        classOf("namespace App; class N { public function c() { {$body} } }", 'App\N'));
    check(true, $trivial('return 0;'), 'a body that returns a constant is trivial');
    check(true, $trivial('return;'), 'so is one that returns nothing');
    check(true, $trivial('return [];'), 'so is one that returns an empty list');
    check(true, $trivial(''), 'so is an empty body');
    check(true, $trivial('return true;'), 'so is one that returns a constant by name');
    check(false, $trivial('$x = 1; return $x;'), 'a body that computes is not');
    check(false, $trivial('return "value: $this->name";'),
        'a string built from what the object holds is no constant, however short the body reads');
    check(true, $trivial('return __CLASS__;'),
        'a magic constant is one, resolved where it is written and reading nothing of the object');
    check(false, $trivial('return ["a" => 1];'),
        'a list with something in it is a body that builds something, however short it reads');
    check(false, $trivial('return 0; $x = 1;'),
        'and a body of more than one statement is no placeholder, whatever its first statement returns');
    check([true, true], [$trivial('return -1;'), $trivial('return +1;')],
        'a literal with a sign in front of it is the same literal, either sign, and a body returning one is the same placeholder');
    check(true, $trivial('return self::NONE;'),
        'and so is a class constant, which is the shape a placeholder returning a named nought takes');
    check(false, $trivial('return new class { public function x() { $a = 1; return $a; } };'),
        'a body that constructs something is no constant return, whatever the class it constructs holds');
    check(true, isTrivialImplementation(classOf('namespace App; interface I { public function c(): int; }', 'App\I')),
        'and a declaration with no body at all is trivial');

    // Mock seam.
    check([['class' => 'App\Svc', 'state' => 'internal']],
        mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = $this->createMock(\App\Svc::class); } }'),
        'a mocked class under App\ is own production code');
    check([['class' => 'Vendor\Client', 'state' => 'boundary']],
        mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = $this->getMockBuilder(\Vendor\Client::class); } }'),
        'a mocked class outside it is a boundary');
    check([['class' => 'AppBundle\Legacy\Client', 'state' => 'boundary']],
        mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = $this->createMock(\AppBundle\Legacy\Client::class); } }'),
        'the namespace App\ is what own production code starts with, separator and all');
    check([['class' => 'Vendor\App\Client', 'state' => 'boundary']],
        mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = $this->createMock(\Vendor\App\Client::class); } }'),
        'and own production code is what a name starts with, never what it holds somewhere along the way');
    check([['class' => 'App\Svc', 'state' => 'internal']],
        mockSeamProbe('<?php namespace App\Tests; class TTest { public function setUp(): void { $a = $this->createMock(\App\Svc::class); } }'),
        'a double built in setUp is read as well as one built in the test method');
    check([], mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = $this->createMock($name); } }'),
        'a mock built from a variable names no class to read');
    check([['class' => 'App\Svc', 'state' => 'internal']],
        mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = self::createMock(\App\Svc::class); } }'),
        'a builder called statically is read as well as one called on $this');
    check([], mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = $this->createMock(); } }'),
        'a builder handed nothing names no class either');
    check([], mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = $this->createMock($other::class); } }'),
        'nor does one handed a class named at runtime');
    check([], mockSeamProbe('<?php class TTest {'),
        'a test file that does not parse hands nothing to a builder');
    check([], mockSeamProbe('<?php namespace App\Tests; class TTest { public function testX() { $a = $this->createMock(...$args); } }'),
        'nor does one handed its arguments through a spread, which names no class the probe can read');
    foreach (['createStub', 'createPartialMock', 'createConfiguredMock', 'createMockForIntersectionOfInterfaces',
        'prophesize'] as $builder) {
        check([['class' => 'App\Svc', 'state' => 'internal']],
            mockSeamProbe("<?php namespace App\\Tests; class TTest { public function testX() { \$a = \$this->{$builder}(\\App\\Svc::class); } }"),
            'the probe reads every builder a double is written with, ' . $builder . ' among them');
    }
}

/** Fan out score and its bands, and the direction the question blocks read every figure with. */
function selfTestBands(): void
{
    check('A', band(90.0), 'a score of 90 is an A');
    check('B', band(89.99), 'and anything under it is a B, each cut belonging to the band above it');
    check('B', band(80.0), 'a score of 80 is a B');
    check('C', band(70.0), 'a score of 70 is a C');
    check('D', band(50.0), 'a score of 50 is a D');
    check('E', band(40.0), 'a score of 40 is an E');
    check('F', band(39.99), 'and anything under 40 is an F');
    check(100.0, fanOutScore(0.0, 0.0), 'a file referencing nothing scores 100');
    check(62.42, round(fanOutScore(8.0, 2.0), 2), 'an internal reference weighs four times an external one in the score');
    check(['up', 'down', 'unmoved'], [direction(1.0, 2.0), direction(2.0, 1.0), direction(1.0, 1.0)],
        'a figure moves up, down, or not at all');
    check('unmoved', direction(0.071429, 0.068966),
        'two figures that print the same have not moved: the reader subtracts what is printed, and every figure here is printed to the cent');
    check(['unmoved', 'live', 'absent', 'absent'],
        [stateOf(79.996013, 80.000100), stateOf(79.996013, 80.010000), stateOf(null, 80.0), stateOf(80.0, null)],
        'a measure is unmoved where its two sides print the same figure, live where they do not, and absent where a side had nothing to read');
    check(['down', 'up'], [direction(0.075, 0.068), direction(1.0, 1.006)],
        'and a move the cent can hold is a move, either way');
}

/** The two question blocks: every outcome each one can reach, and the readings that leave it unreachable. */
function selfTestQuestions(): void
{
    $outcome = static fn (?array $result, ?array $test): string => questionOne($result, $test)['outcome'];

    // First group — files added, no control flow with them, the same test methods reaching further.
    $firstGroup = figures(['fan' => ['nIncrement' => 3], 'delegation' => [['state' => 'pass-through']],
        'implementations' => [['state' => 'single']]]);
    $firstTest = testFigures(2, 2, 2.0, 3.0, [['state' => 'internal']]);
    check('ceremony — names added, nothing simplified', $outcome($firstGroup, $firstTest),
        'the first group is ceremony where all three probes agree');
    check('real work — the new file earns its place',
        $outcome(array_merge($firstGroup, ['delegation' => [['state' => 'adapter']]]), $firstTest),
        'an adapter is enough to part the first group from ceremony');
    check('real work — the new file earns its place',
        $outcome(array_merge($firstGroup, ['implementations' => [['state' => 'polymorphic']]]), $firstTest),
        'polymorphism already there is enough');
    check('real work — the new file earns its place',
        $outcome($firstGroup, testFigures(2, 2, 2.0, 3.0, [['state' => 'boundary']])),
        'a mocked boundary is enough');
    check('not settled — a probe this group needs had nothing to read',
        $outcome(array_merge($firstGroup, ['implementations' => []]), $firstTest),
        'the first group is not settled where a probe it needs has nothing to read and the rest point at ceremony');
    check('real work — the new file earns its place',
        $outcome(array_merge($firstGroup, ['implementations' => [], 'delegation' => [['state' => 'adapter']]]), $firstTest),
        'but one probe pointing the other way is enough on its own, whether or not the others had anything to read');
    check('delegation ratio pass-through, implementations had nothing to read, mock seam internal',
        questionOne(array_merge($firstGroup, ['implementations' => []]), $firstTest)['probes'],
        'and the block names the probe that had nothing to read rather than passing over it');
    check('real work — the new file earns its place',
        $outcome(array_merge($firstGroup, ['delegation' => [['state' => 'pass-through'], ['state' => 'adapter']]]), $firstTest),
        'one probe whose own rows disagree is a probe that does not agree');
    check('delegation ratio pass-through, implementations single, mock seam internal — all three agree',
        questionOne($firstGroup, $firstTest)['probes'],
        'the first group says so where every probe points the same way');
    check('delegation ratio adapter, implementations single, mock seam internal',
        questionOne(array_merge($firstGroup, ['delegation' => [['state' => 'adapter']]]), $firstTest)['probes'],
        'and says nothing of agreement where one of them points the other way');

    // A `partial` row is neither shape, so it neither agrees nor disagrees: the touched class that forwards
    // some of its bodies and not others says nothing about the class the increment added.
    check('ceremony — names added, nothing simplified',
        $outcome(array_merge($firstGroup, ['delegation' => [['state' => 'pass-through'], ['state' => 'partial']]]), $firstTest),
        'a partial row beside a pass-through does not part the first group from ceremony');
    check('real work — the new file earns its place',
        $outcome(array_merge($firstGroup, ['delegation' => [['state' => 'adapter'], ['state' => 'partial']]]), $firstTest),
        'and an adapter beside one still parts it');
    check('not settled — a probe this group needs had nothing to read',
        $outcome(array_merge($firstGroup, ['delegation' => [['state' => 'partial']]]), $firstTest),
        'a probe whose every row is partial read no shape, which settles nothing');
    check('delegation ratio partial only, neither shape, implementations single, mock seam internal',
        questionOne(array_merge($firstGroup, ['delegation' => [['state' => 'partial']]]), $firstTest)['probes'],
        'and the block says so rather than claiming the probe found nothing at all');

    // Second group — files added, control flow added, test methods added.
    $secondGroup = static fn (int $riseAdded, int $riseExisting, int $fallExisting): array => figures([
        'fan' => ['nIncrement' => 3], 'cognitive' => ['totalIncrement' => 9,
            'leaving' => ['fallExisting' => $fallExisting, 'fallDeleted' => 0, 'riseAdded' => $riseAdded,
                'riseExisting' => $riseExisting, 'state' => $fallExisting > 0 ? 'relocated' : 'written']]]);
    $secondTest = testFigures(2, 4, 2.0, 2.0);
    check('real work — the new control flow is new behaviour', $outcome($secondGroup(4, 0, 0), $secondTest),
        'the second group is real work where the rise sits in methods the increment added and nothing fell');
    check('ceremony — the new files carry nothing', $outcome($secondGroup(0, 4, 0), $secondTest),
        'the second group is ceremony where the rise sits in a method that was already there');
    check('mixed — a refactor and a feature in one increment', $outcome($secondGroup(4, 0, 4), $secondTest),
        'a fall beside the rise makes the second group mixed');
    check('mixed — a refactor and a feature in one increment', $outcome($secondGroup(4, 0, 1), $secondTest),
        'and one unit of fall is a fall, the probe reading whether control flow left rather than how much of it did');
    check('not settled — the rise landed on both sides of the attribution split', $outcome($secondGroup(4, 4, 0), $secondTest),
        'a rise in both the added methods and the ones already there is neither row of this group: the added files carry something and so does what was there');
    check('not detected', $outcome($secondGroup(4, 0, 0), testFigures(2, 4, 2.0, 3.0)),
        'test methods added while each reaches further is neither the second group nor any other reading');
    check('attribution — rise 4 in method(s) added, rise 0 in method(s) already there; control flow leaving — fall 2 in method(s) that still exist, fall 0 in method(s) deleted',
        questionOne($secondGroup(4, 0, 2), $secondTest)['probes'],
        'and the second group names each figure against the label it was read under');
    check('mixed — a refactor and a feature in one increment',
        $outcome(figures(['fan' => ['nIncrement' => 3], 'cognitive' => ['totalIncrement' => 9,
            'leaving' => ['fallExisting' => 0, 'fallDeleted' => 3, 'riseAdded' => 6, 'riseExisting' => 0,
                'state' => 'written']]]), $secondTest),
        'a fall in a method the increment deleted is a fall on the baseline side, so the second group reads it as relocation too');

    // Third group — files added, control flow added, the same test methods reaching further.
    $thirdGroup = static fn (string $implementations, array $attribution): array => figures([
        'fan' => ['nIncrement' => 3],
        'cognitive' => ['totalIncrement' => 9, 'attribution' => $attribution],
        'implementations' => [['state' => $implementations]]]);
    $added = [['method' => 'App\F::for', 'baseline' => null, 'increment' => 3, 'state' => 'added']];
    $existing = [['method' => 'App\Computes::calculate', 'baseline' => 2, 'increment' => 6, 'state' => 'rose']];
    $thirdTest = testFigures(2, 2, 2.0, 3.0);
    check('ceremony that branches — a factory or a dispatcher, not a feature',
        $outcome($thirdGroup('single', $added), $thirdTest),
        'the third group is ceremony that branches where the rise came in with a method the increment added and there is one implementation to select among');
    check('real work — a fix or a rule the existing test methods already assert',
        $outcome($thirdGroup('polymorphic', $added), $thirdTest),
        'load-bearing polymorphism is real work');
    check('real work — a fix or a rule the existing test methods already assert',
        $outcome($thirdGroup('single', $existing), $thirdTest),
        'a rise in a method that was already there is an existing unit taking on a rule, not a name added beside it');
    check('real work — a fix or a rule the existing test methods already assert',
        $outcome($thirdGroup('single', array_merge($added, $existing)), $thirdTest),
        'and a rise in both is not the added file carrying it alone');
    check('implementations single, attribution rise in a method the increment added',
        questionOne($thirdGroup('single', $added), $thirdTest)['probes'],
        'and the third group names both probes it read');
    check('implementations single, attribution rise in a method that was already there',
        questionOne($thirdGroup('single', $existing), $thirdTest)['probes'],
        'naming where the rise landed when it landed outside the added methods');
    check('implementations single, attribution rise in both',
        questionOne($thirdGroup('single', array_merge($added, $existing)), $thirdTest)['probes'],
        'and saying so where it landed on both sides of that split');
    check('not detected', $outcome($thirdGroup('single', $added), testFigures(2, 2, 3.0, 3.0)),
        'the same production figures with a rate that held reach no group at all, the test side being what parts them');
    check('implementations single, attribution had nothing to read',
        questionOne($thirdGroup('single', [['method' => 'App\F::for', 'baseline' => null, 'increment' => 0, 'state' => 'added']]),
            $thirdTest)['probes'],
        'a method carrying no control flow is no rise, whichever side it landed on');

    // The worked example of the methodology, in figures: an extraction whose added rows carry the relocated
    // body, and a factory that branches beside them.
    $extraction = figures(['fan' => ['nIncrement' => 4], 'cognitive' => ['totalIncrement' => 7,
        'attribution' => [
            ['method' => 'App\Timesheet\TimesheetService::sumDuration', 'baseline' => 6, 'increment' => 0, 'state' => 'fell'],
            ['method' => 'App\Timesheet\TimesheetCalculator::calculate', 'baseline' => null, 'increment' => 6, 'state' => 'added'],
            ['method' => 'App\Timesheet\PlaceholderCalculator::calculate', 'baseline' => null, 'increment' => 0, 'state' => 'added'],
            ['method' => 'App\Timesheet\TimesheetCalculatorFactory::for', 'baseline' => null, 'increment' => 1, 'state' => 'added']],
        'leaving' => ['fallExisting' => 6, 'fallDeleted' => 0, 'riseAdded' => 7, 'riseExisting' => 0,
            'state' => 'relocated']],
        'implementations' => [['state' => 'single']]]);
    check('ceremony that branches — a factory or a dispatcher, not a feature', $outcome($extraction, $thirdTest),
        'an extraction whose rise came in with the methods it added reads as ceremony that branches');
    check('real work — a fix or a rule the existing test methods already assert',
        $outcome(array_merge($extraction, ['implementations' => [['state' => 'single'], ['state' => 'polymorphic']]]), $thirdTest),
        'implementations disagreeing among themselves is not one implementation to select among');
    check('real work — a fix or a rule the existing test methods already assert',
        questionOne(figures(['fan' => ['nIncrement' => 3], 'cognitive' => ['totalIncrement' => 9],
            'implementations' => [['state' => 'single']]]), $thirdTest)['outcome'],
        'and a group with no attribution to read reaches no ceremony either');

    // Fourth group — files added, control flow removed. Told apart on the production side alone.
    $fourthGroup = static fn (int $fallExisting, int $fallDeleted): array => figures([
        'fan' => ['nIncrement' => 3], 'cognitive' => ['totalIncrement' => 1,
            'leaving' => ['fallExisting' => $fallExisting, 'fallDeleted' => $fallDeleted, 'riseAdded' => 0,
                'riseExisting' => 0, 'state' => $fallExisting > 0 ? 'relocated' : 'written']]]);
    check('real decomposition — the split removed reading cost', $outcome($fourthGroup(4, 0), null),
        'a fall in methods that still exist is decomposition, and it reads without the test measure');
    check('deletion, not decomposition', $outcome($fourthGroup(0, 4), null),
        'a fall only in methods the increment deleted is deletion');
    check('control flow leaving — fall 4 in method(s) that still exist, fall 2 in method(s) deleted',
        questionOne($fourthGroup(4, 2), null)['probes'],
        'and the fourth group names each fall against the label it was read under');

    // Fifth group, and the readings that reach no group at all.
    check('ordinary work — a fix or a feature reaching further',
        $outcome(figures(['fan' => ['internalIncrement' => 3.0], 'cognitive' => ['totalIncrement' => 9]]), null),
        'no file added and more internal references is ordinary work');
    check('n production files unmoved, internal references per touched production file up, cognitive complexity total up; test measure absent',
        questionOne(figures(['fan' => ['internalIncrement' => 3.0], 'cognitive' => ['totalIncrement' => 9]]), null)['figures'],
        'and the fifth group names the reference rate it was read on between the two production figures, where the table names it');
    check('not readable — the test measure is absent',
        $outcome(figures(['fan' => ['nIncrement' => 3], 'cognitive' => ['totalIncrement' => 9]]), null),
        'the second and third groups cannot be read without the test measure');
    check('not detected', $outcome(figures(), testFigures(2, 2, 2.0, 2.0)),
        'figures that move nowhere reach no group');

    // A measure that read nothing is not a measure that held: a cognitive side with no method to read carries
    // no transition, and every group naming one is unreadable rather than satisfied by it.
    $noMethod = figures(['fan' => ['nIncrement' => 3], 'cognitive' => ['nBaseline' => 0, 'nIncrement' => 0,
        'totalBaseline' => 0, 'totalIncrement' => 0, 'state' => 'absent']]);
    check('n production files up, cognitive complexity total absent; test methods unmoved, calls per test method up',
        questionOne($noMethod, testFigures(2, 2, 2.0, 3.0))['figures'],
        'question 1 names an unread cognitive measure absent rather than unmoved');
    check('not readable — the cognitive complexity total is absent',
        $outcome($noMethod, testFigures(2, 2, 2.0, 3.0)),
        'and reaches no outcome off it, where reading it as unmoved would have said ceremony');
    check('not detected',
        $outcome(figures(['fan' => ['nIncrement' => 1], 'cognitive' => ['nBaseline' => 0, 'nIncrement' => 0,
            'totalBaseline' => 0, 'totalIncrement' => 0, 'state' => 'absent']]), testFigures(2, 2, 2.0, 3.0)),
        'but where no file was added, no group reads the cognitive total at all, and a measure no group needed leaves the block readable');
    check('cognitive complexity total absent, fan out score unmoved', questionTwo($noMethod)['figures'],
        'question 2 names it absent as well');
    check('not readable — the inside-a-method half is absent', questionTwo($noMethod)['outcome'],
        'and says the half it could not read rather than reporting nothing detected');
    check('harder across files — the same logic, in more files to open',
        questionTwo(figures(['fan' => ['scoreIncrement' => 70.0], 'cognitive' => ['nBaseline' => 0,
            'nIncrement' => 0, 'state' => 'absent']]))['outcome'],
        'but a half it did read is still read: a constant pulled into a class with no method is fan out falling and nothing else');
    check('not readable — the across-files half is absent',
        questionTwo(figures(['fan' => ['scoreBaseline' => null, 'bandBaseline' => null, 'state' => 'absent']]))['outcome'],
        'and the across-files half unread is named the same way round');
    check('not readable — neither half was read',
        questionTwo(figures(['fan' => ['scoreBaseline' => null, 'bandBaseline' => null, 'state' => 'absent'],
            'cognitive' => ['nBaseline' => 0, 'nIncrement' => 0, 'state' => 'absent']]))['outcome'],
        'and where neither was read the block says so, rather than naming one half and passing over the other');
    check('not detected', $outcome(figures(['fan' => ['nBaseline' => 3], 'cognitive' => ['totalIncrement' => 9]]),
        testFigures(2, 2, 2.0, 3.0)),
        'fewer production files than the baseline reach no group either — no abstraction was added');
    check('not detected', $outcome(figures(['fan' => ['nIncrement' => 3]]), testFigures(2, 4, 2.0, 2.0)),
        'files added against figures no group names reach no group');
    check('n production files up, cognitive complexity total unmoved; test methods up, calls per test method down',
        questionOne(figures(['fan' => ['nIncrement' => 3]]), testFigures(2, 4, 3.0, 2.0))['figures'],
        'and the block names each test-side figure against the count it was read from, in the order it names them');
    check('not detected', $outcome(figures(), testFigures(2, 2, 2.0, 3.0)),
        'the same production file count is no abstraction added, whatever the test side did');
    check('not detected', $outcome(figures(['cognitive' => ['totalIncrement' => 1]]), testFigures(2, 2, 2.0, 2.0)),
        'and control flow removed without a file added is no decomposition to read — the fourth group needs both');
    check('not detected',
        $outcome(figures(['fan' => ['nIncrement' => 3, 'internalIncrement' => 3.0], 'cognitive' => ['totalIncrement' => 9]]),
            testFigures(2, 2, 2.0, 2.0)),
        'references reaching further where a file was added is not the fifth group, which is the one that added none');
    check('not detected', $outcome(figures(['fan' => ['internalIncrement' => 3.0]]), testFigures(2, 2, 2.0, 2.0)),
        'nor is it the fifth group where no control flow came with them');
    check('not detected',
        $outcome(figures(['fan' => ['internalIncrement' => null, 'externalIncrement' => null, 'nIncrement' => 0],
            'cognitive' => ['totalIncrement' => 9]]), testFigures(2, 2, 2.0, 2.0)),
        'and a side that read no file carries no rate to compare, on the increment side as on the baseline side');
    check('not detected', $outcome(figures(['fan' => ['nIncrement' => 3]]), testFigures(2, 2, 2.0, 2.0)),
        'and a file added against a test measure that held entirely reaches no group either');
    check('not detected', $outcome(figures(['fan' => ['nIncrement' => 3]]), testFigures(2, 4, 2.0, 3.0)),
        'and the first group needs its test methods to have held, a rise in them being another reading entirely');
    check('not detected', $outcome(figures(['cognitive' => ['totalIncrement' => 9]]), testFigures(2, 2, 2.0, 2.0)),
        'no file added and no more internal references is not the fifth group, whatever the control flow did');
    check('implementations had nothing to read, attribution had nothing to read',
        questionOne(figures(['fan' => ['nIncrement' => 3], 'cognitive' => ['totalIncrement' => 9]]),
            testFigures(2, 2, 2.0, 3.0))['probes'],
        'the third group says which probe had nothing to read rather than counting it as agreement');
    check('not readable — the production measures are absent', $outcome(null, testFigures(2, 2, 2.0, 3.0)),
        'question 1 is not readable where no production file was touched');

    // Question 2, on the two halves of reading cost and their opposite polarities.
    $harder = static fn (int $cognitive, float $score): string => questionTwo(figures([
        'cognitive' => ['totalIncrement' => $cognitive], 'fan' => ['scoreIncrement' => $score]]))['outcome'];
    check('harder inside the methods — deeper nesting, more breaks in the linear flow', $harder(9, 80.0),
        'more control flow with no more references is harder inside');
    check('harder across files — the same logic, in more files to open', $harder(5, 70.0),
        'a falling fan out score with no more control flow is harder across');
    check('harder inside the methods and across the files both', $harder(9, 70.0),
        'both moving against their polarity is both');
    check('not detected — no measure here reads size', $harder(5, 90.0),
        'nothing moved against its polarity detects nothing, which is not the same as nothing changed');
    check('not detected — no measure here reads size', $harder(1, 80.0),
        'and control flow removed with the references held is not harder reading either');
}

// ---------------------------------------------------------------- entry points

if ($mode === 'selftest') {
    $failed = selfTest();
    printf("selftest\n");
    foreach ($failures as $failure) {
        printf("  FAIL  %s\n", $failure);
    }
    printf("  %d check(s), %d failed\n", $checks ?? 0, $failed);
    exit($failed === 0 ? 0 : 1);
}

/**
 * The report of one increment: every measure with its figure per side and its state, each probe beneath the
 * measure whose reading it settles, the unmeasured share, and the two question blocks (*Output*).
 */
function reportIncrement(string $incrementSha, ?array $result, ?array $test, array $outside): void
{
    $live = 0;
    printf("increment %s\n", substr($incrementSha, 0, 8));

    if ($result === null) {
        printf("  Production                      no src/ file touched  [absent]\n");
    } else {
        printf("  %d touched production file(s)\n", $result['files']);
        // A side with no production file prints as absent rather than as a figure it never had.
        $figure = static fn (?float $score): string => $score === null ? 'absent' : sprintf('%.2f', $score);
        printf("  Production Fan out              %s -> %s  score %s -> %s (higher is better); %s -> %s refs/production file (internal %s -> %s, external %s -> %s), n %d -> %d production file(s)  [%s]\n",
            $result['fan']['bandBaseline'] ?? 'absent', $result['fan']['bandIncrement'] ?? 'absent',
            $figure($result['fan']['scoreBaseline']), $figure($result['fan']['scoreIncrement']),
            $figure($result['fan']['refsBaseline']), $figure($result['fan']['refsIncrement']),
            $figure($result['fan']['internalBaseline']), $figure($result['fan']['internalIncrement']),
            $figure($result['fan']['externalBaseline']), $figure($result['fan']['externalIncrement']),
            $result['fan']['nBaseline'], $result['fan']['nIncrement'], $result['fan']['state']);
        $live += $result['fan']['state'] === 'live' ? 1 : 0;
        if ($result['cognitive']['state'] === 'absent') {
            printf("  Production Cognitive            no method changed complexity  [absent]\n");
        } else {
            printf("  Production Cognitive            total %d -> %d over the touched methods (higher is worse), n %d -> %d touched method(s)  [%s]\n",
                $result['cognitive']['totalBaseline'], $result['cognitive']['totalIncrement'],
                $result['cognitive']['nBaseline'], $result['cognitive']['nIncrement'],
                $result['cognitive']['state']);
            $live += $result['cognitive']['state'] === 'live' ? 1 : 0;
        }

        // A probe prints beneath the measure whose reading it settles (*Output*). Two read the per-method
        // figures behind the cognitive complexity total; three read the syntax tree of the production files.
        $label = '    %-30s';
        foreach ($result['cognitive']['attribution'] as $index => $row) {
            printf($label . "%-46s %s  [%s]\n", $index === 0 ? 'attribution' : '', $row['method'],
                match ($row['state']) {
                    'added' => sprintf('added at %d', $row['increment']),
                    'deleted' => sprintf('deleted at %d', $row['baseline']),
                    default => sprintf('%d -> %d', $row['baseline'], $row['increment']),
                }, $row['state']);
        }
        if ($result['cognitive']['state'] !== 'absent') {
            $leaving = $result['cognitive']['leaving'];
            printf($label . "fall %d in method(s) that still exist, fall %d in method(s) deleted, rise %d in method(s) added, rise %d in method(s) already there  [%s]\n",
                'control flow leaving', $leaving['fallExisting'], $leaving['fallDeleted'],
                $leaving['riseAdded'], $leaving['riseExisting'], $leaving['state']);
        }
        foreach ($result['delegation'] as $index => $row) {
            printf($label . "%-46s %d of %d method(s) forward, collaborator %s  [%s]\n",
                $index === 0 ? 'delegation ratio' : '', $row['class'], $row['forwards'], $row['methods'],
                $row['collaborator'], $row['state']);
        }
        foreach ($result['implementations'] as $index => $row) {
            printf($label . "%-46s %d real, %d discounted  [%s]\n", $index === 0 ? 'implementations' : '',
                $row['abstraction'], $row['real'], $row['discounted'], $row['state']);
        }
        printf("  Unresolved sites (production)   %d -> %d  [%s]\n",
            $result['unresolved']['baseline'], $result['unresolved']['increment'],
            $result['unresolved']['baseline'] === $result['unresolved']['increment']
                ? 'unmoved' : 'live — the fan out figures are not evidence');
    }

    // The test measure is pooled over the touched test files, per side, so an added class counts (*Output*). The
    // per-class rows below it are detail: printed, never summed, and never carrying the measure.
    if ($test === null) {
        printf("  Test unit dependency            no test class touched  [absent]\n");
    } else {
        $rate = static fn (?float $figure): string => $figure === null ? 'absent' : sprintf('%.2f', $figure);
        printf("  Test unit dependency            total %d -> %d production call(s) (%s -> %s per test method, higher is worse), n %d -> %d test class(es), %d -> %d test method(s)  [%s]\n",
            $test['callsBaseline'], $test['callsIncrement'],
            $rate($test['baseline']), $rate($test['increment']),
            $test['nBaseline'], $test['nIncrement'],
            $test['methodsBaseline'], $test['methodsIncrement'], $test['state']);
        $live += $test['state'] === 'live' ? 1 : 0;
        foreach ($test['rows'] as $row) {
            printf("                                  %-44s %s  [%s]\n",
                $row['class'],
                $row['state'] === 'added'
                    ? sprintf('added at %.2f/test method', $row['increment'])
                    : sprintf('%.2f -> %.2f/test method', $row['baseline'], $row['increment']),
                $row['state']);
        }
        foreach ($test['mockSeam'] as $index => $row) {
            printf("    %-30s%-46s mocked  [%s]\n", $index === 0 ? 'mock seam' : '', $row['class'], $row['state']);
        }
        printf("  Unresolved sites (test files)   %d -> %d  [%s]\n",
            $test['unresolvedBaseline'], $test['unresolvedIncrement'],
            $test['unresolvedBaseline'] === $test['unresolvedIncrement']
                ? 'unmoved' : 'live — the test figures are not evidence');
    }

    // The unmeasured share, printed so an increment that moved logic out of PHP does not read as a quiet one.
    if ($outside['unmeasured'] !== []) {
        printf("  Unmeasured paths                %d path(s), %d line(s)\n",
            count($outside['unmeasured']), array_sum(array_column($outside['unmeasured'], 'lines')));
        foreach ($outside['unmeasured'] as $file) {
            printf("                                  %-44s %d line(s)\n", $file['path'], $file['lines']);
        }
    }
    if ($outside['deleted'] !== []) {
        printf("  Deleted paths, not scored       %s\n", implode(', ', $outside['deleted']));
    }

    printf("  measures live: %d of 3\n", $live);

    // The two question blocks are not figures: each names what the measures showed, which probes were read
    // against them, and the outcome that leaves (*Output*).
    $one = questionOne($result, $test);
    $two = questionTwo($result);
    printf("\n  Question 1  figures  %s\n", $one['figures']);
    if ($one['probes'] !== '') {
        printf("              probes   %s\n", $one['probes']);
    }
    printf("              outcome  %s\n", $one['outcome']);
    printf("  Question 2  figures  %s\n", $two['figures']);
    printf("              outcome  %s\n", $two['outcome']);
}

if ($mode === 'increment') {
    $incrementSha = $argv[2] ?? '';
    $baselineSha = $argv[3] ?? defaultBaseline($incrementSha);
    // A ref that resolves to nothing would read as an increment that touched nothing, which is a figure and not
    // an error. Say which side could not be read instead.
    // `^{...}` is what makes the check read the object rather than the name: a full hexadecimal string is a
    // well-formed object name whether or not the repository holds the object.
    // Resolved to their full names once, here: everything downstream keys on them — the blobs, and the
    // analysis of each side, which the server holds under the sha and not under whatever abbreviation was typed.
    foreach (['increment' => [$incrementSha, '^{commit}'], 'baseline' => [$baselineSha, '^{tree}']] as $side => [$sha, $peel]) {
        $resolved = trim(git('rev-parse --verify --quiet ' . escapeshellarg($sha . $peel)));
        if ($resolved === '') {
            fwrite(STDERR, "no such {$side} in the repository: {$sha}\n");
            exit(1);
        }
        if ($side === 'increment') {
            $incrementSha = $resolved;
        } elseif (trim(git('rev-parse --verify --quiet ' . escapeshellarg($sha . '^{commit}'))) !== '') {
            $baselineSha = trim(git('rev-parse --verify --quiet ' . escapeshellarg($sha . '^{commit}')));
        }
    }
    $result = measureIncrement($incrementSha, $baselineSha);
    $test = measureTestFiles($incrementSha, $baselineSha);
    $outside = unmeasuredFiles($incrementSha, $baselineSha);
    // Nothing to measure is still something to report: an increment that moved a file out of the measured set,
    // or one that touched nothing but Twig, has an unmeasured share the reader has to see (*Output*).
    if ($result === null && $test === null && $outside['unmeasured'] === [] && $outside['deleted'] === []) {
        fwrite(STDERR, "no src/ or tests/ PHP touched by {$incrementSha}\n");
        exit(1);
    }
    reportIncrement($incrementSha, $result, $test, $outside);
    exit(0);
}

// Fan out over the whole of src/ at one sha — the scale TPM itself reads the metric at (§5.5, §6.1). It is not
// an increment measure and bands nothing; it exists so the whole-repo internal-to-external ratio the
// methodology quotes is reproducible, and so the increment-scope ratios can be read against it.
if ($mode === 'repo') {
    $sha = $argv[2] ?? 'HEAD';
    $internal = $external = $files = 0;
    foreach (srcBlobs($sha) as $code) {
        $ast = parseCode($code);
        if ($ast === null) {
            continue;
        }
        $out = fanOut($ast);
        $internal += $out['internal'];
        $external += $out['external'];
        ++$files;
    }
    if ($files === 0) {
        fwrite(STDERR, "no src/ PHP at {$sha}\n");
        exit(1);
    }
    $perFileInternal = $internal / $files;
    $perFileExternal = $external / $files;
    $score = fanOutScore($perFileInternal, $perFileExternal);
    printf("repo %s\n", $sha);
    printf("  Fan out over src/               %.2f refs/production file (internal %.2f, external %.2f), score %.2f, band %s, n %d production file(s)\n",
        round($perFileInternal, 2) + round($perFileExternal, 2), round($perFileInternal, 2),
        round($perFileExternal, 2), round($score, 2), band(round($score, 2)), $files);
    exit(0);
}

if ($mode === 'scan') {
    $sha = trim(git('rev-parse --verify --quiet ' . escapeshellarg(($argv[2] ?? '') . '^{commit}')));
    if ($sha === '') {
        fwrite(STDERR, "no such commit in the repository: " . ($argv[2] ?? '') . "\n");
        exit(1);
    }
    if ($sonarToken === '') {
        fwrite(STDERR, "SONAR_TOKEN is not set, and the server has to accept the analysis from somebody\n");
        exit(1);
    }
    $project = $sonarPrefix . $sha;
    // The tree is materialised rather than checked out: the working copy stays where it is, and a sha whose
    // dependencies no longer install is still scannable — only its `src/` is read (*Input*).
    $tree = sys_get_temp_dir() . '/assessment-scan-' . $sha;
    removeDirectory($tree);
    mkdir($tree, 0o777, true);
    shell_exec('cd ' . escapeshellarg($app) . ' && git archive ' . escapeshellarg($sha)
        . ' src/ | tar -x -C ' . escapeshellarg($tree));
    if (!is_dir($tree . '/src')) {
        fwrite(STDERR, "no src/ at {$sha}\n");
        removeDirectory($tree);
        exit(1);
    }
    printf("scanning %s as %s\n", substr($sha, 0, 8), $project);
    $log = (string) shell_exec('docker run --rm --network host -v ' . escapeshellarg($tree . '/src') . ':/usr/src '
        . escapeshellarg($sonarImage)
        . ' -Dsonar.host.url=' . escapeshellarg($sonarHost)
        . ' -Dsonar.token=' . escapeshellarg($sonarToken)
        . ' -Dsonar.projectKey=' . escapeshellarg($project)
        . ' -Dsonar.projectName=' . escapeshellarg($project)
        . ' -Dsonar.sources=. -Dsonar.scm.disabled=true 2>&1');
    removeDirectory($tree);
    if (!str_contains($log, 'EXECUTION SUCCESS')) {
        fwrite(STDERR, "the scanner did not finish:\n" . implode("\n", array_slice(explode("\n", trim($log)), -6)) . "\n");
        exit(1);
    }
    // The scanner hands the report over and returns; the server still has to process it, so the analysis is
    // not there to read until the queue says it is.
    for ($waited = 0; $waited < 300; ++$waited) {
        $task = sonarGet('/api/ce/component?component=' . urlencode($project));
        $status = $task['current']['status'] ?? '';
        if (($task['queue'] ?? []) === [] && $status === 'SUCCESS') {
            printf("analysis of %s is ready\n", substr($sha, 0, 8));
            exit(0);
        }
        if ($status === 'FAILED' || $status === 'CANCELED') {
            fwrite(STDERR, "the server could not process the analysis of {$sha}\n");
            exit(1);
        }
        sleep(1);
    }
    fwrite(STDERR, "the analysis of {$sha} was still being processed after five minutes\n");
    exit(1);
}

fwrite(STDERR, "usage: php assessment.php increment <sha> [<baseline>]\n"
    . "       php assessment.php scan <sha>\n"
    . "       php assessment.php repo [<sha>]\n"
    . "       php assessment.php selftest\n");
exit(1);
