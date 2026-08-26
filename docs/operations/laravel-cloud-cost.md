# laravel-cloud-cost

## What it does

Takes one Laravel Cloud billing period and turns it into a per-application cost breakdown, then
flags the lines worth a second look. It reads everything through the `cloud` CLI, so the only
prerequisite is that CLI, installed and authenticated. There is no invoice PDF to paste in, no
dashboard to click through, and no API token to go and fetch.

The report has three parts. A **breakdown** puts every cost line under an application: compute,
databases, caches, buckets, websocket clusters. A **reconciliation** adds the parts up and checks
them against the total Laravel Cloud published, so a figure that does not add up is visible rather
than quietly absorbed. And a set of **findings** names the things that usually turn out to be waste:
a serverless database billed a flat amount every month, a cache costing ten times the application it
serves, a websocket cluster left up after the feature came out, a resource nobody can put a name to.

The arithmetic is done by a script, not by the model. Two runs over the same period produce the same
numbers, which is the one thing a cost report has to do. What the model brings is the part a script
cannot: deciding which findings matter to you, and going and reading the code to work out *why* a
database never sleeps.

## When to reach for it

| Situation | What you get |
| --- | --- |
| The Laravel Cloud invoice arrived and you want it explained | The same period, broken down per application, reconciled against the invoice total |
| The bill jumped and nobody knows why | Four periods of history, and a finding for any resource whose cost is flat, duplicated, or unattributable |
| You want to know what one client's app actually costs | That application's compute plus every resource attributed to it, with each line's confidence shown |
| A project was cancelled and you want to be sure it stopped billing | The unattributed section, which is where a deleted project's leftovers show up |
| You are deciding whether an app can move to a smaller tier | Per-environment CPU hours, which separate an app that ran all period from one that woke up occasionally |

Do not reach for it to *change* anything. It reads. When a finding points at a resource that should
be resized or removed, that is a decision for you, and the change itself belongs in the Laravel Cloud
dashboard or in a deployment skill.

## Common questions

**Does it need my Laravel Cloud API token?**
No. It shells out to the `cloud` CLI, which already holds your credentials. The token is never read,
printed, or written anywhere, and the report deliberately asks the CLI for a narrowed set of fields
so that credential-bearing values (build commands, connection strings) are never fetched in the first
place.

**Can it tell me what each app cost me last month exactly?**
For compute, yes: that comes back joined to a real application id. For attached resources, which are
usually the larger half of the bill, no API field links a database or cache to the application that
uses it, so the report matches them by name and labels every one of those lines `inferred`. On a
real organization the naming convention covers about 98% of resource spend, but "about 98%" is not
"exactly", and the report never pretends otherwise. If a resource is attributed wrongly, you can pin
it by hand in a small JSON map file and it stays pinned.

**Why is the period not a calendar month?**
Laravel Cloud bills on a cycle, so a period might run from the 24th to the 23rd. The report labels
every figure with the period it covers. Use `--period=previous` to reconcile against an invoice: the
current period is still in progress and its numbers will keep rising.

**How far back can it go?**
Four periods, which is what the API exposes. There is no per-day or per-hour cost at all, in any
period.

**What does "this database never idles" actually mean?**
It means that database's cost varied by less than ten percent across the periods compared. Serverless
compute that bills the same every period is compute that never scaled down. What is keeping it awake
is not in the billing data: usually a scheduler running every minute, a health check that queries, or
a connection pool that never closes. The finding tells you which database to look at and what to look
for; confirming it means reading the app or the dashboard's database metrics.

**Can it look at metrics or logs?**
Not for cost. The CLI exposes environment logs, which can help identify what is touching an expensive
resource, but no API links a log line to a dollar. Database connection counts and memory use live in
the dashboard only.

**How current are the figures?**
Typically thirty to sixty minutes behind. The report prints the snapshot time and quotes it alongside
the total, so nobody reads it as live.

## It's working if

- The total the report prints matches the total on your Laravel Cloud invoice for the same period,
  to the cent.
- Every application in your organization appears, including the ones that cost nothing.
- Each resource line says `linked`, `inferred`, or `override`, and you can see at a glance which
  parts of the number are certain.
- Anything it could not attribute is listed on its own rather than folded into an application's
  total, and you recognise at least one of them as something you had forgotten about.
- Running it twice over the same period gives you identical numbers.
