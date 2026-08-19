# Kurash Competition Management System

## Consolidated initial planning and implementation specification

This document consolidates the four **Initial Planning and Information for
System Implementation** PDFs. It records the requested user workflows, screen
content, exports, and tournament rules in implementation-ready English.

The later documents refine the earlier ones. Where they conflict, the guidance
in documents 3 and 4 is treated as the final requirement and is noted below.

## 1. Purpose

Build a management system for running a Kurash competition from athlete
registration through final results, medal standings, certificates, and
scoreboard integration.

The core workflow is:

```text
Championship setup
  → athlete registration
  → weigh-in confirmation
  → entries by NOC / weight category
  → draw numbers
  → bracket generation and draw
  → fight order
  → scoreboard updates
  → results, medals, certificates, archive
```

## 2. Global user experience

### 2.1 Entry and authentication

- Show a login page before access to competition data.
- After login, the initial welcome screen shows only:

  ```text
  Welcome
  to the
  International KURASH Association
  ```

- Clicking the welcome message opens the **Championship Management Dashboard**.

### 2.2 Navigation

The main navigation must contain:

1. Championship Management
2. Athlete Registration
3. Weigh-in Form
4. Number of Entries by NOC
5. Number of Entries by Weight Categories
6. Fight Order
7. Result
8. Medal Standing
9. Archive

Documents 2 and 3 disagree on the sidebar side (right versus left). Use one
consistent, responsive sidebar; document 3 requests the **left** side and is
the later requirement.

### 2.3 Common page header

Every page after the welcome screen displays:

```text
International KURASH Association
```

From Athlete Registration onward, also display:

```text
[Competition name]
[Table or form title]
```

### 2.4 General screen requirements

- All list/detail screens need the appropriate **Save**, **Edit**, and
  **Delete** actions.
- Every relevant table/form must provide a printable or downloadable PDF.
- Exported Excel/CSV files must use clear, descriptive filenames.
- The application must support English labels. Persian content in the planning
  documents should be treated as requirement notes, not as a restriction on
  future localisation.

## 3. Championship management

### 3.1 Championships

- Create, edit, and delete championships.
- A championship owns one or more categories and their weight classes.
- Replace the label **Age Categories** with **No. of Categories** in the
  championship list.
- Replace **Manage Age Categories** with **Manage Categories**.

### 3.2 Category management

- Create and edit registered categories and their weight classes.
- Replace the former **Open Dashboard** category action with **Edit**.
- Weight classes must be available throughout the system for registration,
  weigh-in, draw, bracket, results, and medal calculations.

## 4. Athlete registration

### 4.1 Registration form

Each athlete registration must support at least:

| Field | Notes |
| --- | --- |
| Athlete name | Required display name |
| Athlete ID (IKA) | Unique competition/athlete identifier |
| Nationality / NOC | NOC code, country name, and flag where available |
| Gender | Used in category and export labels |
| Weight category | Selected weight class |

### 4.2 Registration outputs

- Show an athlete list for the selected championship/category.
- Export the list as PDF.
- Export the list as Excel/CSV.
- Produce the **Total Athlete Information Table** with athlete name, athlete
  ID, flag, NOC, gender, and weight category.
- Support an Entrance ID Card / accreditation card with competition logos,
  personal photo, athlete name, NOC code, position, QR code, access areas,
  access-area descriptions, and accreditation conditions.

## 5. Weigh-in

### 5.1 Weigh-in form

Display at least:

| Field | Notes |
| --- | --- |
| Athlete name | Registered athlete |
| Athlete ID (IKA) | Registered identifier |
| Nationality / NOC | Include flag when available |
| Measured weight | Actual weigh-in result |
| Weight category | Registered class |
| Status | See rules below |

### 5.2 Weigh-in rules

- Provide a weight-category filter; selecting a class displays only athletes
  in that class.
- Default status: **No weight-in**.
- Enable **Save** when the official records a weight.
- Treat a result within **±500 grams** of the category target/rule as **OK**.
- Mark a result outside the permitted range as **Pending**.
- The final status vocabulary is:

  ```text
  No weight-in | Pending | OK
  ```

- Use a database-compatible timestamp function. The original SQLite error
  `no such function: NOW` must not occur.

### 5.3 Confirmed weigh-in export

- Provide PDF and Excel/CSV export for the selected/confirmed weight class.
- Export only confirmed/eligible athletes when creating the draw input.
- The legacy workflow stores these files in:

  ```text
  Confirmed weight-in List/
  ```

- Name the export by gender and weight class, for example:

  ```text
  Male -91
  ```

- Include this export header:

  ```text
  Gender / Weight Category
  ```

- Include these columns:

  ```text
  Athlete's Name
  Athlete's ID (IKA)
  NOC + Flag
  Bracket Title
  Draw No.
  ```

- Leave **Draw No.** empty in this export so the executive team can assign or
  edit draw numbers.

## 6. Entries and competition readiness

### 6.1 Entries by NOC

- Show the number of entries grouped by NOC/country.
- Provide a printable/downloadable report.

### 6.2 Entries by weight category

For each registered weight class, show:

| Column | Requirement |
| --- | --- |
| Weight Category | Registered class |
| Number of Entries | Confirmed/weighed-in athletes in the class |
| Athlete's List | Downloadable athlete-list PDF |
| Start | Starts the draw/bracket for that exact category |
| Draw Result | Downloadable draw/bracket result |
| Draw Status | `Not Started` or `Done` |

Document 4 overrides the earlier labels: **Status** becomes **Draw Status**,
the status values are **Not Started** / **Done**, and **Result** becomes
**Draw Result**.

The entry-count page replaces the former standalone **Start Competition**
navigation item.

## 7. Draw and bracket generation

### 7.1 Starting a draw

- Clicking **Start** for a weight class opens that class's draw and bracket
  directly; it must not first open a file-upload page.
- Populate a **To be Drawn** area with eligible athletes for the selected
  gender/category/weight class.
- Read the draw number from the confirmed weigh-in/export workflow or its
  imported data.
- Clicking **Draw** places athletes into bracket slots according to their
  assigned draw number and removes each placed athlete from **To be Drawn**.
- Display athlete name, NOC, and country flag in the bracket where available.
- Empty bracket slots must be labelled **BYE**.

### 7.2 Bracket sizes and phases

Choose the bracket based on the number of athletes:

| Number of athletes | Bracket / highest phase |
| ---: | --- |
| 2 | Final |
| 3–4 | Semi-final |
| 5–8 | Quarter-final (1/4 Final) |
| 9–16 | 1/8 Final |
| 17–32 | 1/16 Final |
| 33–64 | 1/32 Final |

Supported phases include 1/32 Final, 1/16 Final, 1/8 Final, Quarter-final,
Semi-final, Final, and Round Robin where competition rules require it.

### 7.3 Draw screen layout

The screen contains:

```text
International KURASH Association
[Competition name]
[Gender category] [Weight category]

DRAW     SAVE

To be Drawn                     [Bracket title]
```

- Use the selected category's correct bracket size, not one fixed template.
- Follow the supplied slot ordering for the relevant seed size.
- The documents show a 16-slot example; it is only an example.

### 7.4 Saved draw artifacts

- After **Save**, generate both PDF and Excel/CSV draw artifacts.
- The legacy requested folder is:

  ```text
  Draw Results/
  ```

- Required filename pattern:

  ```text
  Draw-[Gender category] [Weight category]
  ```

- Downloads must be saveable files; they do not need to open in a separate
  application window.

> Implementation note: the database should remain the source of truth. The
> `Confirmed weight-in List` and `Draw Results` folders are export/import
> compatibility artifacts, not the authoritative competition state.

## 8. Fight order and scoreboard

### 8.1 Fight order

Add **Fight Order** after entries-by-weight and before Archive in navigation.

The Fight Order report/table must include:

| Field | Notes |
| --- | --- |
| Fight Number | Running order number |
| Gender / Weight Category | Competition division |
| Phase | Bracket phase |
| Color | Blue / Green competitor row |
| Athlete name | Competitor name |
| NOC / Flag | National representation |
| Winner | Result winner |

The historical process read manually assigned fight numbers from a yellow
column in exported Excel draw sheets. Yellow was only a visual instruction;
final spreadsheets should use normal white cells.

### 8.2 Scoreboard loop

The competition loop is:

```text
Fight order → send bout to scoreboard → receive result → update fight order
                                      → update bracket → update results
```

- Connect courts/mats to the scoreboard.
- Send the scheduled bout to its assigned mat.
- Receive score/result updates from the scoreboard.
- Repeated scoreboard updates must safely update the fight order and bracket.
- Results must then feed medals, standings, and archive data.

## 9. Results, medals, certificates, and archive

Provide:

1. Results by event/weight category
2. Medal lists by event
3. Medal standing by NOC
4. Certificates
5. Archive of completed competitions and result reports

Result and Medal Standing layouts should retain the previously approved forms
referenced by the planning documents.

## 10. Implementation acceptance checklist

### Data and security

- [ ] Authenticated access and role-based permissions
- [ ] Unique athlete identifiers and scoped championship/category records
- [ ] Database migrations and backups
- [ ] Audit trail for weigh-in, draw, bracket, and result changes
- [ ] Secure scoreboard webhook authentication

### Tournament operation

- [ ] Registration, editing, and exports work
- [ ] Weigh-in statuses follow the ±500 g rule
- [ ] Entries count only eligible athletes
- [ ] Each weight class opens its own draw and correctly sized bracket
- [ ] Empty bracket positions are BYE
- [ ] Draw PDF/Excel artifacts are generated with the requested names
- [ ] Fight order never schedules a bout before its feeder
- [ ] Scoreboard results update the correct bout exactly once
- [ ] Results, medals, standings, and archive are calculated from stored data

### Presentation

- [ ] Common International KURASH Association header is present
- [ ] Competition name and form/table title appear where required
- [ ] Pages are printable and exportable where requested
- [ ] PDF/Excel exports download cleanly instead of forcing a new window

## 11. Source documents

1. `Initial Planning and Information for System Implementation1.pdf`
2. `Initial Planning and Information for System Implementation2.pdf`
3. `Initial Planning and Information for System Implementation3.pdf`
4. `Initial Planning and Information for System Implementation4.pdf`

The original PDFs contain UI mockups and Persian requirement notes. This file
is the consolidated technical specification derived from those documents.
