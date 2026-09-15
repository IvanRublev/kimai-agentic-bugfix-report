# Style guide for a per-branch maintainability report

Last updated: 15 September 2026, 12:39 CEST.

How to turn one `php assessment.php increment <tip> <baseline>` run into a report.
The scoring methodology itself lives in `maintainability-assessment.md`; this file is about 
presentation and judgment on top of it, not about the measures themselves.

## Producing the run

- Treat a multi-commit branch as one squashed increment: baseline is
  `git merge-base <base-branch> <branch>`, increment is the branch tip. Pass both explicitly —
  `php assessment.php increment <tip> <merge-base>` — rather than letting it default to the tip's
  immediate parent.
- `scan` both commits before running `increment`. If the scan fails naming `php:S3776 at threshold
  15`, the SonarQube quality profile is reporting only methods over that limit. Fix it once per
  server: create a PHP quality profile, activate `php:S3776` with `params=threshold=0`, set it as
  the default PHP profile, then re-scan both commits.
- Keep the exact commit list (`git log --oneline <base>..<tip>`) and both SHAs — the report cites
  them for reproducibility.

## Section order and shape

1. `# Maintainability assessment — <ticket>` — title only.
2. `## Verdict` — first thing after the title, two sentences, state plus evidence. Sentence one
   answers the abstraction question; sentence two the harder-to-read question. Every measure named
   carries a short inline gloss in plain words the first time it appears (see *Glossing measures
   inline*) and every claim carries the number that backs it — a grade, a score, a before/after
   pair. A skimmer reading only the title and this section should come away with the right
   conclusion and the numbers that support it, without having to open Reading.
3. `## Increment` — branch name, commit count, both SHAs, the full commit list in a fenced block,
   and the exact command used. This is provenance, not analysis: a reader should be able to
   reproduce the run from this section alone.
4. `## Reading` — the reasoning behind the verdict, one subsection per question (see below).
5. `## Raw output` — the full unedited `assessment.php increment` output in a fenced block, kept
   verbatim, last. It's the audit trail; nothing in Reading or Verdict should require the reader
   to open it, but it has to be there for anyone who wants to check a number.

Verdict leads because it's the conclusion; Increment and Reading are the evidence a reader opens
only if they want to check it; Raw output is last for the reader who wants to verify it themselves
against the tool's own words.

## The Reading section

Three subsections, each opening with a bold question in plain language, then either a short
paragraph or a bulleted list of concrete points under it — never both a paragraph *and* a bullet
list making the same point twice:

- **Did this add abstraction it didn't need?** Answer this by looking at the *new* production code
  itself, not only at the file-count/complexity comparison against baseline. Name the branch's
  touched-file count plainly — "this branch touches N production files; M already existed at
  baseline, K are new" — never "M files were touched before the branch," which reads as if the
  touching happened earlier than the branch itself. Check the new classes for the concrete tells:
  an interface with one implementation, a factory choosing between options, a class whose every
  method just forwards to a collaborator. If a small new class is reused by two or more of the
  other new classes instead of each duplicating the same check, say so — that's abstraction earning
  its keep, not ceremony. Name any class that reads as a "flag either way" but isn't strong enough
  to change the verdict (e.g. a mostly-forwarding class that predates the branch).
- **Did this leave the code harder to read?** Read cognitive complexity per individual function,
  at Campbell's own scope, not as one summed total — a method a reader opens is read on its own,
  not blended into an aggregate. Split every touched method into what the branch wrote from
  scratch and what it edited in a method that already existed:
  - **New functions** carry no inherited baggage; every point of complexity in them is the
    branch's own. Name the highest-opening one and its figure.
  - **Edited pre-existing methods** carry an inherited starting figure the branch didn't choose —
    state it plainly as inherited, not something to hold against this branch — and then the
    branch's own delta on top of it, which the branch *is* accountable for. A method already at 33
    that the branch pushes to 35 is a different finding than a method the branch opens at 35 from
    nothing; say which one happened.
  - Name the severity **band** a method's overage ratio falls in — marginal (1.00–1.33×), moderate
    (>1.33–2×), high (>2–3×), severe (>3×) — but never show the division itself (no `83 ÷ 15`, no
    `×` arithmetic in the prose); the reader picks up the exact math from the methodology if they
    want it. State plainly that this banding is this project's own convention, not a calibrated
    one, the same honesty the 15 line itself already gets.
  - Call a change **significant** or **insignificant** by whether it actually crossed a band or a
    letter grade, not by the raw size of the number. A method that stays severe on both sides after
    gaining a few points is an insignificant degradation; a method crossing SonarQube's 15-point
    line for the first time is a significant change in kind even if it lands in the mildest band —
    say both halves when they diverge like that ("significant in kind... but insignificant in
    degree"). A fan out score that moves within the same letter grade is insignificant; a score
    that crosses a grade boundary is significant.
  - Read the two measures — cognitive complexity and fan out — separately: one can get worse while
    the other gets better, and neither cancels the other.
- **Tests.** Report calls-per-test-method, before and after, glossing the measure the first time it
  appears: fewer calls means a failure is easier to trace to its actual cause, more calls means a
  failure leaves more candidate causes to check first. Read individual test classes against the
  test-quality paper's own named bands — low (up to 3 calls), moderate (4), high (6), very high
  (above 6) — rather than only citing the raw high-risk cut informally; name which band a class
  lands in when it's notable, not just its number. Close with the unresolved-test-call-sites line:
  gloss what an unresolved site is (a call whose target the static parser couldn't resolve, so it
  never enters the count) once, then report it as **a share** — unresolved sites over all attempted
  call sites, at baseline and at the increment — not as a bare before/after count. The raw count can
  double while the branch adds far more tests than unresolved sites, which makes the share fall even
  as the count rises; report the share so the reader isn't misled by the count alone. Keep this
  subsection short — one paragraph, not a full bulleted breakdown, unless the user asks for the
  per-class trade-off behind the number.

## Glossing measures inline

The first time cognitive complexity or fan out is named in the Verdict, and again the first time
each is named in Reading, gloss it in plain words rather than assuming the reader carries the
definition from an earlier section — Verdict and Reading are each read as their own entry point:

- Cognitive complexity — "how many branches and nested conditions a method holds."
- Fan out — "how many other files a reader has to open alongside this one."

For each measure the methodology itself declines to band (cognitive complexity, test unit
dependency), still give the reader a number to anchor against. Cognitive complexity's own
over-threshold count already does this for you — it's a direct count against 15, not something to
compute yourself — but still say plainly that 15 is a common reference point (SonarQube's own
default, and this project's own Baseline complexity ceiling), not a TPM-style band this measure
carries. For test unit dependency, cite the test-quality paper's own low/moderate/high call-count
bands the same way. Where a measure *does* carry a real band (fan out under TPM), cite the band,
the score behind it, and roughly what population it's calibrated against.

## Prose rules

- Write for an attentive skimmer with no methodology background: normal grade-level prose, plain
  words for methodology jargon the first time it's used in *each* section (see *Glossing measures
  inline* — a term glossed in Verdict still needs glossing again on its first appearance in
  Reading).
- No parentheses. Use an em dash or a separate sentence instead.
- No ratio or division arithmetic shown in prose (no `83 ÷ 15`, no `5.53×` computed inline) — name
  the resulting band instead and let Raw output or the methodology carry the math.
- Prefer plain, explicit **improved** / **degraded**, paired with **significantly** /
  **insignificantly**, over metaphorical movement verbs (climbed, dropped, slipped, worse, better,
  heavier, lighter) — the metaphor's own polarity doesn't reliably match the measure's polarity
  (climbing reads as good, but a rising cognitive-complexity figure is bad), where "improved" /
  "degraded" states the direction the measure itself assigns. Reserve "worst" / "most complex" for
  naming which single method holds the highest figure, not as a stand-in for "degraded."
- Don't repeat the same qualifier construction across subsections verbatim (e.g. "For scale, X is a
  rule of thumb, not a limit this project enforces" said the same way twice reads as templated).
  Vary the phrasing or fold the caveat into the sentence that needs it.
- Bullets are for genuinely parallel, separable points under one claim. If a paragraph would read
  fine as flowing prose, don't force it into a list — ask which shape a section wants before
  bulleting all of them uniformly, and drop bullets on request.
- Don't add analysis the user didn't ask for (e.g. a "what did we trade this for" framing) unless
  asked; when asked, add it, but remove it cleanly if asked to drop it again — don't leave a stub.
- Don't add methodology commentary as an aside inside a report (e.g. explaining why a measure
  can't be split the way another one can) unless asked — that belongs in
  `maintainability-assessment.md`, not repeated per report.
- Compress on request by cutting restatement first (a caveat or number said twice), not by cutting
  a claim or a number.
- Every number in Verdict and Reading must trace back to a line in Raw output — no figure gets
  invented or rounded away from what the tool printed.
- Wrap prose paragraphs (Increment, Verdict, Reading) to a consistent width, around 95 columns;
  never rewrap Raw output's fenced block — it stays exactly as the tool printed it.
