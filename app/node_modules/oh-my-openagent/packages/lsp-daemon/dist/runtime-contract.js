import { statSync } from "node:fs";
import { isAbsolute } from "node:path";
export const OMO_LSP_DAEMON_DIR = "OMO_LSP_DAEMON_DIR";
export const OMO_LSP_DAEMON_CLI = "OMO_LSP_DAEMON_CLI";
export const OMO_LSP_DAEMON_VERSION = "OMO_LSP_DAEMON_VERSION";
const DAEMON_VERSION_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._+-]{0,127}$/;
export class InvalidRuntimeOverrideError extends Error {
    constructor(reason, message) {
        super(message);
        this.code = "invalid_runtime_override";
        this.name = "InvalidRuntimeOverrideError";
        this.reason = reason;
    }
}
export class InvalidDaemonVersionError extends Error {
    constructor(version) {
        super("LSP daemon version must match [A-Za-z0-9][A-Za-z0-9._+-]{0,127}");
        this.code = "invalid_daemon_version";
        this.name = "InvalidDaemonVersionError";
        this.version = version;
    }
}
export function validateDaemonVersion(version) {
    if (!DAEMON_VERSION_PATTERN.test(version))
        throw new InvalidDaemonVersionError(version);
    return version;
}
export function resolveDaemonRuntime(env, defaults) {
    const cliOverride = env[OMO_LSP_DAEMON_CLI];
    const versionOverride = env[OMO_LSP_DAEMON_VERSION];
    const hasCliOverride = cliOverride !== undefined;
    const hasVersionOverride = versionOverride !== undefined;
    if (hasCliOverride !== hasVersionOverride) {
        throw new InvalidRuntimeOverrideError("paired_values_required", `${OMO_LSP_DAEMON_CLI} and ${OMO_LSP_DAEMON_VERSION} must be set together`);
    }
    if (!hasCliOverride || !hasVersionOverride) {
        if (!isAbsolute(defaults.cliPath)) {
            throw new InvalidRuntimeOverrideError("packaged_cli_must_be_absolute", "Packaged LSP daemon CLI path must be absolute");
        }
        return { cliPath: defaults.cliPath, version: validateDaemonVersion(defaults.version) };
    }
    if (!isAbsolute(cliOverride)) {
        throw new InvalidRuntimeOverrideError("cli_must_be_absolute", `${OMO_LSP_DAEMON_CLI} must be an absolute path to an existing regular file`);
    }
    let cliStats;
    try {
        cliStats = statSync(cliOverride);
    }
    catch (error) {
        if (!(error instanceof Error))
            throw error;
        throw new InvalidRuntimeOverrideError("cli_not_found", `${OMO_LSP_DAEMON_CLI} must name an existing regular file`);
    }
    if (!cliStats.isFile()) {
        throw new InvalidRuntimeOverrideError("cli_not_file", `${OMO_LSP_DAEMON_CLI} must name an existing regular file`);
    }
    return { cliPath: cliOverride, version: validateDaemonVersion(versionOverride) };
}
