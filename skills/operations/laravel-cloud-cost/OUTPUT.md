# The `--json` payloads

Two scripts, two schemas: [the cost report](#cloud-costphp) and [the orphan probe](#cloud-orphansphp).
Both emit one object on stdout and nothing else. Money is **integer cents** everywhere; the
`currency` field says what those cents are. Exit code 0 means the object is complete.

## `cloud-cost.php`

```jsonc
{
  "schemaVersion": 1,
  "currency": "USD",
  "lastUpdatedAt": "2026-08-26T13:35:00.000000Z",   // the API's snapshot time, typically 30-60 min old

  "period": {
    "index": 1,                 // 0 = current, 1..3 = prior
    "from": "Jul 24",           // billing cycle, not calendar month
    "to": "Aug 23",
    "complete": true,           // false for index 0, whose figures keep rising
    "hours": 744                // period length, derived from the data; null if underivable
  },

  "totals": {
    "publishedTotalCents": 21450,
    "partsTotalCents": 21450,
    "discrepancyCents": 0,
    "reconciles": true,         // false means the API's own totals disagree: say so, do not pick a side
    "applicationsAddUp": true,
    "resourcesAddUp": true,
    "attributedCents": 21150,
    "unattributedCents": 300
  },

  "breakdown": {
    "applicationsCents": 8200,   // compute, linked
    "resourcesCents": 13250,      // attached resources, inferred
    "addonsCents": 0,
    "bandwidthCents": 0
  },

  "bandwidth": { "costCents": 0, "usagePercentage": 41, "allowanceBytes": 1099511627776 },

  "apps": [                       // sorted by totalCents descending
    {
      "id": "app-...",
      "name": "example.com",      // null when the application no longer exists
      "slug": "example.com",
      "known": true,              // false = billed, but absent from application:list
      "computeCents": 480,
      "resourceCents": 5300,
      "totalCents": 5780,
      "resources": [
        {
          "kind": "database",     // database | cache | bucket | websocket
          "name": "example_com",
          "identifier": "...",
          "type": "Laravel Serverless Postgres 18",
          "totalCents": 1990,
          "confidence": "inferred"   // inferred | override
        }
      ],
      "environments": []          // populated only with --environments
    }
  ],

  "unattributed": [               // same resource shape, plus:
    {
      "kind": "cache",
      "name": "main",
      "totalCents": 300,
      "confidence": "unattributed",
      "ambiguousBetween": []      // non-empty when the name matched several applications
    }
  ],

  "addons": [],

  "findings": [                   // absent with --no-findings; ordered high, medium, info
    {
      "id": "serverless-database-never-idles",
      "severity": "high",
      "title": "Serverless database billed a flat amount every period",
      "subject": "database 'example_com'",
      "periodCents": 1990,
      "evidence": "Jul 24-Aug 23: $19.90, Jun 24-Jul 23: $19.34, ...",
      "check": "Find what keeps it awake: ..."
    }
  ]
}
```

## Finding ids

| id | Fires when |
| --- | --- |
| `unattributed-resource` | a resource with cost matches no application |
| `orphan-resource` | a resource with no cost matches no application |
| `ambiguous-attribution` | a resource name matches more than one application |
| `billed-unknown-application` | compute billed against an `app-` id that no longer exists |
| `resource-outweighs-application` | a resource costs more than twice the compute of the app it serves |
| `duplicate-resource-family` | two databases, or two caches, attributed to one application |
| `serverless-database-never-idles` | a serverless database's cost varies under 10% across periods |
| `websocket-cluster-billed-full-period` | a websocket cluster billed for the whole period |
| `bucket-storage-without-requests` | a bucket holds data that was not read this period |
| `bandwidth-allowance-nearly-spent` | 80% or more of the bandwidth allowance is used |
| `non-production-environment-cost` | a non-production environment carries real cost (`--environments`) |
| `instance-runs-continuously-without-hibernation` | an environment ran all period with hibernation off (`--environments`) |

`periodCents` is what the subject cost in the period being reported, except for
`serverless-database-never-idles`, where it is the mean across the periods compared.

## `cloud-orphans.php`

The probe's object. Every resource in the organization appears exactly once, with the evidence behind
its verdict. The call budget is printed to **stderr** in `--json` mode, so stdout stays one object.

```jsonc
{
  "schemaVersion": 1,
  "generatedAt": "2026-10-14T21:12:03Z",
  "currency": "USD",
  "lastUpdatedAt": "2026-10-14T13:35:00.000000Z",  // from the cost report; null with --no-cost
  "period": {                                       // the cost report's period block, or null
    "index": 1, "from": "Sep 10", "to": "Oct 9", "complete": true, "hours": 720
  },
  "metricsWindow": "30d",                           // "not fetched" with --no-metrics
  "activeHoursThreshold": 1,                        // --active-hours

  "callBudget": {
    "planned": 53,          // what it expected to spend
    "made": 53,             // what it actually spent, retries included
    "rateLimited": 0,       // calls that came back 429
    "retried": 0            // calls retried after a backoff
  },

  "inventory": {
    "applications": 4, "environments": 6, "databaseClusters": 4,
    "databaseSchemas": 5, "caches": 2, "buckets": 2, "websocketClusters": 0,
    "listedEntries": 8,        // rows the CLI returned
    "distinctResources": 7,    // resources that actually exist
    "duplicateIdGroups": 1     // resources listed more than once; see below
  },

  "verdictCounts": {           // one per DISTINCT resource, so these sum to 7
    "in-use": 3, "possibly-superseded": 1, "unreferenced-but-active": 0,
    "probable-orphan": 1, "unproven": 2
  },

  "resources": [                 // sorted: probable-orphan first, then by cost descending
    {
      "kind": "database-cluster",   // database-cluster | cache | bucket | websocket-cluster
      "id": "plain-field-12345678",
      "name": "widgets",
      "type": "laravel_mysql",
      "status": "available",
      "createdAt": "2026-02-01T00:00:00Z",
      "verdict": "probable-orphan",

      "referencedBy": [],          // environments that name it: the proof of use
      "schemas": [                 // database clusters only. A cluster is a candidate
        {                          // only when NO schema in it is referenced.
          "id": "40606701",
          "name": "main",
          "referencedBy": []       // ["widgets.example / production", ...]
        }
      ],

      "manualWiringEnvs": [],      // referencing environments that override this kind of
                                   // wiring by hand: [{environment, keys: ["DB_HOST"]}]
                                   // KEY NAMES ONLY. No value is ever fetched.

      "activity": {
        "signal": "compute-hours", // compute-hours | bandwidth | none
        "value": 0,
        "unit": "hours",
        "window": "30d",
        "state": "unknown",        // active | idle | unknown
        "note": "every laravel_mysql resource in this organization reports no compute-hours, including ones an environment proves are in use, so this signal cannot tell activity from silence for this type. The verdict rests on the references alone."
      },
      "metrics": {                 // null with --no-metrics or when the call failed
        "computeHoursTotal": 0,
        "cpuUsageAverage": 0.004,
        "storageUsageCurrentBytes": 2147483648
      },

      "cost": {                    // null when the cost report found no billed line for it,
        "totalCents": 1250,        // and null on the second entry of a duplicated id
        "attributedTo": "widgets.example",   // what the COST REPORT decided, by name
        "confidence": "inferred"             // inferred | override | unattributed
      },
      "sameIdAs": null,            // the entry carrying the cost, when this one shares its id
      "nameOwner": "widgets.example",        // the application its NAME looks like: a hint
      "misattributed": null,                 // {costReportSays, referenceProves} when they disagree
      "notes": []                            // caveats in plain words; see below
    }
  ],

  "misattributions": [             // in use, but the cost report put the money elsewhere
    {
      "kind": "cache",
      "name": "shared_cache",
      "identifier": "cache-...",
      "totalCents": 900,
      "costReportSays": null,            // null = the cost report could not attribute it at all
      "referenceProves": ["widgets.example"],
      "mappable": true                   // false when several applications share it
    }
  ],
  "mapSuggestion": { "shared_cache": "widgets.example" },  // ready for cloud-cost.php --map

  "danglingReferences": [          // an environment points at something no list reports
    { "environment": "widgets.example / staging", "kind": "cache", "id": "cache-..." }
  ],

  "costCoverage": {                // null with --no-cost
    "usageRowsJoined": 7,
    "usageRowsWithoutListedResource": 1,  // billed, but in no resource list: no verdict available
    "attachedCents": 12400,               // resource cost that carries a verdict
    "costReportResourcesCents": 12750,    // what the cost report published for resources
    "withinTotal": true                   // false means a billed line is counted twice: see errors[]
  },

  "errors": []                     // per-resource failures that did not stop the run
}
```

### Verdicts

| verdict | reference | activity | what it means |
| --- | --- | --- | --- |
| `in-use` | yes | — | an environment names it |
| `possibly-superseded` | yes | — | every referencing environment overrides that wiring by hand |
| `unreferenced-but-active` | no | active | nothing names it, but it is doing work |
| `probable-orphan` | no | idle or unknown | nothing names it and nothing says it is working |
| `unproven` | n/a | n/a | a bucket: no attachment field exists in either direction |

`activity.state` is `unknown` rather than `idle` whenever the signal cannot be trusted for that
resource type, which the probe works out from the resources of that type that *are* referenced. A
`probable-orphan` with `activity.state: "unknown"` rests on the references alone.

`notes[]` carries the caveats that change what a verdict means: a shared cluster, a resource listed
twice, metrics identical to a different resource's, the manual-wiring explanation behind the two
middle verdicts, and the reason a bucket cannot be judged.

### One resource, listed twice

`database-cluster:list` can return two entries that carry **the same id** under different names:

```jsonc
[
  { "id": "plain-field-12345678", "name": "widgets",         "schemas": [{ "id": "1001", "name": "main" }] },
  { "id": "plain-field-12345678", "name": "widgets_staging", "schemas": [{ "id": "1002", "name": "main" }] }
]
```

That is one cluster under two names, not two clusters, and taking the entries at face value breaks
three things at once:

- **The money.** The cost join keys on the id, so both entries claim the same billed line. Any sum
  over them invents cost that is not on the invoice.
- **The verdict.** The entries can carry different schema subsets, so one reads `in-use` and the
  other `probable-orphan` — two contradictory answers about a single cluster.
- **The counts.** Verdicts would add up to more resources than exist.

So references, schemas, and the verdict are decided **once per id**, across every entry sharing it.
Both entries report the same verdict and the same `referencedBy`. The cost is billed to one of them,
the one carrying the references (name order breaks a tie, so two runs agree), and the other gets
`cost: null` and `sameIdAs` naming the entry that holds it. No split is invented: there is one line on
the invoice and it stays whole.

`inventory.duplicateIdGroups` says how many resources this applied to, and `verdictCounts` counts
distinct resources rather than listed entries.

### Reconcile

`costCoverage` adds up the resource cost that carries a verdict and compares it to the resource total
the cost report published. The parts can be **smaller** than the total, because a billed resource that
no list reports gets no verdict. They must never be **larger**: that means a billed line has been
counted more than once. When that happens `withinTotal` is `false` and `errors[]` says so by how much,
rather than the output quietly presenting a total that is too high.

## Errors

Failures go to stderr and exit non-zero: `1` for a real failure, `2` for a bad flag. The three
first-run failures (more than one organization authenticated, not authenticated, an upstream CLI bug)
are rewritten into an instruction rather than passed through raw.

The probe makes one call per environment, so a failure partway through would waste the whole run. A
429 is retried with a growing backoff (15s, 30s, 45s, 60s) and counted in `callBudget`; a metrics
call that fails is recorded in `errors[]` and leaves that resource's `activity.state` as `unknown`
rather than ending the run. If the cost report fails, the probe still emits every verdict with
`cost: null` and the reason in `errors[]`.
