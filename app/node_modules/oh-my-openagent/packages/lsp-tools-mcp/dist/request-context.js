// ../lsp-core/src/request-context.ts
import { AsyncLocalStorage } from "node:async_hooks";
import { existsSync, realpathSync, statSync } from "node:fs";
import { homedir } from "node:os";
import { basename, delimiter, dirname, isAbsolute, join, relative, resolve } from "node:path";

class LspRequestContextParseError extends Error {
  code;
  name = "LspRequestContextParseError";
  constructor(code, message) {
    super(message);
    this.code = code;
  }
}

class LspRequestContextUnavailableError extends Error {
  name = "LspRequestContextUnavailableError";
  constructor() {
    super("LSP request context is required. Standalone MCP startup must install one with runWithRequestContext(createStandaloneMcpRequestContext()).");
  }
}
var storage = new AsyncLocalStorage;
var CONTEXT_FIELDS = new Set(["cwd", "projectConfigPaths", "userConfigPath", "installDecisionsPath", "capabilities"]);
var CAPABILITY_FIELDS = new Set(["installDecisionTool"]);
function runWithRequestContext(context, fn) {
  return storage.run(context, fn);
}
function lspRequestContext() {
  const context = storage.getStore();
  if (!context)
    throw new LspRequestContextUnavailableError;
  return context;
}
function contextCwd() {
  return lspRequestContext().cwd;
}
function contextEnv(key) {
  const context = lspRequestContext();
  if (key === "LSP_TOOLS_MCP_PROJECT_CONFIG")
    return context.projectConfigPaths.join(delimiter);
  if (key === "LSP_TOOLS_MCP_USER_CONFIG")
    return context.userConfigPath;
  if (key === "LSP_TOOLS_MCP_INSTALL_DECISIONS")
    return context.installDecisionsPath;
  return;
}
function createStandaloneMcpRequestContext(input = {}) {
  const env = input.env ?? process.env;
  const cwd = canonicalCwd(input.cwd ?? process.cwd());
  const home = input.homeDir ?? homedir();
  const projectConfigPaths = translateProjectConfigEnv(env["LSP_TOOLS_MCP_PROJECT_CONFIG"], cwd);
  const userConfigPath = translateHomeConfigEnv(env["LSP_TOOLS_MCP_USER_CONFIG"], home, ".codex/lsp-client.json");
  const installDecisionsPath = translateHomeConfigEnv(env["LSP_TOOLS_MCP_INSTALL_DECISIONS"], home, ".codex/lsp-install-decisions.json");
  return parseLspRequestContext({
    cwd,
    projectConfigPaths,
    userConfigPath,
    installDecisionsPath,
    capabilities: { installDecisionTool: true }
  });
}
function parseLspRequestContext(value) {
  if (!isRecord(value)) {
    throw new LspRequestContextParseError("invalid_context", "LSP request context must be an object.");
  }
  rejectUnknownFields(value, CONTEXT_FIELDS, "context");
  const cwd = stringField(value, "cwd");
  const projectConfigPaths = stringArrayField(value, "projectConfigPaths");
  const userConfigPath = stringField(value, "userConfigPath");
  const installDecisionsPath = stringField(value, "installDecisionsPath");
  const capabilities = capabilitiesField(value["capabilities"]);
  const canonical = canonicalCwd(cwd);
  for (const path of projectConfigPaths) {
    requireAbsolutePath(path, "projectConfigPaths");
    const projectPath = canonicalizeExistingOrNearestAncestor(path);
    if (!isPathInside(canonical, projectPath)) {
      throw new LspRequestContextParseError("project_config_outside_cwd", `Project LSP config path must be inside cwd: ${path}`);
    }
  }
  requireAbsolutePath(userConfigPath, "userConfigPath");
  requireAbsolutePath(installDecisionsPath, "installDecisionsPath");
  return {
    cwd: canonical,
    projectConfigPaths: projectConfigPaths.map((path) => canonicalizeExistingOrNearestAncestor(path)),
    userConfigPath,
    installDecisionsPath,
    capabilities
  };
}
function translateProjectConfigEnv(value, cwd) {
  if (value === undefined || value.length === 0)
    return [join(cwd, ".codex", "lsp-client.json")];
  return value.split(delimiter).filter((entry) => entry.length > 0).map((entry) => isAbsolute(entry) ? entry : join(cwd, entry));
}
function translateHomeConfigEnv(value, home, fallback) {
  if (value === undefined || value.length === 0)
    return join(home, fallback);
  return isAbsolute(value) ? value : join(home, value);
}
function canonicalCwd(cwd) {
  const resolved = resolve(cwd);
  if (!existsSync(resolved) || !statSync(resolved).isDirectory()) {
    throw new LspRequestContextParseError("invalid_cwd", `LSP request cwd must be an existing directory: ${cwd}`);
  }
  return realpathSync(resolved);
}
function canonicalizeExistingOrNearestAncestor(path) {
  let current = resolve(path);
  const suffix = [];
  while (true) {
    try {
      const existing = realpathSync(current);
      return suffix.length === 0 ? existing : join(existing, ...suffix);
    } catch (error) {
      if (!isMissingPathError(error))
        throw error;
      const parent = dirname(current);
      if (parent === current)
        throw error;
      suffix.unshift(basename(current));
      current = parent;
    }
  }
}
function capabilitiesField(value) {
  if (!isRecord(value)) {
    throw new LspRequestContextParseError("invalid_capabilities", "LSP request capabilities must be an object.");
  }
  rejectUnknownFields(value, CAPABILITY_FIELDS, "capabilities");
  const installDecisionTool = value["installDecisionTool"];
  if (typeof installDecisionTool !== "boolean") {
    throw new LspRequestContextParseError("invalid_install_decision_capability", "LSP request capabilities.installDecisionTool must be a boolean.");
  }
  return { installDecisionTool };
}
function stringField(value, field) {
  const fieldValue = value[field];
  if (typeof fieldValue !== "string" || fieldValue.length === 0) {
    throw new LspRequestContextParseError("invalid_field", `LSP request context.${field} must be a non-empty string.`);
  }
  return fieldValue;
}
function stringArrayField(value, field) {
  const fieldValue = value[field];
  if (!Array.isArray(fieldValue) || !fieldValue.every((item) => typeof item === "string" && item.length > 0)) {
    throw new LspRequestContextParseError("invalid_field", `LSP request context.${field} must be a non-empty string array.`);
  }
  return fieldValue;
}
function requireAbsolutePath(path, field) {
  if (!isAbsolute(path)) {
    throw new LspRequestContextParseError("relative_path", `LSP request context.${field} must be absolute: ${path}`);
  }
}
function isPathInside(parent, child) {
  const childPath = resolve(child);
  const relativePath = relative(parent, childPath);
  return relativePath === "" || !relativePath.startsWith("..") && !isAbsolute(relativePath);
}
function isMissingPathError(error) {
  const code = errorCode(error);
  return code === "ENOENT" || code === "ENOTDIR";
}
function rejectUnknownFields(value, allowed, scope) {
  const unknown = Object.keys(value).filter((key) => !allowed.has(key));
  if (unknown.length > 0) {
    throw new LspRequestContextParseError("unknown_field", `Unknown LSP request ${scope} field: ${unknown.join(", ")}`);
  }
}
function isRecord(value) {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function errorCode(error) {
  if (!error || typeof error !== "object" || !("code" in error))
    return;
  const code = Reflect.get(error, "code");
  return typeof code === "string" ? code : undefined;
}
export {
  runWithRequestContext,
  parseLspRequestContext,
  lspRequestContext,
  isPathInside,
  createStandaloneMcpRequestContext,
  contextEnv,
  contextCwd,
  canonicalizeExistingOrNearestAncestor,
  LspRequestContextUnavailableError,
  LspRequestContextParseError
};
