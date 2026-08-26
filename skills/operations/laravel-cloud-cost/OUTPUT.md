# The `--json` payload

One object on stdout, nothing else. Money is **integer cents** everywhere; the `currency` field says
what those cents are. Exit code 0 means the object is complete.

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

## Errors

Failures go to stderr and exit non-zero: `1` for a real failure, `2` for a bad flag. The three
first-run failures (more than one organization authenticated, not authenticated, an upstream CLI bug)
are rewritten into an instruction rather than passed through raw.
