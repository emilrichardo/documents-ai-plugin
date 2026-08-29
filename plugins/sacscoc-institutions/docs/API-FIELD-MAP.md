# SACSCOC API → local field map

What the API returns, where each field is stored, and what the existing
directory does with it. Read against `includes/fields.php`, which is where the
mapping actually lives — this document describes it, the code applies it.

Measured against the full dataset on 28 August 2026: **1,201 institutions**,
1.7 MB, one request, ~2.5 s.

## The API

Base URL is configurable (Institutions → Settings → API Base URL); it is not
written into the plugin's logic anywhere. Default `https://api.sacscoc.org`.

| Endpoint | Returns |
| --- | --- |
| `GET /api/v1/search?name=&state=&degree=&next_reaffirm_date=` | The whole directory in one response. All four parameters accept an empty value meaning "no filter". |
| `GET /api/v1/institution?sf_institution_id=…` | One institution. Same fields as the search endpoint. |
| `GET /api/v1/sites?sf_institution_id=…` | Off-campus instructional sites for one institution. |
| `GET /api/v1/recentmeetings?sf_institution_id=…` | Completed reviews / meetings. |
| `GET /api/v1/inprogressmeetings?sf_institution_id=…` | Reviews currently under way. |

Every response is wrapped as `{"results": [ … ]}`. `results` is `null`, not
`[]`, when an endpoint has nothing to return. No endpoint requires
authentication. There is no pagination and no total count.

Things worth knowing before relying on this API:

- **There is no incremental endpoint.** Nothing accepts a "changed since"
  parameter, and `updated_at` cannot substitute for one: the API rewrites it on
  every record on every refresh, so the whole dataset shares a single minute of
  `updated_at` values a few seconds apart. The sync therefore downloads
  everything and compares locally against a stored `content_hash`.
- **`results` is flat.** No nested objects, no arrays — every institution is 43
  scalar fields. Related data lives behind the per-institution endpoints above.
- **Every record carries all 43 keys.** Not one record in the dataset omits a
  key; absence is expressed as `null`, never as a missing key.
- **Filter parameter values.** `degree` takes `associate`, `baccalaureate`,
  `master`, `education_specialist`, `doctorate`. `state` takes a two-letter
  code, plus the special value `INTL` — which is not a state at all but
  everything whose `address_country` is not the United States (38 records
  across 20 countries). `next_reaffirm_date` takes a four-digit year.

## The `degree` filter, and a bug in it

`degree` means *highest* degree offered, not "offers this degree". `associate`
returns institutions whose highest degree is an associate's — not the 766 that
offer one.

Reproducing it locally means deriving the highest of the five `deg_*` flags:

| `degree` value | Highest flag set | Institutions | API returns |
| --- | --- | --- | --- |
| `associate` | `associate` | 365 | **357** |
| `baccalaureate` | `baccalaureate` | 181 | **176** |
| `master` | `master` | 181 | 181 |
| `education_specialist` | `education_specialist` | 19 | 19 |
| `doctorate` | `doctorate` | 392 | 392 |
| (none set) | — | 63 | not reachable |

**The API drops 13 institutions from the two lowest tiers.** Every one of the 8
missing from `associate` and the 5 missing from `baccalaureate` has
`master: null` rather than `master: "No"` — so a filter written as
`… AND master = 'No' AND …` excludes them, because in SQL nothing equals NULL.
68 records in the dataset have `master: null`; the 13 with a highest degree of
associate's or baccalaureate are the ones the bug is visible on. Examples:
Kentucky College of Art and Design (Accredited), Heart of Texas Foundation
College of Ministry (Candidate).

Two of the five flags are unreliable this way: `master` is null on 68 records
and `level` is null on 64. The other three degree flags are never null.

When the local directory implements this filter, treat null as "No" — which
returns the 13 institutions the current site loses. Result counts will then
differ slightly from the live site, correctly.

`level` is very nearly the same information as the derived highest degree:
I → associate (365/365), II → baccalaureate (181/181), III → master (181 of
183), IV → education specialist (18/18), V and VI → doctorate (390/390). The
three exceptions are two level-III records that also have `doctorate: "Yes"`
and one null-level record with `education_specialist: "Yes"`. Prefer deriving
from the flags; `level` is what the frontend *displays*, with a tooltip.

## Institution fields

All 43 fields. None are dropped for being unused in the current design; the
ones marked "—" in the last column are simply not displayed today.

"Filled" counts records where the API sent a non-null value, out of 1,201.

| API field | Local column | Column type | Filled | Distinct | Frontend usage |
| --- | --- | --- | --- | --- | --- |
| `sf_id` | `sf_id` | `varchar(20)` | 1201 / 1201 | 1201 | Not displayed. The key the detail, sites and meetings endpoints are queried by, and the key this plugin matches records on. |
| `id` | `api_id` | `bigint unsigned` | 1201 / 1201 | 1201 | — The API's own numeric id. Kept for cross-referencing against the API. |
| `sf_owner_id` | `sf_owner_id` | `varchar(20)` | 1201 / 1201 | 17 | — Salesforce owner of the record. |
| `name` | `name` | `varchar(255)` | 1182 / 1201 | 1134 | Result title and detail heading. The Institution Name search matches on it. |
| `sortable_name` | `sortable_name` | `varchar(255)` | 1201 / 1201 | 1200 | Not displayed. Sort order of the result list. |
| `former_names` | `former_names` | `text` | 571 / 1201 | 570 | Result list "Former Name:" line, and the "Former Name" note under the detail heading. |
| `phone` | `phone` | `varchar(64)` | 1134 / 1201 | 1069 | Detail → General Information → "Institutional Phone". |
| `website` | `website` | `varchar(500)` | 1201 / 1201 | 961 | Result list "View Website" link. |
| `ceo_name` | `ceo_name` | `varchar(255)` | 859 / 1201 | 852 | Detail → General Information → "CEO Name". The row is hidden when empty. |
| `program_list` | `program_list` | `varchar(500)` | 1201 / 1201 | 813 | Detail → "View Available Programs" link. |
| `student_achievement_url` | `student_achievement_url` | `varchar(500)` | 1096 / 1201 | 846 | Detail → "View Student Achievement Data" link — shown only when the status is Accredited or Candidate. |
| `general_disclosure_url` | `general_disclosure_url` | `varchar(500)` | 21 / 1201 | 21 | Detail → the "Accreditation Actions & Disclosure Statements" link beside a public sanction. |
| `associate` | `deg_associate` | `varchar(8)` | 1201 / 1201 | 2 | Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=associate). |
| `baccalaureate` | `deg_baccalaureate` | `varchar(8)` | 1201 / 1201 | 2 | Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=baccalaureate). |
| `master` | `deg_master` | `varchar(8)` | 1133 / 1201 | 2 | Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=master). |
| `education_specialist` | `deg_education_specialist` | `varchar(8)` | 1201 / 1201 | 2 | Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=education_specialist). |
| `doctorate` | `deg_doctorate` | `varchar(8)` | 1201 / 1201 | 2 | Detail → "Approved to Offer" list. Also the Highest Degree Offered filter (degree=doctorate). |
| `accreditation_status` | `accreditation_status` | `varchar(64)` | 1201 / 1201 | 7 | Result list "Status" and detail → "Status". Also gates two blocks: the SACSCOC Staff Member block is hidden for the three "Former …" statuses, and the student achievement link needs Accredited or Candidate. |
| `sort__accreditation_status` | `sort_accreditation_status` | `smallint` | 1201 / 1201 | 6 | — A numeric rank for the status, for ordering. |
| `level` | `level` | `varchar(8)` | 1137 / 1201 | 6 | Result list "Level" and detail → "Degree Level", each with a tooltip naming the highest degree that level offers (I = Associate … VI = Doctorate, 4 or more). |
| `control` | `control` | `varchar(64)` | 1146 / 1201 | 3 | Detail → "Control" (Public / Private, Not-For-Profit / Private, For-Profit). |
| `sanctions` | `sanctions` | `varchar(128)` | 19 / 1201 | 3 | Result list and detail → "Public Sanctions", shown in red. The literal value "No Sanction" means there is none and is treated as empty. |
| `accreditation_history` | `accreditation_history` | `longtext` | 401 / 1201 | 401 | Detail → the collapsed "View Full Accreditation History" table. One free-text block, split into lines for the table. |
| `candidacy_date` | `candidacy_date` | `date` | 369 / 1201 | 74 | Detail → "Candidacy Date", as a full date. |
| `accreditation_date` | `accreditation_date` | `date` | 1055 / 1201 | 126 | Detail → "Accreditation Granted", as a full date. |
| `reaffirmed_date` | `reaffirmed_date` | `date` | 804 / 1201 | 44 | Detail → "Reaffirmation" — the year only. |
| `next_reaffirm_date` | `next_reaffirm_date` | `date` | 783 / 1201 | 23 | Detail → "Next Reaffirmation" — the year only. Also the Next Reaffirmation Year filter, which matches on the year. |
| `fifth_year_date` | `fifth_year_date` | `date` | 789 / 1201 | 28 | Detail → "Next Fifth-Year Review" — the year only. |
| `distance_learning_approved` | `distance_learning_approved` | `date` | 824 / 1201 | 459 | Detail → "Distance Education Approval Date", as a full date. |
| `course_credit_based_approved` | `course_credit_based_approved` | `date` | 23 / 1201 | 17 | Detail → "CBE Course/Credit-based Approved", as a full date. |
| `address_street` | `address_street` | `varchar(255)` | 1119 / 1201 | 1063 | Detail → General Information → the address block. |
| `address_city` | `address_city` | `varchar(128)` | 1195 / 1201 | 579 | Result list "City" and the detail address block. |
| `address_state` | `address_state` | `varchar(64)` | 1158 / 1201 | 16 | Result list "State" and the detail address block. Also the State filter — where the "International" option is not a state at all but everything whose country is not the United States. |
| `address_zip` | `address_zip` | `varchar(32)` | 1131 / 1201 | 997 | Result list "ZIP" and the detail address block. |
| `address_country` | `address_country` | `varchar(128)` | 1201 / 1201 | 21 | Result list "Country" and detail → "Country". What the State filter's "International" option actually keys on. |
| `contact_first_name` | `contact_first_name` | `varchar(128)` | 1201 / 1201 | 17 | Detail → "SACSCOC Staff Member" name. |
| `contact_last_name` | `contact_last_name` | `varchar(128)` | 1201 / 1201 | 17 | Detail → "SACSCOC Staff Member" name. |
| `contact_email` | `contact_email` | `varchar(255)` | 1201 / 1201 | 17 | Detail → "SACSCOC Staff Member" — the Email link (mailto:). |
| `contact_phone` | `contact_phone` | `varchar(64)` | 267 / 1201 | 5 | Detail → "SACSCOC Staff Member" — the phone link (tel:). Absent for most institutions. |
| `delete_flag` | `delete_flag` | `tinyint unsigned` | 1201 / 1201 | 1 | — Soft-delete marker. 0 for every record in the current dataset. |
| `created_at` | `api_created_at` | `datetime` | 1201 / 1201 | 53 | — When the API created the record. |
| `updated_at` | `api_updated_at` | `datetime` | 1201 / 1201 | 26 | — When the API last touched the record. Not usable for change detection: the API rewrites it on every record on every refresh. |
| `deleted_at` | `api_deleted_at` | `datetime` | 0 / 1201 | 0 | — Soft-delete timestamp. Null for every record in the current dataset. |

### Notes on individual fields

- **`sf_id` is the key.** Unique across all 1,201 records, and the id the
  related endpoints are queried by. Matching on `name` instead would be wrong
  twice over: 1,182 non-null names cover only 1,134 distinct values (three
  institutions are called "Bevill State Community College", two "Georgia
  Southern University", and so on), and 19 records have no `name` at all.
- **`name` may be null.** All 19 such records do have a `sortable_name`, which
  is what the plugin falls back to for both the label and the slug.
- **`sanctions` uses a sentinel.** The literal string `"No Sanction"` means
  there is none; the frontend treats it as empty. Only 19 records have any
  value at all.
- **`accreditation_history`** is one free-text block up to ~1,500 characters,
  present on 401 records, which the frontend splits into lines for a table.
- **`level`** is a Roman numeral I–VI with a tooltip naming the highest degree
  that level offers: I Associate, II Baccalaureate, III Master's, IV Education
  Specialist, V Doctorate (up to 3), VI Doctorate (4 or more).
- **`address_state`** is not always a US state: the dataset contains `Dubai`
  and `Limón`, and is null for most international institutions.
- **`delete_flag` / `deleted_at`** are a soft-delete mechanism that is entirely
  unused in the current dataset — `delete_flag` is 0 on all 1,201 records and
  `deleted_at` is null on all of them. They are stored anyway.

### Accreditation status distribution

| Status | Institutions |
| --- | --- |
| Accredited | 777 |
| Former Accredited | 280 |
| Former Applicant | 97 |
| Former Candidate | 22 |
| Inquirer | 11 |
| Applicant | 9 |
| Candidate | 5 |

The 777 accredited are the ~780 the directory is usually described as holding.
The public directory as it stands today returns all 1,201.

## Local columns with no API counterpart

| Column | Purpose |
| --- | --- |
| `id` | Auto-increment surrogate key. |
| `slug` | The institution's public URL segment. Assigned once on first insert and never rewritten, so an upstream rename cannot break links. Collisions get a numeric suffix (`bevill-state-community-college`, `-2`, `-3`). |
| `raw_json` | The untouched API record. Makes a mapping mistake recoverable and a newly-added API field adoptable without re-discovering the response; shown beside the parsed values on each institution's admin screen. |
| `content_hash` | SHA-1 of the record with `created_at` and `updated_at` removed. Equal hash means the row is not written at all. |
| `first_seen` | When the institution first arrived locally. Never revised. |
| `last_seen` | The last sync in which the API returned it, changed or not. |
| `last_synced` | The last time its data was actually written. Unchanged records deliberately keep an older value. |
| `missing_since` | Set when the API stops returning the institution, cleared if it returns. Never a reason to delete the row. |

## Related data (tables created, sync in the next milestone)

### `sacscoc_institution_sites` — off-campus instructional sites

From `/api/v1/sites`. Flat records joined back by `sf_institution_id`.

| API field | Local column | Notes |
| --- | --- | --- |
| `sf_id` | `sf_id` | Stable key, unique per site. |
| `id` | `api_id` | |
| `sf_institution_id` | `sf_institution_id` | Foreign key to the institution's `sf_id`. |
| `name` | `name` | Site name, e.g. "North Atlanta Campus". |
| `status` | `status` | `Open` or `Closed`. The existing frontend states that closed sites are not shown. |
| `type` | `type` | `Approved >= 50%`, `Approved Branch >= 50%`, `Notified 25-49%`. |
| `street`, `city`, `state`, `zip`, `country` | same | Address. |
| `created_at`, `updated_at`, `deleted_at` | `api_*` | |

### `sacscoc_institution_meetings` — reviews and meetings

From `/api/v1/recentmeetings` and `/api/v1/inprogressmeetings`, which return the
same shape. The local `kind` column records which list a record came from,
because the frontend renders them as two separate sections.

| API field | Local column | Notes |
| --- | --- | --- |
| `id` | `api_id` | Unique together with `kind`. |
| `sf_institution_id` | `sf_institution_id` | Foreign key to the institution's `sf_id`. |
| `sf_meeting_id`, `sf_committee_review_id` | same | Often null. |
| `name` | `name` | The event, e.g. "Fifth-Year Interim Report". |
| `description` | `description` | |
| `stage` | `stage` | e.g. "Not Started". |
| `action_date`, `end_date` | same | `end_date` is a bare year in the data seen. |
| — | `kind` | `recent` or `inprogress`. |
| — | `display_year` | What the frontend shows: `end_date`, falling back to the year of `action_date`. |
| `original_data` | **not stored** | See below. |

**`original_data` is deliberately dropped.** Each meeting record carries the
entire raw Salesforce `Committee_Review__c` object as a JSON string — 10–16 KB
of internal fields: hotel amenities, staff evaluation form links, box.com
folder ids, per-committee due dates. None of it is institution data, none of it
is public-facing, and at roughly three meetings per institution it would add
well over 30 MB of Salesforce internals to the database. The meeting's
`raw_json` keeps the record with that one key removed.

## Not in the API

The **About SACSCOC and accreditation** block, the complaints procedure text
and the off-campus site type/status glossary are the same on every institution
page and come from the theme, not the API. They belong in one plugin setting
rather than 1,201 stored copies.
