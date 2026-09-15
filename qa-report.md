# QA report

Report-only, live black-box verification (`/qa-only` methodology, driven with `/browse`
against a running dev instance of each fix branch) made by Claude Sonnet 5.
No code was fixed or edited. Each bug's own "Steps to reproduce" section in its spec 
was walked live in the browser against the branch tip commit; acceptance-criteria 
checkmarks in the spec were **not** trusted, only independently re-observed behavior.

Date: 15 September 2026, 11:15–11:31 CEST.

Environment: MySQL/MariaDB dev container `kimai-db`, dev database `kimai`, PHP built-in
server (`php -S 127.0.0.1:8001`). Test data (one customer "Acme Corp", projects "Website
Redesign" / "Mobile App", activity "Frontend development") created fresh through the UI for
this QA pass.

---

## BUG-5348 — Quick entry gives every row of a day the same start time and saves overlaps

**Branch:** `BUG-5348-quick-entry-gives-every-row-of-a-day-the` (tip `5377ccf5`)

**Upstream:** [kimai/kimai#5348](https://github.com/kimai/kimai/issues/5348)

**Verdict: FIXED**

### What was tested

1. Set System Settings → Time Tracking: Default start-time `09:00`, unticked "Allow
   overlapping time entries".
2. Main repro: on `Weekly hours`, one row `Website Redesign` + `Frontend development` with
   `3:00` on Wednesday, a second row `Mobile App` + `Frontend development` with `2:00` on the
   same Wednesday. Saved.
3. Re-opened the same week and resubmitted unchanged, to check the pair doesn't lock the week.
4. Day-capacity boundary: added a third row with `30:00` on a different day (Thursday) of the
   same week.

### Observed vs expected

- Save succeeded without error (spec expects no silent overlap).
- Inspected the two persisted records directly in the database: `2026-09-16 07:00:00 –
  2026-09-16 10:00:00` (3h, Mobile App) and `2026-09-16 10:00:00 – 2026-09-16 12:00:00` (2h,
  Website Redesign) — chained back-to-back, zero overlap. Matches the spec's expected
  "either chained back to back... or the save is rejected" — chaining is the behavior
  implemented.
- Resubmitting the same week with the now-existing pair unchanged produced no "You already
  have an entry for this time." error and no data loss — matches the acceptance criterion that
  existing pairs don't self-lock the week.
- The `30:00` single-cell entry on Thursday was rejected with message "The entries for
  2026-09-17 exceed the hours available on that day." — matches the day-capacity rule. Verified
  via direct DB read that nothing was persisted for that day (only the original two Wednesday
  records existed afterward).

### Discrepancies / concerns

None found. Did not independently re-verify the DST-boundary and multi-user acceptance
criteria live (time-boxed to the primary repro + two of the most consequential edge cases);
those rely on the automated test suite cited in the spec.

---

## BUG-5779 — Exported timesheets carry dates outside the filtered range (timezone mismatch)

**Branch:** `BUG-5779-exported-timesheets-carry-dates-outside` (tip `f7815ba1`)

**Upstream:** [kimai/kimai#5779](https://github.com/kimai/kimai/issues/5779)

**Verdict: FIXED**

### What was tested

Timezone offset at this date: `Europe/Berlin` is CEST (UTC+2), `America/New_York` is EDT
(UTC-4) — a 6-hour difference, both in DST in August. So a `01:00` Berlin timestamp lands at
`19:00` the previous day in New York.

Exact repro from the spec:
1. Set admin timezone to `Europe/Berlin`, created a timesheet `2026-08-21 01:00–02:00`.
2. Verified in the database it stored as `2026-08-20 23:00:00` UTC / `date_tz=2026-08-21` /
   `timezone=Europe/Berlin` — matches the spec's stated fixture exactly.
3. Switched admin timezone to `America/New_York`.
4. Opened `Time Tracking > Export`, set the date filter to `8/20/2026 - 8/20/2026`, searched.
5. Read the preview table's Date/From/To cells.
6. Fetched the CSV export (`POST /en/export/data`, `renderer=csv`) for the same filter and read
   the raw file content.
7. Fetched the print export (`renderer=print`) and read the rendered Date/From cells for the
   same row.

### Observed vs expected

- Export filter label reads "Date range" with hint "Filter timesheets using your timezone
  (America/New_York)" — matches spec's requested labelling exactly.
- Preview table: Date `8/20/2026`, From `7:00 PM`, To `8:00 PM` — matches spec's expected
  values exactly, and the date is inside the filtered range (not `8/21/2026`).
- CSV file content:
  ```
  "Date (America/New_York)",From,To,Duration,...
  2026-08-20,19:00,20:00,1:00,...
  ```
  Header names the timezone; row date/times match spec's expected `2026-08-20,19:00,20:00`
  exactly.
- Print document: Date cell `8/20/2026`, From cell `19:00` for the same row — same instant, one
  timezone, no `8/21/2026` found anywhere in the rendered document (checked via string search
  over the full HTML).

### Discrepancies / concerns

None found. Did not independently exercise the XLSX/PDF binary renderers, the DST scenario, or
the multi-user scenario live — those rely on the automated tests cited in the spec.

---

## BUG-6039 — Exported decimal durations print a total that differs from the sum of the rows

**Branch:** `BUG-6039-exported-decimal-durations-print-a-total` (tip `fb069f70`)

**Upstream:** [kimai/kimai#6039](https://github.com/kimai/kimai/issues/6039)

**Verdict: FIXED**

### What was tested

Exact repro from the spec:
1. Set admin hourly rate to `10.00` (My profile > Preferences).
2. Created three timesheet entries of exactly 10 minutes each on the same day, same
   project/activity (`09:00–09:10`, `10:00–10:10`, `11:00–11:10`). Confirmed in the database
   each stored as `duration=600`, `rate=1.6667`.
3. Exported that day via `renderer=print` (`POST /en/export/data`) and inspected the raw HTML
   for `data-duration-decimal` and money (`€`) values.

### Observed vs expected

- Duration column: three rows of `0.17`, totals row `0.51` — printed rows sum to the printed
  total exactly, matching the spec's expected fix (`0.51`, not the buggy raw-sum `0.50`). No
  `0.50` string present anywhere in the document at all.
- Price column: three rows of `€1.67`, total `€5.01` — again reconciled, matching the spec's
  money-check acceptance criterion exactly (not the buggy `€5.00`).

### Discrepancies / concerns

None found for the primary repro and the money check, the two scenarios the spec calls out as
central. Did not independently re-verify the round-down direction (29-minute entries), the
multi-level group/grand-total case, the reporting-export family (`report_user_list_export.html.twig`
and siblings), or the three-decimal-currency edge case live — those rely on the automated
tests cited in the spec.

---

## Summary

| Bug | Verdict |
|---|---|
| BUG-5348 | FIXED |
| BUG-5779 | FIXED |
| BUG-6039 | FIXED |

All three fixes hold up under independent, live, black-box re-verification of their primary
repro steps and at least one consequential edge case each. No regressions or discrepancies
observed in what was tested.
