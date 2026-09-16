# Agentic fleet report - three bugs fixed in [Kimai](https://www.kimai.org), a PHP 8.2 project

**Bottom line.** A fully autonomous Claude Sonnet 5 agent fleet at medium effort fixed three
real upstream Kimai bugs in 2h 04m of wall clock and verified them live in 15m 28s. With 1h 50m
of human ticket work and an estimated 7.6–10.2 hours of pull request review, the full cycle takes
11.8–14.4 hours. A senior developer plus a QA engineer using AI copilots would need 148 hours,
or 19 working days. Static measures show no unneeded abstraction and no new hard-to-read method
in the code produced by the agentic fleet. The three-agent parallel work was orchestrated by
[Kaizero](https://kaizero.sh) — Sensei and Supervisor for a fleet of coding agents.

| Question | Answer | Evidence |
|---|---|---|
| Are the bugs fixed? | Yes, 3 of 3 | [qa-report.md](qa-report.md) |
| How fast? | 11.8–14.4 hours, against 148 hours with AI copilots | [times](#2-speed-for-2035-changed-lines-of-code) |
| Is the code bloated or hard to read? | No, with two small caveats | [assessments](#4-code-bloat-and-readability) |

---

## 1. Goal reached

All three fixes start from Kimai 2.67.0, commit `397bcb76`, and each is one pull request to upstream
Kimai. Each bug's "Steps to reproduce" was walked live in a browser against the branch tip in a
separate QA session. A Claude Sonnet 5 QA-agent did this, report-only, and ignored previous model's
checkmarks. A human then confirmed the model's results.

| Bug | Symptom before | Observed after | Verdict | Fix |
|---|---|---|---|---|
| [#5348](https://github.com/kimai/kimai/issues/5348) | Quick entry saves same-day rows with the same start, so they overlap | Rows chain back to back. A 30h day is rejected | FIXED | [PR #6192](https://github.com/kimai/kimai/pull/6192) |
| [#5779](https://github.com/kimai/kimai/issues/5779) | Export shows dates outside the filtered range | Preview, CSV and print all show `2026-08-20 19:00` in the user's timezone | FIXED | [PR #6193](https://github.com/kimai/kimai/pull/6193) |
| [#6039](https://github.com/kimai/kimai/issues/6039) | Decimal total differs from the sum of rows | Rows `0.17 × 3`, total `0.51`. Money `€1.67 × 3`, total `€5.01` | FIXED | [PR #6194](https://github.com/kimai/kimai/pull/6194) |

**Not verified live:** DST and multi-user cases, XLSX/PDF binaries, some reporting exports.
Automated tests cover these cases. Details: [qa-report.md](qa-report.md).

**9 live test cases:** 3 for #5348 (overlap repro, resubmitting an existing pair, the 30-hour day
limit), 4 for #5779 (filter label, preview, CSV, print), 2 for #6039 (duration total, money total).

## 2. Speed for 2,035 changed lines of code

| | Software Developer + Agentic fleet | Senior developer and QA engineer using AI copilots, sequential | Senior developer and QA engineer without AI, sequential |
|---|---|---|---|
| Ticket reproduction and writing | 39m, human with Claude Opus 5 | 39m | 39m |
| Ticket review and edge cases | 1h 11m, human with Claude Opus 5 | 1h 11m | 1h 11m |
| Implementation | 2h 04m wall clock, 3 parallel agents at medium effort, unattended | 146 hours, 18.3 person-days | 185 hours, 23.1 person-days |
| Live QA, 9 test cases | 15m 28s, 1 agent | included in rate | included in rate |
| Pull request review, 2,035 lines | 7.6–10.2 hours, human, estimated | included in rate | included in rate |
| **Total elapsed** | **11.8–14.4 hours** | **148 hours, or 19 working days** | **187 hours, or 23 working days** |
| **Agentic fleet speed-up** | | **10.3–12.5×** | **13.0–15.8×** |
| **Cost estimated** | **€695–855** | **€9,108** | **€11,508** |

**How the senior developer figure without AI is built.** The three branches add 2,035 effective
lines. That count covers production code, templates, translations and tests, and excludes blank and
comment lines. Kimai 2.67.0 holds about 124,000 effective lines of PHP in `src/` and
`tests/` [[1]](#ref-1), so it falls in McConnell's 100,000-line project tier. That tier's
best case is 20,000 lines per staff-year [[2]](#ref-2). With a staff-year of 12 COCOMO II
person-months at 152 hours each [[3]](#ref-3), that is 11.0 lines per hour, or about 88 per 8-hour
day. The rate covers the whole project effort, so coordination is already inside it. 2,035 lines
take 185 hours, or 23.1 person-days. Tests make up 1,505 of those lines. 20,000 is the top of
McConnell's range; the tier's COCOMO II nominal of 2,600 lines per staff-year gives 1.43 lines per
hour and about 1,420 hours.

**What the COCOMO rate holds.** For the Waterfall model, COCOMO II effort runs from the software
requirements review to the software acceptance review (§6.6) [[3]](#ref-3). Its work breakdown
includes product and acceptance testing (§6.4, Table 48), so the rate above already holds QA. It
excludes requirements work before that review (§6.4, Table 48), so the recorded ticket times are
copied into both human columns.

**How the fleet figures are built.** The agent times come from the Claude Code session logs,
from the launch prompt to the final answer. A human launched the fleet with
[Kaizero](https://kaizero.sh), which coordinated the agents from there on. No human attention
was needed until the pull requests were ready, so the fleet column carries no unrecorded
supervision time. Ticket work, implementation and QA take 1.83 + 2.07 + 0.26 = 4.16 hours.

**How the copilot figures are built.** In a randomized controlled trial at Google, 96 engineers used
code completion, smart paste and natural language to code [[4]](#ref-4). A simple comparison of the
two groups finds AI users significantly faster: 96 against 114 minutes on average (p = .038).
Controlling for factors such as coding hours per day and seniority, the authors estimate AI made
them about 21% faster, but the confidence interval is large and that estimate is not statistically
significant (p = 0.086). The 21% time reduction is applied to the whole COCOMO rate, which also
holds testing. On the 185 implementation hours without AI it gives 185 × 0.79 = 146 hours.

**How the review figure is built.** Pull request review time was not recorded, so the fleet's review
time is an estimate. The human columns already hold review, because the COCOMO II MBASE/RUP work
breakdown includes inspections and peer reviews (§6.4, Table 50) [[3]](#ref-3). A SmartBear study of
a Cisco Systems programming team revealed that developers should review no more than 200 to 400
lines of code at a time, and in practice a review of 200–400 lines over 60 to 90 minutes should
yield 70–90% defect discovery [[5]](#ref-5). Both numbers describe one sitting, so a small sitting
is 200 lines in 60 minutes and a large one 400 lines in 90. For 2,035 lines that is 2,035 × 1.5 ÷
400 = 7.6 hours to 2,035 ÷ 200 = 10.2 hours, spread over several sittings. With the 4.16 fleet
hours, the fleet column totals 11.8–14.4 hours. The two caveats in section 4 may slow review
locally.

## 3. Cost estimation

**Agent tokens.** ccusage [[6]](#ref-6) read the Claude Code session logs of the ticket session, the
implementation sessions and the QA session. Implementation used 3.0 thousand uncached input tokens,
5.88 million 5-minute cache writes, 0.65 million 1-hour cache writes, 363.3 million cache reads and
0.79 million output tokens. QA used 0.3 thousand, 0.09 million, 0.07 million, 23.5 million and 0.03
million. At the European Central Bank reference rate of 1.1551 dollars per euro on 14 September 2026
[[8]](#ref-8), the Claude Sonnet 5 prices per million tokens are €1.7315 for input, €2.1643 for
5-minute cache writes, €3.4629 for 1-hour cache writes, €0.1731 for cache reads and €8.6573 for
output [[7]](#ref-7). All requests ran at standard tier and speed, with no data residency surcharge.
Implementation cost €84.75 and QA €4.77. The ticket work ran in one Claude Opus 5 session from the
first reproduction prompt to the last review prompt. It used 458 uncached input tokens, 0.21 million
1-hour cache writes, 54.1 million cache reads and 0.13 million output tokens. At the Opus 5 prices
of €4.3286 for input, €8.6573 for 1-hour cache writes, €0.4329 for cache reads and €21.6431 for
output [[7]](#ref-7), that is €27.95. All tokens together cost €117.47. The token counts above are
rounded for display. Applied to the unrounded counts, these prices reproduce the cost ccusage
reports to the cent for whole sessions.

**Human labor.** The median senior software engineer salary is €111,800 in Germany, €96,200 in
France and €128,741 in the United Kingdom [[9]](#ref-9), converted from pounds at the reference
rate of 0.85598 pounds per euro on the same day [[8]](#ref-8). The average of the three is
€112,247 a year. Over a COCOMO II staff-year of 1,824 hours [[3]](#ref-3), that is €61.54 per
hour. The salary excludes employer social contributions, so the human costs are a lower bound.

**Totals.** The fleet column pays for 1.83 + 7.6 = 9.4 to 1.83 + 10.2 = 12.0 human hours of ticket
work and review, plus the tokens: €578–738 plus €117.47, or €695–855. The copilot column pays for
148 hours: €9,108. The column without AI pays for 187 hours: €11,508. The fleet costs 10.7–13.1×
less than work with copilots and 13.5–16.6× less than work without AI. Copilot subscriptions are not
counted.

## 4. Code bloat and readability

The two common objections to agent code are covered by the two questions each assessment answers.

- **Excessive code:** did the fix add abstraction it didn't need?
- **Harder to read:** did the fix raise cognitive complexity or fan out? Cognitive complexity counts
  branches and nesting in one method; 15 is SonarQube's alarm line [[10]](#ref-10). Fan out counts
  how many other files a reader must open alongside this one; TPM's benchmark average is 8.19
  references per file, a score of 75, grade C [[11]](#ref-11), on a scale where 90 or more is A, 80
  B, 70 C, 50 D, 40 E, below 40 F.

| Bug | Unneeded abstraction? | Most complex new method | Inherited methods touched | Fan out grade |
|---|---|---|---|---|
| [5348](assesment-BUG-5348.md) | No. 2 new classes, no interface, no forwarding methods | 9 | none | E → D, improved |
| [5779](assesment-BUG-5779.md) | No. 1 new trait, shared by 3 classes | 2 | 4 `LocaleFormatter` methods improved, 3 from 7 → 2 and 1 from 5 → 4. `getColumns` 83 → 86 | D → D |
| [6039](assesment-BUG-6039.md) | No. 0 new production files | 2 | `calculateSummary` 33 → 35. One repository method 14 → 17, now over 15 | C → C |

**Where the code went.** 74% of new effective lines are tests, and 26% are production code. The
volume is proof of the fix, not ceremony.

**Caveats.**

- 6039 pushed one inherited method past the 15-point line.
- In 5779, test calls per method degraded from 1.40 to 2.52. Four renderer test classes rose from
  0.00–1.00 to 4.75–6.67 calls per test method [[12]](#ref-12). More calls per test means a failure
  is harder to trace to one cause.

**Method.** Scores come from SonarQube plus [`assessment.php`](how-we-measured/assessment.php). The
methodology and its sources are in
[maintainability-assessment.md](how-we-measured/maintainability-assessment.md). Report rules are in
[report-style-guide.md](how-we-measured/report-style-guide.md). Each report cites its commit SHAs
and the exact command, so the numbers can be reproduced.

## References

1. <a id="ref-1"></a>Kimai, version 2.67.0. <https://github.com/kimai/kimai>. Source of the codebase
   size: 136,694 effective lines, 63,764 in `src/`, 60,380 in `tests/` and 12,550 in `templates/`,
   counted in the local checkout without blank and comment lines. By language that is 124,144
   lines of PHP in `src/` and `tests/`, and 12,550 lines of Twig in `templates/`.
2. <a id="ref-2"></a>Steve McConnell, *Software Estimation: Demystifying the Black Art*, Microsoft
   Press, 2006, Chapter 5, Table 5-1, column "Lines of Code per Staff Year (COCOMO II Nominal in
   Parentheses)". Also quoted in Jeff Atwood, "Diseconomies of Scale and Lines of Code",
   <https://blog.codinghorror.com/diseconomies-of-scale-and-lines-of-code/>. Source of the senior
   developer rate: 1,000–20,000 lines per staff-year for a 100,000-line project, 2,600 COCOMO II
   nominal, top of the range used.
3. <a id="ref-3"></a>Barry Boehm et al., *COCOMO II Model Definition Manual*, version 2.1, 2000.
   <https://www.rose-hulman.edu/class/csse/csse372/201410/Homework/CII_modelman2000.pdf>. Source of
   the nominal 152 hours per person-month (§3), and of the effort scope: the Waterfall end points
   from software requirements review to software acceptance review (§6.6), product and acceptance
   testing in the Waterfall work breakdown (§6.4, Table 48), and inspections and peer reviews in
   the MBASE/RUP work breakdown (§6.4, Table 50).
4. <a id="ref-4"></a>Elise Paradis et al., "How much does AI impact development speed? An
   enterprise-based randomized controlled trial", Google, 2024. <https://arxiv.org/abs/2410.12944>.
   Source of the 21% speed-up with AI features.
5. <a id="ref-5"></a>SmartBear, "Best Practices for Code Review".
   <https://smartbear.com/learn/code-review/best-practices-for-peer-code-review/>. Source of the
   review rate: a SmartBear study of a Cisco Systems programming team revealed that developers
   should review no more than 200 to 400 lines of code (LOC) at a time, and in practice a review
   of 200-400 LOC over 60 to 90 minutes should yield 70-90% defect discovery. The underlying Cisco
   study covered 2,500 reviews of 3.2 million lines over ten months:
   <https://smartbear.com/resources/case-studies/cisco-systems-collaborator/>.
6. <a id="ref-6"></a>ccusage, version 20.0.20. <https://github.com/ryoppippi/ccusage>. Source of the
   token counts, read from the Claude Code session logs.
7. <a id="ref-7"></a>Anthropic, "Pricing".
   <https://platform.claude.com/docs/en/about-claude/pricing>. Source of the prices per million
   tokens, published in US dollars and converted at the ECB rate. Claude Sonnet 5: $2 = €1.7315
   input, $2.50 = €2.1643 5-minute cache write, $4 = €3.4629 1-hour cache write, $0.20 = €0.1731
   cache read, $10 = €8.6573 output. Claude Opus 5: $5 = €4.3286 input, $6.25 = €5.4108 5-minute
   cache write, $10 = €8.6573 1-hour cache write, $0.50 = €0.4329 cache read, $25 = €21.6431 output.
8. <a id="ref-8"></a>European Central Bank, "Euro foreign exchange reference rates", 14 September
   2026.
   <https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html>.
   Source of the exchange rates: 1.1551 US dollars and 0.85598 pounds sterling per euro.
9. <a id="ref-9"></a>Ravio, "Software engineer salary trends in 2026: Average salaries, differences
   per country, and AI premiums", 2026. <https://ravio.com/blog/software-engineer-salary-trends>.
   Source of the median senior software engineer salaries: €111,800 in Germany, €96,200 in France,
   €128,741 in the United Kingdom, converted from pounds at the ECB rate.
10. <a id="ref-10"></a>G. Ann Campbell, "Cognitive Complexity: A new way of measuring
    understandability", SonarSource white paper, version 1.7, 29 August 2023.
    <https://www.sonarsource.com/resources/cognitive-complexity/>. Source of the cognitive
    complexity metric. The 15-point line is the default threshold of SonarQube rule `php:S3776`,
    which implements it.
11. <a id="ref-11"></a>TIOBE and TÜV Informationstechnik, "TIOBE TÜViT Trusted Product
    Maintainability ISO/IEC 25010 Quality Model", version 1.2, 2021, §5.5.
    <https://www.tiobe.com/quality-models/trusted-product-maintainability/>. Source of the fan out
    score formula, the grade cuts and the benchmark average: 8.19 references per file, which scores
    75 under TPM's 1:1 internal-to-external assumption.
12. <a id="ref-12"></a>D. Athanasiou, A. Nugroho, J. Visser and A. Zaidman, "Test Code Quality and
    Its Relation to Issue Handling Performance", IEEE Transactions on Software Engineering 40(11),
    2014. <https://doi.org/10.1109/TSE.2014.2342227>. Source of the calls-per-test-method measure,
    "the number of unique outgoing calls (fan-out) from a test code unit to production code units".

---

16 September 2026, Ivan Rublev https://ivanrublev.com
