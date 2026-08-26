---
name: laravel-cloud-cost
description: Break a Laravel Cloud bill down by application and hunt for waste. Use when the user asks where their Laravel Cloud spend is going, wants to reconcile a Cloud invoice, asks which app or environment costs the most, mentions a cost spike or a surprising bill, or asks how to reduce Laravel Cloud costs. Reads through the `cloud` CLI and changes nothing.
---

# Laravel Cloud cost

Turns one Laravel Cloud billing period into a per-application cost breakdown and a list of findings
worth a human's attention. Everything comes from the `cloud` CLI, so there is no invoice PDF to
copy out of an email and no dashboard to click through.

**This skill only reads.** It never provisions, resizes, or deletes anything. When a finding suggests
a change, hand the change to the user or to `laravel-cloud-deploy`; do not make it here.

## Prerequisites

The `cloud` CLI, installed and authenticated:

```bash
cloud --version   # if this fails: composer global require laravel/cloud-cli
cloud auth        # if the report says "not authenticated"
```

Nothing else. No API token to fetch, no package to install, no dashboard access.

## 1. Pick the organization

The CLI resolves the organization from the `.cloud/config.json` of the directory it runs in. If the
user has authenticated more than one organization, every command fails with "Multiple API tokens
found" until you tell it which one.

Ask the user which organization they mean, then pass a directory whose `.cloud/config.json` selects
it:

```bash
php scripts/cloud-cost.php --dir=/path/to/a/repo/in/that/org
```

With a single organization authenticated, `--dir` is unnecessary. Do not go looking for the token
yourself: the CLI is the credential boundary, and reading around it is how credentials end up
somewhere they should not be.

## 2. Run the report

```bash
php scripts/cloud-cost.php --period=previous --dir=<path>
```

`--period` takes `current`, `previous`, `1`, `2`, or `3`. Four periods are available and they follow
the **billing cycle, not the calendar month**: a period runs from a day like the 24th to the 23rd of
the next month. Reconciling an invoice means `--period=previous` (or the matching index), because
`current` is a period still in progress and its figures will keep rising.

Add `--json` when you want to compute over the result rather than read it. The JSON is one object on
stdout, documented in [OUTPUT.md](OUTPUT.md).

Useful flags:

| Flag | What it buys | What it costs |
| --- | --- | --- |
| `--environments` | per-environment cost and CPU hours inside each application | one API call per environment: roughly a minute for twenty |
| `--map=FILE` | pins a resource to an application by hand | nothing; see step 3 |
| `--no-trend` | skips the prior-period fetches | loses the findings that need a shape over time |
| `--no-findings` | cost only | loses section 4 |

## 3. Read the attribution honestly

Every line in the report carries a confidence, and the difference between them is the single most
important thing to convey to the user:

- **linked**: compute cost, joined to the application on a real identifier. Trust it.
- **inferred**: an attached resource (database, cache, bucket, websocket cluster) matched to an
  application **by name**. The API exposes no link from a resource back to the application that uses
  it, so this is a naming-convention guess. It is usually right and it is never certain.
- **override**: the user pinned it in a `--map` file. As trustworthy as they are.
- **unattributed**: nothing matched.

Roughly two thirds of a typical Laravel Cloud bill is attached resources, so most of the money in
this report is `inferred`. **Say so when you present a per-app total.** Never round the distinction
away into one confident number, and never present an inferred figure to a third party (a client, a
finance team) without the caveat attached. [ATTRIBUTION.md](ATTRIBUTION.md) explains the matching
rules and how to correct them.

When the user disputes an attribution, or the report flags one as ambiguous, fix it permanently with
a map file rather than by explaining the discrepancy each time:

```json
{ "main": "beacon", "legacy_cache": "oldsite.example" }
```

Keys are resource names as the report prints them; values are application names, slugs, or ids. Keep
the file next to the project it describes and pass it with `--map`.

## 4. Work the findings

Findings are observations with evidence attached, ordered by severity. Each one names what was seen,
what it cost, and what to check. They are **not** instructions: whether a cost is wrong depends on
what the application is for, and the script does not know that.

Present the findings in the user's terms. For each one worth raising:

1. Say what it costs per period, in money.
2. Say what the evidence actually shows, not what it implies.
3. Give them the check to run. Where the check is something you can do (read the repo's scheduler,
   look for a health check that hits the database, find out whether broadcasting is still wired up),
   offer to do it.

Two findings deserve extra care because they are the ones most likely to be wrong:

- **`serverless-database-never-idles`** compares the same database across billing periods. A flat
  cost every period means the compute never scaled down, which is real; *why* it never scaled down
  is not in this data. Look for a scheduler running every minute, a health check that queries, a
  persistent connection pool, or a scale-to-zero delay longer than the gap between requests. Confirm
  in the dashboard's database metrics before recommending a change.
- **`unattributed-resource`** is often the most valuable line in the report, because a resource
  nobody can name is a resource nobody is watching. Resist the urge to guess an owner: find out.

## 5. Go deeper only when it earns its place

Per-environment detail (`--environments`) answers "which environment of this app costs the money" and
gives CPU hours per instance, which is how you separate an environment that ran all period from one
that woke up occasionally. It costs one call per environment. Run it for one application under
investigation rather than across the whole organization.

Environment logs (`cloud env:logs <app> <env> --hours=24`) can tell you what is *touching* an
expensive resource, which is how a "never idles" finding turns into a named cause. Logs are not
linked to cost by any API, so this is reading, not reporting.

## What this cannot do

Say these plainly rather than working around them:

- **No per-day or per-hour cost.** Billing-period granularity is all the API has. Four periods of
  history, no time series inside one.
- **No resource-to-application link.** Covered above. This is the reason most of the bill is
  `inferred`.
- **No bandwidth breakdown.** Bandwidth is a single organization-level figure against an allowance.
  It cannot be attributed to an application at all.
- **No database or cache metrics through the CLI.** Connection counts, query volume, and memory use
  live in the dashboard. A finding can tell the user which database to look at; it cannot tell them
  what the database was doing.
- **The figures lag.** `lastUpdatedAt` in the report is typically thirty to sixty minutes behind.
  Quote it whenever you quote a number, and never call the total "live".
