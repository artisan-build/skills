---
name: laravel-cloud-cost
description: Break a Laravel Cloud bill down by application, hunt for waste, and prove which resources nothing is using. Use when the user asks where their Laravel Cloud spend is going, wants to reconcile a Cloud invoice, asks which app or environment costs the most, mentions a cost spike or a surprising bill, asks how to reduce Laravel Cloud costs, or asks whether a database, cache, or bucket is still in use, orphaned, or safe to delete. Reads through the `cloud` CLI and changes nothing.
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

An `inferred` line can be turned into evidence rather than a guess: step 5 proves ownership one
environment at a time and emits a `--map` file that pins what it proves. Do that before anyone bills
a client from these figures.

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
  nobody can name is a resource nobody is watching. Resist the urge to guess an owner, and do not
  read it as "unused" either: step 5 settles both questions with evidence instead of a name, and it
  usually finds that most of this section is in use by an application whose name does not match.

## 5. Prove an orphan before you call it one

Everything in step 4 that touches ownership rests on a name, and a name is the wrong evidence for
the question "is anything using this?". The two failure modes are symmetrical, and both are
expensive:

- A resource whose name matches a live application looks owned, whether or not anything points at
  it. **This is where real orphans hide.** A database still named after the application that moved
  off it bills every period and reads as healthy.
- A resource named after something else reads as abandoned while it serves production. The
  `unattributed` section fills up with resources that are perfectly in use.

On one fifteen-application organization, name matching called $50 of $51 of "unattributed" spend
ownerless when every one of those resources was in use, and missed a $45-per-period cluster that
nothing referenced at all, because its name still matched a live app.

The probe answers the question with references instead:

```bash
php scripts/cloud-orphans.php --period=previous --dir=<path>
```

It walks every environment with `cloud environment:get` and reads `databaseSchemaId`, `cacheId`,
and `websocketApplicationId`. That is the only route that returns those populated. **The
list-level relation fields lie:** `cache:list.environmentIds`,
`application:list environments[].databaseSchemaId`, `websocket-cluster:list.applicationIds`, and
`bucket:list.keyIds` all come back empty or null for resources that are provably in use. Never read
"unused" off any of them.

It costs **one call per environment**, plus one per database cluster and cache for activity, plus
six. Twenty environments is about fifty calls and a few minutes. The script prints the budget before
it spends it. Four concurrent calls earn HTTP 429s, so it runs serially with a pause and backs off
when it is throttled — do not try to parallelise it.

### Read the verdict, not the headline

| Verdict | What it means | What to do |
| --- | --- | --- |
| `in-use` | an environment references it | nothing |
| `probable-orphan` | nothing references it, and it shows no activity | the real candidate; check outside Cloud before removing anything |
| `unreferenced-but-active` | nothing references it, but it is doing work | almost always wired by hand. Find the environment variables before touching it |
| `possibly-superseded` | referenced, but every environment that references it overrides that wiring by hand | the attachment is probably a leftover from a migration. The app is talking to something else |
| `unproven` | a bucket | no attachment field exists; the name is all there is |

The two middle verdicts are both consequences of the same thing: **a manually wired environment
beats the attachment.** An environment that sets `DB_HOST` or `REDIS_HOST` itself talks to whatever
that says, not to what Cloud attached. So a referenced resource can be dead, and an unreferenced
resource can be load-bearing. The probe reports the **key names** it saw and stops there: it asks the
CLI for `environmentVariables.key`, so a value is never fetched. Never pass `--show-sensitive`, and
never ask for `connection` fields on a cluster or cache to "check" what an app points at.

### Activity signals that are worth reading

`database-cluster:metrics <id> --period=30d` and `cache:metrics <id> --period=30d` both exist
(windows: `6h`, `24h`, `3d`, `7d`, `30d`). What they are good for:

| Field | Usable? |
| --- | --- |
| `computeHours.total` | yes, for serverless Postgres: the probe's activity signal |
| `cpuUsage.average`, `storageUsage.current` | yes, as corroboration |
| cache `bandwidthUsage.total` | sometimes; it reads zero on some cache types |
| `writes.total` | **no** — returns 0 for every cluster |
| cache `hitsAndMisses`, `throughput` | **no** — totals come back null |

Compute-hours are zero for *every* MySQL cluster, including ones several environments point at, so
the probe works out per resource type whether the signal can tell activity from silence and says
`unknown` rather than `idle` when it cannot. A `probable-orphan` with `activity: unknown` rests on
the references alone. Say that when you report it.

### Misattribution is a finding too

A resource the cost report put on the wrong application is not an orphan, but it is wrong money. The
probe lists those separately and emits a ready-to-use `--map` object. Hand it to the user, or write
it to a file and pass it to the cost report, and the next run is right:

```bash
php scripts/cloud-orphans.php --period=previous --json --dir=<path> > orphans.json
# take .mapSuggestion from that file into map.json, then:
php scripts/cloud-cost.php --period=previous --map=map.json --dir=<path>
```

A cluster whose schemas are referenced by **two** applications is shared, and no map entry can split
it. The probe says so and emits no suggestion for it.

### What a verdict still does not know

This probe sees Laravel Cloud and nothing else. A `probable-orphan` can still have a live consumer:
a local script, a data pipeline, an extractor job, a developer's machine. Confirm there is nothing
outside Cloud holding a connection before anyone deletes anything. The probe narrows a bill to the
few resources worth asking about; it does not authorise a deletion, and neither does this skill.

## 6. Go deeper only when it earns its place

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
- **No resource-to-application link in the cost data.** The billing payload carries no application
  id for an attached resource, which is why most of the bill is `inferred`. The link does exist
  elsewhere, one environment at a time, and step 5 goes and gets it — but it costs a call per
  environment, so the cost report does not.
- **No bandwidth breakdown.** Bandwidth is a single organization-level figure against an allowance.
  It cannot be attributed to an application at all.
- **No way to attach a bucket to anything.** Databases, caches, and websocket clusters can be proven
  in use. Buckets cannot: no field in the CLI connects a bucket to an environment in either
  direction. A bucket's name is the only evidence that exists.
- **Metrics exist, but only some of them are usable.** See step 5. Connection counts and query
  volume are still dashboard-only.
- **The figures lag.** `lastUpdatedAt` in the report is typically thirty to sixty minutes behind.
  Quote it whenever you quote a number, and never call the total "live".
