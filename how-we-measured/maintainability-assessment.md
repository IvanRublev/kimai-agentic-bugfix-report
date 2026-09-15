# Assessment of Maintainability of a Software Increment in [Kimai](https://github.com/kimai/kimai) project

Last updated: 13 September 2026, 13:28 CEST.

Increment is the git commit under assessment, baseline is its parent commit.

The assessment measures what one increment did to the code it touched by comparing baseline against
increment.

Where a figure carries a band, its cuts are published third-party thresholds calibrated on
industrial populations. Those thresholds describe common practice rather than a proven level of
maintainability.

## What this assessment answers

Two questions about one increment:

1. **Did the increment add abstractions it did not need?** — the interface, factory or service
   written where the code could have done the work in place, which is the shape an AI assistant
   produces by reflex.
2. **Did the increment leave the code harder to read?** — more control flow to hold in the head per
   method, or more files to open to follow one.

Neither is answered by a single measure. Each is a reading across two of them, and the answer is
one-sided: the assessment can flag an increment that added ceremony or left the code harder to read,
and can never show that one did neither.

**Blind spot on both questions.** No measure here reads size. A straight-line method that grows from
3 statements to 30 adds no reference and no control flow, so fan out reads `unmoved` while the code
got longer — and cognitive complexity does not even read `unmoved`: a method whose figure is the
same on both sides enters neither side of the union, so with nothing else touched the measure reads
`absent`.

**A second blind spot follows from reading cognitive complexity as a max and a count, not a sum.** A
small branch added beside a method that was already the touched set's worst offender moves neither
figure: the max was already set by the bigger method, and one added `if` rarely crosses the
15-method threshold on its own. A total would have caught that addition; max and count together do
not. What they gain instead is that neither figure hides a genuinely bad method behind many
mildly-added ones the way a total's rate-of-two problem could — see *Deviations* under Cognitive
complexity for the trade this makes.

Where every measure comes out `unmoved` that is silence and not a pass. The verdict is a human
reading of the increment, either way.

### Question 1 — an abstraction that was not needed

Read `n` touched production files against cognitive complexity's max and count, then read the test
measure against both. Each group of figures carries more than one outcome, and the probes are what
tell them apart.

| Figures | Why | Outcome |
|---|---|---|
| `n` touched production files **up**, cognitive complexity **unmoved**; on the test side `n` test methods **unmoved**, calls per test method **up** | a pure relocation carries the same control flow into the new method without adding any, so neither the highest figure in the touched set nor the count over 15 moves, and test methods that did not change say the behaviour did not either. All three probes agree: **delegation ratio**, every method body of a touched class forwards to a collaborator under `App\`, a `partial` row entering neither side of the agreement; **implementations**, one real implementation behind the abstraction, discounting any whose methods are empty or return a constant; **mock seam**, the class a touched test file hands to a mock builder is own production code | **ceremony** — names added, nothing simplified |
| | any one probe pointing the other way is enough: **delegation ratio**, the class forwards to a type outside `App\`, so it is an adapter confining an external dependency; **implementations**, two or more real ones, so the abstraction carries polymorphism that already existed; **mock seam**, the mocked class sits at a system boundary, which the test code quality model names as a solution to coupling ([[3]](#ref-3) §3.2.5) | **real work** — the new file earns its place |
| `n` touched production files **up**, cognitive complexity **up**; on the test side `n` test methods **up**, calls per test method **unmoved** | behaviour that did not exist needs test methods that did not exist, and a rate that held says each still reaches about as far. Confirmed by **attribution**, the rise sitting in a method the increment added, and **control flow leaving**, no fall on the baseline side beside it | **real work** — the new control flow is new behaviour |
| | **attribution** puts the rise in a method that was already there while the methods the increment added carry nothing — the split is per method, so one added to a class that already existed counts as added. Test methods can be added for behaviour that already existed, so their count rising is not by itself new behaviour | **ceremony** — the new files carry nothing |
| | **control flow leaving** shows a fall on the baseline side, in a method that survived or in one the increment deleted: some of the control flow was relocated rather than written, and neither outcome holds alone. Any fall is enough — the probe reads whether control flow left, not how much of it did, so a fall of one beside a rise of forty still reads as both | **mixed** — a refactor and a feature in one increment |
| `n` touched production files **up**, cognitive complexity **up**; on the test side `n` test methods **unmoved**, calls per test method **up** | the same test methods reaching more production units say the machinery grew while the behaviour did not. Both probes agree: **implementations**, one real implementation to select among, so the new control flow chooses between an abstraction and nothing; **attribution**, the rise came in with the methods the increment added, so the cost of reading arrived with the new name | **ceremony that branches** — a factory or a dispatcher, not a feature |
| | either probe pointing the other way is enough: **implementations**, two or more, so the polymorphism is load-bearing; **attribution**, the rise sits in a method that was already there, so an existing unit took on a rule rather than a name being added beside it | **real work** — a fix or a rule the existing test methods already assert |
| `n` touched production files **up**, cognitive complexity **down** | only removing control flow brings the highest figure or the over-15 count down, and **control flow leaving** puts the fall in methods that still exist at the increment: the split absorbed the reading cost. A fall in deleted methods beside it does not take that back — an increment that both absorbed and removed reading cost still absorbed some | **real decomposition** — the split removed reading cost |
| | **control flow leaving** puts the fall *only* in methods the increment deleted. Code was removed rather than absorbed, and the added files still have to justify themselves | **deletion, not decomposition** |
| `n` touched production files **unmoved**, internal references per touched production file **up**, cognitive complexity **up** | no file was added, so there is no abstraction that could have been unnecessary. No probe applies, and none is missing | **ordinary work** — a fix or a feature reaching further |

The second and third groups share their production figures and are told apart only on the test side.
Where the test measure is `absent`, neither can be read.

Where no row of the table fires, or where the probes a row needs do not part it, the block says
which of three readings it reached instead — `not detected`, `not settled` or `not readable`
(*Output*).

*Ceremony.* `TimesheetService` sums a timesheet's duration inline, in a method of cognitive
complexity 6. The increment extracts `TimesheetCalculatorInterface` and `TimesheetCalculator`; the
calculator carries the same 6 and the service is left delegating, which branches nowhere and carries
0. `n` goes `1 -> 3` production files, cognitive complexity `6 -> 6`. Two names were added and
nothing was simplified, and the probes agree: the **delegation ratio** finds the service forwarding
to a collaborator under `App\`, **implementations** finds one class behind
`TimesheetCalculatorInterface`, and the **mock seam** finds the touched test file mocking that
interface, which is own production code. Add a `TimesheetCalculatorFactory` beside them, picking
with an `if` between the calculator and a placeholder `src/` already holds, and the increment reads
`1 -> 4` files and `6 -> 7`, the third group: **attribution** puts the rise in the factory method
the increment added, **implementations** discounts the placeholder for returning a constant and
still finds one, and the plumbing now costs something to read too. Write that same choice as a
`match` and the increment reads `6 -> 6` and stays in the first group, the analyser charging nothing
for one — so which row an extraction lands in can turn on the syntax its factory is written in.

This `6 -> 7` transition is itself the blind spot named above: read as a max and a count over 15
rather than a sum, the factory's one added `if` moves neither figure — the calculator's 6 was
already the touched set's highest figure before the factory existed, and one more branch does not
cross 15. A total would have moved; max and count do not. The row this example illustrates is
reachable only when the added branch itself becomes the set's new highest figure or pushes a method
over 15 — a smaller addition beside an already-large method reads `unmoved` here and needs a human
to notice it, the same way the doc's blind-spot section already says silence is not a pass.

*Real work — the new file earns its place.* The increment adds `ExchangeRateClient`, whose every
method forwards to a vendor HTTP client, and `InvoiceService` names the new class where it named the
vendor type. `n` goes `1 -> 2` production files, cognitive complexity `0 -> 0`, and the touched test
file swaps a vendor stub for a mock of `ExchangeRateClient`, so calls per test method rise. Those
are the first group's figures exactly, and the probes are what part them: the **delegation ratio**
finds every method forwarding to a type outside `App\`, so the class confines an external dependency
instead of standing between two of your own, and the **mock seam** agrees, the mocked class being
that boundary. Fan out meanwhile reads worse on `InvoiceService` alone, because the reference it
swapped weighs four times as an internal one what it weighed as an external — a third reason not to
read fan out for this question.

*Real decomposition.* Two methods hold the same three-level conditional, cognitive complexity 12
across them. The increment pulls that conditional into one new class, written once. `n` goes `1 ->
2` production files, cognitive complexity `12 -> 7`. **Control flow leaving** puts the fall in those
two methods, which still exist at the increment and now hold less, so the new file absorbed the
reading cost rather than the increment deleting it. The new file paid for itself.

### Question 2 — code that is harder to read

Cognitive complexity answers the inside-a-method half: it charges a structural increment for every
break in the linear flow, and a nesting increment for each enclosing level. Fan out answers the
across-files half: how many other files someone has to open to follow one.

| Reading | What it says |
|---|---|
| cognitive complexity **up**, fan out unmoved | harder inside the methods — deeper nesting, more breaks in the linear flow |
| cognitive complexity unmoved, fan out score **down** | harder across files — the same logic, in more files to open |
| both move against their polarity | both |
| one moves against its polarity, the other with it | what the half moving against it says, and nothing about the other: each measure stands alone, so a fan out score that rose takes nothing back from methods that got harder to read |
| neither moves against its polarity — both unmoved, both improving, or one of each | nothing was detected, which is not the same as nothing changed |
| one half `absent`, the other moved against its polarity | what the half that was read says, and nothing about the other: an increment touching no method reads across files alone, and one whose touched production files are all new reads inside the methods alone |
| one half `absent`, the other unmoved or moved with its polarity | not readable — the half that could have shown something is the one that was not read |

*Harder inside.* A guard added inside an existing `foreach` inside an `if` takes one structural
increment and two nesting increments: `4 -> 7`. It adds no reference, so fan out is unmoved.

*Harder across.* A constant the increment pulls out into a new `App\Timesheet\RoundingDefaults`
class carries no control flow, and the method it came from holds the same control flow it held
before — so no touched method enters either side and cognitive complexity reads `absent`, while the
touched production file adds one internal reference. The reading cost did not disappear; it became a
trip to another file.

Whether the score falls with it is a second question, and often it does not: the new file joins the
increment side carrying references of its own — usually none — so the *rate* the score is read off
is diluted by it. The score falls only where the file the constant came from carried fewer
references than the new file drags the average down by, which for a class naming even one internal
type it usually does not. The extraction in the *Ceremony* example above raises the score from 73.71
to 83.90 for exactly that reason. Fan out is a rate per touched file, and an extraction adds a file:
the measure reads what it reads, and this example is the shape where reading it as "harder across
files" needs the touched file to have carried almost nothing.

## Input

**Included files**

| Set | What it is |
|---|---|
| Touched production files | the `src/**.php` files the increment modified, added or renamed. A file renamed **into** `src/` from outside it reads as added: at the baseline it was not production code, so there is no baseline side to compare it against |
| Touched test files | the `tests/**Test.php` files it modified, added or renamed. The rename rule above is the production side's alone: a file renamed **into** the test set is read at its old path on the baseline side, where the same move into `src/` reads as added |

**Excluded files**

| What | Why it is out |
|---|---|
| The files the increment deleted | there is no increment side to read; each one is named, not scored. A file renamed **out** of the measured set leaves it the way a deletion does, so its old path is named there too, while the file it became is unmeasured like any other path outside the set |
| Everything else the increment touched — Twig, YAML, translations, migrations, frontend assets, configuration | outside the measured set; every such path is listed with its line count, so the unmeasured share of the increment stays visible |
| Third-party code | TPM measures manually written own production code only ([[1]](#ref-1) §6.1). It lives in `vendor/`, outside the target of evaluation |
| Generated code (deterministically from templates) | excluded by TPM on the same ground ([[1]](#ref-1) §6.1). No file under `src/` carries a generated-code marker, so the whole tree is taken as manually written |
| Test code, from TPM only | TPM excludes test code outright ([[1]](#ref-1) §6.1), which is why the touched test files are measured under a second model and never carry a TPM band |

Everything is parsed; no code from the repository is executed. So an increment on an old baseline
can still be measured, even when its test suite no longer starts.

## The three measures and probes

Where every figure comes from, and what is done with it.

| Reading | Figure produced by | What `assessment.php` does with it |
|---|---|---|
| *the files every reading runs over* | `git`, `git diff --name-status -M <baseline> <increment>` for the set and `git show <sha>:<path>` for each side's blob | sorts the changed paths into touched production files, touched test files, deleted paths and unmeasured ones (*Input*), and hands the blobs to whatever reads them |
| **Measures** |
| Fan out | `assessment.php`, off the `nikic/php-parser` tree of each touched production file | counts the distinct outgoing type references, splits `App\` from the rest, and feeds the two rates to TPM's score formula and cuts |
| Cognitive complexity | `sonar-php`, one figure per method, read over the SonarQube web API | keys those figures onto methods by line range and totals the touched ones, per side |
| Test unit dependency | `assessment.php`, off the `nikic/php-parser` tree of each touched test file | resolves each call's receiver and counts the unique production calls per test method, pooled per side |
| **Probes** |
| *attribution* | `sonar-php`, the same per-method figures | reads them as the union of the two sides and labels each method `fell`, `rose`, `added` or `deleted` |
| *control flow leaving* | `sonar-php`, the same per-method figures | splits the fall by whether the method it came from still exists at the increment |
| *delegation ratio* | `assessment.php`, off the tree of each touched production class | reads whether every method body forwards, and what to |
| *implementations* | `assessment.php`, off the trees of `src/` at the increment | counts what implements, extends or uses each abstraction the increment added |
| *mock seam* | `assessment.php`, off the trees of the touched test files | splits the classes handed to a mock builder on `App\` |
| unresolved sites | `assessment.php`, off both sides' trees | counts the names a static pass could not resolve, as a guard beside the figures |

## Measures

### 1. Fan out (production code) — did the increment leave its touched production files referencing more other files?

#### Origin

The TIOBE/TÜViT Trusted Product Maintainability model, TPM ([[1]](#ref-1) §4.5, §5.5).

#### What it shows

Fan out counts how many other files a file needs to work. More references per touched production
file say the increment left those files harder to move, reuse or test alone; fewer say it left them
easier.

It does not say whether those references were unnecessary — a bug fix may legitimately reach three
more classes, a feature may genuinely need a new service.

Under TPM's mapping the fan out metric reaches four of the five ISO/IEC 25010 maintainability
sub-characteristics — modularity, reusability, modifiability and testability ([[1]](#ref-1) §4.5).

#### How it is measured

Per touched production file, count the distinct outgoing type references, split internal (`App\`)
from external.

Sum each across the touched production files and divide by how many there are, then feed the two
averages to TPM's own formula, `score = 100 / 2 ^ ((8 · internal + 2 · external) / 100)`
([[1]](#ref-1) §5.5) — internal weighs four times external.

Repeat once at the baseline, once at the increment.

Then for each score the band is read off TPM's score cuts: 90 or more is `A`, 80 `B`, 70 `C`, 50
`D`, 40 `E`, below that `F`.

TPM settled that weighting "based on experiments and checking available data", reasoning that an
external import mostly reuses software that already exists ([[1]](#ref-1) §5.5).

#### What is never counted

TPM counts a dependency between files ([[1]](#ref-1) §4.5), so assessment skips names pointing at no
other file — a function call, a global constant, the file's own `namespace` — and names pointing at:
`self`, `static` and any class the file declares, plus `parent`, already named by the `extends`
clause. A class the platform provides — `DateTime`, `Throwable` — does count as external, the way
TPM's worked example scores the JDK; against the true dependency set the figure is a lower bound,
for the reasons under *What the figures do not say*.

#### Deviations

**References are counted where the file uses them, not its `use` imports.** TPM counts imports per
file ([[1]](#ref-1) §5.5), so this moves the figure both ways: it catches inline fully-qualified
names, and drops imports declared but never used. TPM makes the same move for C#, demanding "the
actual number of unique dependencies per file" instead of its `using` directives.

**The band is read off the score cuts, not off Table 9's metric cuts.** TPM also publishes cuts on
the metric value itself — 3.04, 6.44, 10.29, 20.00, 26.43 references per file ([[1]](#ref-1) Table
9) — but they hold only under its stated 1:1 internal-to-external assumption, which `src/` as a
whole nearly meets at 2.94 : 3.18 and the handful of files one increment touches does not: five
measured increments run from `0.00 : 4.00` to `8.00 : 1.00`, each reproducible with `assessment.php
increment <sha>`. How often the mix lands near 1:1 is not known and no sample of this repository's
history is kept, so the score formula is used directly and the table is not.

**The formula is read at a scale TPM never reads it at.** TPM's target of evaluation is a product
and the figure it feeds the formula is a whole-system average ([[1]](#ref-1) §6.1, §5.5), so here
that same per-file rate runs over the handful of files one increment touched. Dropping Table 9
removes an assumption the formula itself does not make, but it does not make the formula scale-free:
at an `n` of one file the band says what a one-file figure says and no more, which is why `n` is
printed beside it.

### 2. Cognitive complexity (production code) — did the increment make its touched code costlier to read?

#### Origin

Cognitive Complexity, G. Ann Campbell (SonarSource), white paper 2017, presented at the ACM
International Conference on Technical Debt in 2018 ([[4]](#ref-4)). It was written to replace
McCabe's cyclomatic complexity ([[2]](#ref-2)) wherever the question is reading cost. Cyclomatic
counts the independent paths through a method ([[2]](#ref-2)), which answers how many test cases the
method needs. What cognitive complexity adds is the charge for nesting and the refusal to charge for
width, which is what makes it a statement about reading rather than about coverage.

#### What it shows

Cognitive complexity charges a method for the control flow a reader has to hold at once: every break
in the linear flow costs one, and a break nested inside another costs one more for each level it
sits under. Two figures are read off the touched methods: the **highest single figure** among them,
and **how many of them exceed 15** — SonarQube's own default threshold for `php:S3776`, and the same
ceiling this repository's own Baseline rules already set for method complexity. A rising max says
the touched set's single worst method got worse, or a new method opened worse than anything already
there; a rising count says more methods crossed that line, whether or not any one of them is the
worst.

It does not say whether the reading cost was unneeded: an edge case may genuinely need a guard
inside a loop.

#### How it is measured

The analysis reports one figure per method, a closure the method declares folded into it, and
addresses it by a line at or after its declaration. Keying it onto a method is this assessment's
part: the method a figure belongs to is the one whose declaration encloses that line, read from the
syntax tree of the same blob. Where two figures land in one method's lines the outermost is taken —
a guard against an analyser that reports a nested declaration in its own right, which no analysis of
this repository produces. A method the analysis says nothing about carries nought: silence is how it
reports a method with no control flow.

Both figures are read off the same population: the touched methods, pooled across every touched
production file and identified by the union of the two sides — a method whose complexity moved, one
the increment added, and one it deleted or renamed away, which sits on the baseline side with
nothing opposite it. A method the increment left alone is on neither side and is not read. The max
is the highest figure in that union on a given side; the count is how many members of that union
exceed 15 on a given side. A side with no method in the union carries neither figure — max and count
both read `absent`, the same as the union itself being empty.

The measure's **state** reads `unmoved` only where both figures hold identical values on both sides,
`live` where either one differs, `absent` where the union is empty. Its **polarity** (Question 1 and
Question 2's `up`/`down`/`unmoved` readings) is `up` where either figure rose and neither fell,
`down` where either fell and neither rose, and left unread — the same as `not detected` elsewhere in
this doc — where one figure rose while the other fell, since the two would then disagree about which
way the touched set moved.

**A method counted over 15 does not all cost the same, and the count alone does not say so.** A
method at 16 and a method at 86 both add exactly 1 to the count — the count answers "how many,"
never "by how much." This assessment reads that second question as an **overage ratio**, figure ÷
15, for any method over the line, and bands it on a doubling scale rather than a linear one:
**marginal** at 1.00–1.33×, **moderate** above 1.33–2×, **high** above 2–3×, **severe** above 3×. A
method at 16 is 1.07× — marginal. One at 86 is 5.73× — severe. The doubling scale is not arbitrary
in kind, if it is in its exact cuts: TPM's own fan out score already reads "how much more"
multiplicatively rather than additively (`score = 100 / 2^((8·internal + 2·external)/100)`, [1]
§5.5), on the reasoning that each further reference costs proportionally less to notice than the
first did — the same shape applies here, since the jump from 15 to 30 is a different order of
reading cost than the jump from 100 to 115, even though both are "+15."

**This banding is this project's own convention, not a calibrated one.** No source cited here
publishes a severity scale above SonarQube's single 15-point alarm line — Campbell's paper stops at
defining the increments, and SonarQube's own default profile treats any figure over the threshold
the same way, a flat "worth a look." The four bands and their exact cuts (1.33×, 2×, 3×) are this
assessment's own choice, not industry practice, in the same sense the fan out score's own cuts are
TPM's practice and not this repository's. Report a method's band alongside its figure; never let the
band stand alone the way a TPM letter grade can.

Each side is a commit, so a side with a touched production file to read needs an analysis of its own
— `assessment.php scan <sha>`, once per commit. A side with no such file is never asked for: an
increment that only adds production files needs one analysis, and a root commit's empty-tree
baseline needs none.

Silence inside an analysis means nought — but only where the analysis would have spoken. What it was
allowed to report is read from the messages it wrote, each of which names the threshold it ran
under; silence *about* an analysis, or from one that was never going to report, stops the run rather
than becoming a figure nobody gave it. The readings, and what each does:

| What the server says | What it means | What happens |
|---|---|---|
| no analysis under that project | the commit was never scanned, or was scanned under another name | stops, naming the sha and the scan that would make one |
| an analysis that never read a touched file | the tree moved under a reused project key, or the analyser could not read that file | stops, naming the file and how many the analysis covers |
| an analysis whose findings this run cannot read | the analyser worded them otherwise than the version this was written against | stops, naming how many and the wording it expects |
| an analysis taken with the rule at a threshold above 0 | it reported only the methods above that threshold and stayed silent about the rest, which reads here as nought | stops, naming the threshold and the sha to scan again |
| an analysis that read files and reported nothing | it cannot say what it was taken at — a rule inactive, a threshold above 0 and a tree with no control flow all read the same from outside — and the profile it used can have moved since | stops, naming what the profile carries now |
| an analysis that read no PHP file under `src/` | the analyser was not there when it ran, or read somewhere else — an analysis exists, but of nothing this assessment measures | stops, naming that rather than a missing analysis |

An analysis can also be handed in as a file — `SONAR_ANALYSIS_FILE`, keyed by blob — which is what
reproduces a run without a server. It answers under the same rule: a blob it does not carry stops
the run, and a blob it carries with nothing in it is nought.

#### What is never counted

The counting rules are the analyser's, not this assessment's, and they are not restated here — what
the metric charges for is what sonar-php charges for, and it is the reference implementation of it.
Two consequences are worth naming because they part it from the paper and from an earlier version of
this assessment:

- **a recursion cycle takes no increment**, where Campbell's paper gives one ([[4]](#ref-4));
- **`??`, `??=` and `match` take none either**, where a reader may expect the first two to read as a
  break in the flow and the third to read as the `switch` it stands in for.

A method with no body is not read at all and never enters `n`: there is no control flow in an
interface or an abstract signature to report, and the analysis reports none.

#### Deviations

**Max and count, not a total.** A per-method rate divides by a count the increment itself moves, so
it can fall while reading cost rises — four trivial methods added beside one method doubled would
read as an improvement. A total avoids that specific inversion, but folds a genuinely bad method and
many mildly-touched ones into one number that cannot tell them apart. Max and count avoid both
failure modes at once: max is immune to dilution by unrelated small methods the same way a total is,
and unlike a total it stays Campbell's own per-function figure rather than an aggregate across a set
he never defined the metric over. Count adds back the one thing max alone would miss — whether a
problem is one method or many — without reintroducing a summed magnitude. The trade this makes
against the total it replaces is named in the *Blind spot* section above: a small addition beside an
already-large method can move neither figure, where it would have moved a sum.

**No qualitative band is read off either figure.** Campbell defines the increments and no mapping
onto a score, so there is no cut of his to read for the max. TPM's score cuts are TPM's own,
published for TPM's five metrics against TPM's benchmark population ([[1]](#ref-1) §5.1, §6.1), and
cognitive complexity is not one of them. The 15 the count is read against is SonarQube's own default
rule threshold for `php:S3776` — a common reference point this project's own coding rules also adopt
as their complexity ceiling, never a TPM-style calibrated cut. No calibration against this
repository's own history is kept either. Both figures are reported and neither is banded.

**The max is read at Campbell's own scope; the count is not.** Campbell's increments are defined per
function ([[4]](#ref-4)), so the max — the touched set's single highest per-function figure — is a
function-level reading with no deviation from his own definition. The count is a different kind of
aggregate: not a magnitude summed across methods, but how many of them independently cross a fixed
line, which is why it does not carry the total's inversion risk — adding methods that stay under 15
cannot move it, and the only way to raise it is for a method to actually cross the line itself.

### 3. Test unit dependency (test code) — did the increment tie its tests to more production code?

#### Origin

The test code quality model of Athanasiou et al. ([[3]](#ref-3) §3.2.5), which adapts the SIG
quality model ([[6]](#ref-6)) to test code and adds this metric as a property of its own.

#### What it shows

The paper sets the ideal — *"ideally every test unit tests one production unit in isolation"* — and
names the cost of missing it: a tightly coupled test is harder to change and easier to break.

A rising figure says the increment left its tests further from that ideal. It does not say why: the
production units may not come apart, or the test may not use test doubles, which the paper names as
a solution for avoiding the coupling.

#### How it is measured

Per test method, count the unique outgoing calls into production, keyed `Class::method` — the
paper's own definition, *"the number of unique outgoing calls (fan-out) from a test code unit to
production code units"*, read at the scope it counts on, since for xUnit code "a test is
self-contained in a method or function" ([[3]](#ref-3) §3.2.5). The paper does not say whether a
call is keyed by the production class or by the method it lands on; keying the method is this
assessment's convention, and it is why two calls into two methods of one production class are two
and not one.

A construction is itself an outgoing call, keyed `::__construct`: the test reaches into production
to make the object, and reaches again to use it. So `(new Svc())->calculate()` is two calls and not
one, and a test method whose only line is `new Svc()` counts one.

#### What is never counted

A bare `Foo::class` handed to a mock builder is a reference and not a call. The paper names test
doubles, "such as stubs and mock testing", as *"a solution to avoid this coupling"* ([[3]](#ref-3)
§3.2.5), so scoring one as coupling would invert the model.

A call whose receiver the pass resolved to a class outside production — a vendor object — is not a
call into production, and it is not a miss either: the pass knows what it is and knows it is not
counted. A declaration naming no class at all is different. `int`, `object`, `iterable` and their
like leave the receiver unresolved, so the call on one is a site the pass could not attribute and
the guard below counts it.

#### How a receiver is resolved

The test method is read in source order, carrying what each variable holds at that point: a declared
parameter type, or the class of the last `new` assigned to it before that point — so a variable
reassigned mid-method is read at whichever assignment came before the call, and the calls before the
reassignment keep the type they were made on. `?Svc` is the same declared type as `Svc`, while a
union naming two types of its own names no single receiver. A construction used as the receiver
itself, `(new Svc())->calculate()`, resolves the same way, and a nullsafe call `$svc?->calculate()`
reaches production exactly as an ordinary one does.

A closure the test writes is read with a **copy** of what the method holds: the calls it makes are
still the test reaching into production, while a variable it assigns stays inside it. A class-like
declared inside the method is not read at all — its bodies are its own.

#### Deviations

**The figure is not a bound in either direction.** A static pass resolves only some receivers, and
every site it fails on is counted separately and never added in, which undershoots — a receiver held
in a property is never resolved at all, and is one of those. It also mis-attributes upward: a method
a production class inherits from outside `App\` is still keyed to that production class. Neither
error is measured, so no direction is claimed.

**The aggregation is not the paper's.** Where the paper sorts test methods into four risk categories
and rolls those into a star rating ([[3]](#ref-3) §3.4.1, §3.4.3), we pool the touched test files —
every unique production call summed over every test method in them, per side — and report that total
with calls per test method beside it. Pooling is what lets a class the increment added or deleted
count on the side where it exists, judged against the rest of the touched test files and never
against zero, the same rule the production measures use and the reason `n` is printed per side
throughout; the total leads because `calls / methods` divides by a count the increment itself moves,
so twenty added well-isolated test methods would read as an improvement while coupling to production
grows.

**The figure never carries a band, though the paper does publish thresholds.** Its calibration puts
a test method at low risk up to 3 calls, moderate above 3 up to 4, high above 4 up to 6 and very
high above 6 ([[3]](#ref-3) Table 6). Neither a total over the touched test files nor a pooled rate
over them is one test method, so they do not apply here and no band is read off them.

## Probes

A probe is not a measure. It reports no figure per side, carries no band and takes no state — it
exists to settle an outcome the three measures leave open, and only where they leave one open. Two
probes read the per-method figures the cognitive measure is built from; three read the syntax tree
for a shape.

| Probe | What it reads, and what it misses | Grounding |
|---|---|---|
| **Attribution** | the per-method figures behind cognitive complexity's max and count, both sides: whether a rise landed in a method the increment added or in one that was already there. A method carrying no control flow is no rise. A renamed method reads as one deleted and one added | **Its source's own.** Campbell defines cognitive complexity per function ([[4]](#ref-4)), so a per-method figure is the metric read at its own metric scope — this probe deviates from the source no more than the max does, and less than the count does |
| **Control flow leaving** | a per-method fall on the baseline side beside the rise, and whether the methods it came from still exist at the increment. A fall equal to the rise reads the same whether the control flow was relocated or removed and rewritten | **Its source's own.** The same per-function definition ([[4]](#ref-4)), read on the baseline side of the union the measure already forms |
| **Mock seam** | the classes a touched test file hands to a mock builder, split by whether the class is own production code or sits outside `App\`. The whole test class is read, so a double built in `setUp()` counts as well as one built in the test method. Misses a class that arrives through a variable rather than as `Foo::class`, which names nothing to read | **Its source's own.** The test code quality model names test doubles, "such as stubs and mock testing", as *"a solution to avoid this coupling"* ([[3]](#ref-3) §3.2.5). The measure acts on that by never counting a mocked class as a production call; the probe reports what was mocked, so the reader can tell a boundary from own production code |
| **Implementations** | classes implementing, extending or using each abstraction the increment introduced, discounting any whose methods are empty or return a constant. Misses an implementation outside `src/` | **A source names the shape, the rule is this assessment's.** *Speculative Generality* is what an abstraction with one case is called ([[5]](#ref-5)), and a smell publishes no metric value to cut, so counting implementations and discounting an empty one are ours |
| **Delegation ratio** | every touched production class — a class, that is: an enum or a trait has no ratio read over it, and an interface has no body to read — and not only the ones the increment added, the class an extraction leaves delegating being the shape itself — for whether its every method body forwards to a collaborator. A body **forwards** where it is one statement and that statement is a call: `return $this->calculator->calculate($x);`, the same without the `return`, or a static call on another class. A body that does anything else with the result — `return $this->d->a() + 1;` — is doing work of its own and forwards nothing, and a call through the class's own name is no collaborator at all. Split by what those collaborators are: all under `App\`, none under it, or `internal and external` where both kinds appear at once. A class every body of which forwards is `pass-through` only where the collaborators are all internal — anything else confines something outside `App\` and reads `adapter`. A class where only some bodies forward is `partial`, which is neither shape and settles nothing. Misses methods reached through a trait, and `__call`. A constructor is no method of the ratio — wiring is not delegation — and neither is an empty body, so a class whose constructor does real work and whose one other body forwards reads `pass-through` | **A source names the shape, the rule is this assessment's.** *Middle Man*, a class that delegates most of its work, and *Lazy Element*, one not doing enough to pay for itself ([[5]](#ref-5)); "most of its work" is the source's wording, while the ratio over a touched class's methods and the `App\` split on what they forward to are ours |

Guards that cannot fire, dead `catch` blocks and unreachable branches need no probe: PHPStan at
level 9 fails the pipeline on them, so they never reach either cognitive complexity figure. That
holds while the pipeline runs it — the same standing assumption, and the same expiry, as the coding
standards metric below.

## Output

The `assessment.php` script generates the following output. For how to turn that output into a
per-branch report, see `report-style-guide.md`.

| Measure | Baseline / increment | Band |
|---|---|---|
| Fan out | references per touched production file, internal and external apart, mapped to TPM's score, `n` files per side. A side with no file to read carries no rate, so it carries no score and no band either, and the measure reads `absent` — reading its zero references as a score would hand a perfect 100 to every increment whose touched production files are all new | band `A`–`F` off TPM's score formula and its score cuts, 90 / 80 / 70 / 50 / 40 ([[1]](#ref-1) §5.5) |
| Cognitive complexity | highest figure among the touched methods, and how many of them exceed 15, `n` methods per side | none — reported, never banded, 15 cited only as SonarQube's own default; a method over 15 carries its own overage severity (marginal/moderate/high/severe), this project's own convention, not calibrated |
| Test unit dependency | total production calls over the touched test files, with calls per test method beside it, `n` classes and methods per side | none — reported, never banded |

A probe prints beneath the measure whose reading it settles.

| Probe | What its line prints |
|---|---|
| attribution | one line per touched method, its figure per side, labelled `fell`, `rose`, `added` or `deleted` |
| control flow leaving | four figures — the fall in methods that still exist and the fall in methods the increment deleted, against the rise in methods it added and the rise in methods that were already there — labelled `relocated` or `written` |
| delegation ratio | per touched class that forwards at all — a class no body of which forwards gets no row, and where no touched class does the probe has nothing to read — how many method bodies forward and what they forward to — `internal` where every collaborator sits under `App\`, `external` where none does, `internal and external` where both kinds appear — labelled `pass-through` where every body forwards and the collaborators are internal, `adapter` where every body forwards and they are not, and `partial` where only some bodies forward, which is neither shape |
| implementations | per abstraction the increment introduced, real implementations against discounted ones, labelled `single` or `polymorphic` |
| mock seam | per class a touched test file mocks, labelled `internal` or `boundary` |

An increment with nothing for either measure still carries a report: the two measures print
`absent`, and the unmeasured share and the deleted paths print beneath them, so an increment that
moved a file out of `src/` or touched nothing but Twig is read rather than dismissed. Only an
increment that touched no path at all has nothing to report.

The following two commands in `assessment.php` should be run sequentially to generate a report: 
- `scan` submits a commit's `src/` to SonarQube, which the cognitive complexity figures are read
  back from
- `increment` reads both sides — the cognitive complexity analyses, and the trees for everything
  else — and prints the report

### Example of the script output

Only the increment is scanned below; the example's baseline carries a
touched production file too, and was scanned earlier.

```
$ php assessment.php scan 4f2a91c
scanning 4f2a91c8 as assessment-4f2a91c8e3b0d9a17c5f2e6b4a09d3c81f7e2b5a
analysis of 4f2a91c8 is ready

$ php assessment.php increment 4f2a91c

increment 4f2a91c8
  3 touched production file(s)
  Production Fan out              C -> B  score 73.71 -> 83.90 (higher is better); 7.00 -> 3.67 refs/production file (internal 5.00 -> 3.00, external 2.00 -> 0.67), n 1 -> 3 production file(s)  [live]
  Production Cognitive            max 6 -> 6, over-15 count 0 -> 0 (higher is worse), n 1 -> 2 touched method(s)  [unmoved]
    attribution                   App\Timesheet\TimesheetService::sumDuration    6 -> 0  [fell]
                                  App\Timesheet\TimesheetCalculator::calculate   added at 6  [added]
    control flow leaving          fall 6 in method(s) that still exist, fall 0 in method(s) deleted, rise 6 in method(s) added, rise 0 in method(s) already there  [relocated]
    delegation ratio              App\Timesheet\TimesheetService                 1 of 1 method(s) forward, collaborator internal  [pass-through]
    implementations               App\Timesheet\TimesheetCalculatorInterface     1 real, 0 discounted  [single]
  Unresolved sites (production)   0 -> 0  [unmoved]
  Test unit dependency            total 4 -> 6 production call(s) (2.00 -> 3.00 per test method, higher is worse), n 1 -> 1 test class(es), 2 -> 2 test method(s)  [live]
                                  App\Tests\Timesheet\TimesheetServiceTest     2.00 -> 3.00/test method  [live]
    mock seam                     App\Timesheet\TimesheetCalculatorInterface     mocked  [internal]
  Unresolved sites (test files)   0 -> 0  [unmoved]
  Unmeasured paths                1 path(s), 12 line(s)
                                  config/services.yaml                         12 line(s)
  measures live: 2 of 3

  Question 1  figures  n production files up, cognitive complexity unmoved; test methods unmoved, calls per test method up
              probes   delegation ratio pass-through, implementations single, mock seam internal — all three agree
              outcome  ceremony — names added, nothing simplified
  Question 2  figures  cognitive complexity unmoved, fan out score up
              outcome  not detected — no measure here reads size
```

Reading that: **each measure reports a figure per side**, and the difference between two printed
figures is the reader's subtraction, not a measurement of its own. Polarity is labelled on the line
— fan out on TPM's score, the other two on the metric value — and `n` is printed per side with what
it counts, because files, methods, and classes and methods are not the same thing. Each measure
carries a **state**: `live` when its two sides differ, `unmoved` when they do not (which is not the
same as reading zero — a side can go `15.00 -> 15.00` and be unmoved), `absent` when there was
nothing to read at all. A class that exists only at the baseline is pooled into that side's figure
and gets no class row of its own.

**Each measure stands alone.** TPM weights its five metrics at 20% each, aggregates them into one
overall score, and qualifies a product at 70%, level `C` ([[1]](#ref-1) §6.3, §6.4). Four of those
five are out here (below), so that aggregate is not computable from what this assessment measures,
and no TPM qualification is claimed or implied by the one band it does print. The two production
measures and the test measure are not combined either: they come from three different sources, and
TPM's target of evaluation excludes test code.

The unresolved-site count is a guard, and the only reading that never takes `absent`. It prints
beside the measures and never into them — a **deviation from TPM's metric coverage rule**, which
scales a metric's score by the share of lines it could be measured over ([[1]](#ref-1) §6.2): a name
a static pass cannot resolve limits what is visible, not what is maintainable, and folding it into
the score would mark down a file that got easier to read for holding one more runtime class name.

The two question blocks are not figures: each names what the measures showed, which probes were read
against them, and the outcome that leaves. That outcome is a row of the question's own table, or one
of three readings that leave no row:

- **not detected** — the figures reach no row of the table, which is silence and not a pass.
- **not settled** — a row was reached but what it reads did not part it. Question 1's first group
  says so where one of the three probes it needs had nothing to read or found only `partial` rows;
  its second group, where the rise landed in both the added methods and the ones already there,
  which is two readings and not one. Its third group settles either way: the *ceremony that
  branches* row needs both its probes to agree, and anything else — including an implementations
  probe with nothing to read — is the *real work* row.
- **not readable** — a measure a row would have read was never read at all: no production file
  touched, no method in the cognitive union, or no test method to take a rate from. A measure no row
  needed is not missing; the block answers without it, and question 2 names which half it could not
  read.

## Why TPM's other four metrics are out

They are out on four different grounds, and only one of the four is a fact about this repository
rather than about the metric.

| TPM metric | Why it is out here | What that costs |
|---|---|---|
| Cyclomatic complexity | replaced, not unmeasurable. TPM takes McCabe's definition unchanged ([[1]](#ref-1) §5.1) and it counts the independent paths through a method, which answers how many test cases the method needs; the question this assessment puts to an increment is what its code costs to read, and cognitive complexity answers that one — it charges nesting, where cyclomatic scores a flat `switch` and a triple-nested conditional alike ([[4]](#ref-4)) | Testability reading — paths to cover — which no kept measure replaces |
| Compiler warnings | TPM runs the compiler at its highest warning level and normalises the result through the compliance factor, which needs both a severity level per warning and a count of the checks the compiler performs ([[1]](#ref-1) §5.2, Appendix A). PHP publishes neither: no enumerated catalogue of compiler warnings and no severity grading over one, so the compliance factor has nothing to divide by | Little, while the repository stays clean |
| Code duplication | TPM measures it as the duplicated tokens over the total tokens **of the system** ([[1]](#ref-1) §5.4) — a system-scope quantity by definition. An increment-sized file set is not that system, and a block copied into it from elsewhere in `src/` reads inside it as a single copy | Tautology alarm, nothing in `.github/workflows/` or `composer.json` runs a copy-paste detector, so copying existing code into a touched production file is invisible to this assessment and to the pipeline both |
| Coding standards | circular — `.github/workflows/linting.yaml` runs php-cs-fixer over `migrations/`, `src/` and `tests/`, PHPStan level 9 over `src/` and `tests/`, and `composer linting`, on every pull request and every push to `main`, so an increment that breaks the standard fails the pipeline | Little, analysability reached by no kept TPM measure ([[1]](#ref-1) §4.5), cognitive complexity is what this assessment reads analysability with |

## What the figures do not say

- **Silence is an ordinary outcome.** A small increment moves nothing, and no sample of this
  repository's history is kept, so how often that happens is not measured.
- **No measure reads size.** TPM rules it out as an indicator of maintainability at all
  ([[1]](#ref-1) §3), which is why a straight-line method growing from three statements to thirty is
  not scored at all: its figure did not move, so it enters neither side and the measure has nothing
  to read.
- **Fan out is a lower bound.** It misses container lookups, class names held in strings and types
  named only in a docblock; each is a miss and none of them inflates the figure.
- **What the analyser does not charge for is invisible here.** The two the measure's own section
  names come to 232 points of a 7,871 total at this checkout, all three measured against the same
  tree: 201 coalescing operators over 96 methods, worth a point each; 18 `match` expressions over 18
  methods, worth 29, an arm inside a loop costing more than one; and 2 recursion cycles, worth 2.
  The same code therefore reads about 3% lower than it did while this assessment counted the metric
  itself, and a method built out of `?? ''` reads far lower than that.
- **The guard is narrower than the assumption it guards.** Reading the transition rather than the
  absolute figure assumes the same share is missed on both sides. The unresolved-site count shows
  only whether the *number* of such sites changed, never the missed *share*, which moves with file
  size even when the count holds; and it is syntax only, so a container lookup or a class name held
  in a string leaves no site on either side. A count that moves is a positive result — the figures
  beside it are not evidence; a count that holds is only the absence of that warning.
- **Logic can leave the measured set.** Move an `if` out of PHP into Twig and cognitive complexity
  falls, because the logic left `src/`, not the repository. Fan out follows only if type references
  leave with it. Kimai holds 176 `.twig` templates under `templates/`.
- **The band says nothing about what is normal for Kimai.** The fan out weighting was settled "based
  on experiments and checking available data" and the score cuts are TPM's, both read against TPM's
  own benchmark population — around 5,000 industrial projects and over a billion lines, where fan
  out averages 8.19, level `C` ([[1]](#ref-1) §5.5, §6.4). Neither involves this repository.
- **The overage bands (marginal/moderate/high/severe) are not TPM's kind of band.** They carry no
  benchmark population, no calibration against fault rates or maintenance cost, and no source
  publishes them — they exist only so two methods over 15 aren't read as the same finding, and would
  be exactly as defensible drawn at 1.5×/2.5×/4× instead of 1.33×/2×/3×. Treat the label as a
  reading aid, not a verdict.

## Tools

| Tool | Version | What it does |
|---|---|---|
| `friendsofphp/php-cs-fixer` | v3.95.18 | supports the coding standard claim: whether the linting step gates on an unparseable file |
| `phpstan/phpstan` | 2.2.8 | supports the coding standard claim: whether a parse failure reaches the linting step as an error |
| `php -l` | v8.5.9 | supports the claim that no `src/**.php` file fails to parse |
| `SonarQube Community` | 26.8.0.126808 | holds the analysis of each commit and answers for it over its web API: `/api/issues/search` for the figures rule `php:S3776` reported, `/api/components/tree` for the files that analysis read, `/api/components/show` to tell a project it does not hold from one whose analysis read no PHP, `/api/qualityprofiles/search` and `/api/rules/search` to tell an analysis that found nothing from a profile that would not have reported anything, and `/api/ce/component` to know when a scan has been processed |
| `sonar-php` | 3.59.0.16496 | the analyser that computes cognitive complexity, one figure per method — the reference implementation of the metric, and what this assessment reports instead of counting it itself. Its rule `php:S3776` has to be active at threshold 0 in the profile the projects use, which is what makes it report every method rather than only the ones over a limit |
| `sonarsource/sonar-scanner-cli` | 8.0.1.6346 | submits one commit's `src/` to that server, run in Docker by `assessment.php scan` |
| `assessment.php` | v2.0.0 | calculates fan out and test unit dependency, reads cognitive complexity from SonarQube, resolves the five probes, the two question blocks, the unresolved-site counts, the unmeasured paths and the deleted paths per increment, and whole-`src/` fan out under `repo`; checks its own counting rules, probes and question blocks under `selftest`; invokes `git`, `nikic/php-parser` and the SonarQube web API |
| `git` | v2.51.2 | names the files an increment changed and hands each side's blob; `git archive` materialises the tree `scan` analyses |
| `nikic/php-parser` | v5.8.0 | extracts the syntax tree for calculations |

## Links

<a id="ref-1"></a>
**[1] TPM** — TIOBE and TÜV Informationstechnik, *TIOBE TÜViT Trusted Product Maintainability
ISO/IEC 25010 Quality Model*, version 1.2, 16 December 2021, document ID TIOBE-20211016.1. [Trusted
Product Maintainability —
TIOBE](https://www.tiobe.com/quality-models/trusted-product-maintainability/), which links the PDF.
Sections cited above: §2 (the five ISO/IEC 25010 maintainability sub-characteristics, as TPM states
them), §3 (the mapping of the five metrics onto those sub-characteristics), §4.1 and §5.1
(cyclomatic complexity, Table 1), §4.2 and §5.2 (compiler warnings, Table 3), §4.3 and §5.3 (coding
standards), §4.4 and §5.4 (code duplication), §4.5 and §5.5 (fan out, Table 9), §6.1 (target of
evaluation), §6.2 (metric coverage), §6.3 and §6.4 (weights, aggregation, qualification).

<a id="ref-2"></a>
**[2] McCabe** — Thomas J. McCabe, *A Complexity Measure*, IEEE Transactions on Software Engineering
SE-2(4):308–320, December 1976,
[doi:10.1109/TSE.1976.233837](https://doi.org/10.1109/TSE.1976.233837). The metric cognitive
complexity was written to replace, and TPM's own second metric, taken from here unchanged ([1]
§5.1). Cited above: §V (*Simplification*) for compound predicates counted by their conditions and
for the CASE rule at its note 3 — the two places cognitive complexity counts differently.

<a id="ref-3"></a>
**[3] Test code quality model** — D. Athanasiou, A. Nugroho, J. Visser and A. Zaidman, *Test Code
Quality and Its Relation to Issue Handling Performance*, IEEE Transactions on Software Engineering
40(11):1100–1125, 2014, [doi:10.1109/TSE.2014.2342227](https://doi.org/10.1109/TSE.2014.2342227).
Open-access preprint, TUD-SERG-2014-008: [TU Delft
Repository](https://repository.tudelft.nl/record/uuid:3b6e5a90-d338-4c78-8c84-9c78598568bf). Cited
above: §3.2.5 *Maintainability*, where unit dependency is defined and mapped to a system property of
its own; Table 1 (the maintainability model adjusted from the SIG quality model); Table 2, which
maps the properties onto completeness, effectiveness and maintainability, maintainability entering
it as the adjusted SIG quality model of Table 1 rather than as its four properties; Table 4, whose
`Scope` column is the paper's own system/unit split, with Table 5 (thresholds for system level
metrics) and Table 6 (thresholds for unit level metrics) calibrating the two groups apart; §3.4.1
for the benchmark of 86 proprietary and open-source Java systems, and §3.4.3 for the four risk
categories and the star ratings the paper sorts units into. Every quotation above is verbatim from
§3.2.5, checked against the preprint linked here.

<a id="ref-4"></a>
**[4] Cognitive Complexity** — G. Ann Campbell, *Cognitive Complexity: A new way of measuring
understandability*, SonarSource white paper, 2017; presented as *Cognitive Complexity: an Overview
and Evaluation* at the ACM International Conference on Technical Debt (TechDebt '18), 2018.
[Cognitive Complexity — SonarSource](https://www.sonarsource.com/resources/cognitive-complexity/),
which links the PDF. Cited above for the three kinds of increment — structural, nesting, and one per
sequence of like binary logical operators — for the labelled-jump increment, for `switch` taking one
increment whatever its arm count, and for the metric being defined per function.

<a id="ref-5"></a>
**[5] Refactoring** — Martin Fowler with Kent Beck, *Refactoring: Improving the Design of Existing
Code*, second edition, Addison-Wesley, 2018; the catalogue of smells, online at
[refactoring.com](https://refactoring.com/catalog/). Cited above for three smell names only —
*Middle Man*, a class that delegates most of its work; *Lazy Element*, called *Lazy Class* in the
first edition, one not doing enough to pay for itself; and *Speculative Generality*, an abstraction
built for a case that has not arrived. No chapter or page is cited. This source publishes no metric,
no metric value to cut and no benchmark population — it names the shapes the probes look for, and
every figure a probe puts on one is a convention of this assessment.

<a id="ref-6"></a>
**[6] SIG quality model** — Ilja Heitlager, Tobias Kuipers and Joost Visser, *A Practical Model for
Measuring Maintainability*, 6th International Conference on the Quality of Information and
Communications Technology (QUATIC), IEEE, 2007. The source model the test code quality model adjusts
for test code: source properties — volume, duplication, unit size, unit complexity — mapped onto the
ISO/IEC 9126 maintainability sub-characteristics, each rated against a benchmark of industrial
systems. Cited above only for what `[3]` adapts; nothing here reads a figure or a rating off it, and
no section is cited.
