# Maintainability assessment — BUG-6039

## Verdict

The new code added no abstraction it didn't need and stays easy to read: no new production file
was added, and of six new functions, the two with real logic open at 1 and 2 on cognitive
complexity — how many branches and nested conditions a method holds. The branch insignificantly
degraded two inherited methods — `RendererTrait::calculateSummary` 33 to 35,
`CustomerMonthlyProjectsRepository::getGroupedByCustomerProjectActivityUser` 14 to 17, newly
crossing SonarQube's 15-point line — while fan out, how many other files a reader has to open
alongside this one, held grade `C`, score 71.50 to 71.10.

## Increment

Branch `BUG-6039-exported-decimal-durations-print-a-total`, 4 commits treated as one squashed
increment against the methodology in `maintainability-assessment.md`.

- Increment (tip): `fb069f70f2a6dd3d8a280fb5533c1542c12b9d4b`
- Baseline (`git merge-base main BUG-6039-...`): `397bcb76c67a1788bc7169663faf76279295b0e4`
- Measured: 15 September 2026, 09:03 CEST

Commits squashed for this reading:

```
fb069f70 fix(export): reconcile per-row money() with moneyValue() totals per currency
faea797a fix(money): round to the currency's own fraction digits, not hardcoded 2
53547510 test(export): extract shared fixtures to remove duplication flagged by jscpd
c16b244d fix(export): reconcile printed totals with the sum of printed decimal rows
```

Produced with `php assessment.php increment fb069f70f2a6dd3d8a280fb5533c1542c12b9d4b
397bcb76c67a1788bc7169663faf76279295b0e4`, cognitive complexity read off a SonarQube analysis
of each side under `php:S3776` at threshold 0.

## Reading

**Did this add abstraction it didn't need?** No abstraction introduced by the branch to have an
opinion about. This branch touches five production files, and all five already existed at
baseline — nothing added, nothing renamed into `src/`, nothing removed. Neither the
delegation-ratio nor the implementations probe prints a row for the production side, because no
touched class forwards in any method and no interface was introduced.

**Did this leave the code harder to read?** Cognitive complexity splits by what this branch
actually wrote versus what it inherited, and the two read differently. The six functions it
wrote from scratch — `durationDecimalValue`, `formatDecimalValue`, `moneyValue` in
`LocaleFormatExtensions`, and their counterparts in `LocaleFormatter` — carry the branch's only
real logic in `formatDecimalValue` and `moneyValue`, opening at 1 and 2. None of them asks a
reader to hold any real control flow in their head. `LocaleFormatter::durationDecimal`, a
method that already existed, actually got simpler, falling from 1 to 0. The two functions that
did degrade were already shaped the way they are before this branch touched them.
`App\Export\Base\RendererTrait::calculateSummary` opened this branch already the touched set's
most complex method, in this project's own high band, and this branch's own edit adds 2 to it,
landing at 35 — an insignificant degradation, since it stays in that same high band.
`CustomerMonthlyProjectsRepository::getGroupedByCustomerProjectActivityUser` opened at 14, one
point under the alarm line, and this branch's edit adds 3, carrying it to 17 — an insignificant
one in degree. Neither function's starting complexity is something this branch chose; what it
chose is how much it added on top, and that's 2 and 3 points respectively — a small addition to
an already-large method, and a small addition that happened to tip a borderline one over the
SonarQube's 15-point alarm line. Fan out — the measure of how many other files a reader has to
open alongside this one — held its `C` grade on both sides, degrading only insignificantly
underneath it, 71.50 to 71.10: internal references per touched file stayed flat at 4.40 while
external rose from 6.60 to 7.00.

**Tests.** Calls per test method — where more means a failure leaves more candidate causes to
check before finding the real one — degraded insignificantly, 0.25 to 0.36, while the suite
grew substantially, 32 to 58 methods — mostly five new test classes covering the reconciliation
behavior this branch adds. `WeeklyUsersExportReconciliationTest` sits at 4.00 calls per method;
the rest sit at 0.82 or below. The two pre-existing classes touched, `LocaleFormatExtensionsTest`
and `LocaleFormatterTest`, stayed roughly flat. A share of unresolved
test-call sites — calls whose target the static parser couldn't resolve, so they never enter
the count — fall from 94% at baseline to 88% at the increment.

## Raw output

```
increment fb069f70
  5 touched production file(s)
  Production Fan out              C -> C  score 71.50 -> 71.10 (higher is better); 11.00 -> 11.40 refs/production file (internal 4.40 -> 4.40, external 6.60 -> 7.00), n 5 -> 5 production file(s)  [live]
  Production Cognitive            total 48 -> 55 over the touched methods (higher is worse), n 3 -> 9 touched method(s)  [live]
    attribution                   App\Export\Base\RendererTrait::calculateSummary 33 -> 35  [rose]
                                  App\Reporting\CustomerMonthlyProjects\CustomerMonthlyProjectsRepository::getGroupedByCustomerProjectActivityUser 14 -> 17  [rose]
                                  App\Utils\LocaleFormatter::durationDecimal     1 -> 0  [fell]
                                  App\Twig\LocaleFormatExtensions::durationDecimalValue added at 0  [added]
                                  App\Twig\LocaleFormatExtensions::formatDecimalValue added at 0  [added]
                                  App\Twig\LocaleFormatExtensions::moneyValue    added at 0  [added]
                                  App\Utils\LocaleFormatter::durationDecimalValue added at 0  [added]
                                  App\Utils\LocaleFormatter::formatDecimalValue  added at 1  [added]
                                  App\Utils\LocaleFormatter::moneyValue          added at 2  [added]
    control flow leaving          fall 1 in method(s) that still exist, fall 0 in method(s) deleted, rise 3 in method(s) added, rise 5 in method(s) already there  [relocated]
  Unresolved sites (production)   1 -> 1  [unmoved]
  Test unit dependency            total 8 -> 21 production call(s) (0.25 -> 0.36 per test method, higher is worse), n 2 -> 7 test class(es), 32 -> 58 test method(s)  [live]
                                  App\Tests\Controller\Reporting\WeeklyUsersExportReconciliationTest added at 4.00/test method  [added]
                                  App\Tests\Export\Base\PrintDecimalReconciliationTest added at 0.82/test method  [added]
                                  App\Tests\Export\Base\RendererTraitTest      added at 0.00/test method  [added]
                                  App\Tests\Reporting\CustomerMonthlyProjects\CustomerMonthlyProjectsRepositoryTest added at 0.00/test method  [added]
                                  App\Tests\Reporting\CustomerMonthlyProjects\MonthlyProjectsExportReconciliationTest added at 0.00/test method  [added]
                                  App\Tests\Twig\LocaleFormatExtensionsTest    0.24 -> 0.22/test method  [live]
                                  App\Tests\Utils\LocaleFormatterTest          0.29 -> 0.15/test method  [live]
    mock seam                     Psr\EventDispatcher\EventDispatcherInterface   mocked  [boundary]
                                  App\Project\ProjectStatisticService            mocked  [internal]
                                  App\Activity\ActivityStatisticService          mocked  [internal]
                                  App\Entity\User                                mocked  [internal]
  Unresolved sites (test files)   129 -> 158  [live — the test figures are not evidence]
  Unmeasured paths                11 path(s), 4922 line(s)
                                  phpstan.neon                                 3180 line(s)
                                  templates/export/print.html.twig             792 line(s)
                                  templates/export/renderer.pdf.twig           349 line(s)
                                  templates/reporting/customer/monthly_projects_data.html.twig 106 line(s)
                                  templates/reporting/customer/monthly_projects_export.html.twig 3 line(s)
                                  templates/reporting/report_user_list_export.html.twig 46 line(s)
                                  templates/reporting/report_user_list_monthly_export.html.twig 46 line(s)
                                  templates/reporting/user_list_period_data.html.twig 150 line(s)
                                  tests/Export/Base/TimesheetEntryFixtureTrait.php 63 line(s)
                                  tests/Reporting/CustomerMonthlyProjects/ThreeUserTenMinuteEntriesFixtureTrait.php 81 line(s)
                                  tests/Twig/SecurityPolicy/StrictPolicyTestCase.php 106 line(s)
  measures live: 3 of 3

  Question 1  figures  n production files unmoved, cognitive complexity total up; test methods up, calls per test method up
              outcome  not detected
  Question 2  figures  cognitive complexity total up, fan out score down
              outcome  harder inside the methods and across the files both
```


