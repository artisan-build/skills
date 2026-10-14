# How a cost line gets attributed to an application

## The shape of the problem

A Laravel Cloud bill has two halves that behave completely differently.

**Compute** arrives as `applications[]`, a list of `{identifier, totalCostCents}` where the
identifier is a real `app-` id. Joining it to `cloud application:list` is exact. Per-environment
compute is exact too, and the parts add up: an application's compute cost equals the sum of its
environments'.

**Attached resources** (databases, caches, buckets, websocket clusters) arrive as organization-wide
lists carrying a name, an identifier, and a cost. They carry **no application id and no environment
id** anywhere in the billing data, and the relation fields on the resource lists are not a way
around it:

- `cloud cache:list` has an `environmentIds` field that comes back empty for caches that are
  provably in use.
- `cloud websocket-cluster:list` has `applicationIds`, and `cloud bucket:list` has `keyIds`. Same
  story.
- `cloud application:list` exposes `environments[].databaseSchemaId`, which comes back null even
  when the environment has a database attached.

Reading "nothing uses this" off any of those fields is how you delete a production database. They
are unpopulated, not empty.

The link does exist in exactly one place: **`cloud environment:get <env-id>` returns a populated
`databaseSchemaId`, `cacheId`, and `websocketApplicationId`.** One environment at a time, one call
each. That is what `cloud-orphans.php` walks, and [why reference beats name](#why-a-reference-beats-a-name)
is the rest of this document.

The cost report does not walk it, because a per-environment fan-out costs a call per environment and
a bill breakdown should be one call. So the matching rules below are what the cost report uses, and
everything they produce is labelled `inferred`.

## The matching rules

Laravel Cloud names an auto-provisioned resource after the application, sometimes with the
environment appended. The report exploits that convention:

1. **Normalise** both sides: lowercase, drop everything that is not a letter or a digit.
   `acme-crm.com`, `acme_crm`, and `ACME-CRM` all become `acmecrm`.
2. **Build the index** from every application, using its slug and its name, each one both intact and
   with a known TLD stripped (`tally.app` also indexes as `tally`), and each of those again with
   every environment name and slug appended (`widgets` also indexes as `widgets_production`).
3. **Match** a resource name against the index: exact key first, then the longest key the resource
   name starts with, so `acme_crm_production` prefers the `acme_crm_production` key over the
   shorter `acmecrm` one.
4. Keys shorter than four characters are dropped, because they collide.
5. A key matching **more than one** application is not a match. The resource is reported as
   unattributed and flagged as ambiguous, naming the candidates.

A match produces `inferred`, never `linked`. There is no identifier behind it.

## Correcting it

A `--map` file overrides the matcher and produces `override`:

```json
{
  "main": "beacon",
  "legacy_cache": "oldsite.example",
  "shared_db": "portal.example"
}
```

Keys are resource names exactly as the report prints them, normalised the same way, so case and
punctuation do not matter. Values are an application name, slug, or `app-` id; an unknown value is
an error rather than a silent miss, so a typo cannot quietly move money to the wrong application.

Keep the map file in the repo it describes and pass it on every run. It is the right answer whenever
a resource genuinely serves an application whose name it does not share, or serves several.

## Why a reference beats a name

Name matching answers "which application is this probably for?". It cannot answer "is anything using
this?", and the difference is not academic. The two questions fail in opposite directions:

| | name says | reference says |
| --- | --- | --- |
| a database the app migrated off, still named after it | owned by that app, healthy | nothing points at it |
| a cache called `main` from before the convention | unattributed, suspicious | production depends on it |

So a name-matched report systematically hides the orphans that cost the most, the ones named after
something real, and systematically accuses the resources whose names are merely untidy. Measured on
one fifteen-application organization: of $51 per period that name matching reported as
unattributed, $50 was in use by applications whose names did not match, and the single largest real
orphan ($45 per period) was never flagged at all because its name matched a live application.

`cloud-orphans.php` therefore classifies on the reference and uses the name only as a hint printed
next to it. The vocabulary is deliberately not "orphan" and "not orphan":

- **`in-use`**: an environment names it. This is the only positive proof available.
- **`probable-orphan`**: nothing names it, and it shows no activity.
- **`unreferenced-but-active`**: nothing names it, but it is doing work.
- **`possibly-superseded`**: named, but every environment naming it overrides that wiring by hand.
- **`unproven`**: a bucket. No attachment field exists in either direction, so neither answer is
  available and the name is the only evidence there is.

A cluster is only a candidate when **no schema in it** is referenced. Clusters hold several schemas,
and a cluster with one dead schema and three live ones is a live cluster.

## The resource lists can report one resource twice

`database-cluster:list` can return two entries carrying **the same id** under different names, for
example `widgets` and `widgets_staging` both at `plain-field-12345678`. It is one cluster with two
records, and it is a trap in three directions at once:

- The billed line is keyed by id, so believing both entries counts the same money twice.
- The entries can list different schemas, so one entry looks referenced and the other looks
  abandoned, and a probe reporting per entry contradicts itself about a single cluster.
- The organization looks like it holds more resources than it does.

The identity that matters is the **id**, never the name. So the probe decides references, schemas and
the verdict once per id across every entry that shares it, bills the cost to one entry, and points
the others at it with `sameIdAs`. It does not split the cost between them: the invoice has one line,
and a division invented here would be a second guess stacked on the first.

This is also why the probe reconciles. The resource cost it attaches to verdicts is compared to the
resource total the cost report published, and a sum that comes out **larger** than the published
total is reported as an error rather than printed as a total. A duplicate id was how that could
happen, and a reconciliation is how it stops being silent.

## Manual wiring beats both

An environment that sets `DB_HOST`, `DB_CONNECTION`, `REDIS_HOST` or `DATABASE_URL` itself talks to
whatever those say. Laravel Cloud injects an attached resource's own configuration separately from
the variables set on the environment, so a variable set by hand wins, and the attachment stops
describing reality.

That single fact produces both of the middle verdicts above:

- An attached resource on a hand-wired environment may be a **leftover**: the app moved and the
  attachment stayed. That is `possibly-superseded`.
- A resource nothing is attached to may be **load-bearing**, reached through those same variables.
  That is `unreferenced-but-active`, and activity is how it is caught: compute-hours on a cluster
  nothing references mean something is connecting to it.

The probe reports the **key names** it found and stops there. It asks the CLI for
`environmentVariables.key`, so no value is ever fetched, and a key name is not a secret. Resolving
where a hand-wired app actually points is a job for whoever can read the values: do not reach for
`--show-sensitive`, and do not request `connection` fields on a cluster or cache to find out.

## What the residue means

Two things end up unattributed, and they mean opposite things.

**A resource with cost that matches nothing** is a resource nobody can name, which is worth knowing
and is not the same as a resource nobody uses. Run the probe before you treat it as either: in
practice most of this section turns out to be in use by an application whose name does not match,
and the map file it emits moves that money onto the right application for good.

**A resource with no cost that matches nothing** is clutter. "Confirm nothing points at it" is
exactly what the probe does, and the fields you would otherwise check by hand are the unpopulated
ones at the top of this document.

## Coverage in practice

On a live organization of twenty-two applications, the rules above attributed 97.9% of resource
spend. The residue was one cache from before the convention existed and one zero-cost orphan: both
of them real findings rather than matcher failures. Expect a similar shape, and expect it to be worse
in an organization whose resources were renamed by hand.

## If Laravel Cloud fixes this

`environmentIds` already exists on the cache resource and comes back empty, which looks like an
unpopulated field rather than a missing concept. If it starts returning data, resource attribution
becomes `linked` in one call instead of one per environment, and the matcher becomes a fallback. The
report is written so that adding a real join means adding a branch, not rewriting the matcher.

Buckets would need a new field rather than a populated one, so `unproven` is likely to stay
`unproven` for longer than the rest.
