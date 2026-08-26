# How a cost line gets attributed to an application

## The shape of the problem

A Laravel Cloud bill has two halves that behave completely differently.

**Compute** arrives as `applications[]`, a list of `{identifier, totalCostCents}` where the
identifier is a real `app-` id. Joining it to `cloud application:list` is exact. Per-environment
compute is exact too, and the parts add up: an application's compute cost equals the sum of its
environments'.

**Attached resources** (databases, caches, buckets, websocket clusters) arrive as organization-wide
lists carrying a name, an identifier, and a cost. They carry **no application id and no environment
id**, in either direction:

- `cloud cache:list` has an `environmentIds` field that comes back empty.
- `cloud bucket:list` has no environment field at all.
- `cloud environment:get` returns `databaseSchemaId`, `cacheId`, and `websocketApplicationId`, and
  all three come back null.

So the link genuinely is not in the API. Everything below is a workaround for that.

## The matching rules

Laravel Cloud names an auto-provisioned resource after the application, sometimes with the
environment appended. The report exploits that convention:

1. **Normalise** both sides: lowercase, drop everything that is not a letter or a digit.
   `phpscore.com`, `phpscore_com`, and `PHPScore-COM` all become `phpscorecom`.
2. **Build the index** from every application, using its slug and its name, each one both intact and
   with a known TLD stripped (`ourcves.com` also indexes as `ourcves`), and each of those again with
   every environment name and slug appended (`ballast` also indexes as `ballast_production`).
3. **Match** a resource name against the index: exact key first, then the longest key the resource
   name starts with, so `phpscore_com_production` prefers the `phpscore_com_production` key over the
   shorter `phpscore` one.
4. Keys shorter than four characters are dropped, because they collide.
5. A key matching **more than one** application is not a match. The resource is reported as
   unattributed and flagged as ambiguous, naming the candidates.

A match produces `inferred`, never `linked`. There is no identifier behind it.

## Correcting it

A `--map` file overrides the matcher and produces `override`:

```json
{
  "main": "hone",
  "legacy_cache": "artisan-tv.com",
  "shared_db": "clients.artisan.build"
}
```

Keys are resource names exactly as the report prints them, normalised the same way, so case and
punctuation do not matter. Values are an application name, slug, or `app-` id; an unknown value is
an error rather than a silent miss, so a typo cannot quietly move money to the wrong application.

Keep the map file in the repo it describes and pass it on every run. It is the right answer whenever
a resource genuinely serves an application whose name it does not share, or serves several.

## What the residue means

Two things end up unattributed, and they mean opposite things.

**A resource with cost that matches nothing** is the finding this whole exercise exists to surface.
Every organization has one: a cache named `main` from before there was a naming convention, a
database left behind by a project that was deleted. It bills every month and nobody sees it, because
nobody can say which application it belongs to.

**A resource with no cost that matches nothing** is clutter. Confirm nothing points at it and delete
it so it stops appearing.

## Coverage in practice

On a live organization of twenty-two applications, the rules above attributed 97.9% of resource
spend. The residue was one cache from before the convention existed and one zero-cost orphan: both
of them real findings rather than matcher failures. Expect a similar shape, and expect it to be worse
in an organization whose resources were renamed by hand.

## If Laravel Cloud fixes this

`environmentIds` already exists on the cache resource and comes back empty, which looks like an
unpopulated field rather than a missing concept. If it starts returning data, resource attribution
becomes `linked` and this document becomes a fallback. The report is written so that adding a real
join means adding a branch, not rewriting the matcher.
