#!/usr/bin/env node
// Static validation for the agentic workflow wiring.
// Checks .opencode/agentic-workflows.json against itself and against the
// workflow command files on disk. Exit code 1 on any problem.

import { readFileSync, existsSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..", "..");
const wiringPath = join(root, ".opencode", "agentic-workflows.json");

if (!existsSync(wiringPath)) {
  console.error(`agentic workflows: missing ${wiringPath}`);
  process.exit(1);
}

let wiring;
try {
  wiring = JSON.parse(readFileSync(wiringPath, "utf8"));
} catch (error) {
  console.error(`agentic workflows: cannot parse ${wiringPath}: ${error.message}`);
  process.exit(1);
}

const problems = [];
const fail = (message) => problems.push(message);

for (const key of ["version", "agents", "skills", "mcp", "router", "workflows"]) {
  if (!(key in wiring)) fail(`top-level key "${key}" is missing`);
}

const agents = Array.isArray(wiring.agents) ? wiring.agents : [];
const agentNames = new Set();
for (const agent of agents) {
  if (!agent.name) {
    fail("agent without a name");
    continue;
  }
  agentNames.add(agent.name);
  if (!["primary", "subagent", "all"].includes(agent.mode)) {
    fail(`agent "${agent.name}" has invalid mode "${agent.mode}"`);
  }
}
if (!agents.some((agent) => agent.mode === "primary")) {
  fail("no primary agent is registered");
}

const entryPoints = wiring.router?.entryPoints ?? [];
for (const entry of entryPoints) {
  if (!agentNames.has(entry)) fail(`router entry point "${entry}" is not a registered agent`);
}

const catalog = wiring.skills?.catalog ?? [];
const skillNames = new Set(catalog.map((skill) => skill.name));
if (catalog.length === 0) fail("skills.catalog is empty");

const servers = wiring.mcp?.servers ?? [];
const serverNames = new Set(servers.map((server) => server.name));
if (servers.length === 0) fail("mcp.servers is empty");

const workflowEntries = Object.entries(wiring.workflows ?? {});
if (workflowEntries.length === 0) fail("workflows is empty");
for (const [key, workflow] of workflowEntries) {
  if (!workflow.command) {
    fail(`workflow "${key}" has no command`);
  } else if (!existsSync(join(root, workflow.command))) {
    fail(`workflow "${key}" command file is missing: ${workflow.command}`);
  }
  for (const agent of workflow.entry ?? []) {
    if (!agentNames.has(agent)) fail(`workflow "${key}" entry references unknown agent "${agent}"`);
  }
  for (const agent of workflow.subagents ?? []) {
    if (!agentNames.has(agent)) fail(`workflow "${key}" references unknown subagent "${agent}"`);
  }
}

const perWorkflow = wiring.skills?.perWorkflow ?? {};
const workflowKeys = Object.keys(wiring.workflows ?? {}).sort();
if (Object.keys(perWorkflow).sort().join(",") !== workflowKeys.join(",")) {
  fail(`skills.perWorkflow keys do not match workflows: ${Object.keys(perWorkflow).join(", ")}`);
}
for (const [key, config] of Object.entries(perWorkflow)) {
  const referenced = [
    ...(config.required ?? []),
    ...(config.conditional ?? []).map((hook) => hook.skill),
  ];
  for (const skill of referenced) {
    if (!skillNames.has(skill)) fail(`skills.perWorkflow["${key}"] references unknown skill "${skill}"`);
  }
}

const mcpPerWorkflow = wiring.mcp?.perWorkflow ?? {};
if (Object.keys(mcpPerWorkflow).sort().join(",") !== workflowKeys.join(",")) {
  fail(`mcp.perWorkflow keys do not match workflows: ${Object.keys(mcpPerWorkflow).join(", ")}`);
}
for (const [key, list] of Object.entries(mcpPerWorkflow)) {
  for (const server of list) {
    if (!serverNames.has(server)) fail(`mcp.perWorkflow["${key}"] references unknown server "${server}"`);
  }
}

if (problems.length > 0) {
  console.error(`agentic workflow validation failed (${problems.length} problem(s)):`);
  for (const problem of problems) console.error(`  - ${problem}`);
  process.exit(1);
}

console.log(
  `agentic workflows OK: ${agents.length} agents, ${catalog.length} skills, ` +
    `${servers.length} MCP servers, ${workflowEntries.length} workflows`,
);
