#!/usr/bin/env node
// Keeps .claude-plugin/plugin.json's version in step with package.json.
// `--check` verifies instead of writing, for CI.

import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const repo = join(dirname(fileURLToPath(import.meta.url)), "..");
const pkgPath = join(repo, "package.json");
const pluginPath = join(repo, ".claude-plugin", "plugin.json");

const pkg = JSON.parse(readFileSync(pkgPath, "utf8"));
const raw = readFileSync(pluginPath, "utf8");
const plugin = JSON.parse(raw);

const check = process.argv.includes("--check");

if (plugin.version === pkg.version) {
  console.log(`plugin.json already at ${pkg.version}`);
  process.exit(0);
}

if (check) {
  console.error(
    `plugin.json is ${plugin.version}, package.json is ${pkg.version}. Run: npm run version`,
  );
  process.exit(1);
}

// Rewrite the single version line so the rest of the file keeps its formatting.
const next = raw.replace(
  /("version"\s*:\s*)"[^"]*"/,
  `$1"${pkg.version}"`,
);
writeFileSync(pluginPath, next);
console.log(`plugin.json ${plugin.version} -> ${pkg.version}`);
