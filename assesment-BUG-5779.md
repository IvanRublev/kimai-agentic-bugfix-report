# Maintainability assessment — BUG-5779

## Verdict

The new code added no abstraction it didn't need and stays easy to read: eight new functions
all open at 2 or under on cognitive complexity — how many branches and nested conditions a
method holds. The branch significantly improved four inherited `LocaleFormatter` methods, three
falling from 7 to 2, while insignificantly degrading the already-severe
`ColumnConverter::getColumns`, 83 to 86; fan out — how many other files a reader has to open
alongside this one — held grade `D`, score 59.34 to 60.37.

## Increment

Branch `BUG-5779-exported-timesheets-carry-dates-outside`, 11 commits treated as one squashed
increment against the methodology in `maintainability-assessment.md`.

- Increment (tip): `f7815ba1fa3d27e95d3ed07520d8a0f6e8c05f21`
- Baseline (`git merge-base main BUG-5779-...`): `397bcb76c67a1788bc7169663faf76279295b0e4`
- Measured: 15 September 2026, 09:03 CEST

Commits squashed for this reading:

```
f7815ba1 test(export): close remaining acceptance-criteria coverage gaps (XLSX file, PDF/print same-instant, DST wall-clock, multi-user, preview/CSV agreement)
a91799c7 test(export): prove PdfTemplateRenderer converts Date/From/To into query timezone
25bea5e9 refactor(export): dedupe timezone-aware date formatting behind shared helpers
6c1ec675 test(export): prove date/time conversion uses the offset in effect at each instant across DST
7a22113a fix(export): label the export date range filter and its timezone hint
ca904934 fix(export): resolve explicit render timezone in ExportController and export console command
957539b6 fix(export): render print and PDF date/from/to columns in the query timezone
a1c8c1a0 fix(export): wire explicit render timezone through ColumnConverter, CSV, and LocaleFormatter
c16468df fix(export): thread header translation params through Column and SpoutSpreadsheet
a2805e8a fix(export): convert date/time cell formatters into explicit timezone
5eacaa91 fix(export): move explicit render timezone to TimesheetQuery base class
d5854c23 fix(export): add explicit export timezone to ExportQuery
```

Produced with `php assessment.php increment f7815ba1fa3d27e95d3ed07520d8a0f6e8c05f21
397bcb76c67a1788bc7169663faf76279295b0e4`, cognitive complexity read off a SonarQube analysis
of each side under `php:S3776` at threshold 0.

## Reading

**Did this add abstraction it didn't need?** This branch touches fifteen production files;
fourteen of them already existed at baseline, and one is new. The one new piece,
`ConvertsTimezoneTrait`, is used by three real classes with no discounted implementation among
them — the implementations probe calls it `polymorphic`, meaning the timezone-conversion logic
it holds is genuinely shared rather than standing over a single caller. Two existing classes
come back `partial` on the delegation-ratio probe: `CsvRenderer` forwards in 2 of its 5
methods, `Column` in 1 of its 10. Neither is a forwarding shell, and neither is clean either —
they sit in between, which is exactly what `partial` means, and it doesn't move the verdict on
its own. No interface was introduced, and no touched class forwards in every method, so neither
of the two strong ceremony tells fires here.

**Did this leave the code harder to read?** Cognitive complexity splits cleanly here between
what this branch wrote versus what it edited in place, and the picture is mostly good. The
eight functions it wrote from scratch — `ConvertsTimezoneTrait::__construct` and
`convertToTimezone`, `Column::getHeaderParams`, `TimesheetQuery::getTimezone` and
`setTimezone`, and `LocaleFormatter`'s `resolveIntlFormatter`, `toDateTimeOrNull`, and
`formatWithIntl` — all open at 2 or under. `LocaleFormatter`'s `dateShort` and `time` each
improved significantly, from 7 to 2, `dateTime` from 7 to 2, `dateFormat` from 5 to 4, as their
timezone-aware formatting moved out into those new helpers — the control-flow-leaving probe
confirms the fall landed in methods that still exist, so this is the branch actively
simplifying code it inherited, not just writing clean code beside it. The one function that got
harder, `ColumnConverter::getColumns`, has to be read in two parts. Its starting point, 83, is
this branch's inherited reality — already the touched set's biggest method before this branch,
sitting in this project's own severe band. What the branch is accountable for is the 3 points
it added on top, landing at 86 — an insignificant degradation. Those severity bands are this
project's own convention, not a calibrated one, but the shape is clear either way: a small
addition to a method that was already a severe outlier, next to a significant fix to four
others. Fan out — the measure of how many other files a reader has to open alongside this one —
held `D` on both sides; the score behind it moved only insignificantly, 59.34 to 60.37, still
below the middle of TPM's benchmark population before this branch and after it.

**Tests.** Calls per test method — where more means a failure leaves more candidate causes to
check before finding the real one — degraded significantly, 1.40 to 2.52, and the suite grew
from 70 to 91 methods with total production calls rising from 98 to 229. Several renderer test
classes rose from zero or one call per method: `XlsxRendererTest` to 6.67,
`PdfTemplateRendererTest` to 6.00, `CsvRendererTest` to 5.67 and `HtmlRendererTest` to 4.75, all
of them newly reaching into renderer classes these tests didn't touch before.
`ColumnConverterTest` improved insignificantly, 8.25 to 7.43. A share of
unresolved test-call sites — calls whose target the static parser couldn't resolve, so they
never enter the count — fall from 69% at baseline to 57% at the increment.

## Raw output

```
increment f7815ba1
  15 touched production file(s)
  Production Fan out              D -> D  score 59.34 -> 60.37 (higher is better); 14.07 -> 13.80 refs/production file (internal 7.86 -> 7.53, external 6.21 -> 6.27), n 14 -> 15 production file(s)  [live]
  Production Cognitive            total 110 -> 107 over the touched methods (higher is worse), n 6 -> 14 touched method(s)  [live]
    attribution                   App\Export\ColumnConverter::getColumns         83 -> 86  [rose]
                                  App\Form\Toolbar\ToolbarFormTrait::addDateRange 1 -> 4  [rose]
                                  App\Utils\LocaleFormatter::dateShort           7 -> 2  [fell]
                                  App\Utils\LocaleFormatter::dateTime            7 -> 2  [fell]
                                  App\Utils\LocaleFormatter::dateFormat          5 -> 4  [fell]
                                  App\Utils\LocaleFormatter::time                7 -> 2  [fell]
                                  App\Export\Package\CellFormatter\ConvertsTimezoneTrait::__construct added at 0  [added]
                                  App\Export\Package\CellFormatter\ConvertsTimezoneTrait::convertToTimezone added at 1  [added]
                                  App\Export\Package\Column::getHeaderParams     added at 0  [added]
                                  App\Repository\Query\TimesheetQuery::getTimezone added at 0  [added]
                                  App\Repository\Query\TimesheetQuery::setTimezone added at 0  [added]
                                  App\Utils\LocaleFormatter::resolveIntlFormatter added at 2  [added]
                                  App\Utils\LocaleFormatter::toDateTimeOrNull    added at 2  [added]
                                  App\Utils\LocaleFormatter::formatWithIntl      added at 2  [added]
    control flow leaving          fall 16 in method(s) that still exist, fall 0 in method(s) deleted, rise 7 in method(s) added, rise 6 in method(s) already there  [relocated]
    delegation ratio              App\Export\Base\CsvRenderer                    2 of 5 method(s) forward, collaborator internal  [partial]
                                  App\Export\Package\Column                      1 of 10 method(s) forward, collaborator internal  [partial]
    implementations               App\Export\Package\CellFormatter\ConvertsTimezoneTrait 3 real, 0 discounted  [polymorphic]
  Unresolved sites (production)   0 -> 0  [unmoved]
  Test unit dependency            total 98 -> 229 production call(s) (1.40 -> 2.52 per test method, higher is worse), n 15 -> 15 test class(es), 70 -> 91 test method(s)  [live]
                                  App\Tests\Command\ExportCreateCommandTest    0.00 -> 0.69/test method  [live]
                                  App\Tests\Controller\ExportControllerTest    0.29 -> 0.25/test method  [live]
                                  App\Tests\Export\Base\CsvRendererTest        0.00 -> 5.67/test method  [live]
                                  App\Tests\Export\Base\HtmlRendererTest       0.00 -> 4.75/test method  [live]
                                  App\Tests\Export\Base\PdfTemplateRendererTest 1.00 -> 6.00/test method  [live]
                                  App\Tests\Export\Base\XlsxRendererTest       0.00 -> 6.67/test method  [live]
                                  App\Tests\Export\ColumnConverterTest         8.25 -> 7.43/test method  [live]
                                  App\Tests\Export\Package\CellFormatter\DateFormatterTest 2.00 -> 2.00/test method  [unmoved]
                                  App\Tests\Export\Package\CellFormatter\DateStringFormatterTest 2.00 -> 2.00/test method  [unmoved]
                                  App\Tests\Export\Package\CellFormatter\TimeFormatterTest 2.00 -> 2.00/test method  [unmoved]
                                  App\Tests\Export\Package\ColumnTest          3.14 -> 3.11/test method  [live]
                                  App\Tests\Export\Package\SpoutSpreadsheetTest 7.00 -> 7.50/test method  [live]
                                  App\Tests\Repository\Query\ExportQueryTest   1.00 -> 1.00/test method  [unmoved]
                                  App\Tests\Repository\Query\TimesheetQueryTest 5.00 -> 5.00/test method  [unmoved]
                                  App\Tests\Utils\LocaleFormatterTest          0.29 -> 0.25/test method  [live]
    mock seam                     Symfony\Component\Mailer\MailerInterface       mocked  [boundary]
                                  Symfony\Bundle\SecurityBundle\Security         mocked  [boundary]
                                  Twig\Environment                               mocked  [boundary]
                                  Symfony\Component\EventDispatcher\EventDispatcherInterface mocked  [boundary]
                                  App\Project\ProjectStatisticService            mocked  [internal]
                                  App\Activity\ActivityStatisticService          mocked  [internal]
                                  App\Pdf\HtmlToPdfConverter                     mocked  [internal]
                                  Symfony\Component\Translation\LocaleSwitcher   mocked  [boundary]
                                  Symfony\Contracts\Translation\TranslatorInterface mocked  [boundary]
                                  Psr\Log\LoggerInterface                        mocked  [boundary]
                                  Symfony\Component\EventDispatcher\EventDispatcher mocked  [boundary]
                                  App\Export\Package\CellFormatter\CellFormatterInterface mocked  [internal]
                                  App\Entity\ExportableItem                      mocked  [internal]
                                  App\Entity\User                                mocked  [internal]
  Unresolved sites (test files)   222 -> 305  [live — the test figures are not evidence]
  Unmeasured paths                4 path(s), 3261 line(s)
                                  .test-db-env                                 1 line(s)
                                  templates/export/print.html.twig             771 line(s)
                                  templates/export/renderer.pdf.twig           326 line(s)
                                  translations/messages.en.xlf                 2163 line(s)
  measures live: 3 of 3

  Question 1  figures  n production files up, cognitive complexity total down; test methods up, calls per test method up
              probes   control flow leaving — fall 16 in method(s) that still exist, fall 0 in method(s) deleted
              outcome  real decomposition — the split removed reading cost
  Question 2  figures  cognitive complexity total down, fan out score up
              outcome  not detected — no measure here reads size
```


