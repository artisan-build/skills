# The JSON output

`--json` emits one object on stdout and nothing else. `--tsv` emits the hit table alone, one row per
hit, with a row carrying empty hit columns for a candidate that had none.

```json
{
  "schemaVersion": 1,
  "generatedAt": "2026-10-19T19:00:00+00:00",
  "candidateSource": {
    "path": "candidates.tsv",
    "format": "tsv",
    "columns": { "symbol": "symbol", "kind": "kind", "staticVerdict": "static verdict" },
    "headers": ["symbol", "file:line", "kind", "static verdict"],
    "rows": 15
  },
  "sourcesRun": ["repos", "ops", "deploy", "contracts"],
  "sourcesSkipped": ["github"],
  "includeNoContext": false,
  "budget": {
    "filesWalked": 0,
    "filesShortlisted": 184,
    "filesRead": 181,
    "usedGrep": true,
    "githubQueries": 0,
    "githubCached": 0,
    "githubPending": 0
  },
  "candidates": [
    {
      "symbol": "App\\Jobs\\SyncOrders",
      "kind": "class",
      "location": "app/Jobs/SyncOrders.php:11",
      "staticVerdict": "dead",
      "entryPointEvidence": "none",
      "prodEvidence": "none",
      "priorDecision": "",
      "reason": "no dispatch found",
      "extra": { "owner": "platform" },
      "indexVerdict": "alive-elsewhere",
      "hitCounts": { "linked": 2, "inferred": 3, "noContextSuppressed": 11 },
      "needles": [{ "text": "App\\Jobs\\SyncOrders", "matchKind": "fqcn", "confidence": "linked" }],
      "hits": [
        {
          "source": "repos",
          "root": "/checkouts",
          "path": "admin-tools/app/Console/Resync.php",
          "line": 22,
          "matchKind": "fqcn",
          "confidence": "linked",
          "needle": "App\\Jobs\\SyncOrders",
          "snippet": "use App\\Jobs\\SyncOrders;"
        }
      ]
    }
  ],
  "database": { "sql": [], "sqlScript": "", "ran": false, "distinctValues": {} },
  "notes": []
}
```

## Fields worth knowing

`candidateSource.columns` maps the script's field names to the headers it found. Read it before
trusting a TSV run: it is how you confirm that the column you meant as the static verdict was read as
one.

`candidateSource.headers` is every header in the file, in order, so a column the script did not
recognise is still visible. Unrecognised columns are carried per candidate in `extra`.

`sourcesSkipped` holds configured sources that did not run, whether because `--only` or `--skip`
excluded them or because a dependency was missing. The reason is in `notes`.

`budget.filesWalked` is zero when the grep prefilter was used, because grep does the walking.
`filesShortlisted` is what grep named, and `filesRead` is what the script opened; the two differ when
a shortlisted file is inside a `selfRoots` path.

`hitCounts.noContextSuppressed` counts matches thrown away for failing their context requirement. A
candidate with zero linked hits and a large suppressed count is the shape that needs a human to look.

`needles` is every needle built for that candidate, which is how you check that a candidate's kind
was inferred the way you expected.

`database.sqlScript` is the ready-to-run SQL as one string. `database.distinctValues` is populated
only when a DSN was given, and holds the distinct column values read, keyed by the column's label.

`notes` carries everything that went wrong without failing the run: a missing root, an unavailable
`gh`, a refused DSN, a column name that was not an identifier. Read it on every run. A source that
silently did not run looks exactly like a source that found nothing.

## Exit codes

`0` the run completed, whatever it found. `1` a real failure: an unreadable candidates file, a config
that is not JSON, no candidates. `2` misuse: an unknown flag, a missing required flag, an unknown
source name, mutually exclusive output flags.
