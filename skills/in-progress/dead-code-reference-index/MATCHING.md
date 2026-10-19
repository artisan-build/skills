# Match kinds and where they go wrong

Each candidate becomes a set of needles. Each needle knows what finding it would mean, and some
carry a context requirement that a file has to satisfy before the match counts.

## Confidence

`linked` means the match cannot plausibly be another symbol. `inferred` means it could. A candidate
with no hits is unattributed, reported as the verdict `no-external-evidence`.

Nothing promotes an `inferred` hit to `linked`. The way to settle one is to read it, which is why
every hit carries its line.

## Class, interface, trait, enum, constant, property

| Match kind | Needle | Confidence | Context required |
| --- | --- | --- | --- |
| `fqcn` | `App\Jobs\SyncOrders` | linked | none |
| `fqcn-escaped` | `App\\Jobs\\SyncOrders` | linked | none |
| `fqcn-double-escaped` | `App\\\\Jobs\\\\SyncOrders` | linked | none |
| `fqcn-slashed` | `App/Jobs/SyncOrders` | inferred | none |
| `class-const` | `SyncOrders::class` | linked | the FQCN or its namespace in the same file |
| `short-name` | `SyncOrders` | inferred | the FQCN or its namespace in the same file |

The escaped forms are the ones a dead-code hunt usually forgets. A queued job's payload, a
polymorphic `*_type` column, a cached config array and a JSON manifest all hold a class name with
escaped backslashes, and the double-escaped form appears when that payload is itself a JSON string
inside JSON.

`class-const` is `linked` despite being a short form because the context requirement does the work: a
file that writes `SyncOrders::class` and also imports `App\Jobs\SyncOrders` is talking about this
class. A file that writes `SyncOrders::class` with no such import might be talking about a different
`SyncOrders`, and that match is suppressed.

## Method

| Match kind | Needle | Confidence | Context required |
| --- | --- | --- | --- |
| `method-call` | `->send(` | inferred | the owning class, short or fully qualified, or its namespace |
| `static-method-call` | `::send(` | inferred | same |
| `method-as-string` | `'send'` or `"send"` | inferred | same |
| `owning-class-short-name` | `SyncOrders` | inferred | the FQCN or namespace |
| `owning-class-fqcn` | `App\Jobs\SyncOrders` | linked | none |
| `method-no-class-context` | `->send(` | inferred | impossible to satisfy |

A method hit is never `linked`, whatever the context. One file can reference two classes that both
define `send`, and a text search cannot say which one a call went to.

`method-as-string` catches the callable array form, `[SyncOrders::class, 'send']`, and the string
form a config or a listener map uses.

A candidate written as a bare method name with no class (`send` rather than `SyncOrders::send`)
cannot be fenced at all. Its needles are given a context no file can satisfy, so they are counted and
never reported. Hand the script `Class::method` when you have it.

## Route

| Match kind | Needle | Confidence |
| --- | --- | --- |
| `route-name` | `orders.export` | linked |
| `route-name-kebab` | `orders-export` | inferred |
| `route-name-snake` | `orders_export` | inferred |
| `route-uri` | `/orders/export` | inferred |
| `route-name-dotted` | from a URI-shaped candidate | inferred |

The URI form is a guess about how the route is spelled in a URL, built by swapping dots for slashes.
It is right often enough to be worth searching and wrong often enough to stay inferred.

## Command

| Match kind | Needle | Confidence |
| --- | --- | --- |
| `command-signature` | `widgets:reconcile` | linked |
| `command-invocation` | `artisan widgets:reconcile`, `artisan('widgets:reconcile')` | linked |
| `command-name-kebab` | `widgets-reconcile` | inferred |
| `command-name-snake` | `widgets_reconcile` | inferred |

Arguments and options are stripped from a signature before the needle is built, so
`widgets:reconcile {--force}` searches for `widgets:reconcile`.

A command signature in an ops-knowledge source is the single most valuable hit this script produces.
A command somebody runs by hand once a quarter is unreachable from every entry point and completely
alive, and nothing inside the application can tell you that.

## MCP tool, view, and anything else

A tool name is searched literally and in its kebab and snake spellings. A view name is searched
dotted and as a path. A candidate whose kind the script cannot place is searched as a literal and as
a quoted string, both `linked`.

## Suppression

A needle with a context requirement that the file does not meet produces a suppressed hit. Suppressed
hits are counted in `hitCounts.noContextSuppressed` and left out of the report.

`--include-no-context` includes them, tagged with a `-no-context` suffix on the match kind and forced
to `inferred`, so they cannot be mistaken for the fenced ones. Turn it on when a candidate's only
evidence is suppressed and you want to see what was thrown away.

## Known false-positive shapes

A short class name that is a common English word matches prose. `Status`, `Token`, `Report` and
`Invoice` all appear in documentation that has nothing to do with the class, and the namespace
context requirement is what keeps most of that out.

A method name shared across an interface and its implementations matches every implementation's file.
The context fence passes, because those files do name the class, and the hit is real but says nothing
about this particular implementation.

A route name that is also a config key matches the config. Reading the line settles it in seconds.

A vendor directory that was not excluded matches your own class names in its own lockfile or cache.
The default excludes cover `vendor` and `node_modules`; a per-source `exclude` is how you add others.
