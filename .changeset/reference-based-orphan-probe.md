---
"artisan-build-skills": minor
---

laravel-cloud-cost: a new `scripts/cloud-orphans.php` decides which Laravel Cloud resources nothing
is using, by proving references instead of matching names.

The cost report matches a resource to an application by name, because the billing data carries no
application id. That is fine for splitting a bill and misleading for finding an orphan: a database
still named after the application that migrated off it reads as owned, and a cache named from before
there was a convention reads as abandoned while production depends on it. On a fifteen-application
organization, name matching called $50 of $51 of "unattributed" spend ownerless when all of it was in
use, and missed a $45-per-period cluster that nothing referenced.

The probe walks every environment with `cloud environment:get` — the only route that returns a
populated `databaseSchemaId`, `cacheId`, and `websocketApplicationId` — and classifies every
database cluster, cache, bucket, and websocket cluster as `in-use`, `probable-orphan`,
`unreferenced-but-active`, `possibly-superseded`, or `unproven`. A cluster is a candidate only when
no schema in it is referenced. Each candidate carries what it costs per period, joined from the cost
report, and resources the cost report attributed to the wrong application are listed separately with
a ready-to-use `--map` object.

It costs one call per environment, so it runs serially with a pause and backs off on a 429, and it
prints the call budget before spending it. Read-only, like the rest of the skill. Environment
variables are read as key names only, through `--fields=environmentVariables.key`, so no value is
ever fetched.

Also corrected two things the skill claimed that are not true of CLI v0.6.3: database and cache
metrics do exist (`database-cluster:metrics`, `cache:metrics`, windows `6h` to `30d`), and
`environment:get` does return the three attachment ids rather than null. The docs now name which
metric fields are usable and which come back empty.

The resource lists can return two entries carrying the same id under different names. That is one
resource with two records, so the probe decides references, schemas and the verdict once per id and
bills the cost once, pointing the duplicate entry at the one holding the money with `sameIdAs`. It
also reconciles the cost it attaches to verdicts against the resource total the cost report
published, and reports a sum that exceeds it as an error rather than printing a total that is too
high.
