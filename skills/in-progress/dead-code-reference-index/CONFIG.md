# The sources config

One JSON file names every place the index looks. Nothing is assumed: no paths, no organization, no
database. `--print-config` writes a starter.

```json
{
  "selfRoots": ["/checkouts/widget-shop"],
  "sources": {
    "repos": {
      "roots": ["/checkouts"],
      "exclude": ["vendor", "node_modules", ".git"],
      "extensions": ["php", "json", "yml", "yaml", "sh"]
    },
    "ops": { "roots": ["/notes/runbooks", "/notes/playbooks"] },
    "deploy": {
      "roots": ["/checkouts/widget-shop"],
      "paths": [".github/workflows", ".cloud/config.json", "Procfile", "crontab"]
    },
    "contracts": { "roots": ["/checkouts/widget-shop/openapi", "/checkouts/widget-shop/docs"] },
    "github": { "org": "widget-co", "repos": [], "extraQualifiers": "--language=php" },
    "database": {
      "driver": "mysql",
      "morphTypeColumns": ["taggables.taggable_type", "activity_log.subject_type"],
      "typeColumns": ["notifications.type"],
      "payloadColumns": ["jobs.payload", "failed_jobs.payload"],
      "extraColumns": ["settings.handler_class"]
    }
  }
}
```

## Top level

`selfRoots` is a list of absolute paths holding the application under test. Files under them are
skipped by the `repos` and `ops` sources, so an application never counts as evidence about itself.
`deploy` and `contracts` ignore `selfRoots` on purpose: an application's own CI workflow and its own
published OpenAPI document are external evidence about its code.

`sources` holds one block per source. A source with no block does not run. An unknown source name is
a misuse and exits 2 rather than being ignored.

## Filesystem sources

These four fields are shared by `repos`, `ops`, `deploy` and `contracts`.

| Field | Meaning |
| --- | --- |
| `roots` | absolute paths to search. A root may be a single file. A missing root is reported in `notes` and skipped |
| `exclude` | directory names never walked. Defaults to the usual build and dependency directories |
| `extensions` | file extensions read, without the dot. Defaults differ per source |

`deploy` also takes `paths`, which are relative paths inside each root. Only those are read, so the
source costs nothing on a large repository. The default list covers `.github/workflows`,
`.gitlab-ci.yml`, `.cloud/config.json`, `.cloud`, `Procfile`, `crontab`, `crontabs`, `deploy`,
`deploy.sh`, `Dockerfile`, `docker-compose.yml`, `fly.toml`, `vapor.yml` and `nixpacks.toml`.

Default extensions: `repos` reads code and config (`php`, `json`, `yml`, `yaml`, `xml`, `neon`, `sh`,
`bash`, `env`, `ini`, `toml`, `js`, `ts`, `stub`); `ops` reads prose and scripts (`md`, `txt`, `sh`,
`bash`, `yml`, `yaml`, `json`, `log`, `rst`, `org`); `deploy` adds extensionless files, which is how
`Procfile` and `crontab` get read; `contracts` reads `yml`, `yaml`, `json`, `md`, `graphql`, `proto`,
`raml` and `txt`.

A file larger than `--max-file-kb` (512 KB by default) is skipped. Minified assets and committed
fixtures are the usual reason to leave that low.

## github

| Field | Meaning |
| --- | --- |
| `org` | the organization to search. Ignored when `repos` is set |
| `repos` | specific `owner/name` repositories, which narrows the search and the rate-limit cost |
| `extraQualifiers` | appended to the `gh search code` command verbatim, for qualifiers such as `--language=php` |

Code search indexes default branches only, and allows roughly ten queries a minute. See the GitHub
section of the SKILL.md for `--github-limit` and `--state`.

## database

| Field | Meaning |
| --- | --- |
| `driver` | `mysql`, `pgsql` or `sqlite`. Decides the JSON extraction and the identifier quoting |
| `morphTypeColumns` | polymorphic type columns, as `table.column` |
| `typeColumns` | other columns holding a class name, such as `notifications.type` |
| `payloadColumns` | columns holding a serialised job payload. The generated SQL extracts `displayName` from the JSON |
| `extraColumns` | anything else holding a class name as a string |

Every name must be a plain identifier (`[A-Za-z_][A-Za-z0-9_]*`). A name that is not is skipped with
a note, which is also what keeps a config from smuggling SQL into a statement.

Every generated statement is a `SELECT DISTINCT` over one column. No statement returns a row of data,
and the script refuses to execute anything that does not start with `SELECT`.

To find the morph columns in a schema, this returns them and nothing else:

```sql
SELECT table_name, column_name FROM information_schema.columns
WHERE table_schema = DATABASE() AND column_name LIKE '%\_type' ORDER BY table_name;
```
