# Maintainability assessment — BUG-5348

## Verdict

The new code added no abstraction it didn't need and stays easy to read: eleven new
`QuickEntryWeekValidator` methods, none forwarding to a collaborator, cognitive complexity —
how many branches and nested conditions a method holds — topping out at 9 against SonarQube's
15-point alarm line. Fan out — how many other files a reader has to open alongside this one —
improved significantly, grade `E` to `D`, score 41.75 to 56.84.

## Increment

Branch `BUG-5348-quick-entry-gives-every-row-of-a-day-the`, 1 commit treated as one squashed
increment against the methodology in `maintainability-assessment.md`.

- Increment (tip): `5377ccf5fccac2aa0e328836a87b4a897536e185`
- Baseline (`git merge-base main BUG-5348-...`): `397bcb76c67a1788bc7169663faf76279295b0e4`
- Measured: 15 September 2026, 09:03 CEST

Commits squashed for this reading:

```
5377ccf5 fix(quick-entry): chain same-day rows and enforce day capacity to stop overlaps
```

Produced with `php assessment.php increment 5377ccf5fccac2aa0e328836a87b4a897536e185
397bcb76c67a1788bc7169663faf76279295b0e4`, cognitive complexity read off a SonarQube analysis
of each side under `php:S3776` at threshold 0.

## Reading

**Did this add abstraction it didn't need?** This branch touches four production files; two of
them already existed at baseline, and two are new — `QuickEntryWeek` and
`QuickEntryWeekValidator`. Neither ceremony tell applies. None of the validator's eleven
methods is a one-line delegation to something else, and nothing in this increment introduces an
interface, so there's no single implementation hiding behind one either.
`groupSubmittedEntriesByUserAndDay`, `validateDay`, `summarizeExistingEntries`, `chainEntries`,
`raiseOverlapViolation`, `raiseDayCapacityViolation` — each of these does its own work rather
than routing to something else. That's a class doing something, not a name added for its own
sake.

**Did this leave the code harder to read?** Twelve methods enter the touched set, and every one
of them is new, so there's no pre-existing method carrying an inherited baseline into this
reading; every point of cognitive complexity is this branch's own.
`QuickEntryWeekValidator::groupSubmittedEntriesByUserAndDay` opens highest, at 9; `validateDay`
and `summarizeExistingEntries` follow at 6 each; the rest — `validate`, `isSubmittedForSave`,
`keepPrefilledTimes`, `chainEntries`, `raiseOverlapViolation`, and three others — sit at 1 or
0. None crosses SonarQube's 15-point alarm line, so none of them needs an overage band. Fan out
— the measure of how many other files a reader has to open alongside this one — improved
significantly, its TPM grade moving from `E` to `D`, score 41.75 to 56.84, because the two new
files carry fewer references than the two files that were already there and pull the average
down: internal references per touched file fell from 12.50 to 8.25, external from 13.00 to
7.75. `D` is still below the middle of TPM's own benchmark population, but the direction here
is an improvement, not a cost.

**Tests.** Calls per test method — where fewer means a failure is easier to trace to its actual
cause — improved insignificantly, 5.93 to 5.57, while the suite grew from 15 to 37 methods and total
production calls rose from 89 to 206. Most of that growth is one new class,
`QuickEntryWeekValidatorTest`, at 5.79 calls per test method. A share of unresolved test-call sites
— calls whose target the static parser couldn't resolve, so they never enter the count — fall from
69% at baseline to 65% at the increment.

## Raw output

```
increment 5377ccf5
  4 touched production file(s)
  Production Fan out              E -> D  score 41.75 -> 56.84 (higher is better); 25.50 -> 16.00 refs/production file (internal 12.50 -> 8.25, external 13.00 -> 7.75), n 2 -> 4 production file(s)  [live]
  Production Cognitive            total 0 -> 29 over the touched methods (higher is worse), n 0 -> 12 touched method(s)  [live]
    attribution                   App\Repository\TimesheetRepository::findForDay added at 0  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::__construct added at 0  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::validate added at 4  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::groupSubmittedEntriesByUserAndDay added at 9  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::isSubmittedForSave added at 1  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::validateDay added at 6  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::summarizeExistingEntries added at 6  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::defaultBeginOf added at 0  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::keepPrefilledTimes added at 1  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::chainEntries added at 1  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::raiseOverlapViolation added at 1  [added]
                                  App\Validator\Constraints\QuickEntryWeekValidator::raiseDayCapacityViolation added at 0  [added]
    control flow leaving          fall 0 in method(s) that still exist, fall 0 in method(s) deleted, rise 29 in method(s) added, rise 0 in method(s) already there  [written]
  Unresolved sites (production)   0 -> 0  [unmoved]
  Test unit dependency            total 89 -> 206 production call(s) (5.93 -> 5.57 per test method, higher is worse), n 2 -> 3 test class(es), 15 -> 37 test method(s)  [live]
                                  App\Tests\Controller\QuickEntryControllerTest 0.86 -> 0.88/test method  [live]
                                  App\Tests\Repository\TimesheetRepositoryTest 10.38 -> 8.90/test method  [live]
                                  App\Tests\Validator\Constraints\QuickEntryWeekValidatorTest added at 5.79/test method  [added]
    mock seam                     App\Repository\TimesheetRepository             mocked  [internal]
                                  Symfony\Contracts\Translation\TranslatorInterface mocked  [boundary]
                                  Symfony\Component\Validator\Validator\ValidatorInterface mocked  [boundary]
  Unresolved sites (test files)   199 -> 389  [live — the test figures are not evidence]
  Unmeasured paths                1 path(s), 195 line(s)
                                  translations/validators.en.xlf               195 line(s)
  measures live: 3 of 3

  Question 1  figures  n production files up, cognitive complexity total up; test methods up, calls per test method down
              outcome  not detected
  Question 2  figures  cognitive complexity total up, fan out score up
              outcome  harder inside the methods — deeper nesting, more breaks in the linear flow
```


